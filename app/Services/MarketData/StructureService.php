<?php

declare(strict_types=1);

namespace GoldBot\Services\MarketData;

use GoldBot\Domain\Market\Timeframe;
use GoldBot\Domain\Structure\LevelBuilder;
use GoldBot\Domain\Structure\PriceLevel;
use GoldBot\Domain\Structure\StructureAnalyser;
use GoldBot\Domain\Structure\SwingDetector;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\WatermarkRepositoryInterface;
use Paragon\Core\Database;
use Paragon\Core\Logging\LoggerInterface;

/**
 * Persists swing points, structural breaks and price levels.
 *
 * Structure is recomputed over a trailing window rather than appended
 * incrementally, because a swing is only confirmed some bars after it forms
 * (see SwingDetector) and a level's strength changes as price revisits it.
 * Upserts on a natural key make the recomputation idempotent, so re-running
 * converges rather than duplicating.
 *
 * Levels are replaced wholesale per instrument and timeframe: a level that has
 * ceased to exist must disappear, and there is no reliable key by which to
 * discover its absence.
 */
final class StructureService
{
    /** Bars of history structure is derived from. */
    private const WINDOW_BARS = 500;

    public function __construct(
        private readonly CandleRepositoryInterface $candles,
        private readonly WatermarkRepositoryInterface $watermarks,
        private readonly Database $database,
        private readonly SwingDetector $swings,
        private readonly StructureAnalyser $analyser,
        private readonly LevelBuilder $levels,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return array{swings:int,breaks:int,levels:int} */
    public function process(int $instrumentId, Timeframe $timeframe): array
    {
        $series = $this->candles->latest($instrumentId, $timeframe->id, self::WINDOW_BARS, closedOnly: true);

        if (count($series) < 20) {
            return ['swings' => 0, 'breaks' => 0, 'levels' => 0];
        }

        $swingCount = 0;

        foreach ($this->swings->detect($series) as $swing) {
            // The natural key is (instrument, timeframe, type, occurred_at),
            // so recomputing the same window rewrites rather than duplicates.
            $this->database->upsert(
                'market_structure_points',
                [
                    'instrument_id' => $instrumentId,
                    'timeframe_id'  => $timeframe->id,
                    'candle_id'     => $swing->candleId,
                    'type'          => $swing->type->value,
                    'price'         => number_format($swing->price, 5, '.', ''),
                    'direction'     => null,
                    'strength'      => $swing->strength,
                    'occurred_at'   => $swing->occurredAt->format('Y-m-d H:i:s'),
                ],
                ['candle_id', 'price', 'strength']
            );

            $swingCount++;
        }

        $breakCount = 0;

        foreach ($this->analyser->breaks($series) as $break) {
            $this->database->upsert(
                'market_structure_points',
                [
                    'instrument_id' => $instrumentId,
                    'timeframe_id'  => $timeframe->id,
                    'candle_id'     => $break->candleId,
                    'type'          => $break->type->value,
                    'price'         => number_format($break->price, 5, '.', ''),
                    'direction'     => $break->impliedTrend->value,
                    'strength'      => 1,
                    'occurred_at'   => $break->occurredAt->format('Y-m-d H:i:s'),
                ],
                ['candle_id', 'price', 'direction']
            );

            $breakCount++;
        }

        $levelCount = $this->replaceLevels($instrumentId, $timeframe, $series);

        $this->logger->info('Structure analysed', [
            'event'     => 'market.structure_analysed',
            'timeframe' => $timeframe->code,
            'trend'     => $this->analyser->trend($series)->value,
            'swings'    => $swingCount,
            'breaks'    => $breakCount,
            'levels'    => $levelCount,
        ]);

        $newest = $series->last();

        if ($newest !== null) {
            $this->watermarks->advance(
                $instrumentId,
                $timeframe->id,
                WatermarkRepositoryInterface::STAGE_STRUCTURE,
                $newest->openTime,
                $newest->id
            );
        }

        return ['swings' => $swingCount, 'breaks' => $breakCount, 'levels' => $levelCount];
    }

    /**
     * Replace this series' levels atomically.
     *
     * Delete-then-insert inside one transaction, so a reader never observes an
     * empty level set — the Live Market chart would otherwise flicker its
     * overlays away on every analysis run.
     */
    private function replaceLevels(int $instrumentId, Timeframe $timeframe, \GoldBot\Domain\Market\CandleSeries $series): int
    {
        $levels = [
            ...$this->levels->supportAndResistance($series),
            ...$this->levels->supplyDemandZones($series),
            ...$this->levels->sessionExtremes($series),
        ];

        return $this->database->transaction(function () use ($instrumentId, $timeframe, $levels): int {
            $this->database->run(
                'DELETE FROM market_levels WHERE instrument_id = ? AND timeframe_id = ?',
                [$instrumentId, $timeframe->id]
            );

            foreach ($levels as $level) {
                $this->database->insert('market_levels', [
                    'instrument_id' => $instrumentId,
                    'timeframe_id'  => $timeframe->id,
                    'type'          => $level->type->value,
                    'price_from'    => number_format($level->from, 5, '.', ''),
                    'price_to'      => number_format($level->to, 5, '.', ''),
                    'strength'      => $level->strength,
                    'touch_count'   => $level->touchCount,
                    'is_active'     => 1,
                    'formed_at'     => $level->formedAt->format('Y-m-d H:i:s'),
                ]);
            }

            return count($levels);
        });
    }

    /** The current trend for a series — read by the dashboard and strategies. */
    public function currentTrend(int $instrumentId, Timeframe $timeframe): \GoldBot\Domain\Market\Enums\TrendState
    {
        return $this->analyser->trend(
            $this->candles->latest($instrumentId, $timeframe->id, self::WINDOW_BARS, closedOnly: true)
        );
    }

    /** @return list<PriceLevel> */
    public function levelsFor(int $instrumentId, Timeframe $timeframe): array
    {
        return $this->levels->supportAndResistance(
            $this->candles->latest($instrumentId, $timeframe->id, self::WINDOW_BARS, closedOnly: true)
        );
    }
}
