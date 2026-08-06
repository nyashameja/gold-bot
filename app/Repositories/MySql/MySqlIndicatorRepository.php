<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use GoldBot\Core\Database;
use GoldBot\Repositories\Contracts\IndicatorRepositoryInterface;

final class MySqlIndicatorRepository implements IndicatorRepositoryInterface
{
    /** Columns the wide table carries (ADR-13). */
    private const COLUMNS = [
        'ema_50', 'ema_200', 'rsi_14', 'atr_14',
        'macd', 'macd_signal', 'macd_histogram',
        'bb_upper', 'bb_middle', 'bb_lower', 'volume_sma_20',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function upsertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return $this->database->transaction(function () use ($rows): int {
            $written = 0;

            foreach ($rows as $row) {
                $written += $this->database->upsert('candle_indicators', $row, self::COLUMNS);
            }

            return $written;
        });
    }

    public function latestFor(int $instrumentId, int $timeframeId): ?array
    {
        $row = $this->database->selectOne(
            'SELECT * FROM candle_indicators
             WHERE instrument_id = ? AND timeframe_id = ?
             ORDER BY open_time DESC LIMIT 1',
            [$instrumentId, $timeframeId]
        );

        return $row === null ? null : $this->cast($row);
    }

    public function window(int $instrumentId, int $timeframeId, int $limit = 300): array
    {
        $rows = $this->database->select(
            'SELECT * FROM candle_indicators
             WHERE instrument_id = ? AND timeframe_id = ?
             ORDER BY open_time DESC LIMIT ?',
            [$instrumentId, $timeframeId, max(1, $limit)]
        );

        return array_reverse(array_map($this->cast(...), $rows));
    }

    public function countFor(int $instrumentId, int $timeframeId): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM candle_indicators WHERE instrument_id = ? AND timeframe_id = ?',
            [$instrumentId, $timeframeId]
        );
    }

    public function deleteFrom(int $instrumentId, int $timeframeId, DateTimeImmutable $from): int
    {
        return $this->database->run(
            'DELETE FROM candle_indicators WHERE instrument_id = ? AND timeframe_id = ? AND open_time >= ?',
            [$instrumentId, $timeframeId, $from->format('Y-m-d H:i:s')]
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function cast(array $row): array
    {
        // DECIMAL columns arrive as strings; indicators are consumed as floats.
        // Nulls stay null — a warm-up gap must never read as 0.0, which would
        // look like a real value to a comparison.
        foreach (self::COLUMNS as $column) {
            $row[$column] = $row[$column] === null ? null : (float) $row[$column];
        }

        return $row;
    }
}
