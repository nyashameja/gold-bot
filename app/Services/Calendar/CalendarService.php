<?php

declare(strict_types=1);

namespace GoldBot\Services\Calendar;

use DateTimeImmutable;
use GoldBot\Core\Database;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Integrations\Calendar\CompositeCalendarProvider;
use GoldBot\Integrations\Calendar\EventIdentityHasher;
use GoldBot\Repositories\Contracts\EconomicEventRepositoryInterface;

/**
 * Imports and archives economic events.
 *
 * The archive is the product here, not a cache. The upstream feed exposes a
 * rolling three weeks and nothing more, so `economic_events` is the only
 * history that will ever exist and it begins the day this first runs
 * (ADR-15). That is why this phase was pulled ahead of its dependency order.
 */
final class CalendarService
{
    public function __construct(
        private readonly CompositeCalendarProvider $provider,
        private readonly EconomicEventRepositoryInterface $events,
        private readonly EventIdentityHasher $hasher,
        private readonly Database $database,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Import the provider window and reconcile it against what is stored.
     *
     * @return array{fetched:int,inserted:int,updated:int,retired:int,categorised:int}
     */
    public function import(int $daysBack = 7, int $daysForward = 14): array
    {
        $now = $this->clock->now();
        $from = $now->modify(sprintf('-%d days', max(0, $daysBack)));
        $to = $now->modify(sprintf('+%d days', max(1, $daysForward)));

        $fetched = $this->provider->events($from, $to);

        // Categorise before writing, so blackout windows apply from the first
        // poll rather than only after a later pass.
        $categorised = 0;
        $prepared = [];

        foreach ($fetched as $event) {
            $categoryId = $this->categoryFor($event);

            if ($categoryId !== null) {
                $categorised++;
            }

            $prepared[] = new EconomicEvent(
                providerEventId:   $event->providerEventId,
                source:            $event->source,
                currency:          $event->currency,
                title:             $event->title,
                impact:            $event->impact,
                scheduledAt:       $event->scheduledAt,
                timeIsApproximate: $event->timeIsApproximate,
                country:           $event->country,
                actual:            $event->actual,
                forecast:          $event->forecast,
                previous:          $event->previous,
                revisedFrom:       $event->revisedFrom,
                unit:              $event->unit,
                detailUrl:         $event->detailUrl,
                categoryId:        $categoryId
            );
        }

        $result = $this->events->upsertMany($prepared, $now);

        // Reconcile per source: an unreleased event that has stopped appearing
        // was rescheduled or cancelled, and a phantom would suppress real
        // signals forever (ADR-16). Only sources that actually returned data
        // are reconciled — a failed provider must not retire its own history.
        $retired = 0;
        $bySource = [];

        foreach ($prepared as $event) {
            $bySource[$event->source][] = $event->providerEventId;
        }

        foreach ($bySource as $source => $ids) {
            $retired += $this->events->retireMissing($source, $from, $to, $ids, $now);
        }

        $this->logger->info('Calendar imported', [
            'event'       => 'calendar.imported',
            'providers'   => $this->provider->enabledProviders(),
            'from'        => $from->format('c'),
            'to'          => $to->format('c'),
            'fetched'     => count($fetched),
            'inserted'    => $result['inserted'],
            'updated'     => $result['updated'],
            'retired'     => $retired,
            'categorised' => $categorised,
        ]);

        return [
            'fetched'     => count($fetched),
            'inserted'    => $result['inserted'],
            'updated'     => $result['updated'],
            'retired'     => $retired,
            'categorised' => $categorised,
        ];
    }

    /**
     * Match an event title to a category.
     *
     * Categories carry the blackout windows, so this is what turns "no signals
     * within 30 minutes of NFP" into behaviour. Patterns live in the database
     * rather than in code, so an operator can add one without a deploy.
     */
    private function categoryFor(EconomicEvent $event): ?int
    {
        $normalised = $this->hasher->normaliseTitle($event->title);

        foreach ($this->categories() as $category) {
            foreach ($category['patterns'] as $pattern) {
                if ($pattern !== '' && str_contains($normalised, $pattern)) {
                    return $category['id'];
                }
            }
        }

        return null;
    }

    /** @var list<array{id:int,patterns:list<string>}>|null */
    private ?array $categoryCache = null;

    /** @return list<array{id:int,patterns:list<string>}> */
    private function categories(): array
    {
        if ($this->categoryCache !== null) {
            return $this->categoryCache;
        }

        $rows = $this->database->select(
            'SELECT id, code, match_patterns FROM event_categories WHERE is_active = 1 ORDER BY id'
        );

        $categories = [];

        foreach ($rows as $row) {
            $patterns = json_decode((string) ($row['match_patterns'] ?? '[]'), true);

            $categories[] = [
                'id'       => (int) $row['id'],
                'patterns' => is_array($patterns)
                    ? array_map(fn (mixed $p): string => $this->hasher->normaliseTitle((string) $p), $patterns)
                    : [],
            ];
        }

        return $this->categoryCache = $categories;
    }

    /**
     * The earliest archived event.
     *
     * The backtester consults this before running a news-filtered strategy: a
     * period predating the archive would run with no filter at all and report
     * better results than the live system could have produced (ADR-15).
     */
    public function archiveStartsAt(): ?DateTimeImmutable
    {
        return $this->events->earliestScheduledAt();
    }
}
