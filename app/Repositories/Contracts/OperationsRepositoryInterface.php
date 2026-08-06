<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;

/**
 * Read side of the operations tables (docs/02 §9).
 *
 * Deliberately read-only. The writers — ApiBudget, TaskDispatcher, the health
 * checker — own their inserts and are on the hot path; giving the dashboard a
 * separate interface keeps its aggregate queries from creeping into code that
 * runs every minute.
 *
 * Every method here is a single aggregate query. The API Usage and System
 * Health pages show hundreds of rows' worth of information, and doing that by
 * fetching rows and summing them in PHP is how an index page acquires an N+1.
 */
interface OperationsRepositoryInterface
{
    /**
     * Providers with their limits and today's consumption, in one query.
     *
     * @return list<array<string,mixed>>
     */
    public function providerUsage(DateTimeImmutable $now): array;

    /**
     * Calls bucketed by hour for the trailing window — the API Usage chart.
     *
     * @return list<array{bucket:string,calls:int,credits:int,failures:int,avg_ms:float|null}>
     */
    public function usageByHour(DateTimeImmutable $since, DateTimeImmutable $until, ?int $providerId = null): array;

    /**
     * Per-endpoint totals for the window, heaviest first.
     *
     * @return list<array<string,mixed>>
     */
    public function usageByEndpoint(DateTimeImmutable $since, int $limit = 20): array;

    /**
     * @return list<array<string,mixed>>
     */
    public function recentApiFailures(int $limit = 20): array;

    /**
     * The schedule with each task's latest run attached — one query, not one
     * per task.
     *
     * @return list<array<string,mixed>>
     */
    public function scheduledTasks(): array;

    /**
     * @return list<array<string,mixed>>
     */
    public function recentTaskRuns(int $limit = 50, ?string $taskCode = null): array;

    /**
     * Success/failure counts per task over the window, for the health page.
     *
     * @return list<array<string,mixed>>
     */
    public function taskReliability(DateTimeImmutable $since): array;

    /**
     * The most recent health check per component.
     *
     * @return list<array<string,mixed>>
     */
    public function latestHealthChecks(): array;

    /**
     * @return list<array<string,mixed>>
     */
    public function recentLogs(int $limit = 50, ?string $level = null, ?string $channel = null): array;

    /**
     * @return list<array{level:string,total:int}>
     */
    public function logLevelCounts(DateTimeImmutable $since): array;

    /**
     * Row counts and on-disk size per table — the growth panel on System
     * Health. Read from information_schema, which is an estimate for InnoDB;
     * that is fine for a trend and far cheaper than COUNT(*) per table.
     *
     * @return list<array{table_name:string,row_estimate:int,size_bytes:int}>
     */
    public function tableSizes(): array;

    public function recordHealthCheck(
        string $component,
        string $status,
        ?string $message,
        ?array $metrics,
        ?int $durationMs,
        DateTimeImmutable $checkedAt
    ): void;
}
