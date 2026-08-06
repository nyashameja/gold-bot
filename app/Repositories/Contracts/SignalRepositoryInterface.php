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

    /** @return list<array<string,mixed>> */
    public function scores(int $signalId): array;

    public function markTargetHit(int $signalId, int $level, DateTimeImmutable $at, float $price): void;

    public function updateState(int $signalId, SignalState $state, DateTimeImmutable $at, ?string $closeReason = null, ?float $realisedR = null): void;
}
