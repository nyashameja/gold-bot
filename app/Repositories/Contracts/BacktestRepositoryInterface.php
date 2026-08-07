<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

/**
 * Storage for backtest runs and their trades.
 *
 * Deliberately separate from SignalRepositoryInterface. A hypothetical run and
 * a live signal must never share a table: every performance figure the
 * platform reports is computed from `signals`, and once the two are mixed they
 * cannot be told apart by inspection.
 */
interface BacktestRepositoryInterface
{
    /**
     * Persist a completed run and its trades, atomically.
     *
     * @param array<string,mixed> $run The BacktestRunner's result.
     * @return string The run's UUID.
     */
    public function store(array $run, ?int $userId = null, ?string $label = null, ?string $notes = null): string;

    /** @return list<array<string,mixed>> Newest first. */
    public function recent(int $limit = 25): array;

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array;

    /** @return list<array<string,mixed>> */
    public function trades(int $backtestId): array;

    /**
     * Score band against outcome for one run — the distribution that sets a
     * threshold empirically rather than by intuition (ADR-04).
     *
     * @return list<array{low:int,total:int,wins:int,losses:int,net_r:float}>
     */
    public function scoreBands(int $backtestId, int $width = 5): array;

    public function delete(string $uuid): bool;

    public function count(): int;
}
