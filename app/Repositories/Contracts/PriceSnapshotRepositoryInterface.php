<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;
use GoldBot\Domain\Market\PriceSnapshot;

interface PriceSnapshotRepositoryInterface
{
    public function store(int $instrumentId, PriceSnapshot $snapshot): int;

    /** The newest quote, or null if none has been captured yet. */
    public function latest(int $instrumentId): ?PriceSnapshot;

    /** Delete snapshots older than $before. Returns rows removed. */
    public function pruneBefore(DateTimeImmutable $before): int;
}
