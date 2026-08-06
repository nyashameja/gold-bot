<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;
use GoldBot\Domain\Strategy\StrategyConfig;

interface StrategyRepositoryInterface
{
    /**
     * Every strategy, including the disabled ones.
     *
     * The engine must use enabled(); running a disabled strategy is exactly
     * the bug that separation prevents. This method exists for the UI, which
     * has to be able to show a strategy in order to turn it on.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array;

    /** @return list<array<string,mixed>> Enabled strategies. */
    public function enabled(): array;

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array;

    /** The active config for a strategy, or null if none has been activated. */
    public function activeConfig(int $strategyId): ?StrategyConfig;

    public function configById(int $configId): ?StrategyConfig;

    /**
     * Store a new immutable config version and make it active (ADR-06).
     *
     * @param array<string,mixed> $config
     * @return int The new version's id.
     */
    public function addConfigVersion(int $strategyId, array $config, ?string $notes = null, ?int $userId = null): int;

    /** @return list<array<string,mixed>> Version history, newest first. */
    public function configHistory(int $strategyId): array;

    /**
     * Record an evaluation — written for every run, not only for signals.
     *
     * @param array<string,mixed> $features
     * @return int The run id, or 0 when this candle was already evaluated.
     */
    public function recordRun(
        int $strategyId,
        int $configId,
        int $instrumentId,
        int $timeframeId,
        ?int $candleId,
        DateTimeImmutable $candleOpenTime,
        DateTimeImmutable $evaluatedAt,
        ?string $direction,
        float $score,
        bool $passed,
        ?string $rejectionReason,
        array $features,
        int $durationMs
    ): int;

    public function hasRunFor(int $strategyId, int $instrumentId, int $timeframeId, DateTimeImmutable $candleOpenTime): bool;

    /** @return list<array<string,mixed>> */
    public function recentRuns(int $strategyId, int $limit = 50): array;

    /**
     * Score distribution over recent runs.
     *
     * This is what makes threshold tuning empirical rather than a guess: it
     * shows how many setups sit just below the current cut-off.
     *
     * @return array<string,int> Score bucket => count.
     */
    public function scoreDistribution(int $strategyId, DateTimeImmutable $since): array;
}
