<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;
use GoldBot\Domain\Performance\MetricSet;
use GoldBot\Domain\Performance\PeriodType;
use GoldBot\Domain\Performance\SnapshotScope;
use GoldBot\Domain\Performance\TradeOutcome;

/**
 * Storage for the performance rollups (docs/02 §9).
 *
 * Snapshots are a projection. Nothing here adjusts a running total — a period
 * is written whole or not at all, so a rebuild always converges on the same
 * answer no matter how many times it runs or what state it found.
 */
interface PerformanceSnapshotRepositoryInterface
{
    /**
     * The closed signals in a window, as outcomes for the calculator.
     *
     * This is where the traded-only rule is applied: cancelled and expired
     * signals never held a position and are excluded here, once, rather than
     * in every caller.
     *
     * @return list<TradeOutcome>
     */
    public function outcomes(DateTimeImmutable $from, DateTimeImmutable $until, SnapshotScope $scope): array;

    /** Write a period's metrics, replacing whatever was there. */
    public function store(
        PeriodType $period,
        DateTimeImmutable $start,
        SnapshotScope $scope,
        MetricSet $metrics,
        DateTimeImmutable $computedAt
    ): void;

    /** @return array{scope:SnapshotScope,metrics:MetricSet,computed_at:string}|null */
    public function find(PeriodType $period, DateTimeImmutable $start, SnapshotScope $scope): ?array;

    /**
     * A time series of one period type for one scope, oldest first.
     *
     * @return list<array{start:string,end:string,metrics:MetricSet}>
     */
    public function series(PeriodType $period, SnapshotScope $scope, int $limit = 90): array;

    /**
     * Every scope's snapshot for one period — the breakdown tables.
     *
     * @return list<array{scope:SnapshotScope,metrics:MetricSet}>
     */
    public function forPeriod(PeriodType $period, DateTimeImmutable $start): array;

    /** The close times bounding the traded record, for a full rebuild. */
    public function tradedRange(): ?array;

    /** Distinct dimension values actually present in the traded record. */
    public function knownScopes(): array;

    public function deleteAll(): int;

    public function count(): int;
}
