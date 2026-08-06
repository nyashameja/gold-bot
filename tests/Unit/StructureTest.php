<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Enums\LevelType;
use GoldBot\Domain\Market\Enums\StructureType;
use GoldBot\Domain\Market\Enums\TrendState;
use GoldBot\Domain\Structure\LevelBuilder;
use GoldBot\Domain\Structure\StructureAnalyser;
use GoldBot\Domain\Structure\SwingDetector;
use PHPUnit\Framework\TestCase;

final class StructureTest extends TestCase
{
    /**
     * Build a series from [high, low] or [high, low, open, close].
     *
     * Swing detection only reads highs and lows, so the two-element form is
     * enough there and keeps those fixtures readable. Zone detection measures
     * impulse by *body* size — a wide-range bar with a tiny body is
     * indecision, not impulse — so those fixtures must state open and close.
     *
     * @param list<array{0:float,1:float,2?:float,3?:float}> $bars
     */
    private function series(array $bars): CandleSeries
    {
        $start = new DateTimeImmutable('2026-01-05 00:00:00', new DateTimeZone('UTC'));
        $candles = [];

        foreach ($bars as $i => $bar) {
            [$high, $low] = $bar;
            $at = $start->modify(sprintf('+%d minutes', $i * 15));
            $mid = ($high + $low) / 2;

            $open = $bar[2] ?? $mid;
            $close = $bar[3] ?? $mid;

            $candles[] = new Candle(
                $at,
                $at->modify('+14 minutes 59 seconds'),
                number_format($open, 5, '.', ''),
                number_format($high, 5, '.', ''),
                number_format($low, 5, '.', ''),
                number_format($close, 5, '.', ''),
                '0',
                true
            );
        }

        return new CandleSeries($candles);
    }

    // ── Swing detection ──────────────────────────────────────────────────────

    public function test_it_finds_an_obvious_swing_high(): void
    {
        // A clean peak at index 3.
        $series = $this->series([
            [10, 5], [11, 6], [12, 7], [20, 8], [12, 7], [11, 6], [10, 5],
        ]);

        $highs = (new SwingDetector(3))->highs($series);

        self::assertCount(1, $highs);
        self::assertEqualsWithDelta(20.0, $highs[0]->price, 1e-9);
        self::assertSame(3, $highs[0]->index);
        self::assertSame(StructureType::SwingHigh, $highs[0]->type);
    }

    public function test_it_finds_an_obvious_swing_low(): void
    {
        $series = $this->series([
            [20, 15], [19, 14], [18, 13], [17, 5], [18, 13], [19, 14], [20, 15],
        ]);

        $lows = (new SwingDetector(3))->lows($series);

        self::assertCount(1, $lows);
        self::assertEqualsWithDelta(5.0, $lows[0]->price, 1e-9);
        self::assertSame(3, $lows[0]->index);
    }

    /**
     * The property that keeps backtests honest (ADR-04).
     *
     * A swing cannot be known until `lookback` bars have failed to exceed it.
     * Returning one from inside the trailing window would be look-ahead bias —
     * the most common way a backtest reports results the live system could
     * never have achieved.
     */
    public function test_a_swing_inside_the_trailing_window_is_not_yet_confirmed(): void
    {
        // Peak at index 5, with only 2 bars after it — lookback is 3.
        $series = $this->series([
            [10, 5], [11, 6], [12, 7], [13, 8], [14, 9], [30, 10], [14, 9], [13, 8],
        ]);

        self::assertSame([], (new SwingDetector(3))->highs($series), 'Not confirmable yet.');

        // One more bar makes it confirmable.
        $extended = $this->series([
            [10, 5], [11, 6], [12, 7], [13, 8], [14, 9], [30, 10], [14, 9], [13, 8], [12, 7],
        ]);

        self::assertCount(1, (new SwingDetector(3))->highs($extended));
    }

    public function test_a_plateau_of_equal_highs_yields_one_swing_not_several(): void
    {
        $series = $this->series([
            [10, 5], [11, 6], [12, 7], [20, 8], [20, 8], [12, 7], [11, 6], [10, 5],
        ]);

        // The asymmetric comparison picks the first bar of the plateau.
        self::assertLessThanOrEqual(1, count((new SwingDetector(3))->highs($series)));
    }

    public function test_a_monotonic_series_has_no_swings(): void
    {
        $bars = [];

        for ($i = 0; $i < 20; $i++) {
            $bars[] = [10 + $i, 5 + $i];
        }

        self::assertSame([], (new SwingDetector(3))->detect($this->series($bars)));
    }

    public function test_a_series_shorter_than_the_window_has_no_swings(): void
    {
        self::assertSame([], (new SwingDetector(3))->detect($this->series([[10, 5], [11, 6]])));
    }

    public function test_a_more_dominant_swing_scores_higher_strength(): void
    {
        $narrow = $this->series([
            [10, 5], [11, 6], [12, 7], [20, 8], [12, 7], [11, 6], [10, 5],
        ]);

        $wide = $this->series([
            [10, 5], [11, 6], [12, 7], [13, 8], [14, 9], [15, 10], [30, 11],
            [15, 10], [14, 9], [13, 8], [12, 7], [11, 6], [10, 5],
        ]);

        $narrowStrength = (new SwingDetector(3))->highs($narrow)[0]->strength;
        $wideStrength = (new SwingDetector(3))->highs($wide)[0]->strength;

        self::assertGreaterThan($narrowStrength, $wideStrength);
    }

    // ── Trend ────────────────────────────────────────────────────────────────

    /** Higher highs and higher lows. */
    public function test_it_reads_an_uptrend(): void
    {
        // Each leg is shaped so the intended swing is the only one on that
        // bar. An outside bar — higher high *and* lower low than both
        // neighbours — is legitimately both a swing high and a swing low, and
        // one appearing mid-leg would displace the swings under test.
        $series = $this->series([
            [10, 5], [11, 6], [12, 7], [20, 8], [12, 7], [11, 6],   // swing high 20
            [14, 4], [13, 3], [12, 2], [10, 1], [12, 2], [13, 3],   // swing low 1
            [14, 5], [15, 6], [16, 7], [30, 8], [16, 7], [15, 6],   // swing high 30 — higher
            [16, 15], [15, 14], [14, 13], [12, 11], [14, 13], [15, 14], // swing low 11 — higher
            [16, 15], [17, 16], [18, 17],
        ]);

        self::assertSame(
            TrendState::Uptrend,
            (new StructureAnalyser(new SwingDetector(3)))->trend($series)
        );
    }

    /** Lower highs and lower lows. */
    public function test_it_reads_a_downtrend(): void
    {
        $series = $this->series([
            [30, 25], [29, 24], [28, 23], [40, 22], [28, 23], [29, 24], // high 40
            [30, 20], [29, 19], [28, 18], [27, 10], [28, 19], [29, 20], // low 10
            [30, 21], [29, 20], [28, 19], [35, 18], [28, 19], [27, 18], // high 35 (lower)
            [26, 17], [25, 16], [24, 15], [23, 5], [24, 16], [25, 17],  // low 5 (lower)
            [26, 18], [27, 19], [28, 20],
        ]);

        self::assertSame(
            TrendState::Downtrend,
            (new StructureAnalyser(new SwingDetector(3)))->trend($series)
        );
    }

    /**
     * "We do not know" must be distinguishable from "the market is flat" —
     * most setups are invalid without a trend, so conflating them would let a
     * strategy fire on absent information.
     */
    public function test_insufficient_structure_reads_as_unknown_not_ranging(): void
    {
        $series = $this->series([
            [10, 5], [11, 6], [12, 7], [20, 8], [12, 7], [11, 6], [10, 5],
        ]);

        self::assertSame(
            TrendState::Unknown,
            (new StructureAnalyser(new SwingDetector(3)))->trend($series)
        );
    }

    public function test_mixed_structure_reads_as_ranging(): void
    {
        // Higher highs but lower lows — an expanding range, not a trend.
        $series = $this->series([
            [10, 5], [11, 6], [12, 7], [20, 8], [12, 7], [11, 6],
            [10, 4], [11, 3], [12, 2], [13, 1], [12, 3], [11, 4],
            [12, 5], [13, 6], [14, 7], [30, 8], [14, 7], [13, 6],
            [12, 2], [11, 1], [10, 0], [9, -5], [10, 1], [11, 2],
            [12, 3], [13, 4], [14, 5],
        ]);

        self::assertSame(
            TrendState::Ranging,
            (new StructureAnalyser(new SwingDetector(3)))->trend($series)
        );
    }

    /**
     * BOS is continuation, CHoCH is the first warning of reversal. A strategy
     * treating them alike would add to a position exactly when the trend that
     * justified it is ending.
     */
    public function test_breaking_a_high_after_a_downtrend_is_a_change_of_character(): void
    {
        $series = $this->series([
            // Downtrend: high 40 then lower high 35, low 10 then lower low 5.
            [30, 25], [29, 24], [28, 23], [40, 22], [28, 23], [29, 24],
            [30, 20], [29, 19], [28, 18], [27, 10], [28, 19], [29, 20],
            [30, 21], [29, 20], [28, 19], [35, 18], [28, 19], [27, 18],
            [26, 17], [25, 16], [24, 15], [23, 5], [24, 16], [25, 17],
            // Then price breaks back above the prior swing high.
            [30, 18], [35, 19], [40, 20], [50, 21], [40, 20], [35, 19], [30, 18],
        ]);

        $analyser = new StructureAnalyser(new SwingDetector(3));
        $last = $analyser->lastBreak($series);

        self::assertNotNull($last);
        self::assertSame(StructureType::Choch, $last->type);
        self::assertSame(TrendState::Uptrend, $last->impliedTrend);
    }

    public function test_a_series_with_too_little_structure_has_no_breaks(): void
    {
        $series = $this->series([[10, 5], [11, 6], [12, 7], [20, 8], [12, 7], [11, 6], [10, 5]]);

        self::assertSame([], (new StructureAnalyser(new SwingDetector(3)))->breaks($series));
    }

    // ── Levels ───────────────────────────────────────────────────────────────

    /**
     * A level touched repeatedly matters more than one touched once. Without
     * clustering, every swing becomes its own line and the chart says nothing.
     */
    public function test_nearby_swings_merge_into_one_level_with_a_touch_count(): void
    {
        $bars = [];

        // Three peaks at ~3300 with troughs between, then a lower close.
        for ($cycle = 0; $cycle < 3; $cycle++) {
            $bars[] = [3280, 3270];
            $bars[] = [3290, 3275];
            $bars[] = [3295, 3280];
            $bars[] = [3300.5 + ($cycle * 0.3), 3285];
            $bars[] = [3295, 3280];
            $bars[] = [3290, 3275];
            $bars[] = [3280, 3260];
        }

        for ($i = 0; $i < 5; $i++) {
            $bars[] = [3250, 3240];
        }

        $levels = (new LevelBuilder(new SwingDetector(3)))->supportAndResistance($this->series($bars));

        $clustered = array_values(array_filter(
            $levels,
            static fn ($l): bool => $l->contains(3300.5)
        ));

        self::assertNotEmpty($clustered, 'The repeated peaks must form a level.');
        self::assertGreaterThanOrEqual(2, $clustered[0]->touchCount);
    }

    /** The same swing high becomes support once price trades above it. */
    public function test_a_level_below_price_is_support_and_above_is_resistance(): void
    {
        $bars = [];

        for ($cycle = 0; $cycle < 2; $cycle++) {
            $bars[] = [3280, 3270];
            $bars[] = [3290, 3275];
            $bars[] = [3295, 3280];
            $bars[] = [3300, 3285];
            $bars[] = [3295, 3280];
            $bars[] = [3290, 3275];
            $bars[] = [3280, 3260];
        }

        // Price ends well above the peaks.
        for ($i = 0; $i < 6; $i++) {
            $bars[] = [3400 + $i, 3390 + $i];
        }

        $levels = (new LevelBuilder(new SwingDetector(3)))->supportAndResistance($this->series($bars));

        foreach ($levels as $level) {
            if ($level->contains(3300.0)) {
                self::assertSame(LevelType::Support, $level->type, 'Price is above it now.');

                return;
            }
        }

        self::fail('Expected a level near 3300.');
    }

    public function test_session_extremes_are_derived_from_the_series_not_the_clock(): void
    {
        $bars = [];

        for ($i = 0; $i < 40; $i++) {
            $bars[] = [3300 + $i, 3290 - $i];
        }

        $extremes = (new LevelBuilder(new SwingDetector(3)))->sessionExtremes($this->series($bars));
        $byType = [];

        foreach ($extremes as $level) {
            $byType[$level->type->value] = $level;
        }

        self::assertArrayHasKey('DAILY_HIGH', $byType);
        self::assertArrayHasKey('DAILY_LOW', $byType);
        self::assertEqualsWithDelta(3339.0, $byType['DAILY_HIGH']->from, 1e-9);
        self::assertEqualsWithDelta(3251.0, $byType['DAILY_LOW']->from, 1e-9);
    }

    /**
     * A zone is the base a sharp move left behind. Impulse is measured against
     * recent average range, not a fixed price distance, so the detector works
     * unchanged whether gold trades at $1,800 or $3,300.
     */
    public function test_a_sharp_move_leaves_a_demand_zone_at_its_base(): void
    {
        $bars = [];

        // Quiet accumulation: ~2-wide bars with ~1-wide bodies.
        for ($i = 0; $i < 10; $i++) {
            $bars[] = [3302, 3300, 3300.5, 3301.5];
        }

        // The base (body 3301->3302), then an impulsive bullish rally.
        $bars[] = [3303, 3300, 3301, 3302];
        $bars[] = [3340, 3302, 3302, 3338];

        for ($i = 0; $i < 6; $i++) {
            $bars[] = [3342 + $i, 3338 + $i, 3339 + $i, 3341 + $i];
        }

        $zones = (new LevelBuilder(new SwingDetector(3)))->supplyDemandZones($this->series($bars));

        self::assertNotEmpty($zones, 'An impulsive move must leave a zone.');
        self::assertSame(LevelType::DemandZone, $zones[0]->type, 'A rally leaves demand behind.');
        self::assertGreaterThan(1, $zones[0]->strength);
    }

    public function test_a_sharp_drop_leaves_a_supply_zone(): void
    {
        $bars = [];

        for ($i = 0; $i < 10; $i++) {
            $bars[] = [3402, 3400, 3400.5, 3401.5];
        }

        $bars[] = [3402, 3399, 3400, 3401];
        $bars[] = [3402, 3360, 3401, 3362];

        for ($i = 0; $i < 6; $i++) {
            $bars[] = [3362 - $i, 3358 - $i, 3361 - $i, 3359 - $i];
        }

        $zones = (new LevelBuilder(new SwingDetector(3)))->supplyDemandZones($this->series($bars));

        self::assertNotEmpty($zones);
        self::assertSame(LevelType::SupplyZone, $zones[0]->type);
    }

    /** A quiet series has no impulse, so no zones — not an empty-data bug. */
    public function test_a_series_without_impulsive_moves_yields_no_zones(): void
    {
        $bars = [];

        for ($i = 0; $i < 30; $i++) {
            $bars[] = [3302 + ($i * 0.1), 3300 + ($i * 0.1), 3300.5 + ($i * 0.1), 3301.5 + ($i * 0.1)];
        }

        self::assertSame([], (new LevelBuilder(new SwingDetector(3)))->supplyDemandZones($this->series($bars)));
    }

    public function test_a_doji_base_widens_to_the_full_bar_range(): void
    {
        $bars = [];

        for ($i = 0; $i < 10; $i++) {
            $bars[] = [3302, 3300, 3300.5, 3301.5];
        }

        // The base bar is a doji: open equals close, so its body has zero
        // width. A zero-width zone would match no price at all.
        $bars[] = [3304, 3300, 3302, 3302];
        $bars[] = [3345, 3303, 3303, 3343];

        for ($i = 0; $i < 6; $i++) {
            $bars[] = [3347 + $i, 3343 + $i, 3344 + $i, 3346 + $i];
        }

        $zones = (new LevelBuilder(new SwingDetector(3)))->supplyDemandZones($this->series($bars));

        self::assertNotEmpty($zones);
        self::assertGreaterThan(0.0, abs($zones[0]->to - $zones[0]->from), 'A zone must have width.');
        self::assertTrue($zones[0]->contains($zones[0]->midpoint()));
    }

    public function test_an_empty_series_produces_no_levels(): void
    {
        $builder = new LevelBuilder(new SwingDetector(3));
        $empty = new CandleSeries([]);

        self::assertSame([], $builder->supportAndResistance($empty));
        self::assertSame([], $builder->supplyDemandZones($empty));
        self::assertSame([], $builder->sessionExtremes($empty));
    }

    public function test_a_price_level_reports_containment_and_distance(): void
    {
        $levels = (new LevelBuilder(new SwingDetector(3)))->sessionExtremes(
            $this->series(array_fill(0, 10, [3310.0, 3290.0]))
        );

        self::assertNotEmpty($levels);

        $high = $levels[0];
        self::assertTrue($high->contains(3310.0));
        self::assertEqualsWithDelta(0.0, $high->distanceFrom(3310.0), 1e-9);
        self::assertEqualsWithDelta(10.0, $high->distanceFrom(3320.0), 1e-9);
    }
}
