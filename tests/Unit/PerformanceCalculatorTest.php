<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Performance\MetricSet;
use GoldBot\Domain\Performance\PerformanceCalculator;
use GoldBot\Domain\Performance\TradeOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Every metric the platform reports, against values computed by hand.
 *
 * The calculator is pure, so there is nothing to mock and no reason for any
 * figure here to be approximate. Where a case has an arithmetic answer, the
 * answer is written in the test as a literal with its working shown — a test
 * that asserts what the code currently returns proves only that the code has
 * not changed.
 *
 * The edge cases the roadmap names explicitly are all here: zero losses, zero
 * signals, and breakeven excluded from both win and loss counts.
 */
final class PerformanceCalculatorTest extends TestCase
{
    private PerformanceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PerformanceCalculator();
    }

    /** @param list<float> $rs */
    private function outcomes(array $rs, ?float $riskReward = null): array
    {
        $at = new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC'));

        return array_map(
            static fn (float $r, int $i): TradeOutcome => new TradeOutcome(
                $at->modify("+{$i} hours"),
                $r,
                $riskReward
            ),
            $rs,
            array_keys($rs)
        );
    }

    // ── The named edge cases ─────────────────────────────────────────────────

    /**
     * Zero signals. Every rate is null, not zero.
     *
     * "0% win rate" and "no data yet" are different claims about a strategy,
     * and rendering the second as the first is a lie about something nobody
     * has measured.
     */
    public function test_an_empty_period_reports_nothing_rather_than_zeros(): void
    {
        $metrics = $this->calculator->calculate([]);

        self::assertTrue($metrics->isEmpty());
        self::assertSame(0, $metrics->total);
        self::assertNull($metrics->winRate);
        self::assertNull($metrics->lossRate);
        self::assertNull($metrics->profitFactor);
        self::assertNull($metrics->expectancy);
        self::assertNull($metrics->bestR);
        self::assertNull($metrics->worstR);
        self::assertSame(0.0, $metrics->totalR);
        self::assertSame(0.0, $metrics->maxDrawdownR);
    }

    /**
     * Zero losses. Profit factor is UNDEFINED, not infinite and not a large
     * placeholder — either of which would sort an untested strategy to the top
     * of a comparison table as though it were the best one there.
     */
    public function test_profit_factor_is_undefined_without_losses(): void
    {
        $metrics = $this->calculator->calculate($this->outcomes([2.0, 1.0, 3.0]));

        self::assertNull($metrics->profitFactor);

        // Everything else is still well defined.
        self::assertSame(3, $metrics->total);
        self::assertSame(100.0, $metrics->winRate);
        self::assertSame(6.0, $metrics->grossProfitR);
        self::assertSame(0.0, $metrics->grossLossR);
        self::assertSame(2.0, $metrics->expectancy);   // 6 ÷ 3
        self::assertSame(0.0, $metrics->maxDrawdownR); // never fell
    }

    /**
     * Breakeven is neither a win nor a loss.
     *
     * Folding scratches into losses understates the win rate; folding them
     * into wins overstates it. They are their own outcome and are counted as
     * one.
     */
    public function test_breakeven_is_excluded_from_both_win_and_loss_counts(): void
    {
        // 2W, 1L, 2BE out of five.
        $metrics = $this->calculator->calculate($this->outcomes([1.0, 0.0, -1.0, 0.0, 2.0]));

        self::assertSame(5, $metrics->total);
        self::assertSame(2, $metrics->wins);
        self::assertSame(1, $metrics->losses);
        self::assertSame(2, $metrics->breakeven);

        // Rates are over ALL traded signals, breakevens included in the
        // denominator — they traded, they just finished flat.
        self::assertSame(40.0, $metrics->winRate);  // 2 ÷ 5
        self::assertSame(20.0, $metrics->lossRate); // 1 ÷ 5

        // 2 + 0 - 1 + 0 + 1 … wins are 1.0 and 2.0, so gross profit is 3.
        self::assertSame(3.0, $metrics->grossProfitR);
        self::assertSame(1.0, $metrics->grossLossR);
        self::assertSame(2.0, $metrics->totalR);
        self::assertSame(3.0, $metrics->profitFactor); // 3 ÷ 1
    }

    // ── The headline arithmetic ──────────────────────────────────────────────

    /**
     * A worked example, every figure computed by hand.
     *
     * Sequence: +2, -1, +3, -1, -1, +1
     *   wins           = 3 (2, 3, 1)          losses = 3 (-1, -1, -1)
     *   gross profit   = 6                    gross loss = 3
     *   net            = 3
     *   win rate       = 3 ÷ 6         = 50%
     *   profit factor  = 6 ÷ 3         = 2.00
     *   expectancy     = 3 ÷ 6         = 0.5R
     *   average win    = 6 ÷ 3         = 2.00
     *   average loss   = -3 ÷ 3        = -1.00
     */
    public function test_a_worked_example(): void
    {
        $metrics = $this->calculator->calculate($this->outcomes([2.0, -1.0, 3.0, -1.0, -1.0, 1.0]));

        self::assertSame(6, $metrics->total);
        self::assertSame(3, $metrics->wins);
        self::assertSame(3, $metrics->losses);
        self::assertSame(0, $metrics->breakeven);
        self::assertSame(6.0, $metrics->grossProfitR);
        self::assertSame(3.0, $metrics->grossLossR);
        self::assertSame(3.0, $metrics->totalR);
        self::assertSame(50.0, $metrics->winRate);
        self::assertSame(50.0, $metrics->lossRate);
        self::assertSame(2.0, $metrics->profitFactor);
        self::assertSame(0.5, $metrics->expectancy);
        self::assertSame(2.0, $metrics->averageWinR);
        self::assertSame(-1.0, $metrics->averageLossR);
        self::assertSame(3.0, $metrics->bestR);
        self::assertSame(-1.0, $metrics->worstR);
    }

    /**
     * Expectancy is the number that answers "is running this worth it?".
     * A 40% win rate at 3R beats a 70% win rate at 0.4R, and only expectancy
     * shows it — so the two are checked against each other here.
     */
    public function test_expectancy_ranks_strategies_that_win_rate_ranks_backwards(): void
    {
        // 4 wins at 3R, 6 losses at 1R → 40% win rate, +6R over ten.
        $selective = $this->calculator->calculate(
            $this->outcomes([3.0, 3.0, 3.0, 3.0, -1.0, -1.0, -1.0, -1.0, -1.0, -1.0])
        );

        // 7 wins at 0.4R, 3 losses at 1R → 70% win rate, -0.2R over ten.
        $frequent = $this->calculator->calculate(
            $this->outcomes([0.4, 0.4, 0.4, 0.4, 0.4, 0.4, 0.4, -1.0, -1.0, -1.0])
        );

        self::assertSame(40.0, $selective->winRate);
        self::assertSame(70.0, $frequent->winRate);

        // Win rate prefers the second; expectancy correctly prefers the first.
        self::assertGreaterThan(0, $selective->expectancy);
        self::assertLessThan(0, $frequent->expectancy);
        self::assertGreaterThan($frequent->expectancy, $selective->expectancy);
    }

    public function test_average_risk_reward_ignores_signals_that_recorded_none(): void
    {
        $at = new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC'));

        $metrics = $this->calculator->calculate([
            new TradeOutcome($at, 1.0, 2.0),
            new TradeOutcome($at->modify('+1 hour'), -1.0, 4.0),
            // No planned R:R recorded. Counting it as zero would drag the
            // average down with a number nobody chose.
            new TradeOutcome($at->modify('+2 hours'), 1.0, null),
        ]);

        self::assertSame(3.0, $metrics->averageRiskReward); // (2 + 4) ÷ 2
    }

    // ── Path-dependent metrics ───────────────────────────────────────────────

    /**
     * Drawdown is peak-to-trough of the equity curve, which is what the system
     * actually put an operator through — as opposed to where it finished.
     *
     * +5, -2, -3, +1 → equity 5, 3, 0, 1; peak 5; deepest trough 0 ⇒ DD 5.
     * Net is +1, so a metric that looked only at the final figure would report
     * a comfortable winner.
     */
    public function test_drawdown_is_measured_from_the_running_peak(): void
    {
        $metrics = $this->calculator->calculate($this->outcomes([5.0, -2.0, -3.0, 1.0]));

        self::assertSame(1.0, $metrics->totalR);
        self::assertSame(5.0, $metrics->maxDrawdownR);
    }

    /**
     * A curve that never rises above its starting point still reports the full
     * depth of its decline — the peak starts at zero, not at the first point.
     */
    public function test_a_curve_that_only_falls_still_reports_its_drawdown(): void
    {
        $metrics = $this->calculator->calculate($this->outcomes([-1.0, -1.0, -1.0]));

        self::assertSame(-3.0, $metrics->totalR);
        self::assertSame(3.0, $metrics->maxDrawdownR);
    }

    public function test_streaks_are_the_longest_runs_not_the_totals(): void
    {
        // W W W L W W L L L W  →  longest win 3, longest loss 3, ends on a win.
        $metrics = $this->calculator->calculate(
            $this->outcomes([1.0, 1.0, 1.0, -1.0, 1.0, 1.0, -1.0, -1.0, -1.0, 1.0])
        );

        self::assertSame(3, $metrics->maxConsecutiveWins);
        self::assertSame(3, $metrics->maxConsecutiveLosses);
        self::assertSame(1, $metrics->currentStreak);
    }

    /**
     * A breakeven neither extends nor breaks a run. Treating it as a break
     * would understate genuine losing streaks, which is the streak anyone
     * actually cares about.
     */
    public function test_a_breakeven_does_not_interrupt_a_streak(): void
    {
        $metrics = $this->calculator->calculate($this->outcomes([-1.0, -1.0, 0.0, -1.0]));

        self::assertSame(3, $metrics->maxConsecutiveLosses);
        self::assertSame(-3, $metrics->currentStreak);
    }

    /**
     * Path metrics depend on the ORDER outcomes closed in, so the calculator
     * sorts rather than trusting its caller. The same set in a different order
     * must give the same answer.
     */
    public function test_outcomes_are_ordered_by_close_time_regardless_of_input_order(): void
    {
        $at = new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC'));

        $shuffled = [
            new TradeOutcome($at->modify('+3 hours'), 1.0),
            new TradeOutcome($at, 5.0),
            new TradeOutcome($at->modify('+2 hours'), -3.0),
            new TradeOutcome($at->modify('+1 hour'), -2.0),
        ];

        $metrics = $this->calculator->calculate($shuffled);

        // Same series as the drawdown test above, just handed over jumbled.
        self::assertSame(1.0, $metrics->totalR);
        self::assertSame(5.0, $metrics->maxDrawdownR);
    }

    public function test_the_equity_curve_accumulates_in_close_order(): void
    {
        $points = $this->calculator->equityCurve($this->outcomes([1.5, -1.0, 2.0]));

        self::assertSame([1.5, 0.5, 2.5], array_column($points, 'equity'));
        self::assertSame([1.5, -1.0, 2.0], array_column($points, 'r'));
    }

    // ── Significance ─────────────────────────────────────────────────────────

    /**
     * A 100% win rate over three signals is not evidence. The flag does not
     * hide the rate — it tells the reader how much weight it deserves.
     */
    public function test_a_small_sample_is_flagged_as_not_yet_significant(): void
    {
        self::assertFalse($this->calculator->calculate($this->outcomes([1.0, 1.0, 1.0]))->isSignificant());

        $thirty = $this->calculator->calculate($this->outcomes(array_fill(0, 30, 1.0)));
        self::assertTrue($thirty->isSignificant());
    }

    // ── Round-tripping through storage ───────────────────────────────────────

    /**
     * The stored columns must reproduce the computed metrics. A snapshot that
     * loses a null on the way to the database would turn "undefined" into
     * "zero" on the way back — the exact confusion the nullability exists to
     * prevent.
     */
    public function test_metrics_survive_a_round_trip_through_the_column_shape(): void
    {
        $original = $this->calculator->calculate($this->outcomes([2.0, 1.0, 3.0]));
        $columns = $original->toColumns();

        // Undefined stays undefined through the round trip.
        self::assertNull($columns['profit_factor']);

        $restored = MetricSet::fromColumns([
            ...$columns,
            'max_drawdown_r' => $columns['max_drawdown_r'],
        ]);

        self::assertSame($original->total, $restored->total);
        self::assertSame($original->wins, $restored->wins);
        self::assertSame($original->winRate, $restored->winRate);
        self::assertNull($restored->profitFactor);
        self::assertSame($original->totalR, $restored->totalR);
        self::assertSame($original->expectancy, $restored->expectancy);
    }
}
