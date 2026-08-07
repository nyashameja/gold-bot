<?php

declare(strict_types=1);

namespace GoldBot\Integrations\Calendar\Fred;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Domain\Calendar\EventImpact;
use GoldBot\Infrastructure\Http\ApiBudget;
use GoldBot\Integrations\Calendar\CalendarException;
use GoldBot\Integrations\Calendar\EconomicCalendarProviderInterface;
use GoldBot\Integrations\Calendar\EventIdentityHasher;
use Paragon\Core\Http\HttpClient;
use Paragon\Core\Logging\LoggerInterface;

/**
 * FRED release schedule — corroborating source for US releases (ADR-12).
 *
 * Authoritative, because it is the issuing institution: when FRED and
 * ForexFactory disagree on whether a release happened or when, FRED wins.
 *
 * It carries no consensus forecast, so it does not replace ForexFactory — and
 * it publishes release *dates*, not times. Every FRED event is therefore
 * marked approximate, which widens its blackout window rather than pretending
 * to a precision the source never claimed.
 */
final class FredProvider implements EconomicCalendarProviderInterface
{
    public const CODE = 'FRED';

    public const SOURCE = 'FRED';

    /**
     * Releases worth tracking for gold, and their impact.
     *
     * FRED publishes hundreds of release schedules; almost none move XAU/USD.
     * Filtering here rather than importing everything keeps the archive
     * meaningful and the blackout filter from suppressing the entire session.
     */
    private const TRACKED = [
        'consumer price index'                 => EventImpact::High,
        'employment situation'                 => EventImpact::High,
        'gross domestic product'               => EventImpact::High,
        'producer price index'                 => EventImpact::Medium,
        'personal income and outlays'          => EventImpact::High,
        'advance monthly sales for retail'     => EventImpact::Medium,
        'retail sales'                         => EventImpact::Medium,
        'h.15 selected interest rates'         => EventImpact::Medium,
        'federal open market committee'        => EventImpact::High,
        'fomc'                                 => EventImpact::High,
    ];

    public function __construct(
        private readonly HttpClient $http,
        private readonly EventIdentityHasher $hasher,
        private readonly ApiBudget $budget,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey = '',
        private readonly string $baseUrl = 'https://api.stlouisfed.org/fred',
        private readonly bool $enabled = true
    ) {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function isEnabled(): bool
    {
        // A key is mandatory, so an unconfigured FRED is disabled rather than
        // failing every poll with a 400.
        return $this->enabled && $this->apiKey !== '';
    }

    public function events(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        if (!$this->budget->canSpend(self::CODE)) {
            throw new CalendarException('FRED request budget exhausted; deferring.', retryable: false);
        }

        $endpoint = '/releases/dates';

        $response = $this->http->get($this->baseUrl . $endpoint, [
            'api_key'                             => $this->apiKey,
            'file_type'                           => 'json',
            'realtime_start'                      => $from->format('Y-m-d'),
            'realtime_end'                        => $to->format('Y-m-d'),
            'include_release_dates_with_no_data'  => 'true',
            'sort_order'                          => 'asc',
            'limit'                               => 1000,
        ]);

        $this->budget->record(self::CODE, $endpoint, $response);

        if (!$response->isSuccess()) {
            throw new CalendarException(
                sprintf(
                    'FRED %s failed (HTTP %d)%s',
                    $endpoint,
                    $response->status,
                    $response->error === null ? '' : ': ' . $response->error
                ),
                retryable: $response->isRetryable(),
                httpStatus: $response->status
            );
        }

        $payload = $response->json();

        if ($payload === null) {
            throw CalendarException::badResponse('FRED returned a non-JSON body.', $response->status);
        }

        return $this->toEvents($payload, $from, $to);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<EconomicEvent>
     */
    public function toEvents(array $payload, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $dates = $payload['release_dates'] ?? null;

        if (!is_array($dates)) {
            throw CalendarException::badResponse('FRED response has no `release_dates` array.');
        }

        $utc = new DateTimeZone('UTC');
        $events = [];

        foreach ($dates as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = is_scalar($entry['release_name'] ?? null) ? trim((string) $entry['release_name']) : '';
            $date = is_scalar($entry['date'] ?? null) ? trim((string) $entry['date']) : '';

            if ($name === '' || $date === '') {
                continue;
            }

            $impact = $this->impactFor($name);

            if ($impact === null) {
                continue; // Not a release that moves gold.
            }

            // Date only. 12:30 UTC is when most US data actually lands, but we
            // do not know that from this source — hence approximate.
            $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' 12:30:00', $utc);

            if ($scheduledAt === false || $scheduledAt < $from || $scheduledAt > $to) {
                continue;
            }

            $events[] = new EconomicEvent(
                providerEventId:   $this->hasher->hash(self::SOURCE, 'USD', $name, $scheduledAt),
                source:            self::SOURCE,
                currency:          'USD',
                title:             $name,
                impact:            $impact,
                scheduledAt:       $scheduledAt,
                timeIsApproximate: true,
                country:           'United States'
            );
        }

        usort(
            $events,
            static fn (EconomicEvent $a, EconomicEvent $b): int => $a->scheduledAt <=> $b->scheduledAt
        );

        return $events;
    }

    private function impactFor(string $releaseName): ?EventImpact
    {
        $needle = mb_strtolower($releaseName);

        foreach (self::TRACKED as $pattern => $impact) {
            if (str_contains($needle, $pattern)) {
                return $impact;
            }
        }

        return null;
    }
}
