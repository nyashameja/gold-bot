<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy;

use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Market\Enums\StructureType;
use GoldBot\Domain\Market\Enums\TrendState;
use GoldBot\Domain\Structure\PriceLevel;

/**
 * Evaluates a declarative rule against a context.
 *
 * This exists because the brief requires the 714 method to be configurable
 * rather than hardcoded. Rather than PHP classes per pillar whose logic can
 * only change by deploying, a pillar is a weighted list of rules expressed in
 * the strategy's JSON config — so retuning, adding a condition, or changing a
 * pillar's emphasis is a new config version (ADR-06), not a release.
 *
 * The vocabulary is deliberately small. Every rule type below answers a
 * question a discretionary trader actually asks; a general expression language
 * would be more powerful and far harder to reason about, audit, or explain on
 * the 714 page.
 *
 * Rules return a score in 0..1 rather than a boolean, so a condition can be
 * partially satisfied — "price is near the EMA" degrades with distance instead
 * of flipping at an arbitrary threshold.
 *
 * Pure: no I/O (ADR-03).
 */
final class RuleEvaluator
{
    /**
     * @param array<string,mixed> $rule
     * @return array{score:float,detail:array<string,mixed>}
     */
    public function evaluate(array $rule, StrategyContext $context, Direction $direction): array
    {
        $type = is_string($rule['type'] ?? null) ? $rule['type'] : '';

        return match ($type) {
            'trend'                  => $this->trend($rule, $context, $direction),
            'price_vs_indicator'     => $this->priceVsIndicator($rule, $context, $direction),
            'indicator_vs_indicator' => $this->indicatorVsIndicator($rule, $context, $direction),
            'indicator_range'        => $this->indicatorRange($rule, $context),
            'structure'              => $this->structure($rule, $context, $direction),
            'distance_to_level'      => $this->distanceToLevel($rule, $context),
            'pullback_depth'         => $this->pullbackDepth($rule, $context, $direction),
            'candle'                 => $this->candle($rule, $context, $direction),
            'session'                => $this->session($rule, $context),
            'volatility'             => $this->volatility($rule, $context),
            default                  => $this->unknown($type),
        };
    }

    /** Trend agreement on a timeframe. */
    private function trend(array $rule, StrategyContext $context, Direction $direction): array
    {
        $timeframe = $this->timeframe($rule);
        $trend = $context->trend($timeframe);
        $expect = (string) ($rule['expect'] ?? 'with_direction');

        $score = match ($expect) {
            'with_direction' => $trend->agreesWith($direction) ? 1.0 : 0.0,
            'trending'       => $trend->isTrending() ? 1.0 : 0.0,
            'not_ranging'    => $trend !== TrendState::Ranging ? 1.0 : 0.0,
            default          => $trend->value === strtoupper($expect) ? 1.0 : 0.0,
        };

        return $this->result($score, [
            'timeframe' => $timeframe,
            'trend'     => $trend->value,
            'expect'    => $expect,
        ]);
    }

    /** Price relative to an indicator — the classic "above the 200 EMA". */
    private function priceVsIndicator(array $rule, StrategyContext $context, Direction $direction): array
    {
        $timeframe = $this->timeframe($rule);
        $name = (string) ($rule['indicator'] ?? '');
        $value = $context->indicator($name, $timeframe);
        $price = $context->price();

        if ($value === null || $price === null) {
            // A warming-up indicator is unknown, not false. Scoring it 0 would
            // silently penalise every setup during warm-up.
            return $this->unavailable(['timeframe' => $timeframe, 'indicator' => $name]);
        }

        $expect = (string) ($rule['expect'] ?? 'above_if_buy');
        $above = $price > $value;

        $score = match ($expect) {
            'above'        => $above ? 1.0 : 0.0,
            'below'        => !$above ? 1.0 : 0.0,
            'above_if_buy' => ($direction->isBuy() ? $above : !$above) ? 1.0 : 0.0,
            'below_if_buy' => ($direction->isBuy() ? !$above : $above) ? 1.0 : 0.0,
            default        => 0.0,
        };

        return $this->result($score, [
            'timeframe' => $timeframe,
            'indicator' => $name,
            'value'     => round($value, 5),
            'price'     => round($price, 5),
            'expect'    => $expect,
        ]);
    }

    /** One indicator relative to another — e.g. EMA 50 above EMA 200. */
    private function indicatorVsIndicator(array $rule, StrategyContext $context, Direction $direction): array
    {
        $timeframe = $this->timeframe($rule);
        $leftName = (string) ($rule['left'] ?? '');
        $rightName = (string) ($rule['right'] ?? '');

        $left = $context->indicator($leftName, $timeframe);
        $right = $context->indicator($rightName, $timeframe);

        if ($left === null || $right === null) {
            return $this->unavailable(['timeframe' => $timeframe, 'left' => $leftName, 'right' => $rightName]);
        }

        $expect = (string) ($rule['expect'] ?? 'left_above_if_buy');
        $leftAbove = $left > $right;

        $score = match ($expect) {
            'left_above'        => $leftAbove ? 1.0 : 0.0,
            'left_below'        => !$leftAbove ? 1.0 : 0.0,
            'left_above_if_buy' => ($direction->isBuy() ? $leftAbove : !$leftAbove) ? 1.0 : 0.0,
            default             => 0.0,
        };

        return $this->result($score, [
            'timeframe' => $timeframe,
            'left'      => $leftName,
            'right'     => $rightName,
            'expect'    => $expect,
        ]);
    }

    /**
     * An indicator inside a band — RSI between 40 and 60, say.
     *
     * Scores partially: fully inside is 1.0, and it falls off linearly across
     * the tolerance either side rather than snapping to zero at the edge. A
     * cliff at 60.0 would make an RSI of 60.1 worthless and 59.9 perfect,
     * which is not what the trader means.
     */
    private function indicatorRange(array $rule, StrategyContext $context): array
    {
        $timeframe = $this->timeframe($rule);
        $name = (string) ($rule['indicator'] ?? '');
        $value = $context->indicator($name, $timeframe);

        if ($value === null) {
            return $this->unavailable(['timeframe' => $timeframe, 'indicator' => $name]);
        }

        $min = isset($rule['min']) ? (float) $rule['min'] : -INF;
        $max = isset($rule['max']) ? (float) $rule['max'] : INF;
        $tolerance = isset($rule['tolerance']) ? (float) $rule['tolerance'] : 0.0;

        if ($value >= $min && $value <= $max) {
            $score = 1.0;
        } elseif ($tolerance <= 0.0) {
            $score = 0.0;
        } else {
            $distance = $value < $min ? $min - $value : $value - $max;
            $score = max(0.0, 1.0 - ($distance / $tolerance));
        }

        return $this->result($score, [
            'timeframe' => $timeframe,
            'indicator' => $name,
            'value'     => round($value, 5),
            'min'       => is_finite($min) ? $min : null,
            'max'       => is_finite($max) ? $max : null,
        ]);
    }

    /** A recent break of structure or change of character. */
    private function structure(array $rule, StrategyContext $context, Direction $direction): array
    {
        $break = $context->lastStructureBreak();

        if ($break === null) {
            return $this->unavailable(['reason' => 'no structural break on record']);
        }

        $expect = (string) ($rule['expect'] ?? 'bos_with_direction');
        $agrees = $break->impliedTrend->agreesWith($direction);
        $isChoch = $break->type === StructureType::Choch;

        $score = match ($expect) {
            'bos_with_direction'   => (!$isChoch && $agrees) ? 1.0 : 0.0,
            'break_with_direction' => $agrees ? 1.0 : 0.0,
            'choch_with_direction' => ($isChoch && $agrees) ? 1.0 : 0.0,
            'no_choch_against'     => ($isChoch && !$agrees) ? 0.0 : 1.0,
            default                => 0.0,
        };

        return $this->result($score, [
            'type'          => $break->type->value,
            'implied_trend' => $break->impliedTrend->value,
            'expect'        => $expect,
        ]);
    }

    /**
     * Proximity to a level of interest, measured in ATR.
     *
     * ATR-relative rather than absolute, so the rule means the same thing
     * whether gold trades at $1,800 or $3,300 and whether volatility is calm
     * or violent — a fixed "$5 from support" is a different setup entirely in
     * each regime.
     */
    private function distanceToLevel(array $rule, StrategyContext $context): array
    {
        $price = $context->price();
        $atr = $context->atr();

        if ($price === null || $atr === null || $atr <= 0.0) {
            return $this->unavailable(['reason' => 'price or ATR unavailable']);
        }

        $types = $rule['level_types'] ?? [];
        $types = is_array($types) ? array_map('strval', $types) : [];
        $maxAtr = isset($rule['max_atr']) ? (float) $rule['max_atr'] : 1.0;

        $nearest = null;

        foreach ($context->levels as $level) {
            if ($types !== [] && !in_array($level->type->value, $types, true)) {
                continue;
            }

            $distance = $level->distanceFrom($price);

            if ($nearest === null || $distance < $nearest) {
                $nearest = $distance;
            }
        }

        if ($nearest === null) {
            return $this->unavailable(['reason' => 'no matching level']);
        }

        $inAtr = $nearest / $atr;
        $score = $maxAtr <= 0.0 ? 0.0 : max(0.0, min(1.0, 1.0 - ($inAtr / $maxAtr)));

        return $this->result($score, [
            'distance'     => round($nearest, 5),
            'distance_atr' => round($inAtr, 3),
            'max_atr'      => $maxAtr,
            'level_types'  => $types,
        ]);
    }

    /**
     * How far price has retraced from the recent swing extreme.
     *
     * The core of a pullback pillar: too shallow is not a pullback, too deep
     * is a reversal. Scores 1.0 in the configured band and falls off outside.
     */
    private function pullbackDepth(array $rule, StrategyContext $context, Direction $direction): array
    {
        $candles = $context->candles();
        $lookback = isset($rule['lookback']) ? (int) $rule['lookback'] : 20;
        $window = $candles->tail(max(2, $lookback));

        $high = $window->highestHigh();
        $low = $window->lowestLow();
        $price = $context->price();

        if ($high === null || $low === null || $price === null || $high <= $low) {
            return $this->unavailable(['reason' => 'insufficient range']);
        }

        $range = $high - $low;

        // Retracement from the extreme the trade is trading away from.
        $retraced = $direction->isBuy()
            ? ($high - $price) / $range
            : ($price - $low) / $range;

        $min = isset($rule['min']) ? (float) $rule['min'] : 0.236;
        $max = isset($rule['max']) ? (float) $rule['max'] : 0.786;
        $tolerance = isset($rule['tolerance']) ? (float) $rule['tolerance'] : 0.15;

        if ($retraced >= $min && $retraced <= $max) {
            $score = 1.0;
        } else {
            $distance = $retraced < $min ? $min - $retraced : $retraced - $max;
            $score = $tolerance <= 0.0 ? 0.0 : max(0.0, 1.0 - ($distance / $tolerance));
        }

        return $this->result($score, [
            'retracement' => round($retraced, 4),
            'min'         => $min,
            'max'         => $max,
            'lookback'    => $lookback,
        ]);
    }

    /** The most recent closed candle's direction — a confirmation primitive. */
    private function candle(array $rule, StrategyContext $context, Direction $direction): array
    {
        $candle = $context->candles($this->timeframe($rule))->last();

        if ($candle === null) {
            return $this->unavailable(['reason' => 'no candle']);
        }

        $expect = (string) ($rule['expect'] ?? 'with_direction');

        $score = match ($expect) {
            'with_direction' => ($direction->isBuy() ? $candle->isBullish() : $candle->isBearish()) ? 1.0 : 0.0,
            'bullish'        => $candle->isBullish() ? 1.0 : 0.0,
            'bearish'        => $candle->isBearish() ? 1.0 : 0.0,
            // A close in the upper/lower third of the range — conviction.
            'strong_close'   => $this->strongClose($candle, $direction),
            default          => 0.0,
        };

        return $this->result($score, ['expect' => $expect, 'bullish' => $candle->isBullish()]);
    }

    private function strongClose(\GoldBot\Domain\Market\Candle $candle, Direction $direction): float
    {
        $range = $candle->range();

        if ($range <= 0.0) {
            return 0.0;
        }

        $position = ((float) $candle->close - (float) $candle->low) / $range;
        $strength = $direction->isBuy() ? $position : 1.0 - $position;

        // Rescale so the top third maps onto 0..1.
        return max(0.0, min(1.0, ($strength - 0.5) * 3));
    }

    /** Whether the evaluation moment falls in an allowed session. */
    private function session(array $rule, StrategyContext $context): array
    {
        $allowed = $rule['in'] ?? [];
        $allowed = is_array($allowed) ? array_map('strtoupper', array_map('strval', $allowed)) : [];
        $current = $context->sessionCode();

        $score = $current !== null && ($allowed === [] || in_array($current, $allowed, true)) ? 1.0 : 0.0;

        return $this->result($score, ['session' => $current, 'allowed' => $allowed]);
    }

    /** ATR within an acceptable band, expressed as a fraction of price. */
    private function volatility(array $rule, StrategyContext $context): array
    {
        $atr = $context->atr($this->timeframe($rule));
        $price = $context->price();

        if ($atr === null || $price === null || $price <= 0.0) {
            return $this->unavailable(['reason' => 'ATR or price unavailable']);
        }

        $ratio = $atr / $price;
        $min = isset($rule['min']) ? (float) $rule['min'] : 0.0;
        $max = isset($rule['max']) ? (float) $rule['max'] : INF;

        return $this->result(
            ($ratio >= $min && $ratio <= $max) ? 1.0 : 0.0,
            ['atr' => round($atr, 5), 'ratio' => round($ratio, 6), 'min' => $min, 'max' => is_finite($max) ? $max : null]
        );
    }

    /**
     * An unknown rule type scores zero and says so, rather than throwing.
     *
     * A typo in a config version must not take the engine down mid-run — but
     * it must be visible in the stored detail rather than silently ignored.
     */
    private function unknown(string $type): array
    {
        return $this->result(0.0, ['error' => 'unknown rule type', 'type' => $type]);
    }

    /**
     * An input that is not yet available — a warming-up indicator, an absent
     * level. Scored zero but flagged, so it is distinguishable from a
     * condition that was genuinely tested and failed.
     *
     * @param array<string,mixed> $detail
     */
    private function unavailable(array $detail): array
    {
        return $this->result(0.0, [...$detail, 'unavailable' => true]);
    }

    /** @param array<string,mixed> $detail */
    private function result(float $score, array $detail): array
    {
        return ['score' => max(0.0, min(1.0, $score)), 'detail' => $detail];
    }

    private function timeframe(array $rule): ?string
    {
        $value = $rule['timeframe'] ?? null;

        return is_string($value) && $value !== '' ? strtoupper($value) : null;
    }
}
