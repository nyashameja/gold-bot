<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use GoldBot\Core\Database;
use GoldBot\Repositories\Contracts\MarketStructureRepositoryInterface;

final class MySqlMarketStructureRepository implements MarketStructureRepositoryInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function points(int $instrumentId, int $timeframeId, int $limit = 60): array
    {
        // Newest N, then reversed: taking the oldest N would show the chart a
        // window that scrolls off the left of the visible candles.
        $rows = $this->database->select(
            'SELECT id, type, price, direction, strength, occurred_at
             FROM market_structure_points
             WHERE instrument_id = ? AND timeframe_id = ? AND invalidated_at IS NULL
             ORDER BY occurred_at DESC, id DESC
             LIMIT ?',
            [$instrumentId, $timeframeId, max(1, min($limit, 500))]
        );

        return array_reverse($rows);
    }

    public function levels(int $instrumentId, int $timeframeId): array
    {
        return $this->database->select(
            'SELECT id, type, price_from, price_to, strength, touch_count, formed_at
             FROM market_levels
             WHERE instrument_id = ? AND timeframe_id = ? AND is_active = 1 AND invalidated_at IS NULL
             ORDER BY strength DESC, price_from',
            [$instrumentId, $timeframeId]
        );
    }

    public function lastBreak(int $instrumentId, int $timeframeId): ?array
    {
        return $this->database->selectOne(
            "SELECT type, price, direction, occurred_at
             FROM market_structure_points
             WHERE instrument_id = ? AND timeframe_id = ? AND invalidated_at IS NULL
               AND type IN ('BOS', 'CHOCH')
             ORDER BY occurred_at DESC, id DESC
             LIMIT 1",
            [$instrumentId, $timeframeId]
        );
    }
}
