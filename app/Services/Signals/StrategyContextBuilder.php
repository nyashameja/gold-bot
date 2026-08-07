<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals;

use DateTimeImmutable;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Domain\Session\SessionResolver;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Domain\Structure\LevelBuilder;
use GoldBot\Domain\Structure\StructureAnalyser;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\IndicatorRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\PriceSnapshotRepositoryInterface;
use GoldBot\Services\Calendar\NewsBlackoutService;

/**
 * Assembles the immutable snapshot a strategy evaluates against.
 *
 * Built once per engine run and shared by every strategy, so all of them see
 * identical inputs. If each built its own, two strategies could disagree about
 * the current price simply because one queried a moment later — and neither
 * result would be reproducible.
 */
final class StrategyContextBuilder
{
    /** Bars loaded per timeframe: enough to warm EMA-200 and find structure. */
    private const WINDOW_BARS = 400;

    public function __construct(
        private readonly CandleRepositoryInterface $candles,
        private readonly IndicatorRepositoryInterface $indicators,
        private readonly MarketReferenceRepositoryInterface $reference,
        private readonly PriceSnapshotRepositoryInterface $snapshots,
        private readonly StructureAnalyser $structure,
        private readonly LevelBuilder $levels,
        private readonly SessionResolver $sessions,
        private readonly NewsBlackoutService $blackout
    ) {
    }

    /**
     * Build a context for the given instrument and signal timeframe.
     *
     * @param list<string> $timeframeCodes Every timeframe the strategy reads.
     */
    public function build(
        int $instrumentId,
        Timeframe $signalTimeframe,
        array $timeframeCodes,
        DateTimeImmutable $at,
        bool $historical = false
    ): ?StrategyContext {
        $series = [];
        $indicatorValues = [];
        $trends = [];

        $codes = array_values(array_unique([$signalTimeframe->code, ...$timeframeCodes]));

        foreach ($codes as $code) {
            $timeframe = $this->reference->timeframeByCode($code);

            if ($timeframe === null) {
                continue;
            }

            // Bounded by $at. Live callers pass "now", where this changes
            // nothing — there are no future candles. The backtester passes the
            // bar it is standing on, and the same code then cannot see past
            // it. One builder serving both is what makes "the backtest
            // reproduces the live signals" a meaningful statement rather than
            // a comparison of two different implementations.
            $candles = $this->candles->latest(
                $instrumentId,
                $timeframe->id,
                self::WINDOW_BARS,
                closedOnly: true,
                asOf: $at
            );

            if ($candles->isEmpty()) {
                continue;
            }

            $series[$code] = $candles;
            $trends[$code] = $this->structure->trend($candles);

            $latest = $this->indicators->latestFor($instrumentId, $timeframe->id, $at);
            $indicatorValues[$code] = $latest === null ? [] : $this->extractIndicators($latest);
        }

        // Without candles on the signal timeframe there is nothing to evaluate.
        if (!isset($series[$signalTimeframe->code])) {
            return null;
        }

        $signalSeries = $series[$signalTimeframe->code];

        // Price snapshots are a live-only artefact: there is no historical
        // quote to replay, so a backtest has no spread. Left null rather than
        // approximated from the candle, because a made-up spread would make
        // the spread filter look as though it had been tested when it had not.
        $snapshot = $historical ? null : $this->snapshots->latest($instrumentId);

        return new StrategyContext(
            instrumentId:    $instrumentId,
            timeframe:       $signalTimeframe,
            at:              $at,
            series:          $series,
            indicators:      $indicatorValues,
            trends:          $trends,
            levels:          [
                ...$this->levels->supportAndResistance($signalSeries),
                ...$this->levels->supplyDemandZones($signalSeries),
                ...$this->levels->sessionExtremes($signalSeries),
            ],
            structureBreaks: $this->structure->breaks($signalSeries),
            session:         $this->sessions->primaryAt($at),
            // Resolved once here so every strategy in this run agrees, and so
            // the reason can name the event rather than saying only "news".
            blockingEvent:   $this->blackout->activeEvent($at),
            spread:          $snapshot?->spread()
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,?float>
     */
    private function extractIndicators(array $row): array
    {
        $values = [];

        foreach ($row as $key => $value) {
            // Skip the identity and bookkeeping columns; keep the numbers.
            if (in_array($key, ['id', 'candle_id', 'instrument_id', 'timeframe_id', 'open_time', 'computed_at'], true)) {
                continue;
            }

            $values[$key] = $value === null ? null : (float) $value;
        }

        return $values;
    }
}
