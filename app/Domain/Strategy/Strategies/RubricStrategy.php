<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy\Strategies;

use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Market\Enums\TrendState;
use GoldBot\Domain\Strategy\PillarScore;
use GoldBot\Domain\Strategy\RuleEvaluator;
use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\SignalTarget;
use GoldBot\Domain\Strategy\StrategyConfig;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Domain\Strategy\StrategyInterface;

/**
 * A strategy expressed entirely as a weighted rubric in configuration.
 *
 * The brief requires that the 714 method not be hardcoded and that everything
 * be configurable. This is that requirement taken literally: pillars, the rules
 * inside them, their weights, which act as hard gates, the publish threshold,
 * and how entry, stop and targets are derived all come from the config version
 * (ADR-06). Retuning is a new row, never a deploy.
 *
 * The consequence worth stating: this class contains no opinion about *which*
 * conditions constitute a good setup. That is the strategy author's to supply.
 * What it guarantees is that whatever they supply is scored consistently,
 * explained per-pillar, and permanently attributable to a config version.
 *
 * Pure: no I/O, no clock (ADR-03).
 */
class RubricStrategy implements StrategyInterface
{
    public function __construct(
        private readonly RuleEvaluator $rules,
        private readonly string $code = 'RUBRIC'
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function requiredTimeframes(StrategyConfig $config): array
    {
        $timeframes = [strtoupper($config->string('signal_timeframe', 'M15'))];

        // Any timeframe named by any rule must be loaded, or that rule
        // silently evaluates against nothing.
        foreach ($config->array('pillars') as $pillar) {
            if (!is_array($pillar)) {
                continue;
            }

            foreach ($pillar['rules'] ?? [] as $rule) {
                if (is_array($rule) && isset($rule['timeframe']) && is_string($rule['timeframe'])) {
                    $timeframes[] = strtoupper($rule['timeframe']);
                }
            }
        }

        $directionTimeframe = $config->get('direction.timeframe');

        if (is_string($directionTimeframe) && $directionTimeframe !== '') {
            $timeframes[] = strtoupper($directionTimeframe);
        }

        return array_values(array_unique($timeframes));
    }

    public function evaluate(StrategyContext $context, StrategyConfig $config): SignalResult
    {
        $direction = $this->resolveDirection($context, $config);

        if ($direction === null) {
            return SignalResult::rejected('no_directional_bias');
        }

        if ($context->price() === null) {
            return SignalResult::rejected('no_price');
        }

        $pillars = $this->scorePillars($context, $config, $direction);

        if ($pillars === []) {
            return SignalResult::rejected('no_pillars_configured', 0.0, [], $direction);
        }

        $score = 0.0;

        foreach ($pillars as $pillar) {
            $score += $pillar->weighted();
        }

        $score = round($score, 2);

        // A failed gate rejects regardless of total. That is the point of a
        // gate: a setup can score 90 on everything else and still be one you
        // must not take.
        foreach ($pillars as $pillar) {
            if (!$pillar->passed) {
                return SignalResult::rejected('gate_failed:' . strtolower($pillar->pillar), $score, $pillars, $direction);
            }
        }

        $threshold = $config->float('min_score', 70.0);

        if ($score < $threshold) {
            return SignalResult::rejected(
                sprintf('below_threshold:%.1f<%.1f', $score, $threshold),
                $score,
                $pillars,
                $direction
            );
        }

        $levels = $this->deriveLevels($context, $config, $direction);

        if ($levels === null) {
            return SignalResult::rejected('cannot_derive_levels', $score, $pillars, $direction);
        }

        [$entry, $stop, $targets] = $levels;

        $minRiskReward = $config->float('min_risk_reward', 0.0);

        if ($minRiskReward > 0.0 && $targets !== []) {
            $risk = abs($entry - $stop);
            $reward = abs($targets[count($targets) - 1]->price - $entry);

            if ($risk <= 0.0 || ($reward / $risk) < $minRiskReward) {
                return SignalResult::rejected('risk_reward_too_low', $score, $pillars, $direction);
            }
        }

        return SignalResult::signal($direction, $score, $pillars, $entry, $stop, $targets);
    }

    /**
     * Which way the setup would trade.
     *
     * Resolved before scoring because nearly every rule is direction-relative
     * — "price above the EMA" means the opposite thing for a short.
     */
    protected function resolveDirection(StrategyContext $context, StrategyConfig $config): ?Direction
    {
        $source = $config->string('direction.source', 'trend');

        return match ($source) {
            'trend' => match ($context->trend(strtoupper($config->string('direction.timeframe', 'H4')))) {
                TrendState::Uptrend   => Direction::Buy,
                TrendState::Downtrend => Direction::Sell,
                // Ranging and Unknown both yield no bias. Guessing a direction
                // without one is how a strategy trades noise.
                default               => null,
            },
            'ema' => $this->directionFromEma($context, $config),
            default => null,
        };
    }

    private function directionFromEma(StrategyContext $context, StrategyConfig $config): ?Direction
    {
        $timeframe = strtoupper($config->string('direction.timeframe', 'H1'));
        $fast = $context->indicator($config->string('direction.fast', 'ema_50'), $timeframe);
        $slow = $context->indicator($config->string('direction.slow', 'ema_200'), $timeframe);

        if ($fast === null || $slow === null || $fast === $slow) {
            return null;
        }

        return $fast > $slow ? Direction::Buy : Direction::Sell;
    }

    /**
     * Score each configured pillar.
     *
     * A pillar's raw score is the points its passed rules earned as a
     * percentage of the points available — so adding a rule does not dilute
     * the pillar unless the author intends it to.
     *
     * @return list<PillarScore>
     */
    protected function scorePillars(StrategyContext $context, StrategyConfig $config, Direction $direction): array
    {
        $scores = [];

        foreach ($config->array('pillars') as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $rules = is_array($definition['rules'] ?? null) ? $definition['rules'] : [];
            $weight = isset($definition['weight']) ? (float) $definition['weight'] : 0.0;

            $earned = 0.0;
            $available = 0.0;
            $detail = [];

            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $points = isset($rule['points']) ? (float) $rule['points'] : 1.0;
                $available += $points;

                $outcome = $this->rules->evaluate($rule, $context, $direction);
                $earned += $points * $outcome['score'];

                $detail[] = [
                    'id'     => $rule['id'] ?? ($rule['type'] ?? 'rule'),
                    'type'   => $rule['type'] ?? null,
                    'score'  => round($outcome['score'], 4),
                    'points' => $points,
                    'detail' => $outcome['detail'],
                ];
            }

            $raw = $available > 0.0 ? ($earned / $available) * 100 : 0.0;

            // A gate fails when the pillar falls below its own floor. Absent a
            // configured floor, any gate pillar must be fully satisfied.
            $isGate = (bool) ($definition['gate'] ?? false);
            $minRaw = isset($definition['min_raw']) ? (float) $definition['min_raw'] : 100.0;
            $passed = !$isGate || $raw >= $minRaw;

            $scores[] = new PillarScore(
                (string) $name,
                round($raw, 2),
                $weight,
                $passed,
                ['rules' => $detail, 'gate' => $isGate, 'min_raw' => $isGate ? $minRaw : null]
            );
        }

        return $scores;
    }

    /**
     * Derive entry, stop and targets.
     *
     * @return array{0:float,1:float,2:list<SignalTarget>}|null
     */
    protected function deriveLevels(StrategyContext $context, StrategyConfig $config, Direction $direction): ?array
    {
        $price = $context->price();

        if ($price === null) {
            return null;
        }

        $entry = $price;

        $stop = $this->deriveStop($context, $config, $direction, $entry);

        if ($stop === null) {
            return null;
        }

        $risk = abs($entry - $stop);

        // A zero-width stop would make every R multiple infinite and the risk
        // calculation meaningless.
        if ($risk <= 0.0) {
            return null;
        }

        $targets = [];
        $level = 1;

        foreach ($config->array('targets') as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $r = isset($definition['r']) ? (float) $definition['r'] : 1.0;
            $closePercent = isset($definition['close_percent']) ? (float) $definition['close_percent'] : 100.0;

            $targets[] = new SignalTarget(
                $level++,
                $entry + ($direction->sign() * $risk * $r),
                $closePercent,
                $r
            );
        }

        return [$entry, $stop, $targets];
    }

    private function deriveStop(StrategyContext $context, StrategyConfig $config, Direction $direction, float $entry): ?float
    {
        $type = $config->string('stop.type', 'atr');

        if ($type === 'atr') {
            $atr = $context->atr(strtoupper($config->string('stop.timeframe', $context->timeframe->code)));

            if ($atr === null || $atr <= 0.0) {
                return null;
            }

            return $entry - ($direction->sign() * $atr * $config->float('stop.multiplier', 1.5));
        }

        if ($type === 'swing') {
            // Behind the recent extreme, plus a buffer — a stop exactly on the
            // swing is the first place the market goes looking for liquidity.
            $window = $context->candles()->tail($config->int('stop.lookback', 20));
            $extreme = $direction->isBuy() ? $window->lowestLow() : $window->highestHigh();

            if ($extreme === null) {
                return null;
            }

            $atr = $context->atr();
            $buffer = ($atr ?? 0.0) * $config->float('stop.buffer_atr', 0.25);

            return $extreme - ($direction->sign() * $buffer);
        }

        return null;
    }
}
