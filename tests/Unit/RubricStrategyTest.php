<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Market\Enums\StructureType;
use GoldBot\Domain\Market\Enums\TrendState;
use GoldBot\Domain\Strategy\RuleEvaluator;
use GoldBot\Domain\Strategy\Strategies\RubricStrategy;
use GoldBot\Domain\Strategy\StrategyConfig;
use GoldBot\Domain\Structure\StructureBreak;
use PHPUnit\Framework\TestCase;

/**
 * The config-driven rubric engine.
 *
 * The 714 rules are still open (docs/00 §3, Q1), so what is verified here is
 * the machinery that will score them: that weights compose correctly, that a
 * gate rejects regardless of total, that the threshold is honoured, and that
 * entry, stop and targets are derived as configured.
 *
 * Pure unit tests — no database, no network (ADR-03).
 */
final class RubricStrategyTest extends TestCase
{
    private RubricStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new RubricStrategy(new RuleEvaluator(), 'TEST');
    }

    /** @param array<string,mixed> $overrides */
    private function config(array $overrides = []): StrategyConfig
    {
        return StrategyConfig::fromArray([
            'signal_timeframe' => 'M15',
            'min_score'        => 60,
            'direction'        => ['source' => 'trend', 'timeframe' => 'H4'],
            'stop'             => ['type' => 'atr', 'multiplier' => 2.0],
            'targets'          => [['r' => 1.0], ['r' => 2.0]],
            'pillars'          => [
                'TREND' => [
                    'weight' => 100,
                    'rules'  => [
                        ['id' => 'h4', 'type' => 'trend', 'timeframe' => 'H4', 'expect' => 'with_direction', 'points' => 100],
                    ],
                ],
            ],
            ...$overrides,
        ], id: 1, strategyId: 1, version: 1);
    }

    private function bullishContext(): \GoldBot\Domain\Strategy\StrategyContext
    {
        return StrategyContextFactory::make()
            ->withSeries('M15', bars: 60, start: 3300.0, drift: 1.0)
            ->withTrend('H4', TrendState::Uptrend)
            ->withTrend('M15', TrendState::Uptrend)
            ->withIndicators('M15', ['atr_14' => 5.0, 'rsi_14' => 55.0, 'ema_50' => 3320.0, 'ema_200' => 3300.0])
            ->withIndicators('H4', ['ema_50' => 3320.0, 'ema_200' => 3300.0])
            ->withSession('LONDON')
            ->build();
    }

    // ── Direction ────────────────────────────────────────────────────────────

    public function test_direction_follows_the_configured_trend_timeframe(): void
    {
        $result = $this->strategy->evaluate($this->bullishContext(), $this->config());

        self::assertSame(Direction::Buy, $result->direction);
    }

    public function test_a_downtrend_yields_a_sell(): void
    {
        $context = StrategyContextFactory::make()
            ->withSeries('M15', bars: 60, start: 3400.0, drift: -1.0)
            ->withTrend('H4', TrendState::Downtrend)
            ->withIndicators('M15', ['atr_14' => 5.0])
            ->build();

        self::assertSame(Direction::Sell, $this->strategy->evaluate($context, $this->config())->direction);
    }

    /**
     * A ranging or unknown market gives no bias, and guessing one is how a
     * strategy ends up trading noise.
     */
    public function test_no_directional_bias_rejects_before_scoring(): void
    {
        foreach ([TrendState::Ranging, TrendState::Unknown] as $trend) {
            $context = StrategyContextFactory::make()
                ->withTrend('H4', $trend)
                ->withIndicators('M15', ['atr_14' => 5.0])
                ->build();

            $result = $this->strategy->evaluate($context, $this->config());

            self::assertFalse($result->qualified);
            self::assertSame('no_directional_bias', $result->rejectionReason);
        }
    }

    public function test_direction_can_be_derived_from_an_ema_relationship(): void
    {
        $config = $this->config([
            'direction' => ['source' => 'ema', 'timeframe' => 'H1', 'fast' => 'ema_50', 'slow' => 'ema_200'],
        ]);

        $context = StrategyContextFactory::make()
            ->withSeries('M15', bars: 60)
            ->withIndicators('M15', ['atr_14' => 5.0])
            ->withIndicators('H1', ['ema_50' => 3350.0, 'ema_200' => 3300.0])
            ->withTrend('H4', TrendState::Uptrend)
            ->build();

        self::assertSame(Direction::Buy, $this->strategy->evaluate($context, $config)->direction);
    }

    // ── Scoring ──────────────────────────────────────────────────────────────

    public function test_a_fully_satisfied_pillar_contributes_its_whole_weight(): void
    {
        $result = $this->strategy->evaluate($this->bullishContext(), $this->config());

        self::assertEqualsWithDelta(100.0, $result->score, 0.01);
        self::assertSame(['TREND' => 100.0], $result->pillarBreakdown());
    }

    /** Weights compose: a half-satisfied pillar contributes half its weight. */
    public function test_partially_satisfied_rules_scale_the_pillar(): void
    {
        $config = $this->config([
            'min_score' => 0,
            'pillars'   => [
                'TREND' => [
                    'weight' => 40,
                    'rules'  => [
                        ['id' => 'h4', 'type' => 'trend', 'timeframe' => 'H4', 'expect' => 'with_direction', 'points' => 50],
                        // Against the direction, so it fails.
                        ['id' => 'wrong', 'type' => 'price_vs_indicator', 'timeframe' => 'M15', 'indicator' => 'ema_200', 'expect' => 'below_if_buy', 'points' => 50],
                    ],
                ],
            ],
        ]);

        $result = $this->strategy->evaluate($this->bullishContext(), $config);

        // Half the points earned, of a 40-point pillar.
        self::assertEqualsWithDelta(20.0, $result->score, 0.01);
    }

    public function test_multiple_pillars_sum_to_the_total(): void
    {
        $config = $this->config([
            'min_score' => 0,
            'pillars'   => [
                'TREND'        => ['weight' => 60, 'rules' => [['type' => 'trend', 'timeframe' => 'H4', 'expect' => 'with_direction', 'points' => 1]]],
                'CONFIRMATION' => ['weight' => 40, 'rules' => [['type' => 'candle', 'expect' => 'with_direction', 'points' => 1]]],
            ],
        ]);

        $result = $this->strategy->evaluate($this->bullishContext(), $config);

        self::assertEqualsWithDelta(100.0, $result->score, 0.01);
        self::assertCount(2, $result->pillars);
    }

    /**
     * The point of a gate: a setup can score well everywhere else and still be
     * one you must not take.
     */
    public function test_a_failed_gate_rejects_regardless_of_total_score(): void
    {
        $config = $this->config([
            'min_score' => 10,
            'pillars'   => [
                'TREND' => ['weight' => 90, 'rules' => [['type' => 'trend', 'timeframe' => 'H4', 'expect' => 'with_direction', 'points' => 1]]],
                'RISK'  => [
                    'weight'  => 10,
                    'gate'    => true,
                    'min_raw' => 100,
                    // The context is in LONDON, so this gate fails.
                    'rules'   => [['type' => 'session', 'in' => ['TOKYO'], 'points' => 1]],
                ],
            ],
        ]);

        $result = $this->strategy->evaluate($this->bullishContext(), $config);

        self::assertFalse($result->qualified);
        self::assertSame('gate_failed:risk', $result->rejectionReason);
        self::assertGreaterThan(80.0, $result->score, 'The score is still reported, and still high.');
        self::assertTrue($result->hasFailedGate());
    }

    /** A gate with a floor passes when the pillar clears it. */
    public function test_a_gate_passes_when_its_floor_is_met(): void
    {
        // The threshold is lowered so this isolates the gate: a raw score of
        // 50 clears a floor of 50, and nothing else should reject it.
        $config = $this->config([
            'min_score' => 0,
            'pillars'   => [
                'TREND' => [
                    'weight'  => 100,
                    'gate'    => true,
                    'min_raw' => 50,
                    'rules'   => [
                        ['type' => 'trend', 'timeframe' => 'H4', 'expect' => 'with_direction', 'points' => 50],
                        ['type' => 'session', 'in' => ['TOKYO'], 'points' => 50],
                    ],
                ],
            ],
        ]);

        $result = $this->strategy->evaluate($this->bullishContext(), $config);

        self::assertTrue($result->qualified, 'Raw 50 meets a floor of 50.');
        self::assertFalse($result->hasFailedGate());
    }

    public function test_a_score_below_the_threshold_is_rejected_but_still_reported(): void
    {
        $config = $this->config([
            'min_score' => 90,
            'pillars'   => [
                'TREND' => [
                    'weight' => 100,
                    'rules'  => [
                        ['type' => 'trend', 'timeframe' => 'H4', 'expect' => 'with_direction', 'points' => 50],
                        ['type' => 'session', 'in' => ['TOKYO'], 'points' => 50],
                    ],
                ],
            ],
        ]);

        $result = $this->strategy->evaluate($this->bullishContext(), $config);

        self::assertFalse($result->qualified);
        self::assertStringStartsWith('below_threshold:', (string) $result->rejectionReason);
        self::assertEqualsWithDelta(50.0, $result->score, 0.01, 'A near-miss must still record its score.');
    }

    public function test_no_pillars_configured_is_reported_rather_than_scoring_zero_silently(): void
    {
        $result = $this->strategy->evaluate($this->bullishContext(), $this->config(['pillars' => []]));

        self::assertSame('no_pillars_configured', $result->rejectionReason);
    }

    // ── Entry, stop and targets ──────────────────────────────────────────────

    public function test_an_atr_stop_and_r_multiple_targets_are_derived(): void
    {
        $result = $this->strategy->evaluate($this->bullishContext(), $this->config());

        self::assertTrue($result->qualified);

        $entry = (float) $result->entryPrice;

        // ATR 5.0 x multiplier 2.0 = 10 below entry for a long.
        self::assertEqualsWithDelta($entry - 10.0, (float) $result->stopLoss, 0.01);
        self::assertEqualsWithDelta(10.0, (float) $result->riskDistance(), 0.01);

        self::assertCount(2, $result->targets);
        self::assertEqualsWithDelta($entry + 10.0, $result->targets[0]->price, 0.01);
        self::assertEqualsWithDelta($entry + 20.0, $result->targets[1]->price, 0.01);
        self::assertEqualsWithDelta(2.0, (float) $result->riskReward(), 0.01);
    }

    public function test_a_short_places_its_stop_above_and_targets_below(): void
    {
        $context = StrategyContextFactory::make()
            ->withSeries('M15', bars: 60, start: 3400.0, drift: -1.0)
            ->withTrend('H4', TrendState::Downtrend)
            ->withIndicators('M15', ['atr_14' => 4.0])
            ->build();

        $result = $this->strategy->evaluate($context, $this->config());

        self::assertTrue($result->qualified);

        $entry = (float) $result->entryPrice;

        self::assertGreaterThan($entry, (float) $result->stopLoss, 'A short stops out above entry.');
        self::assertLessThan($entry, $result->targets[0]->price);
    }

    /**
     * A stop behind the swing rather than exactly on it — a stop sitting on
     * the extreme is the first place the market goes looking for liquidity.
     */
    public function test_a_swing_stop_sits_beyond_the_extreme_by_a_buffer(): void
    {
        $config = $this->config([
            'stop' => ['type' => 'swing', 'lookback' => 20, 'buffer_atr' => 0.5],
        ]);

        $context = $this->bullishContext();
        $result = $this->strategy->evaluate($context, $config);

        self::assertTrue($result->qualified);

        $swingLow = (float) $context->candles()->tail(20)->lowestLow();

        // ATR 5.0 x 0.5 buffer = 2.5 below the swing low.
        self::assertEqualsWithDelta($swingLow - 2.5, (float) $result->stopLoss, 0.01);
    }

    public function test_a_missing_atr_prevents_deriving_levels_rather_than_guessing(): void
    {
        $context = StrategyContextFactory::make()
            ->withSeries('M15', bars: 60)
            ->withTrend('H4', TrendState::Uptrend)
            ->withIndicators('M15', ['atr_14' => null])
            ->build();

        $result = $this->strategy->evaluate($context, $this->config());

        self::assertFalse($result->qualified);
        self::assertSame('cannot_derive_levels', $result->rejectionReason);
    }

    public function test_an_insufficient_risk_reward_is_rejected(): void
    {
        $config = $this->config([
            'min_risk_reward' => 3.0,
            'targets'         => [['r' => 1.0]],
        ]);

        $result = $this->strategy->evaluate($this->bullishContext(), $config);

        self::assertFalse($result->qualified);
        self::assertSame('risk_reward_too_low', $result->rejectionReason);
    }

    // ── Timeframe discovery ──────────────────────────────────────────────────

    /**
     * A timeframe named by a rule but not loaded would make that rule evaluate
     * against nothing — scoring zero for a condition never actually tested.
     */
    public function test_required_timeframes_are_collected_from_every_rule(): void
    {
        $config = $this->config([
            'signal_timeframe' => 'M15',
            'direction'        => ['source' => 'trend', 'timeframe' => 'D1'],
            'pillars'          => [
                'TREND' => [
                    'weight' => 100,
                    'rules'  => [
                        ['type' => 'trend', 'timeframe' => 'H4', 'expect' => 'with_direction', 'points' => 1],
                        ['type' => 'price_vs_indicator', 'timeframe' => 'H1', 'indicator' => 'ema_200', 'points' => 1],
                    ],
                ],
            ],
        ]);

        $required = $this->strategy->requiredTimeframes($config);

        self::assertContains('M15', $required);
        self::assertContains('H4', $required);
        self::assertContains('H1', $required);
        self::assertContains('D1', $required);
        self::assertSame(count($required), count(array_unique($required)), 'No duplicates.');
    }

    // ── Rule vocabulary ──────────────────────────────────────────────────────

    public function test_a_structure_rule_reads_the_last_break(): void
    {
        $context = StrategyContextFactory::make()
            ->withSeries('M15', bars: 60)
            ->withTrend('H4', TrendState::Uptrend)
            ->withIndicators('M15', ['atr_14' => 5.0])
            ->withStructureBreaks([
                new StructureBreak(
                    StructureType::Bos,
                    3350.0,
                    new \DateTimeImmutable('2026-08-06 10:00:00', new \DateTimeZone('UTC')),
                    50,
                    TrendState::Uptrend
                ),
            ])
            ->build();

        $config = $this->config([
            'pillars' => [
                'STRUCTURE' => ['weight' => 100, 'rules' => [['type' => 'structure', 'expect' => 'bos_with_direction', 'points' => 1]]],
            ],
        ]);

        self::assertEqualsWithDelta(100.0, $this->strategy->evaluate($context, $config)->score, 0.01);
    }

    /**
     * An unavailable input must be distinguishable from a tested-and-failed
     * one, or a warm-up gap looks like a genuine rejection.
     */
    public function test_an_unavailable_input_is_flagged_in_the_detail(): void
    {
        $context = StrategyContextFactory::make()
            ->withSeries('M15', bars: 60)
            ->withTrend('H4', TrendState::Uptrend)
            ->withIndicators('M15', ['atr_14' => 5.0])
            ->build();

        $config = $this->config([
            'min_score' => 0,
            'pillars'   => [
                'TREND' => ['weight' => 100, 'rules' => [
                    ['id' => 'warming', 'type' => 'price_vs_indicator', 'timeframe' => 'M15', 'indicator' => 'ema_200', 'points' => 1],
                ]],
            ],
        ]);

        $detail = $this->strategy->evaluate($context, $config)->pillars[0]->detail;

        self::assertTrue($detail['rules'][0]['detail']['unavailable'] ?? false);
    }

    /** A typo in config must not take the engine down mid-run. */
    public function test_an_unknown_rule_type_scores_zero_and_records_the_error(): void
    {
        $config = $this->config([
            'min_score' => 0,
            'pillars'   => [
                'TREND' => ['weight' => 100, 'rules' => [['id' => 'oops', 'type' => 'nonsense', 'points' => 1]]],
            ],
        ]);

        $result = $this->strategy->evaluate($this->bullishContext(), $config);

        self::assertEqualsWithDelta(0.0, $result->score, 0.01);
        self::assertSame('unknown rule type', $result->pillars[0]->detail['rules'][0]['detail']['error'] ?? null);
    }

    /**
     * Determinism is what makes a signal re-derivable and a backtest
     * meaningful (ADR-03).
     */
    public function test_the_same_context_evaluated_twice_gives_identical_results(): void
    {
        $context = $this->bullishContext();
        $config = $this->config();

        $first = $this->strategy->evaluate($context, $config);
        $second = $this->strategy->evaluate($context, $config);

        self::assertSame($first->score, $second->score);
        self::assertSame($first->direction, $second->direction);
        self::assertSame($first->entryPrice, $second->entryPrice);
        self::assertSame($first->stopLoss, $second->stopLoss);
        self::assertEquals($first->pillarBreakdown(), $second->pillarBreakdown());
    }
}
