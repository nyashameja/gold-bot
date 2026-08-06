<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Domain\Calendar\EventImpact;
use PHPUnit\Framework\TestCase;

/**
 * Blackout window arithmetic — pure domain, no database (ADR-03).
 *
 * This is the logic that decides whether a valid setup is suppressed, so both
 * directions matter: failing to suppress trades into a release, and
 * over-suppressing silently starves the strategy of every setup.
 */
final class BlackoutWindowTest extends TestCase
{
    private function utc(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }

    private function event(
        string $at = '2026-08-07 12:30:00',
        bool $approximate = false,
        EventImpact $impact = EventImpact::High
    ): EconomicEvent {
        return new EconomicEvent(
            providerEventId:   'test',
            source:            'TEST',
            currency:          'USD',
            title:             'Non-Farm Employment Change',
            impact:            $impact,
            scheduledAt:       $this->utc($at),
            timeIsApproximate: $approximate
        );
    }

    public function test_the_window_spans_the_configured_minutes_either_side(): void
    {
        [$from, $to] = $this->event()->blackoutWindow(45, 60);

        self::assertSame('2026-08-07 11:45:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-07 13:30:00', $to->format('Y-m-d H:i:s'));
    }

    public function test_moments_inside_the_window_are_covered(): void
    {
        $event = $this->event();

        self::assertTrue($event->blackoutCovers($this->utc('2026-08-07 12:30:00'), 45, 60), 'The release itself.');
        self::assertTrue($event->blackoutCovers($this->utc('2026-08-07 11:45:00'), 45, 60), 'The opening edge.');
        self::assertTrue($event->blackoutCovers($this->utc('2026-08-07 13:30:00'), 45, 60), 'The closing edge.');
        self::assertTrue($event->blackoutCovers($this->utc('2026-08-07 12:00:00'), 45, 60));
    }

    public function test_moments_outside_the_window_are_not_covered(): void
    {
        $event = $this->event();

        self::assertFalse($event->blackoutCovers($this->utc('2026-08-07 11:44:59'), 45, 60));
        self::assertFalse($event->blackoutCovers($this->utc('2026-08-07 13:30:01'), 45, 60));
        self::assertFalse($event->blackoutCovers($this->utc('2026-08-06 12:30:00'), 45, 60), 'The day before.');
    }

    /**
     * A narrow window around a time nobody published is false confidence: the
     * release could land anywhere in the day. Widening is the honest response
     * to not knowing.
     */
    public function test_an_approximate_time_widens_the_window(): void
    {
        $precise = $this->event(approximate: false);
        $approximate = $this->event(approximate: true);

        $threeHoursEarlier = $this->utc('2026-08-07 09:30:00');

        self::assertFalse($precise->blackoutCovers($threeHoursEarlier, 45, 60));
        self::assertTrue(
            $approximate->blackoutCovers($threeHoursEarlier, 45, 60, approximatePaddingMinutes: 240),
            'An unknown time must be padded, not trusted.'
        );
    }

    /** Padding widens; it never narrows a window that is already wider. */
    public function test_padding_never_narrows_an_already_wider_window(): void
    {
        [$from, $to] = $this->event(approximate: true)->blackoutWindow(600, 600, approximatePaddingMinutes: 240);

        self::assertSame('2026-08-07 02:30:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-07 22:30:00', $to->format('Y-m-d H:i:s'));
    }

    public function test_a_zero_width_window_covers_only_the_instant(): void
    {
        $event = $this->event(impact: EventImpact::Holiday);

        self::assertTrue($event->blackoutCovers($this->utc('2026-08-07 12:30:00'), 0, 0));
        self::assertFalse($event->blackoutCovers($this->utc('2026-08-07 12:30:01'), 0, 0));
    }

    public function test_released_and_upcoming_are_distinguished(): void
    {
        $upcoming = $this->event();
        $now = $this->utc('2026-08-07 10:00:00');

        self::assertFalse($upcoming->isReleased());
        self::assertTrue($upcoming->isUpcoming($now));

        $released = new EconomicEvent(
            providerEventId: 'test',
            source:          'TEST',
            currency:        'USD',
            title:           'Non-Farm Employment Change',
            impact:          EventImpact::High,
            scheduledAt:     $this->utc('2026-08-07 12:30:00'),
            actual:          '162K'
        );

        self::assertTrue($released->isReleased());
        self::assertFalse($released->isUpcoming($this->utc('2026-08-07 13:00:00')));
    }

    /** An empty-string actual is not a release — some feeds send that. */
    public function test_an_empty_actual_does_not_count_as_released(): void
    {
        $event = new EconomicEvent(
            providerEventId: 'test',
            source:          'TEST',
            currency:        'USD',
            title:           'CPI',
            impact:          EventImpact::High,
            scheduledAt:     $this->utc('2026-08-12 12:30:00'),
            actual:          ''
        );

        self::assertFalse($event->isReleased());
    }

    public function test_currency_relevance_is_decided_by_the_caller(): void
    {
        $usd = $this->event();

        self::assertTrue($usd->affects(['USD']));
        self::assertTrue($usd->affects(['usd', 'EUR']), 'Matching is case-insensitive.');
        self::assertFalse($usd->affects(['EUR', 'GBP']));
        self::assertFalse($usd->affects([]));
    }
}
