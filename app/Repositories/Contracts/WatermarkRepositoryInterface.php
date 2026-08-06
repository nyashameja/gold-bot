<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;

/**
 * Per-stage ingest progress (docs/02 §5).
 *
 * This is what makes "only analyse new candles" structural rather than a
 * convention someone has to remember. Each stage advances independently, so a
 * failure in one does not force another to recompute, and any single stage can
 * be replayed by rewinding one row.
 */
interface WatermarkRepositoryInterface
{
    public const STAGE_INGEST     = 'INGEST';
    public const STAGE_INDICATORS = 'INDICATORS';
    public const STAGE_STRUCTURE  = 'STRUCTURE';
    public const STAGE_LEVELS     = 'LEVELS';
    public const STAGE_STRATEGY   = 'STRATEGY';

    public function lastProcessed(int $instrumentId, int $timeframeId, string $stage): ?DateTimeImmutable;

    public function advance(
        int $instrumentId,
        int $timeframeId,
        string $stage,
        DateTimeImmutable $openTime,
        ?int $candleId = null
    ): void;

    /** Rewind (or clear) a stage so it reprocesses. */
    public function rewind(int $instrumentId, int $timeframeId, string $stage, ?DateTimeImmutable $to = null): void;

    /** @return list<array<string,mixed>> */
    public function all(): array;
}
