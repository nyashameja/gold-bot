<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;

interface IndicatorRepositoryInterface
{
    /**
     * Insert or update indicator rows, keyed on candle_id.
     *
     * @param list<array<string,mixed>> $rows
     * @return int Rows written.
     */
    public function upsertMany(array $rows): int;

    /** @return array<string,float|null>|null Latest indicator values for a series. */
    public function latestFor(int $instrumentId, int $timeframeId): ?array;

    /** @return list<array<string,mixed>> Indicator rows in a window, oldest first. */
    public function window(int $instrumentId, int $timeframeId, int $limit = 300): array;

    public function countFor(int $instrumentId, int $timeframeId): int;

    public function deleteFrom(int $instrumentId, int $timeframeId, DateTimeImmutable $from): int;
}
