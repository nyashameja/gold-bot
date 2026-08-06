<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;
use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Signal\SignalEventType;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Domain\Strategy\SignalResult;

interface SignalRepositoryInterface
{
    /**
     * Persist a signal, its targets, its pillar scores and its GENERATED
     * event — atomically.
     *
     * Partial persistence would leave a signal with no scores to explain it,
     * or targets with no signal.
     *
     * @return int The new signal id.
     */
    public function create(
        SignalResult $result,
        int $strategyId,
        int $configId,
        ?int $runId,
        int $instrumentId,
        int $timeframeId,
        DateTimeImmutable $generatedAt,
        ?DateTimeImmutable $expiresAt,
        ?string $sessionCode,
        ?string $marketRegime
    ): int;

    /** Append an event and move the projection, if the transition is legal. */
    public function recordEvent(
        int $signalId,
        SignalEventType $event,
        DateTimeImmutable $occurredAt,
        ?float $price = null,
        ?string $notes = null,
        string $triggeredBy = 'SYSTEM',
        ?int $userId = null
    ): bool;

    /** @return array<string,mixed>|null */
    public function find(int $signalId): ?array;

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array;

    /** @return list<array<string,mixed>> Signals still capable of changing. */
    public function open(?int $instrumentId = null): array;

    public function countOpen(): int;

    public function hasOpenInDirection(int $instrumentId, Direction $direction): bool;

    public function countSince(int $instrumentId, Direction $direction, DateTimeImmutable $since): int;

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50, int $offset = 0): array;

    /** @return list<array<string,mixed>> */
    public function events(int $signalId): array;

    /** @return list<array<string,mixed>> */
    public function targets(int $signalId): array;

    /**
     * Targets for many signals at once, keyed by signal id.
     *
     * Exists so a list page can show every signal's targets in two queries
     * instead of one per row. The single-signal method above is fine on a
     * detail page and wrong on an index.
     *
     * @param list<int> $signalIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function targetsFor(array $signalIds): array;

    /** @return list<array<string,mixed>> */
    public function scores(int $signalId): array;

    /**
     * A filtered, paginated page of signals.
     *
     * Filter keys: state, direction, strategy_id, timeframe_id, instrument_id,
     * since, until. Unknown keys are ignored rather than interpolated.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function paginate(array $filters, int $limit, int $offset): array;

    /** @param array<string,mixed> $filters Same keys as paginate(). */
    public function countMatching(array $filters): int;

    public function markTargetHit(int $signalId, int $level, DateTimeImmutable $at, float $price): void;

    public function updateState(int $signalId, SignalState $state, DateTimeImmutable $at, ?string $closeReason = null, ?float $realisedR = null): void;
}
