<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use DateTimeImmutable;
use GoldBot\Domain\Calendar\EconomicEvent;

interface EconomicEventRepositoryInterface
{
    /**
     * Insert or update events, keyed on (source, provider_event_id).
     *
     * @param list<EconomicEvent> $events
     * @return array{inserted:int,updated:int}
     */
    public function upsertMany(array $events, DateTimeImmutable $seenAt): array;

    /**
     * Retire unreleased events in a window that the latest poll no longer
     * listed — they were rescheduled or cancelled (ADR-16).
     *
     * Released events are never retired: they are history.
     *
     * @param list<string> $seenProviderIds
     * @return int Rows retired.
     */
    public function retireMissing(
        string $source,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $seenProviderIds,
        DateTimeImmutable $retiredAt
    ): int;

    /**
     * Active events in a window, ordered by time.
     *
     * @param list<string> $currencies Empty means all.
     * @return list<EconomicEvent>
     */
    public function between(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $currencies = [],
        ?string $minimumImpact = null
    ): array;

    /** The next upcoming event, for the dashboard widget. */
    public function nextUpcoming(DateTimeImmutable $after, array $currencies = [], ?string $minimumImpact = null): ?EconomicEvent;

    public function count(): int;

    /** Earliest archived event — the boundary the backtester must respect. */
    public function earliestScheduledAt(): ?DateTimeImmutable;
}
