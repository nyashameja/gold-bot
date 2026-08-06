<?php

declare(strict_types=1);

namespace GoldBot\Integrations\Calendar\ForexFactory;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Domain\Calendar\EventImpact;
use GoldBot\Integrations\Calendar\CalendarException;
use GoldBot\Integrations\Calendar\EventIdentityHasher;

/**
 * Translates the ForexFactory weekly feed into domain events.
 *
 * The feed is a JSON array at its root — not an object — and each entry
 * carries: title, country (which is actually the currency code), date (ISO
 * 8601 with an offset), impact, forecast and previous. `actual` appears only
 * after a release.
 *
 * Separated from the provider so mapping is testable against recorded
 * responses with no network.
 *
 * IMPORTANT: this mapping is derived from the feed's documented shape, not
 * from observed traffic — the build environment cannot reach the host. It
 * must be validated against a live response before Phase 5 is signed off
 * (ADR-12, caveat 2).
 */
final class ForexFactoryMapper
{
    public const SOURCE = 'FOREX_FACTORY';

    public function __construct(private readonly EventIdentityHasher $hasher)
    {
    }

    /**
     * @param mixed $payload Decoded JSON — expected to be a list.
     * @return list<EconomicEvent>
     */
    public function toEvents(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw CalendarException::badResponse('ForexFactory feed did not decode to an array.');
        }

        $events = [];

        foreach ($payload as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $event = $this->toEvent($entry, (string) $index);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        usort(
            $events,
            static fn (EconomicEvent $a, EconomicEvent $b): int => $a->scheduledAt <=> $b->scheduledAt
        );

        return $events;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function toEvent(array $entry, string $index): ?EconomicEvent
    {
        $title = $this->text($entry['title'] ?? null);
        $currency = $this->text($entry['country'] ?? null); // "country" holds the currency code.
        $rawDate = $this->text($entry['date'] ?? null);

        if ($title === null || $currency === null || $rawDate === null) {
            // A malformed entry is skipped rather than failing the import: one
            // bad row upstream must not cost the whole week's archive, which
            // cannot be re-fetched later (ADR-15).
            return null;
        }

        $local = $this->parseDate($rawDate);

        if ($local === null) {
            return null;
        }

        $impact = EventImpact::parse($this->text($entry['impact'] ?? null));

        // "All Day", "Tentative" and holidays have no meaningful minute; the
        // feed expresses them as midnight. That test must run against the
        // feed's OWN offset, before conversion — 00:00 Eastern is 04:00 UTC,
        // so checking after conversion would classify every all-day entry as
        // precisely timed and blackout a four-hour-wrong window.
        $approximate = $impact === EventImpact::Holiday
            || $local->format('H:i:s') === '00:00:00';

        $scheduledAt = $local->setTimezone(new DateTimeZone('UTC'));

        return new EconomicEvent(
            providerEventId:   $this->hasher->hash(self::SOURCE, $currency, $title, $scheduledAt),
            source:            self::SOURCE,
            currency:          strtoupper($currency),
            title:             $title,
            impact:            $impact,
            scheduledAt:       $scheduledAt,
            timeIsApproximate: $approximate,
            country:           null,
            actual:            $this->text($entry['actual'] ?? null),
            forecast:          $this->text($entry['forecast'] ?? null),
            previous:          $this->text($entry['previous'] ?? null),
            revisedFrom:       $this->text($entry['revised'] ?? null),
            unit:              null,
            detailUrl:         $this->text($entry['url'] ?? null)
        );
    }

    /**
     * Parse the feed's ISO 8601 timestamp, preserving its own offset.
     *
     * Returned in the feed's timezone rather than UTC so the caller can test
     * for a midnight "all day" marker before converting. The offset is always
     * honoured — treating the string as UTC would shift every event by four or
     * five hours, and every blackout window with it.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
