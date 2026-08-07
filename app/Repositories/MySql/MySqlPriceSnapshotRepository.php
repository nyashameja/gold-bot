<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\PriceSnapshot;
use GoldBot\Repositories\Contracts\PriceSnapshotRepositoryInterface;
use Paragon\Core\Database;

final class MySqlPriceSnapshotRepository implements PriceSnapshotRepositoryInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function store(int $instrumentId, PriceSnapshot $snapshot): int
    {
        return $this->database->insert('price_snapshots', [
            'instrument_id'   => $instrumentId,
            'price'           => $snapshot->price,
            'bid'             => $snapshot->bid,
            'ask'             => $snapshot->ask,
            'spread'          => $snapshot->spread(),
            'day_high'        => $snapshot->dayHigh,
            'day_low'         => $snapshot->dayLow,
            'change_absolute' => $snapshot->changeAbsolute,
            'change_percent'  => $snapshot->changePercent,
            'provider_time'   => $snapshot->providerTime?->format('Y-m-d H:i:s'),
            'captured_at'     => $snapshot->capturedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function latest(int $instrumentId): ?PriceSnapshot
    {
        $row = $this->database->selectOne(
            'SELECT * FROM price_snapshots WHERE instrument_id = ? ORDER BY captured_at DESC, id DESC LIMIT 1',
            [$instrumentId]
        );

        if ($row === null) {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        return new PriceSnapshot(
            price:          (string) $row['price'],
            capturedAt:     new DateTimeImmutable((string) $row['captured_at'], $utc),
            providerTime:   $row['provider_time'] === null
                ? null
                : new DateTimeImmutable((string) $row['provider_time'], $utc),
            bid:            $row['bid'] === null ? null : (string) $row['bid'],
            ask:            $row['ask'] === null ? null : (string) $row['ask'],
            dayHigh:        $row['day_high'] === null ? null : (string) $row['day_high'],
            dayLow:         $row['day_low'] === null ? null : (string) $row['day_low'],
            changeAbsolute: $row['change_absolute'] === null ? null : (string) $row['change_absolute'],
            changePercent:  $row['change_percent'] === null ? null : (string) $row['change_percent']
        );
    }

    public function pruneBefore(DateTimeImmutable $before): int
    {
        return $this->database->run(
            'DELETE FROM price_snapshots WHERE captured_at < ?',
            [$before->format('Y-m-d H:i:s')]
        );
    }
}
