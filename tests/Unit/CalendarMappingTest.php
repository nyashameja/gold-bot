<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Calendar\EventImpact;
use GoldBot\Integrations\Calendar\CalendarException;
use GoldBot\Integrations\Calendar\EventIdentityHasher;
use GoldBot\Integrations\Calendar\ForexFactory\ForexFactoryMapper;
use PHPUnit\Framework\TestCase;

/**
 * Calendar mapping, asserted against recorded fixtures.
 *
 * As with the market-data mapper, these fixtures are shaped from the feeds'
 * documented format rather than observed traffic — this environment cannot
 * reach either host. They must be re-recorded against live responses before
 * Phase 5 is signed off (ADR-12, caveat 2).
 */
final class CalendarMappingTest extends TestCase
{
    private ForexFactoryMapper $mapper;

    private EventIdentityHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new EventIdentityHasher();
        $this->mapper = new ForexFactoryMapper($this->hasher);
    }

    private function fixture(string $name): mixed
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/Fixtures/Calendar/' . $name . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    // ── ForexFactory mapping ─────────────────────────────────────────────────

    public function test_it_maps_the_weekly_feed(): void
    {
        $events = $this->mapper->toEvents($this->fixture('forexfactory_thisweek'));

        self::assertCount(7, $events);
        self::assertSame('Non-Farm Employment Change', $events[0]->title);
        self::assertSame('USD', $events[0]->currency);
        self::assertSame(EventImpact::High, $events[0]->impact);
        self::assertSame('175K', $events[0]->forecast);
        self::assertSame('147K', $events[0]->previous);
    }

    /**
     * The feed carries a US Eastern offset. Treating the string as UTC would
     * shift every event by four or five hours — and every blackout window
     * with it, which is worse than having no filter at all.
     */
    public function test_the_feed_offset_is_honoured_and_normalised_to_utc(): void
    {
        $events = $this->mapper->toEvents($this->fixture('forexfactory_thisweek'));

        self::assertSame('UTC', $events[0]->scheduledAt->getTimezone()->getName());
        self::assertSame(
            '2026-08-07 12:30:00',
            $events[0]->scheduledAt->format('Y-m-d H:i:s'),
            '08:30 EDT is 12:30 UTC.'
        );
    }

    public function test_events_are_returned_in_chronological_order(): void
    {
        $events = $this->mapper->toEvents($this->fixture('forexfactory_thisweek'));

        $previous = null;

        foreach ($events as $event) {
            if ($previous !== null) {
                self::assertGreaterThanOrEqual($previous, $event->scheduledAt);
            }

            $previous = $event->scheduledAt;
        }
    }

    /** A fourth impact level, and a real reason to suppress: thin liquidity. */
    public function test_a_holiday_is_mapped_as_its_own_impact_level(): void
    {
        $events = $this->mapper->toEvents($this->fixture('forexfactory_thisweek'));

        $holiday = $this->findByTitle($events, 'German Bank Holiday');

        self::assertNotNull($holiday);
        self::assertSame(EventImpact::Holiday, $holiday->impact);
        self::assertTrue($holiday->timeIsApproximate);
        self::assertSame('EUR', $holiday->currency);
    }

    /**
     * "All Day" and "Tentative" entries arrive as a midnight timestamp. Left
     * unflagged they would produce a precise-looking blackout around a time
     * nobody ever published.
     */
    public function test_a_midnight_timestamp_is_flagged_approximate(): void
    {
        $events = $this->mapper->toEvents($this->fixture('forexfactory_thisweek'));

        $speech = $this->findByTitle($events, 'FOMC Member Bowman Speaks');

        self::assertNotNull($speech);
        self::assertTrue($speech->timeIsApproximate);

        $nfp = $this->findByTitle($events, 'Non-Farm Employment Change');
        self::assertFalse($nfp?->timeIsApproximate, 'A timed release is not approximate.');
    }

    public function test_a_released_event_carries_its_actual_and_revision(): void
    {
        $events = $this->mapper->toEvents($this->fixture('forexfactory_released'));

        self::assertCount(1, $events);
        self::assertTrue($events[0]->isReleased());
        self::assertSame('162K', $events[0]->actual);
        self::assertSame('147K', $events[0]->revisedFrom);
    }

    /**
     * One malformed row must not cost the whole week's archive — it cannot be
     * re-fetched later (ADR-15).
     */
    public function test_a_malformed_entry_is_skipped_not_fatal(): void
    {
        $events = $this->mapper->toEvents([
            ['title' => 'Valid Event', 'country' => 'USD', 'date' => '2026-08-07T08:30:00-04:00', 'impact' => 'High'],
            ['title' => 'No date'],
            ['country' => 'USD', 'date' => '2026-08-07T08:30:00-04:00'],
            ['title' => 'Bad date', 'country' => 'USD', 'date' => 'not-a-date'],
            'not even an array',
        ]);

        self::assertCount(1, $events);
        self::assertSame('Valid Event', $events[0]->title);
    }

    public function test_a_non_array_payload_is_rejected(): void
    {
        $this->expectException(CalendarException::class);
        $this->expectExceptionMessage('did not decode to an array');

        $this->mapper->toEvents('nope');
    }

    public function test_an_empty_feed_maps_to_no_events(): void
    {
        self::assertSame([], $this->mapper->toEvents([]));
    }

    // ── Identity hashing (ADR-16) ────────────────────────────────────────────

    /**
     * The property the whole import depends on: the same event must hash
     * identically on every poll, forever, or the archive fills with
     * duplicates of everything.
     */
    public function test_the_same_event_hashes_identically_across_polls(): void
    {
        $first = $this->mapper->toEvents($this->fixture('forexfactory_thisweek'));
        $second = $this->mapper->toEvents($this->fixture('forexfactory_thisweek'));

        foreach ($first as $i => $event) {
            self::assertSame($event->providerEventId, $second[$i]->providerEventId);
        }
    }

    /**
     * Providers tidy labels between polls — "CPI m/m" becomes "CPI (MoM)".
     * Treating those as different events would duplicate every renamed
     * release.
     */
    public function test_cosmetic_title_differences_do_not_change_identity(): void
    {
        $at = new DateTimeImmutable('2026-08-12 12:30:00', new DateTimeZone('UTC'));

        $a = $this->hasher->hash('FOREX_FACTORY', 'USD', 'CPI m/m', $at);
        $b = $this->hasher->hash('FOREX_FACTORY', 'USD', 'CPI (MoM)', $at);
        $c = $this->hasher->hash('FOREX_FACTORY', 'USD', '  cpi  month-over-month ', $at);

        self::assertSame($a, $b);
        self::assertSame($a, $c);
    }

    public function test_genuinely_different_events_hash_differently(): void
    {
        $at = new DateTimeImmutable('2026-08-12 12:30:00', new DateTimeZone('UTC'));

        $cpi = $this->hasher->hash('FOREX_FACTORY', 'USD', 'CPI m/m', $at);

        self::assertNotSame($cpi, $this->hasher->hash('FOREX_FACTORY', 'USD', 'PPI m/m', $at));
        self::assertNotSame($cpi, $this->hasher->hash('FOREX_FACTORY', 'EUR', 'CPI m/m', $at));
        self::assertNotSame($cpi, $this->hasher->hash('FRED', 'USD', 'CPI m/m', $at));
        self::assertNotSame(
            $cpi,
            $this->hasher->hash('FOREX_FACTORY', 'USD', 'CPI m/m', $at->modify('+1 day'))
        );
    }

    /** Providers sometimes shift the published second between polls. */
    public function test_a_differing_second_does_not_change_identity(): void
    {
        $at = new DateTimeImmutable('2026-08-12 12:30:00', new DateTimeZone('UTC'));

        self::assertSame(
            $this->hasher->hash('FF', 'USD', 'CPI', $at),
            $this->hasher->hash('FF', 'USD', 'CPI', $at->modify('+42 seconds'))
        );
    }

    public function test_the_hash_fits_the_storage_column(): void
    {
        $hash = $this->hasher->hash('FOREX_FACTORY', 'USD', 'CPI m/m', new DateTimeImmutable());

        self::assertSame(40, strlen($hash), 'The column is CHAR(40).');
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $hash);
    }

    // ── FRED mapping ─────────────────────────────────────────────────────────

    private function fred(): \GoldBot\Integrations\Calendar\Fred\FredProvider
    {
        // toEvents() is pure, so the collaborators are never reached.
        return new \GoldBot\Integrations\Calendar\Fred\FredProvider(
            new \Paragon\Core\Http\HttpClient(new NullTestLogger()),
            $this->hasher,
            new \GoldBot\Infrastructure\Http\ApiBudget(
                new \Paragon\Core\Database([]),
                new \Paragon\Core\Clock\SystemClock(),
                new NullTestLogger()
            ),
            new NullTestLogger(),
            'test-key'
        );
    }

    /**
     * FRED publishes hundreds of release schedules; almost none move gold.
     * Importing everything would leave the blackout filter suppressing the
     * entire session.
     */
    public function test_fred_keeps_only_releases_that_move_gold(): void
    {
        $events = $this->fred()->toEvents(
            $this->fixture('fred_release_dates'),
            new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-31', new DateTimeZone('UTC'))
        );

        $titles = array_map(static fn ($e): string => $e->title, $events);

        self::assertContains('Employment Situation', $titles);
        self::assertContains('Consumer Price Index', $titles);
        self::assertContains('Gross Domestic Product', $titles);
        self::assertNotContains('Regional Employment', $titles, 'Not a gold-moving release.');
    }

    /**
     * FRED publishes release *dates*, not times. Every event is therefore
     * approximate — widening its blackout rather than claiming a precision
     * the source never offered.
     */
    public function test_every_fred_event_is_flagged_approximate(): void
    {
        $events = $this->fred()->toEvents(
            $this->fixture('fred_release_dates'),
            new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-31', new DateTimeZone('UTC'))
        );

        self::assertNotEmpty($events);

        foreach ($events as $event) {
            self::assertTrue($event->timeIsApproximate, $event->title . ' must be approximate.');
            self::assertSame('USD', $event->currency);
            self::assertSame('FRED', $event->source);
            self::assertNull($event->forecast, 'FRED carries no consensus forecast.');
        }
    }

    public function test_fred_events_outside_the_window_are_dropped(): void
    {
        $events = $this->fred()->toEvents(
            $this->fixture('fred_release_dates'),
            new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-10', new DateTimeZone('UTC'))
        );

        self::assertCount(1, $events, 'Only the 7 August release falls inside.');
        self::assertSame('Employment Situation', $events[0]->title);
    }

    public function test_a_fred_response_without_release_dates_is_rejected(): void
    {
        $this->expectException(CalendarException::class);
        $this->expectExceptionMessage('release_dates');

        $this->fred()->toEvents(
            ['count' => 0],
            new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-08-31', new DateTimeZone('UTC'))
        );
    }

    /**
     * The two sources describe the same release differently, so they are kept
     * as separate rows — `source` is provenance, and merging would hide a
     * disagreement between them.
     */
    public function test_the_two_sources_produce_distinct_identities(): void
    {
        $at = new DateTimeImmutable('2026-08-12 12:30:00', new DateTimeZone('UTC'));

        self::assertNotSame(
            $this->hasher->hash('FOREX_FACTORY', 'USD', 'CPI m/m', $at),
            $this->hasher->hash('FRED', 'USD', 'Consumer Price Index', $at)
        );
    }

    // ── Impact parsing ───────────────────────────────────────────────────────

    public function test_impact_labels_are_parsed_across_provider_spellings(): void
    {
        self::assertSame(EventImpact::High, EventImpact::parse('High'));
        self::assertSame(EventImpact::High, EventImpact::parse('HIGH'));
        self::assertSame(EventImpact::Medium, EventImpact::parse('med'));
        self::assertSame(EventImpact::Low, EventImpact::parse('low'));
        self::assertSame(EventImpact::Holiday, EventImpact::parse('Holiday'));
    }

    /** A new label upstream must not stop the whole import. */
    public function test_an_unknown_impact_falls_back_rather_than_throwing(): void
    {
        self::assertSame(EventImpact::Low, EventImpact::parse('catastrophic'));
        self::assertSame(EventImpact::Low, EventImpact::parse(null));
        self::assertSame(EventImpact::Medium, EventImpact::parse('???', EventImpact::Medium));
    }

    public function test_holiday_counts_as_trade_relevant(): void
    {
        self::assertTrue(EventImpact::High->isTradeRelevant());
        self::assertTrue(EventImpact::Holiday->isTradeRelevant(), 'Thin liquidity is its own reason.');
        self::assertFalse(EventImpact::Medium->isTradeRelevant());
    }

    /** @param list<\GoldBot\Domain\Calendar\EconomicEvent> $events */
    private function findByTitle(array $events, string $title): ?\GoldBot\Domain\Calendar\EconomicEvent
    {
        foreach ($events as $event) {
            if ($event->title === $title) {
                return $event;
            }
        }

        return null;
    }
}
