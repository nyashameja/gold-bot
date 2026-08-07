<?php

declare(strict_types=1);

namespace GoldBot\Services\Calendar;

use DateTimeImmutable;
use GoldBot\Domain\Calendar\EconomicEvent;
use GoldBot\Repositories\Contracts\EconomicEventRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use Paragon\Core\Database;

/**
 * Decides whether trading is suppressed around a news release.
 *
 * Applied by the signal engine as a global filter, outside the strategies
 * (docs/01 §6): a blackout reimplemented in each strategy is a blackout
 * misimplemented in at least one of them.
 *
 * Windows come from the event's category where one matched, falling back to
 * the global defaults — so a rate decision can be given a wider window than
 * retail sales without touching code.
 */
final class NewsBlackoutService
{
    /** Currencies whose releases move gold. */
    private const DEFAULT_CURRENCIES = ['USD'];

    /** @var array<int,array{before:int,after:int}>|null */
    private ?array $categoryWindows = null;

    public function __construct(
        private readonly EconomicEventRepositoryInterface $events,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Database $database
    ) {
    }

    /**
     * Whether $moment falls inside any blackout window.
     */
    public function isBlackedOut(DateTimeImmutable $moment): bool
    {
        return $this->activeEvent($moment) !== null;
    }

    /**
     * The event causing a blackout at $moment, if any.
     *
     * Returns the event rather than a boolean so the rejection reason recorded
     * in strategy_runs can name it — "why did nothing fire?" is the most
     * common operational question, and "news" alone does not answer it.
     */
    public function activeEvent(DateTimeImmutable $moment): ?EconomicEvent
    {
        if (!(bool) $this->settings->get('news.filter_enabled', true)) {
            return null;
        }

        $defaultBefore = (int) $this->settings->get('news.blackout_before_minutes', 30);
        $defaultAfter = (int) $this->settings->get('news.blackout_after_minutes', 30);
        $approximatePadding = (int) $this->settings->get('news.approximate_padding_minutes', 240);

        // Widen the query window by the largest configured padding, so an
        // approximate-time event far outside the nominal window is still
        // considered.
        $span = max($defaultBefore, $defaultAfter, $approximatePadding) + $this->widestCategoryWindow();

        $candidates = $this->events->between(
            $moment->modify(sprintf('-%d minutes', $span)),
            $moment->modify(sprintf('+%d minutes', $span)),
            $this->currencies(),
            $this->minimumImpact()
        );

        foreach ($candidates as $event) {
            [$before, $after] = $this->windowFor($event, $defaultBefore, $defaultAfter);

            if ($event->blackoutCovers($moment, $before, $after, $approximatePadding)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Every event blacking out $moment — for the dashboard, which should show
     * all of them rather than only the first.
     *
     * @return list<EconomicEvent>
     */
    public function activeEvents(DateTimeImmutable $moment): array
    {
        $event = $this->activeEvent($moment);

        if ($event === null) {
            return [];
        }

        $defaultBefore = (int) $this->settings->get('news.blackout_before_minutes', 30);
        $defaultAfter = (int) $this->settings->get('news.blackout_after_minutes', 30);
        $approximatePadding = (int) $this->settings->get('news.approximate_padding_minutes', 240);
        $span = max($defaultBefore, $defaultAfter, $approximatePadding) + $this->widestCategoryWindow();

        $active = [];

        foreach ($this->events->between(
            $moment->modify(sprintf('-%d minutes', $span)),
            $moment->modify(sprintf('+%d minutes', $span)),
            $this->currencies(),
            $this->minimumImpact()
        ) as $candidate) {
            [$before, $after] = $this->windowFor($candidate, $defaultBefore, $defaultAfter);

            if ($candidate->blackoutCovers($moment, $before, $after, $approximatePadding)) {
                $active[] = $candidate;
            }
        }

        return $active;
    }

    /** The next event that will cause a blackout — powers the Overview tile. */
    public function nextEvent(DateTimeImmutable $after): ?EconomicEvent
    {
        return $this->events->nextUpcoming($after, $this->currencies(), $this->minimumImpact());
    }

    /**
     * @return array{0:int,1:int} Minutes before and after, for this event.
     */
    private function windowFor(EconomicEvent $event, int $defaultBefore, int $defaultAfter): array
    {
        if ($event->categoryId === null) {
            return [$defaultBefore, $defaultAfter];
        }

        $windows = $this->loadCategoryWindows();

        if (!isset($windows[$event->categoryId])) {
            return [$defaultBefore, $defaultAfter];
        }

        return [$windows[$event->categoryId]['before'], $windows[$event->categoryId]['after']];
    }

    /** @return array<int,array{before:int,after:int}> */
    private function loadCategoryWindows(): array
    {
        if ($this->categoryWindows !== null) {
            return $this->categoryWindows;
        }

        $windows = [];

        foreach ($this->database->select(
            'SELECT id, blackout_minutes_before, blackout_minutes_after FROM event_categories'
        ) as $row) {
            $windows[(int) $row['id']] = [
                'before' => (int) $row['blackout_minutes_before'],
                'after'  => (int) $row['blackout_minutes_after'],
            ];
        }

        return $this->categoryWindows = $windows;
    }

    private function widestCategoryWindow(): int
    {
        $widest = 0;

        foreach ($this->loadCategoryWindows() as $window) {
            $widest = max($widest, $window['before'], $window['after']);
        }

        return $widest;
    }

    /** @return list<string> */
    private function currencies(): array
    {
        $configured = $this->settings->get('news.currencies');

        if (is_array($configured) && $configured !== []) {
            return array_map('strval', $configured);
        }

        return self::DEFAULT_CURRENCIES;
    }

    private function minimumImpact(): string
    {
        return (string) $this->settings->get('news.minimum_impact', 'HIGH');
    }
}
