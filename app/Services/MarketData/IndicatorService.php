<?php

declare(strict_types=1);

namespace GoldBot\Services\MarketData;

use GoldBot\Domain\Indicators\Atr;
use GoldBot\Domain\Indicators\BollingerBands;
use GoldBot\Domain\Indicators\Ema;
use GoldBot\Domain\Indicators\Macd;
use GoldBot\Domain\Indicators\Rsi;
use GoldBot\Domain\Indicators\VolumeSma;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\IndicatorRepositoryInterface;
use GoldBot\Repositories\Contracts\WatermarkRepositoryInterface;
use Paragon\Core\Logging\LoggerInterface;

/**
 * Computes and persists indicators for closed candles.
 *
 * Incremental by construction (ADR-14, docs/02 §5): the INDICATORS watermark
 * records the last bar processed, and only newer bars are written. But the
 * *computation* still needs history — an EMA-200 at bar N depends on the 200
 * bars before it — so a warm-up window is loaded and then discarded.
 *
 * Getting that distinction wrong is the classic incremental-indicator bug:
 * compute over only the new bars and every value is wrong, because each
 * indicator restarts its warm-up from scratch.
 */
final class IndicatorService
{
    /**
     * Bars of history loaded before the first bar to be written.
     *
     * Comfortably beyond EMA-200's warm-up: an EMA is an infinite series, so
     * it never fully forgets its seed. 200 extra bars puts the residual error
     * far below the 5-decimal storage precision.
     */
    private const WARM_UP_BARS = 400;

    /** Bars written per pass, so a long backfill cannot exhaust memory. */
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly CandleRepositoryInterface $candles,
        private readonly IndicatorRepositoryInterface $indicators,
        private readonly WatermarkRepositoryInterface $watermarks,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process every closed candle newer than the INDICATORS watermark.
     *
     * @return int Rows written.
     */
    public function process(int $instrumentId, Timeframe $timeframe): int
    {
        $stage = WatermarkRepositoryInterface::STAGE_INDICATORS;
        $lastProcessed = $this->watermarks->lastProcessed($instrumentId, $timeframe->id, $stage);

        $pending = $this->candles->closedSince($instrumentId, $timeframe->id, $lastProcessed, self::BATCH_SIZE);

        if ($pending->isEmpty()) {
            return 0;
        }

        $firstPending = $pending->first();

        if ($firstPending === null) {
            return 0;
        }

        // Load the warm-up window plus the pending bars in one read, then
        // compute over the whole thing and keep only the pending tail.
        $context = $this->candles->latest(
            $instrumentId,
            $timeframe->id,
            self::WARM_UP_BARS + count($pending),
            closedOnly: true
        );

        $values = $this->computeAll($context);
        $rows = [];

        $pendingIds = [];

        foreach ($pending as $candle) {
            if ($candle->id !== null) {
                $pendingIds[$candle->id] = true;
            }
        }

        foreach ($context->all() as $index => $candle) {
            // Only the bars that are actually new get written; the warm-up
            // window was loaded for its arithmetic, not to be re-stored.
            if ($candle->id === null || !isset($pendingIds[$candle->id])) {
                continue;
            }

            $rows[] = [
                'candle_id'      => $candle->id,
                'instrument_id'  => $instrumentId,
                'timeframe_id'   => $timeframe->id,
                'open_time'      => $candle->openTime->format('Y-m-d H:i:s'),
                'ema_50'         => $this->round($values['ema_50'][$index] ?? null),
                'ema_200'        => $this->round($values['ema_200'][$index] ?? null),
                'rsi_14'         => $this->round($values['rsi_14'][$index] ?? null, 4),
                'atr_14'         => $this->round($values['atr_14'][$index] ?? null),
                'macd'           => $this->round($values['macd'][$index] ?? null),
                'macd_signal'    => $this->round($values['macd_signal'][$index] ?? null),
                'macd_histogram' => $this->round($values['macd_histogram'][$index] ?? null),
                'bb_upper'       => $this->round($values['bb_upper'][$index] ?? null),
                'bb_middle'      => $this->round($values['bb_middle'][$index] ?? null),
                'bb_lower'       => $this->round($values['bb_lower'][$index] ?? null),
                'volume_sma_20'  => $this->round($values['volume_sma_20'][$index] ?? null),
            ];
        }

        $written = $this->indicators->upsertMany($rows);

        $newest = $pending->last();

        if ($newest !== null) {
            $this->watermarks->advance($instrumentId, $timeframe->id, $stage, $newest->openTime, $newest->id);
        }

        $this->logger->info('Indicators computed', [
            'event'      => 'market.indicators_computed',
            'timeframe'  => $timeframe->code,
            'pending'    => count($pending),
            'written'    => count($rows),
            'context'    => count($context),
        ]);

        return count($rows);
    }

    /**
     * All indicator series for a candle series, keyed by column name.
     *
     * @return array<string,list<float|null>>
     */
    public function computeAll(CandleSeries $series): array
    {
        $macd = (new Macd())->calculateAll($series);
        $bands = (new BollingerBands())->calculateAll($series);

        return [
            'ema_50'         => (new Ema(50))->calculate($series),
            'ema_200'        => (new Ema(200))->calculate($series),
            'rsi_14'         => (new Rsi(14))->calculate($series),
            'atr_14'         => (new Atr(14))->calculate($series),
            'macd'           => $macd['macd'],
            'macd_signal'    => $macd['signal'],
            'macd_histogram' => $macd['histogram'],
            'bb_upper'       => $bands['upper'],
            'bb_middle'      => $bands['middle'],
            'bb_lower'       => $bands['lower'],
            'volume_sma_20'  => (new VolumeSma(20))->calculate($series),
        ];
    }

    /** Null stays null: a warm-up gap must never be stored as zero. */
    private function round(?float $value, int $precision = 5): ?string
    {
        return $value === null ? null : number_format($value, $precision, '.', '');
    }
}
