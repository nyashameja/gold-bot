<?php

declare(strict_types=1);

namespace GoldBot\Integrations\Calendar\ForexFactory;

use DateTimeImmutable;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Infrastructure\Http\ApiBudget;
use GoldBot\Integrations\Calendar\CalendarException;
use GoldBot\Integrations\Calendar\EconomicCalendarProviderInterface;
use Paragon\Core\Http\HttpClient;
use Paragon\Core\Logging\LoggerInterface;

/**
 * ForexFactory weekly calendar feed — the primary source (ADR-12).
 *
 * Free, unauthenticated, and it publishes consensus forecast, which is the
 * field a news filter actually needs and the one most free sources omit.
 *
 * It exposes a rolling three-week view (last, this, next) and nothing more.
 * That is the constraint behind ADR-15: this provider cannot answer a query
 * about last year, so the local archive is the only history that will exist.
 */
final class ForexFactoryProvider implements EconomicCalendarProviderInterface
{
    public const CODE = 'FOREX_FACTORY';

    private const FEEDS = [
        'last'  => '/ff_calendar_lastweek.json',
        'this'  => '/ff_calendar_thisweek.json',
        'next'  => '/ff_calendar_nextweek.json',
    ];

    public function __construct(
        private readonly HttpClient $http,
        private readonly ForexFactoryMapper $mapper,
        private readonly ApiBudget $budget,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl = 'https://nfs.faireconomy.media',
        private readonly bool $enabled = true
    ) {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function events(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $collected = [];
        $failures = [];

        foreach ($this->feedsFor($from, $to) as $feed) {
            try {
                foreach ($this->fetchFeed($feed) as $event) {
                    if ($event->scheduledAt < $from || $event->scheduledAt > $to) {
                        continue;
                    }

                    // The three feeds overlap at week boundaries; the synthetic
                    // id makes de-duplication exact rather than approximate.
                    $collected[$event->providerEventId] = $event;
                }
            } catch (CalendarException $e) {
                // One feed failing must not lose the others. The last-week feed
                // is the least important — it only carries revisions.
                $failures[] = $feed . ': ' . $e->getMessage();

                $this->logger->warning('Calendar feed fetch failed', [
                    'event'    => 'calendar.feed_failed',
                    'provider' => self::CODE,
                    'feed'     => $feed,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        if ($collected === [] && $failures !== []) {
            throw CalendarException::transport(
                'All ForexFactory feeds failed: ' . implode('; ', $failures)
            );
        }

        $events = array_values($collected);

        usort(
            $events,
            static fn (EconomicEvent $a, EconomicEvent $b): int => $a->scheduledAt <=> $b->scheduledAt
        );

        return $events;
    }

    /**
     * Which weekly feeds could overlap the requested window.
     *
     * Fetching all three every poll would triple the request count for data
     * that rarely changes outside the current week.
     *
     * @return list<string>
     */
    private function feedsFor(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $now = new DateTimeImmutable('now', $from->getTimezone());
        $feeds = [self::FEEDS['this']];

        if ($from < $now->modify('monday this week')) {
            array_unshift($feeds, self::FEEDS['last']);
        }

        if ($to > $now->modify('sunday this week')->setTime(23, 59, 59)) {
            $feeds[] = self::FEEDS['next'];
        }

        return $feeds;
    }

    /** @return list<EconomicEvent> */
    private function fetchFeed(string $path): array
    {
        if (!$this->budget->canSpend(self::CODE)) {
            throw new CalendarException('ForexFactory request budget exhausted; deferring.', retryable: false);
        }

        $response = $this->http->get($this->baseUrl . $path);

        $this->budget->record(self::CODE, $path, $response);

        if (!$response->isSuccess()) {
            throw new CalendarException(
                sprintf(
                    'ForexFactory %s failed (HTTP %d)%s',
                    $path,
                    $response->status,
                    $response->error === null ? '' : ': ' . $response->error
                ),
                retryable: $response->isRetryable(),
                httpStatus: $response->status
            );
        }

        $decoded = json_decode($response->body, true);

        if (!is_array($decoded)) {
            throw CalendarException::badResponse(
                sprintf('ForexFactory %s returned a non-JSON body.', $path),
                $response->status
            );
        }

        return $this->mapper->toEvents($decoded);
    }
}
