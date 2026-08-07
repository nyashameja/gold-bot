<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use Paragon\Core\Database;

final class MySqlCandleRepository implements CandleRepositoryInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function upsertSeries(int $instrumentId, int $timeframeId, CandleSeries $series, string $source): array
    {
        if ($series->isEmpty()) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $before = $this->count($instrumentId, $timeframeId);

        $this->database->transaction(function () use ($instrumentId, $timeframeId, $series, $source): void {
            foreach ($series as $candle) {
                $this->database->upsert(
                    'candles',
                    [
                        'instrument_id' => $instrumentId,
                        'timeframe_id'  => $timeframeId,
                        'open_time'     => $candle->openTime->format('Y-m-d H:i:s'),
                        'close_time'    => $candle->closeTime->format('Y-m-d H:i:s'),
                        'open'          => $candle->open,
                        'high'          => $candle->high,
                        'low'           => $candle->low,
                        'close'         => $candle->close,
                        'volume'        => $candle->volume,
                        'is_closed'     => $candle->isClosed ? 1 : 0,
                        'source'        => $source,
                    ],
                    // open_time is the key and is never updated. is_closed is
                    // included so a bar stored while forming is promoted when
                    // it is re-fetched after closing.
                    ['close_time', 'open', 'high', 'low', 'close', 'volume', 'is_closed', 'source']
                );
            }
        });

        $after = $this->count($instrumentId, $timeframeId);
        $inserted = $after - $before;

        return [
            'inserted' => $inserted,
            'updated'  => count($series) - $inserted,
        ];
    }

    public function latest(
        int $instrumentId,
        int $timeframeId,
        int $limit = 300,
        bool $closedOnly = true,
        ?DateTimeImmutable $asOf = null
    ): CandleSeries {
        $bindings = [$instrumentId, $timeframeId];
        $bound = '';

        if ($asOf !== null) {
            // The bar must have CLOSED by $asOf, not merely opened. A bar that
            // opened before the cutoff and closes after it contains prices the
            // caller cannot yet know — feeding it to a backtest is lookahead
            // bias, which manufactures profitable strategies out of nothing.
            $bound = ' AND close_time <= ?';
            $bindings[] = $asOf->format('Y-m-d H:i:s');
        }

        $bindings[] = max(1, $limit);

        $rows = $this->database->select(
            'SELECT * FROM candles
             WHERE instrument_id = ? AND timeframe_id = ?'
            . ($closedOnly ? ' AND is_closed = 1' : '') . $bound . '
             ORDER BY open_time DESC
             LIMIT ?',
            $bindings
        );

        // Fetched newest-first so LIMIT takes the right end; CandleSeries
        // re-sorts to oldest-first, which every indicator assumes.
        return $this->hydrate($rows);
    }

    public function between(
        int $instrumentId,
        int $timeframeId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        bool $closedOnly = true
    ): CandleSeries {
        $rows = $this->database->select(
            'SELECT * FROM candles
             WHERE instrument_id = ? AND timeframe_id = ?
               AND open_time >= ? AND open_time <= ?'
            . ($closedOnly ? ' AND is_closed = 1' : '') . '
             ORDER BY open_time',
            [
                $instrumentId,
                $timeframeId,
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ]
        );

        return $this->hydrate($rows);
    }

    public function closedSince(int $instrumentId, int $timeframeId, ?DateTimeImmutable $after, int $limit = 1000): CandleSeries
    {
        $bindings = [$instrumentId, $timeframeId];
        $clause = '';

        if ($after !== null) {
            $clause = ' AND open_time > ?';
            $bindings[] = $after->format('Y-m-d H:i:s');
        }

        $bindings[] = max(1, $limit);

        $rows = $this->database->select(
            'SELECT * FROM candles
             WHERE instrument_id = ? AND timeframe_id = ? AND is_closed = 1' . $clause . '
             ORDER BY open_time
             LIMIT ?',
            $bindings
        );

        return $this->hydrate($rows);
    }

    public function mostRecent(int $instrumentId, int $timeframeId, bool $closedOnly = true): ?Candle
    {
        $row = $this->database->selectOne(
            'SELECT * FROM candles
             WHERE instrument_id = ? AND timeframe_id = ?'
            . ($closedOnly ? ' AND is_closed = 1' : '') . '
             ORDER BY open_time DESC
             LIMIT 1',
            [$instrumentId, $timeframeId]
        );

        return $row === null ? null : $this->toCandle($row);
    }

    public function count(int $instrumentId, int $timeframeId): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM candles WHERE instrument_id = ? AND timeframe_id = ?',
            [$instrumentId, $timeframeId]
        );
    }

    public function markClosedBefore(int $instrumentId, int $timeframeId, DateTimeImmutable $cutoff): int
    {
        return $this->database->run(
            'UPDATE candles SET is_closed = 1
             WHERE instrument_id = ? AND timeframe_id = ? AND is_closed = 0 AND close_time < ?',
            [$instrumentId, $timeframeId, $cutoff->format('Y-m-d H:i:s')]
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private function hydrate(array $rows): CandleSeries
    {
        return new CandleSeries(array_map(fn (array $row): Candle => $this->toCandle($row), $rows));
    }

    /** @param array<string,mixed> $row */
    private function toCandle(array $row): Candle
    {
        $utc = new DateTimeZone('UTC');

        return new Candle(
            openTime:  new DateTimeImmutable((string) $row['open_time'], $utc),
            closeTime: new DateTimeImmutable((string) $row['close_time'], $utc),
            open:      (string) $row['open'],
            high:      (string) $row['high'],
            low:       (string) $row['low'],
            close:     (string) $row['close'],
            volume:    (string) $row['volume'],
            isClosed:  (int) $row['is_closed'] === 1,
            id:        (int) $row['id']
        );
    }
}
