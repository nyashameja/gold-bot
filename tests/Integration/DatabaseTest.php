<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use RuntimeException;

final class DatabaseTest extends IntegrationTestCase
{
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->table = 'test_scratch_' . bin2hex(random_bytes(4));

        $this->db->run(
            "CREATE TABLE `{$this->table}` (
                id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sku   VARCHAR(20)     NOT NULL,
                price DECIMAL(14,5)   NOT NULL,
                note  VARCHAR(50)     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sku (sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    protected function tearDown(): void
    {
        $this->db->run("DROP TABLE IF EXISTS `{$this->table}`");

        parent::tearDown();
    }

    /**
     * Storage is UTC by convention (docs/02 §1). If the session timezone were
     * left at the server default, NOW() and every CURRENT_TIMESTAMP column
     * would silently shift candle timestamps by hours.
     */
    public function test_the_connection_session_is_utc(): void
    {
        $offset = $this->db->scalar('SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP())');

        self::assertSame('00:00:00', (string) $offset);
    }

    public function test_insert_returns_the_generated_id(): void
    {
        $id = $this->db->insert($this->table, ['sku' => 'XAU', 'price' => '3312.45000']);

        self::assertGreaterThan(0, $id);
        self::assertSame('XAU', $this->db->selectOne("SELECT sku FROM `{$this->table}` WHERE id = ?", [$id])['sku']);
    }

    /**
     * The ingest primitive from docs/02 §5. Providers revise recent bars and
     * fetch windows overlap, so re-importing the same key must update rather
     * than fail — this is what makes the whole ingest path safely retryable.
     */
    public function test_upsert_inserts_then_updates_without_duplicating(): void
    {
        $this->db->upsert($this->table, ['sku' => 'XAU', 'price' => '3300.00000', 'note' => 'first'], ['price', 'note']);
        $this->db->upsert($this->table, ['sku' => 'XAU', 'price' => '3350.00000', 'note' => 'revised'], ['price', 'note']);

        $rows = $this->db->select("SELECT sku, price, note FROM `{$this->table}`");

        self::assertCount(1, $rows, 'The unique key must prevent a duplicate row.');
        self::assertSame('3350.00000', $rows[0]['price']);
        self::assertSame('revised', $rows[0]['note']);
    }

    public function test_upsert_leaves_columns_outside_the_update_list_alone(): void
    {
        $this->db->upsert($this->table, ['sku' => 'XAU', 'price' => '3300.00000', 'note' => 'keep me'], ['price']);
        $this->db->upsert($this->table, ['sku' => 'XAU', 'price' => '3350.00000', 'note' => 'ignored'], ['price']);

        $row = $this->db->selectOne("SELECT price, note FROM `{$this->table}` WHERE sku = 'XAU'");

        self::assertSame('3350.00000', $row['price']);
        self::assertSame('keep me', $row['note'], 'Only listed columns may be overwritten.');
    }

    /**
     * Prices are DECIMAL, never FLOAT (ADR-11). Binary floating point cannot
     * represent decimal fractions exactly, and this system compares prices to
     * stop losses.
     */
    public function test_decimal_prices_survive_a_round_trip_exactly(): void
    {
        $this->db->insert($this->table, ['sku' => 'A', 'price' => '3312.10000']);
        $this->db->insert($this->table, ['sku' => 'B', 'price' => '0.10000']);
        $this->db->insert($this->table, ['sku' => 'C', 'price' => '0.20000']);

        $sum = $this->db->scalar("SELECT price FROM `{$this->table}` WHERE sku = 'B'")
            + 0; // fetched as string; the point is the stored value is exact

        self::assertSame('0.10000', (string) $this->db->scalar("SELECT price FROM `{$this->table}` WHERE sku = 'B'"));
        self::assertSame(
            '0.30000',
            (string) $this->db->scalar("SELECT SUM(price) FROM `{$this->table}` WHERE sku IN ('B','C')"),
            'DECIMAL arithmetic must be exact — 0.1 + 0.2 is where FLOAT betrays you.'
        );
        self::assertIsNumeric($sum);
    }

    public function test_a_transaction_commits_on_success(): void
    {
        $this->db->transaction(function (): void {
            $this->db->insert($this->table, ['sku' => 'XAU', 'price' => '1.00000']);
        });

        self::assertSame(1, (int) $this->db->scalar("SELECT COUNT(*) FROM `{$this->table}`"));
    }

    public function test_a_transaction_rolls_back_on_a_throwable(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->insert($this->table, ['sku' => 'XAU', 'price' => '1.00000']);

                throw new RuntimeException('failure after the write');
            });
            self::fail('The exception should have propagated.');
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertSame(0, (int) $this->db->scalar("SELECT COUNT(*) FROM `{$this->table}`"));
        self::assertFalse($this->db->inTransaction());
    }

    /**
     * The outbox (ADR-07) writes the signal and enqueues its message in one
     * transaction. If a nested call opened a second real transaction, the
     * inner commit would commit the outer work early and break that atomicity.
     */
    public function test_a_nested_transaction_rolls_back_with_the_outer_one(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->insert($this->table, ['sku' => 'OUTER', 'price' => '1.00000']);

                $this->db->transaction(function (): void {
                    $this->db->insert($this->table, ['sku' => 'INNER', 'price' => '2.00000']);
                });

                // The inner transaction has "committed" — the outer must still
                // be able to discard everything.
                throw new RuntimeException('outer failure');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertSame(
            0,
            (int) $this->db->scalar("SELECT COUNT(*) FROM `{$this->table}`"),
            'An inner commit must not durably commit the outer transaction.'
        );
    }

    public function test_an_inner_failure_can_be_caught_without_losing_outer_work(): void
    {
        $this->db->transaction(function (): void {
            $this->db->insert($this->table, ['sku' => 'OUTER', 'price' => '1.00000']);

            try {
                $this->db->transaction(function (): void {
                    $this->db->insert($this->table, ['sku' => 'INNER', 'price' => '2.00000']);

                    throw new RuntimeException('inner failure');
                });
            } catch (RuntimeException) {
                // Savepoint rolled back; the outer transaction continues.
            }
        });

        $skus = array_column($this->db->select("SELECT sku FROM `{$this->table}`"), 'sku');

        self::assertSame(['OUTER'], $skus);
    }

    public function test_select_one_returns_null_rather_than_false_on_no_match(): void
    {
        self::assertNull($this->db->selectOne("SELECT * FROM `{$this->table}` WHERE sku = ?", ['nope']));
        self::assertNull($this->db->scalar("SELECT sku FROM `{$this->table}` WHERE sku = ?", ['nope']));
    }

    public function test_bindings_are_parameterised_not_interpolated(): void
    {
        $this->db->insert($this->table, ['sku' => "'; DROP TABLE x; --", 'price' => '1.00000']);

        self::assertSame(
            "'; DROP TABLE x; --",
            (string) $this->db->scalar("SELECT sku FROM `{$this->table}` LIMIT 1")
        );
        self::assertTrue($this->db->tableExists($this->table));
    }

    public function test_table_exists_reports_correctly(): void
    {
        self::assertTrue($this->db->tableExists($this->table));
        self::assertFalse($this->db->tableExists('definitely_not_a_table'));
    }
}
