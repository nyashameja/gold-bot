<?php

declare(strict_types=1);

namespace GoldBot\Integrations\Calendar;

use DateTimeImmutable;
use GoldBot\Domain\Calendar\EconomicEvent;

/**
 * Economic calendar source (ADR-12).
 *
 * The port that made replacing Trading Economics a non-event when no
 * subscription was available. Two free adapters implement it: ForexFactory as
 * primary (it publishes consensus forecast, which most free sources omit) and
 * FRED as authoritative corroboration for US releases.
 */
interface EconomicCalendarProviderInterface
{
    /**
     * Events in a date window, ordered by scheduled time.
     *
     * Providers exposing only a fixed window — ForexFactory publishes one week
     * — return what overlaps and ignore the rest. The caller must not assume
     * the full range came back; that is precisely why the archive exists
     * (ADR-15).
     *
     * @return list<EconomicEvent>
     * @throws CalendarException
     */
    public function events(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /** Provider code, matching api_providers.code. */
    public function code(): string;

    /** Whether this provider is configured and enabled. */
    public function isEnabled(): bool;
}
