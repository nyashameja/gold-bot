<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy;

use DateTimeImmutable;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Enums\TrendState;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Domain\Session\TradingSession;
use GoldBot\Domain\Structure\PriceLevel;
use GoldBot\Domain\Structure\StructureBreak;

/**
 * Everything a strategy is allowed to see, frozen at one moment.
 *
 * This is the object that makes ADR-03 real. A strategy receives one of these
 * and nothing else — no repository, no clock, no HTTP client — which buys
 * three things that are otherwise expensive or impossible:
 *
 *   1. Strategies are unit-testable by constructing a context.
 *   2. The same strategy code runs live and in backtest, because a backtest is
 *      just the loop replaying historical contexts. Without this you end up
 *      with two implementations that drift, and the backtest stops describing
 *      the live system — which makes it worse than no backtest (ADR-04).
 *   3. Any past signal can be re-derived exactly from the stored watermark and
 *      config version.
 *
 * Multi-timeframe by construction: the 714 method reads trend on H4 and entry
 * on M15, so series and indicators are keyed by timeframe code.
 */
final class StrategyContext
{
    /**
     * @param array<string,CandleSeries>           $series      Timeframe code => closed candles.
     * @param array<string,array<string,?float>>   $indicators  Timeframe code => latest indicator values.
     * @param array<string,TrendState>             $trends      Timeframe code => structural trend.
     * @param list<PriceLevel>                     $levels
     * @param list<StructureBreak>                 $structureBreaks
     */
    public function __construct(
        public readonly int $instrumentId,
        public readonly Timeframe $timeframe,
        public readonly DateTimeImmutable $at,
        private readonly array $series,
        private readonly array $indicators,
        private readonly array $trends,
        public readonly array $levels = [],
        public readonly array $structureBreaks = [],
        public readonly ?TradingSession $session = null,
        public readonly ?EconomicEvent $blockingEvent = null,
        public readonly ?string $spread = null
    ) {
    }

    /** Closed candles for a timeframe, oldest first. */
    public function candles(?string $timeframeCode = null): CandleSeries
    {
        return $this->series[$timeframeCode ?? $this->timeframe->code] ?? new CandleSeries([]);
    }

    public function hasTimeframe(string $code): bool
    {
        return isset($this->series[$code]);
    }

    /** @return list<string> */
    public function timeframeCodes(): array
    {
        return array_keys($this->series);
    }

    /**
     * A latest indicator value, or null if absent or still warming up.
     *
     * Null is meaningful and must not be coerced: a strategy comparing price to
     * a not-yet-warm EMA-200 would be comparing against nothing.
     */
    public function indicator(string $name, ?string $timeframeCode = null): ?float
    {
        return $this->indicators[$timeframeCode ?? $this->timeframe->code][$name] ?? null;
    }

    /** @return array<string,?float> */
    public function indicators(?string $timeframeCode = null): array
    {
        return $this->indicators[$timeframeCode ?? $this->timeframe->code] ?? [];
    }

    public function trend(?string $timeframeCode = null): TrendState
    {
        return $this->trends[$timeframeCode ?? $this->timeframe->code] ?? TrendState::Unknown;
    }

    /** @return array<string,TrendState> */
    public function trends(): array
    {
        return $this->trends;
    }

    /**
     * The price a decision is made at: the close of the signal timeframe's
     * most recent closed candle.
     *
     * Deliberately not the live quote. Using a tick that arrived after the
     * candle closed would make the evaluation unreproducible, and a backtest
     * could never reproduce it at all.
     */
    public function price(): ?float
    {
        return $this->candles()->last()?->closedAsFloat();
    }

    /** ATR on the signal timeframe — the unit distances are measured in. */
    public function atr(?string $timeframeCode = null): ?float
    {
        return $this->indicator('atr_14', $timeframeCode);
    }

    public function isNewsBlackout(): bool
    {
        return $this->blockingEvent !== null;
    }

    public function sessionCode(): ?string
    {
        return $this->session?->code;
    }

    /** @return list<PriceLevel> Levels of the given type. */
    public function levelsOfType(string $type): array
    {
        return array_values(array_filter(
            $this->levels,
            static fn (PriceLevel $l): bool => $l->type->value === $type
        ));
    }

    /**
     * The nearest level below and above the current price.
     *
     * @return array{below:?PriceLevel,above:?PriceLevel}
     */
    public function nearestLevels(): array
    {
        $price = $this->price();

        if ($price === null) {
            return ['below' => null, 'above' => null];
        }

        $below = null;
        $above = null;

        foreach ($this->levels as $level) {
            if ($level->to <= $price && ($below === null || $level->to > $below->to)) {
                $below = $level;
            }

            if ($level->from >= $price && ($above === null || $level->from < $above->from)) {
                $above = $level;
            }
        }

        return ['below' => $below, 'above' => $above];
    }

    /** The most recent structural break, if any. */
    public function lastStructureBreak(): ?StructureBreak
    {
        return $this->structureBreaks === []
            ? null
            : $this->structureBreaks[count($this->structureBreaks) - 1];
    }

    /**
     * A flat, JSON-safe snapshot of the inputs.
     *
     * Persisted to strategy_runs.features on every evaluation. It is what makes
     * a past decision explicable, and it is the labelled dataset any future ML
     * work will need — which cannot be reconstructed after the fact.
     *
     * @return array<string,mixed>
     */
    public function toFeatures(): array
    {
        $features = [
            'at'             => $this->at->format('c'),
            'timeframe'      => $this->timeframe->code,
            'price'          => $this->price(),
            'session'        => $this->sessionCode(),
            'spread'         => $this->spread,
            'news_blackout'  => $this->isNewsBlackout(),
            'blocking_event' => $this->blockingEvent?->title,
            'level_count'    => count($this->levels),
        ];

        foreach ($this->trends as $code => $trend) {
            $features['trend_' . strtolower($code)] = $trend->value;
        }

        foreach ($this->indicators as $code => $values) {
            foreach ($values as $name => $value) {
                if ($value !== null) {
                    $features[strtolower($code) . '_' . $name] = round($value, 5);
                }
            }
        }

        return $features;
    }
}
