<?php

declare(strict_types=1);

namespace GoldBot\Integrations\Calendar;

use DateTimeImmutable;

/**
 * Derives a stable identifier for events whose source supplies none (ADR-16).
 *
 * ForexFactory's feed has no id field at all, so the UNIQUE(source,
 * provider_event_id) constraint that makes import idempotent has nothing to
 * hold onto. This computes one deterministically from the event's own
 * identity: source, currency, normalised title and scheduled time.
 *
 * The tradeoff is honest and worth stating: if a provider *reschedules* an
 * event, the changed timestamp yields a new key and the old row lingers. That
 * is why the import reconciles — an unreleased event that stops appearing in
 * the feed is retired (see CalendarService). Rescheduling is rare, but a
 * phantom event would suppress real signals for no reason.
 *
 * Pure and deterministic: the same event must hash identically on every poll,
 * forever, or the archive fills with duplicates.
 */
final class EventIdentityHasher
{
    /**
     * A 40-character hex digest, matching the CHAR(40) column.
     */
    public function hash(string $source, string $currency, string $title, DateTimeImmutable $scheduledAt): string
    {
        return sha1(implode('|', [
            strtoupper($source),
            strtoupper(trim($currency)),
            $this->normaliseTitle($title),
            // Minute precision: providers sometimes shift the published second
            // between polls, which must not mint a new identity.
            $scheduledAt->format('Y-m-d H:i'),
        ]));
    }

    /**
     * Normalise a title so cosmetic differences do not change identity.
     *
     * Providers vary punctuation, spacing and case between polls, and
     * frequently append a period tag — "CPI y/y", "CPI (YoY)". Those are the
     * same release, and treating them as different would duplicate every
     * event whose label was tidied upstream.
     */
    public function normaliseTitle(string $title): string
    {
        $normalised = mb_strtolower(trim($title));

        // Collapse the common period notations to one form.
        $normalised = preg_replace(
            [
                '/\b(y\/y|yoy|year[- ]over[- ]year)\b/u',
                '/\b(m\/m|mom|month[- ]over[- ]month)\b/u',
                '/\b(q\/q|qoq|quarter[- ]over[- ]quarter)\b/u',
            ],
            ['yoy', 'mom', 'qoq'],
            $normalised
        ) ?? $normalised;

        // Strip everything that is not a letter, digit or space, then collapse
        // runs of whitespace.
        $normalised = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalised) ?? $normalised;
        $normalised = preg_replace('/\s+/u', ' ', $normalised) ?? $normalised;

        return trim($normalised);
    }
}
