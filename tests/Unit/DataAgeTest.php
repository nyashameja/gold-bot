<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Services\Dashboard\DataAge;
use PHPUnit\Framework\TestCase;

/**
 * Data age (docs/01 §8).
 *
 * The dashboard reads MySQL, which a cron filled at some earlier moment. A
 * number with no age beside it looks live whether the feed died a second ago
 * or a day ago, so this small class is load-bearing for every figure on the
 * platform.
 */
final class DataAgeTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-06 12:00:00', new DateTimeZone('UTC'));
    }

    private function at(string $offset): DateTimeImmutable
    {
        return $this->now->modify($offset);
    }

    /** Staleness is judged against the cadence that refreshes the value. */
    public function test_freshness_is_relative_to_the_expected_cadence(): void
    {
        // Fifteen minutes old. Fresh for an hourly import, dead for a
        // per-minute price feed — the same age, two correct answers.
        $age = DataAge::since($this->at('-15 minutes'), $this->now, 3600);
        self::assertSame(DataAge::FRESH, $age->status);

        $age = DataAge::since($this->at('-15 minutes'), $this->now, 60);
        self::assertSame(DataAge::DEAD, $age->status);
    }

    public function test_two_cadences_of_grace_before_stale(): void
    {
        // One missed run is normal on shared hosting; two is a pattern.
        self::assertSame(DataAge::FRESH, DataAge::since($this->at('-119 seconds'), $this->now, 60)->status);
        self::assertSame(DataAge::STALE, DataAge::since($this->at('-121 seconds'), $this->now, 60)->status);
    }

    public function test_ten_cadences_is_not_coming_back(): void
    {
        self::assertSame(DataAge::STALE, DataAge::since($this->at('-9 minutes'), $this->now, 60)->status);
        self::assertSame(DataAge::DEAD, DataAge::since($this->at('-11 minutes'), $this->now, 60)->status);
    }

    /**
     * Missing data is its own state. Reporting "never" as merely very stale
     * would let an empty table read as a slow feed.
     */
    public function test_absent_data_is_distinguished_from_old_data(): void
    {
        $age = DataAge::since(null, $this->now, 60);

        self::assertSame(DataAge::NONE, $age->status);
        self::assertTrue($age->isMissing());
        self::assertNull($age->seconds);
        self::assertNull($age->iso());
        self::assertSame('never', $age->label);
    }

    /**
     * A candle's close time can sit marginally ahead of a drifted clock.
     * "-3s ago" reads as a bug even when nothing is wrong.
     */
    public function test_a_future_timestamp_clamps_to_zero_rather_than_going_negative(): void
    {
        $age = DataAge::since($this->at('+30 seconds'), $this->now, 60);

        self::assertSame(0, $age->seconds);
        self::assertSame('just now', $age->label);
    }

    public function test_labels_are_coarse_on_purpose(): void
    {
        $cases = [
            '-5 seconds'  => 'just now',
            '-45 seconds' => '45s ago',
            '-8 minutes'  => '8m ago',
            '-3 hours'    => '3h ago',
            '-2 days'     => '2d ago',
        ];

        foreach ($cases as $offset => $expected) {
            self::assertSame(
                $expected,
                DataAge::since($this->at($offset), $this->now, 60)->label,
                "Offset {$offset}"
            );
        }
    }

    public function test_the_array_form_carries_everything_a_view_needs(): void
    {
        $age = DataAge::since($this->at('-90 seconds'), $this->now, 60)->toArray();

        self::assertSame(['at', 'seconds', 'status', 'label'], array_keys($age));
        self::assertSame(90, $age['seconds']);
        self::assertSame(DataAge::FRESH, $age['status']);
        // ISO-8601, so the markup stays machine-readable in a <time> element.
        self::assertSame('2026-08-06T11:58:30+00:00', $age['at']);
    }

    public function test_is_fresh_is_only_true_for_fresh(): void
    {
        self::assertTrue(DataAge::since($this->at('-1 second'), $this->now, 60)->isFresh());
        self::assertFalse(DataAge::since($this->at('-5 minutes'), $this->now, 60)->isFresh());
        self::assertFalse(DataAge::since(null, $this->now, 60)->isFresh());
    }
}
