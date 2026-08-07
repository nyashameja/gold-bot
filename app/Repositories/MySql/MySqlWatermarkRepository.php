<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Repositories\Contracts\WatermarkRepositoryInterface;
use Paragon\Core\Database;

final class MySqlWatermarkRepository implements WatermarkRepositoryInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function lastProcessed(int $instrumentId, int $timeframeId, string $stage): ?DateTimeImmutable
    {
        $value = $this->database->scalar(
            'SELECT last_open_time FROM ingest_watermarks
             WHERE instrument_id = ? AND timeframe_id = ? AND stage = ?',
            [$instrumentId, $timeframeId, $stage]
        );

        return $value === null ? null : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    public function advance(
        int $instrumentId,
        int $timeframeId,
        string $stage,
        DateTimeImmutable $openTime,
        ?int $candleId = null
    ): void {
        // GREATEST guards against a concurrent or out-of-order run moving the
        // watermark backwards, which would silently reprocess a window and —
        // worse — could re-emit signals that were already published.
        $this->database->run(
            'INSERT INTO ingest_watermarks (instrument_id, timeframe_id, stage, last_open_time, last_candle_id)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                last_candle_id = IF(VALUES(last_open_time) >= last_open_time, VALUES(last_candle_id), last_candle_id),
                last_open_time = GREATEST(last_open_time, VALUES(last_open_time))',
            [
                $instrumentId,
                $timeframeId,
                $stage,
                $openTime->format('Y-m-d H:i:s'),
                $candleId,
            ]
        );
    }

    public function rewind(int $instrumentId, int $timeframeId, string $stage, ?DateTimeImmutable $to = null): void
    {
        if ($to === null) {
            $this->database->run(
                'DELETE FROM ingest_watermarks WHERE instrument_id = ? AND timeframe_id = ? AND stage = ?',
                [$instrumentId, $timeframeId, $stage]
            );

            return;
        }

        // Not GREATEST here: rewinding backwards is the entire point.
        $this->database->run(
            'INSERT INTO ingest_watermarks (instrument_id, timeframe_id, stage, last_open_time)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_open_time = VALUES(last_open_time), last_candle_id = NULL',
            [$instrumentId, $timeframeId, $stage, $to->format('Y-m-d H:i:s')]
        );
    }

    public function all(): array
    {
        return $this->database->select(
            'SELECT w.stage, w.last_open_time, w.updated_at,
                    i.symbol, t.code AS timeframe
             FROM ingest_watermarks w
             JOIN instruments i ON i.id = w.instrument_id
             JOIN timeframes t ON t.id = w.timeframe_id
             ORDER BY i.symbol, t.sort_order, w.stage'
        );
    }
}
