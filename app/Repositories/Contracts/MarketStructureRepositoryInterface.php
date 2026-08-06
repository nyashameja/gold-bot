<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

/**
 * Read side of the stored structure analysis (docs/02 §6).
 *
 * StructureService owns the writes; the Live Market chart owns the reads, and
 * wants them shaped for overlay rendering rather than as domain objects. Kept
 * separate for the same reason as the operations repository: the writer runs
 * on a cron every few minutes and should not carry the dashboard's queries.
 */
interface MarketStructureRepositoryInterface
{
    /**
     * Active swing points and breaks, oldest first so the chart can draw them
     * in time order without re-sorting.
     *
     * @return list<array<string,mixed>>
     */
    public function points(int $instrumentId, int $timeframeId, int $limit = 60): array;

    /**
     * Active levels and zones for the chart's horizontal overlays.
     *
     * @return list<array<string,mixed>>
     */
    public function levels(int $instrumentId, int $timeframeId): array;

    /** The most recent structure break, which is what names the current bias. */
    public function lastBreak(int $instrumentId, int $timeframeId): ?array;
}
