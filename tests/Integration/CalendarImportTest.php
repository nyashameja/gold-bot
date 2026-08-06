<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Domain\Calendar\EventImpact;
use GoldBot\Infrastructure\Clock\FrozenClock;
use GoldBot\Integrations\Calendar\EventIdentityHasher;
use GoldBot\Repositories\Contracts\EconomicEventRepositoryInterface;
use GoldBot\Services\Calendar\NewsBlackoutService;

/**
 * Calendar persistence and the news filter.
 */
final class CalendarImportTest extends IntegrationTestCase
{
    private const SOURCE = 'TEST_FEED';

    private EconomicEventRepositoryInterface $events;

    private EventIdentityHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->db->tableExists('economic_events')) {
            self::markTestSkipped('Calendar schema not migrated.');
        }

        $this->events = $this->app->container()->get(EconomicEventRepositoryInterface::class);
        $this->hasher = $this->app->container()->get(EventIdentityHasher::class);

        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();

        parent::tearDown();
    }

    private function clear(): void
    {
        $this->db->run('DELETE FROM economic_events WHERE source = ?', [self::SOURCE]);
    }

    private function utc(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }

    private function event(
        string $title,
        string $at,
        EventImpact $impact = EventImpact::High,
        ?string $actual = null,
        ?string $forecast = null,
        bool $approximate = false,
        ?int $categoryId = null
    ): EconomicEvent {
        $scheduledAt = $this->utc($at);

        return new EconomicEvent(
            providerEventId:   $this->hasher->hash(self::SOURCE, 'USD', $title, $scheduledAt),
            source:            self::SOURCE,
            currency:          'USD',
            title:             $title,
            impact:            $impact,
            scheduledAt:       $scheduledAt,
            timeIsApproximate: $approximate,
            actual:            $actual,
            forecast:          $forecast,
            categoryId:        $categoryId
        );
    }

    // ── Idempotent import ────────────────────────────────────────────────────

    public function test_reimporting_the_same_events_creates_no_duplicates(): void
    {
        $events = [
            $this->event('Non-Farm Employment Change', '2026-08-07 12:30:00'),
            $this->event('CPI m/m', '2026-08-12 12:30:00'),
        ];

        $first = $this->events->upsertMany($events, $this->utc('2026-08-06 10:00:00'));
        self::assertSame(2, $first['inserted']);

        $second = $this->events->upsertMany($events, $this->utc('2026-08-06 10:30:00'));

        self::assertSame(0, $second['inserted'], 'A re-poll must insert nothing.');
        self::assertSame(2, $second['updated']);
        self::assertSame(2, $this->events->count());
    }

    /**
     * Releases are revised after publication. Re-polling recent history is how
     * those revisions reach the archive, so an update must land in place.
     */
    public function test_a_release_updates_in_place_when_the_actual_publishes(): void
    {
        $before = $this->event('Non-Farm Employment Change', '2026-08-07 12:30:00', forecast: '175K');
        $this->events->upsertMany([$before], $this->utc('2026-08-06 10:00:00'));

        $after = $this->event('Non-Farm Employment Change', '2026-08-07 12:30:00', actual: '162K', forecast: '175K');
        $this->events->upsertMany([$after], $this->utc('2026-08-07 13:00:00'));

        $stored = $this->events->between($this->utc('2026-08-07 00:00:00'), $this->utc('2026-08-08 00:00:00'));

        self::assertCount(1, $stored);
        self::assertSame('162K', $stored[0]->actual);
        self::assertTrue($stored[0]->isReleased());
    }

    /** first_seen_at records when we first observed the event, forever. */
    public function test_first_seen_survives_a_re_poll_but_last_seen_advances(): void
    {
        $event = $this->event('CPI m/m', '2026-08-12 12:30:00');

        $this->events->upsertMany([$event], $this->utc('2026-08-06 10:00:00'));
        $this->events->upsertMany([$event], $this->utc('2026-08-06 18:00:00'));

        $row = $this->db->selectOne(
            'SELECT first_seen_at, last_seen_at FROM economic_events WHERE source = ?',
            [self::SOURCE]
        );

        self::assertSame('2026-08-06 10:00:00', $row['first_seen_at']);
        self::assertSame('2026-08-06 18:00:00', $row['last_seen_at']);
    }

    // ── Reconciliation (ADR-16) ──────────────────────────────────────────────

    /**
     * The tradeoff the synthetic key forces: a rescheduled event mints a new
     * identity, so the old row must be retired. A phantom would suppress real
     * signals forever.
     */
    public function test_an_unreleased_event_that_disappears_is_retired(): void
    {
        $from = $this->utc('2026-08-01 00:00:00');
        $to = $this->utc('2026-08-31 00:00:00');

        $nfp = $this->event('Non-Farm Employment Change', '2026-08-07 12:30:00');
        $cpi = $this->event('CPI m/m', '2026-08-12 12:30:00');

        $this->events->upsertMany([$nfp, $cpi], $this->utc('2026-08-06 10:00:00'));

        // The next poll no longer lists CPI — it was rescheduled.
        $retired = $this->events->retireMissing(
            self::SOURCE,
            $from,
            $to,
            [$nfp->providerEventId],
            $this->utc('2026-08-06 10:30:00')
        );

        self::assertSame(1, $retired);

        $active = $this->events->between($from, $to);

        self::assertCount(1, $active);
        self::assertSame('Non-Farm Employment Change', $active[0]->title);
    }

    /** Released events are history and must never be retired (ADR-15). */
    public function test_a_released_event_is_never_retired(): void
    {
        $from = $this->utc('2026-08-01 00:00:00');
        $to = $this->utc('2026-08-31 00:00:00');

        $released = $this->event('Non-Farm Employment Change', '2026-08-07 12:30:00', actual: '162K');
        $this->events->upsertMany([$released], $this->utc('2026-08-07 13:00:00'));

        // A later poll's window no longer reaches back this far.
        $retired = $this->events->retireMissing(self::SOURCE, $from, $to, [], $this->utc('2026-08-20 10:00:00'));

        self::assertSame(0, $retired, 'Published history is not retired.');
        self::assertCount(1, $this->events->between($from, $to));
    }

    /** An event that reappears — the reschedule was reverted — comes back. */
    public function test_a_retired_event_is_revived_if_it_reappears(): void
    {
        $from = $this->utc('2026-08-01 00:00:00');
        $to = $this->utc('2026-08-31 00:00:00');

        $cpi = $this->event('CPI m/m', '2026-08-12 12:30:00');

        $this->events->upsertMany([$cpi], $this->utc('2026-08-06 10:00:00'));
        $this->events->retireMissing(self::SOURCE, $from, $to, [], $this->utc('2026-08-06 10:30:00'));

        self::assertCount(0, $this->events->between($from, $to));

        $this->events->upsertMany([$cpi], $this->utc('2026-08-06 11:00:00'));

        self::assertCount(1, $this->events->between($from, $to), 'retired_at must be cleared on reappearance.');
    }

    public function test_reconciliation_is_scoped_to_one_source(): void
    {
        $from = $this->utc('2026-08-01 00:00:00');
        $to = $this->utc('2026-08-31 00:00:00');

        $mine = $this->event('CPI m/m', '2026-08-12 12:30:00');
        $this->events->upsertMany([$mine], $this->utc('2026-08-06 10:00:00'));

        // A different source reconciling must not retire this source's rows —
        // otherwise one provider failing would wipe the other's archive.
        $retired = $this->events->retireMissing('OTHER_SOURCE', $from, $to, [], $this->utc('2026-08-06 10:30:00'));

        self::assertSame(0, $retired);
        self::assertCount(1, $this->events->between($from, $to));
    }

    // ── Querying ─────────────────────────────────────────────────────────────

    public function test_events_are_filtered_by_currency_and_impact(): void
    {
        $from = $this->utc('2026-08-01 00:00:00');
        $to = $this->utc('2026-08-31 00:00:00');

        $this->events->upsertMany([
            $this->event('High USD', '2026-08-07 12:30:00', EventImpact::High),
            $this->event('Medium USD', '2026-08-08 12:30:00', EventImpact::Medium),
            $this->event('Low USD', '2026-08-09 12:30:00', EventImpact::Low),
            $this->event('Holiday USD', '2026-08-10 12:30:00', EventImpact::Holiday),
        ], $this->utc('2026-08-06 10:00:00'));

        // HIGH includes HOLIDAY: alphabetical ordering would not (HIGH < LOW
        // < MEDIUM), which is why the filter uses an explicit set.
        $high = $this->events->between($from, $to, ['USD'], 'HIGH');
        self::assertCount(2, $high);

        self::assertCount(3, $this->events->between($from, $to, ['USD'], 'MEDIUM'));
        self::assertCount(4, $this->events->between($from, $to, ['USD']));
        self::assertCount(0, $this->events->between($from, $to, ['EUR']));
    }

    public function test_next_upcoming_skips_past_events(): void
    {
        $this->events->upsertMany([
            $this->event('Past', '2026-08-05 12:30:00'),
            $this->event('Soon', '2026-08-07 12:30:00'),
            $this->event('Later', '2026-08-12 12:30:00'),
        ], $this->utc('2026-08-06 10:00:00'));

        $next = $this->events->nextUpcoming($this->utc('2026-08-06 12:00:00'), ['USD'], 'HIGH');

        self::assertNotNull($next);
        self::assertSame('Soon', $next->title);
    }

    /**
     * The boundary the backtester must respect: running a news-filtered
     * strategy before this date would silently apply no filter and report
     * better results than the live system could produce (ADR-15).
     */
    public function test_the_archive_start_is_reported(): void
    {
        self::assertNull($this->events->earliestScheduledAt(), 'Empty archive.');

        $this->events->upsertMany([
            $this->event('Later', '2026-08-12 12:30:00'),
            $this->event('Earliest', '2026-08-03 12:30:00'),
        ], $this->utc('2026-08-06 10:00:00'));

        self::assertSame(
            '2026-08-03 12:30:00',
            $this->events->earliestScheduledAt()?->format('Y-m-d H:i:s')
        );
    }

    // ── The news filter ──────────────────────────────────────────────────────

    public function test_the_blackout_service_suppresses_around_a_high_impact_event(): void
    {
        /** @var NewsBlackoutService $blackout */
        $blackout = $this->app->container()->get(NewsBlackoutService::class);

        $this->events->upsertMany(
            [$this->event('Non-Farm Employment Change', '2026-08-07 12:30:00')],
            $this->utc('2026-08-06 10:00:00')
        );

        self::assertTrue($blackout->isBlackedOut($this->utc('2026-08-07 12:30:00')));
        self::assertTrue($blackout->isBlackedOut($this->utc('2026-08-07 12:15:00')));
        self::assertFalse($blackout->isBlackedOut($this->utc('2026-08-07 08:00:00')));
        self::assertFalse($blackout->isBlackedOut($this->utc('2026-08-06 12:30:00')));
    }

    /**
     * The rejection reason must name the event: "why did nothing fire?" is the
     * most common operational question, and "news" alone does not answer it.
     */
    public function test_the_blocking_event_is_identified_not_just_flagged(): void
    {
        /** @var NewsBlackoutService $blackout */
        $blackout = $this->app->container()->get(NewsBlackoutService::class);

        $this->events->upsertMany(
            [$this->event('Non-Farm Employment Change', '2026-08-07 12:30:00')],
            $this->utc('2026-08-06 10:00:00')
        );

        $active = $blackout->activeEvent($this->utc('2026-08-07 12:30:00'));

        self::assertNotNull($active);
        self::assertSame('Non-Farm Employment Change', $active->title);
    }

    public function test_a_medium_impact_event_does_not_blackout_by_default(): void
    {
        /** @var NewsBlackoutService $blackout */
        $blackout = $this->app->container()->get(NewsBlackoutService::class);

        $this->events->upsertMany(
            [$this->event('Core Retail Sales', '2026-08-07 12:30:00', EventImpact::Medium)],
            $this->utc('2026-08-06 10:00:00')
        );

        self::assertFalse(
            $blackout->isBlackedOut($this->utc('2026-08-07 12:30:00')),
            'The default minimum impact is HIGH.'
        );
    }

    public function test_an_approximate_event_blacks_out_a_wide_window(): void
    {
        /** @var NewsBlackoutService $blackout */
        $blackout = $this->app->container()->get(NewsBlackoutService::class);

        $this->events->upsertMany(
            [$this->event('FOMC Statement', '2026-08-07 12:30:00', approximate: true)],
            $this->utc('2026-08-06 10:00:00')
        );

        // Three hours out — well beyond the 30-minute default, inside the
        // 240-minute padding for an unknown time.
        self::assertTrue($blackout->isBlackedOut($this->utc('2026-08-07 09:30:00')));
        self::assertFalse($blackout->isBlackedOut($this->utc('2026-08-07 02:00:00')));
    }

    public function test_the_filter_can_be_switched_off(): void
    {
        /** @var \GoldBot\Repositories\Contracts\SettingsRepositoryInterface $settings */
        $settings = $this->app->container()->get(\GoldBot\Repositories\Contracts\SettingsRepositoryInterface::class);

        $this->events->upsertMany(
            [$this->event('Non-Farm Employment Change', '2026-08-07 12:30:00')],
            $this->utc('2026-08-06 10:00:00')
        );

        $settings->set('news.filter_enabled', false);

        try {
            /** @var NewsBlackoutService $blackout */
            $blackout = $this->app->container()->get(NewsBlackoutService::class);

            self::assertFalse($blackout->isBlackedOut($this->utc('2026-08-07 12:30:00')));
        } finally {
            $settings->set('news.filter_enabled', true);
        }
    }

    public function test_a_retired_event_no_longer_blacks_out(): void
    {
        /** @var NewsBlackoutService $blackout */
        $blackout = $this->app->container()->get(NewsBlackoutService::class);

        $event = $this->event('Non-Farm Employment Change', '2026-08-07 12:30:00');
        $this->events->upsertMany([$event], $this->utc('2026-08-06 10:00:00'));

        self::assertTrue($blackout->isBlackedOut($this->utc('2026-08-07 12:30:00')));

        $this->events->retireMissing(
            self::SOURCE,
            $this->utc('2026-08-01 00:00:00'),
            $this->utc('2026-08-31 00:00:00'),
            [],
            $this->utc('2026-08-06 11:00:00')
        );

        self::assertFalse(
            $blackout->isBlackedOut($this->utc('2026-08-07 12:30:00')),
            'A cancelled event must stop suppressing signals.'
        );
    }
}
