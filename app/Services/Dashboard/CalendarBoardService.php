<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Domain\Calendar\EventImpact;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Repositories\Contracts\EconomicEventRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Services\Calendar\CalendarService;
use GoldBot\Services\Calendar\NewsBlackoutService;

/**
 * The Economic Calendar page.
 *
 * Events are grouped by day and each is annotated with whether it currently
 * suppresses signal generation. Showing the calendar without that annotation
 * would leave an operator unable to answer the question they actually came to
 * the page with — "why did nothing fire at 13:30?".
 *
 * The archive boundary is surfaced deliberately (ADR-15): the free feeds
 * publish a rolling window, so anything before the first import simply does
 * not exist locally and never will. A backtest that filtered news over that
 * period would be silently unfiltered, and the page says so rather than
 * letting the gap be discovered by a surprising result.
 */
final class CalendarBoardService
{
    public function __construct(
        private readonly EconomicEventRepositoryInterface $events,
        private readonly NewsBlackoutService $blackout,
        private readonly CalendarService $calendar,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function board(int $daysBack = 2, int $daysForward = 7, ?string $impact = null): array
    {
        $now = $this->clock->now();
        $daysBack = max(0, min($daysBack, 60));
        $daysForward = max(1, min($daysForward, 60));

        $from = $now->modify("-{$daysBack} days")->setTime(0, 0);
        $to = $now->modify("+{$daysForward} days")->setTime(23, 59, 59);

        $minimumImpact = $impact !== null && EventImpact::tryFrom(strtoupper($impact)) !== null
            ? strtoupper($impact)
            : null;

        $events = $this->events->between($from, $to, [], $minimumImpact);

        $active = $this->blackout->activeEvent($now);
        $activeId = $active?->id;

        $days = [];
        $newest = null;

        foreach ($events as $event) {
            $day = $event->scheduledAt->format('Y-m-d');
            $days[$day] ??= ['date' => $day, 'events' => []];
            $days[$day]['events'][] = $this->decorate($event, $now, $activeId);

            if ($newest === null || $event->scheduledAt > $newest) {
                $newest = $event->scheduledAt;
            }
        }

        $archiveStart = $this->calendar->archiveStartsAt();

        return [
            'days'    => array_values($days),
            'window'  => ['back' => $daysBack, 'forward' => $daysForward, 'impact' => $minimumImpact],
            'blackout' => [
                'enabled'      => (bool) $this->settings->get('news.filter_enabled', true),
                'active'       => $active !== null,
                'event'        => $active === null ? null : $this->decorate($active, $now, $activeId),
                'before_mins'  => (int) $this->settings->get('news.blackout_before_minutes', 30),
                'after_mins'   => (int) $this->settings->get('news.blackout_after_minutes', 30),
            ],
            'next'    => $this->nextHighImpact($now),
            'archive' => [
                'starts_at' => $archiveStart?->format(DATE_ATOM),
                'total'     => $this->events->count(),
            ],
            // The calendar cron runs hourly; a day-old calendar is a problem
            // worth seeing rather than inferring from an empty table.
            'age' => DataAge::since($newest, $now, 3600)->toArray(),
        ];
    }

    /**
     * The next high-impact release — the Overview widget.
     *
     * @return array<string,mixed>|null
     */
    public function nextHighImpact(?DateTimeImmutable $now = null): ?array
    {
        $now ??= $this->clock->now();
        $event = $this->events->nextUpcoming($now, ['USD'], EventImpact::High->value);

        return $event === null ? null : $this->decorate($event, $now, null);
    }

    /**
     * @return array<string,mixed>
     */
    private function decorate(EconomicEvent $event, DateTimeImmutable $now, ?int $activeBlackoutId): array
    {
        $before = (int) $this->settings->get('news.blackout_before_minutes', 30);
        $after = (int) $this->settings->get('news.blackout_after_minutes', 30);
        [$windowFrom, $windowTo] = $event->blackoutWindow($before, $after);

        $secondsAway = $event->scheduledAt->getTimestamp() - $now->getTimestamp();

        return [
            'id'         => $event->id,
            'title'      => $event->title,
            'currency'   => $event->currency,
            'country'    => $event->country,
            'impact'     => $event->impact->value,
            'impact_rank' => $event->impact->weight(),
            'impact_label' => $event->impact->label(),
            'scheduled_at' => $event->scheduledAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'time'       => $event->scheduledAt->format('H:i'),
            'approximate' => $event->timeIsApproximate,
            'actual'     => $event->actual,
            'forecast'   => $event->forecast,
            'previous'   => $event->previous,
            'revised_from' => $event->revisedFrom,
            'released'   => $event->isReleased(),
            'source'     => $event->source,
            'detail_url' => $event->detailUrl,
            'seconds_away' => $secondsAway,
            'countdown'  => $this->countdown($secondsAway),
            // Surprise against consensus is what actually moves price; the
            // absolute figure alone rarely does.
            'surprise'   => $this->surprise($event),
            'blackout'   => [
                'from'   => $windowFrom->format(DATE_ATOM),
                'to'     => $windowTo->format(DATE_ATOM),
                'active' => $activeBlackoutId !== null && $event->id === $activeBlackoutId,
            ],
        ];
    }

    /**
     * Actual versus forecast, when both parse as numbers.
     *
     * Returns null rather than guessing whenever the published strings are not
     * cleanly comparable — "175K" against "180K" is fine, "Tentative" is not,
     * and inventing a comparison there would be worse than showing nothing.
     */
    private function surprise(EconomicEvent $event): ?string
    {
        if ($event->actual === null || $event->forecast === null) {
            return null;
        }

        $actual = $this->numeric($event->actual);
        $forecast = $this->numeric($event->forecast);

        if ($actual === null || $forecast === null) {
            return null;
        }

        return match (true) {
            $actual > $forecast => 'ABOVE',
            $actual < $forecast => 'BELOW',
            default             => 'INLINE',
        };
    }

    /**
     * Parse a published figure, honouring the K/M/B/T suffixes and the
     * percent sign that providers publish inline.
     */
    private function numeric(string $value): ?float
    {
        if (!preg_match('/^\s*(-?[\d.,]+)\s*([KMBT])?\s*%?\s*$/i', $value, $matches)) {
            return null;
        }

        $number = (float) str_replace(',', '', $matches[1]);

        return $number * match (strtoupper($matches[2] ?? '')) {
            'K'     => 1_000,
            'M'     => 1_000_000,
            'B'     => 1_000_000_000,
            'T'     => 1_000_000_000_000,
            default => 1,
        };
    }

    private function countdown(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'released';
        }

        return match (true) {
            $seconds < 60    => 'in under a minute',
            $seconds < 3600  => 'in ' . intdiv($seconds, 60) . 'm',
            $seconds < 86400 => 'in ' . intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm',
            default          => 'in ' . intdiv($seconds, 86400) . 'd',
        };
    }
}
