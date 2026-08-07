<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use GoldBot\Infrastructure\Http\ApiBudget;
use Paragon\Core\Clock\FrozenClock;
use Paragon\Core\Http\HttpResponse;
use Paragon\Core\Logging\LoggerInterface;

/**
 * The gate that stops ingest spending into a rate limit (docs/01 §5).
 */
final class ApiBudgetTest extends IntegrationTestCase
{
    private const PROVIDER = 'TEST_PROVIDER';

    private FrozenClock $clock;

    private int $providerId;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('api_providers')) {
            self::markTestSkipped('Operations schema not migrated.');
        }

        $this->clock = new FrozenClock('2026-08-06 12:00:00');

        $this->db->run('DELETE FROM api_providers WHERE code = ?', [self::PROVIDER]);

        $this->providerId = $this->db->insert('api_providers', [
            'code'             => self::PROVIDER,
            'name'             => 'Test Provider',
            'base_url'         => 'https://example.test',
            'daily_limit'      => 10,
            'per_minute_limit' => 3,
            'is_active'        => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->run('DELETE FROM api_usage_log WHERE provider_id = ?', [$this->providerId]);
        $this->db->run('DELETE FROM api_providers WHERE id = ?', [$this->providerId]);

        parent::tearDown();
    }

    private function budget(): ApiBudget
    {
        return new ApiBudget(
            $this->db,
            $this->clock,
            $this->app->container()->get(LoggerInterface::class)
        );
    }

    private function ok(): HttpResponse
    {
        return new HttpResponse(200, '{}', 25);
    }

    private function failed(): HttpResponse
    {
        return new HttpResponse(500, '', 25, 'connection reset');
    }

    public function test_spending_is_allowed_under_the_limit(): void
    {
        $budget = $this->budget();

        self::assertTrue($budget->canSpend(self::PROVIDER));

        $budget->record(self::PROVIDER, '/time_series', $this->ok());
        $budget->record(self::PROVIDER, '/time_series', $this->ok());

        self::assertTrue($budget->canSpend(self::PROVIDER));
    }

    public function test_the_per_minute_limit_blocks_further_calls(): void
    {
        $budget = $this->budget();

        for ($i = 0; $i < 3; $i++) {
            $budget->record(self::PROVIDER, '/time_series', $this->ok());
        }

        self::assertFalse($budget->canSpend(self::PROVIDER), 'The 4th call in a minute exceeds a limit of 3.');
    }

    /**
     * A rolling window, not a calendar minute: the allowance must free up as
     * the oldest calls age out.
     */
    public function test_the_per_minute_window_rolls(): void
    {
        $budget = $this->budget();

        for ($i = 0; $i < 3; $i++) {
            $budget->record(self::PROVIDER, '/time_series', $this->ok());
        }

        self::assertFalse($budget->canSpend(self::PROVIDER));

        $this->clock->advanceSeconds(61);

        self::assertTrue($this->budget()->canSpend(self::PROVIDER), 'The window has rolled past those calls.');
    }

    /**
     * A refused request still counts against most providers' quotas, so
     * recording only successes would make the next check optimistic in
     * exactly the wrong direction.
     */
    public function test_failed_calls_still_consume_budget(): void
    {
        $budget = $this->budget();

        for ($i = 0; $i < 3; $i++) {
            $budget->record(self::PROVIDER, '/time_series', $this->failed());
        }

        self::assertFalse($budget->canSpend(self::PROVIDER));
    }

    public function test_the_daily_limit_blocks_even_when_the_minute_is_clear(): void
    {
        $budget = $this->budget();

        // Ten calls spread across the day, never more than 3 in one minute.
        for ($i = 0; $i < 10; $i++) {
            $budget->record(self::PROVIDER, '/time_series', $this->ok());
            $this->clock->advanceSeconds(120);
        }

        $budget = $this->budget();

        self::assertTrue($budget->canSpend(self::PROVIDER) === false, 'The daily limit of 10 is reached.');

        $status = $budget->status(self::PROVIDER);
        self::assertSame(10, $status['daily_used']);
        self::assertSame(0, $status['remaining']);
        self::assertSame(0, $status['minute_used'], 'No recent calls, yet still blocked by the daily cap.');
    }

    public function test_status_reports_both_windows(): void
    {
        $budget = $this->budget();

        $budget->record(self::PROVIDER, '/quote', $this->ok());
        $budget->record(self::PROVIDER, '/quote', $this->ok());

        $status = $budget->status(self::PROVIDER);

        self::assertSame(2, $status['minute_used']);
        self::assertSame(3, $status['minute_limit']);
        self::assertSame(2, $status['daily_used']);
        self::assertSame(10, $status['daily_limit']);
        self::assertSame(8, $status['remaining']);
    }

    /**
     * A missing reference row must not block ingest — there is nothing to
     * enforce, and failing closed would take the platform down over a seed
     * that never ran.
     */
    public function test_an_unknown_provider_is_not_blocked(): void
    {
        self::assertTrue($this->budget()->canSpend('NOT_A_REAL_PROVIDER'));
    }

    public function test_recording_stores_the_call_details(): void
    {
        $this->budget()->record(self::PROVIDER, '/time_series', new HttpResponse(429, '{}', 87));

        $row = $this->db->selectOne(
            'SELECT endpoint, http_status, succeeded, response_time_ms FROM api_usage_log WHERE provider_id = ?',
            [$this->providerId]
        );

        self::assertSame('/time_series', $row['endpoint']);
        self::assertSame(429, (int) $row['http_status']);
        self::assertSame(0, (int) $row['succeeded']);
        self::assertSame(87, (int) $row['response_time_ms']);
    }
}
