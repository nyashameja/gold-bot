<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;

/**
 * The Overview page — the one screen that answers "is it working, and how is
 * it doing?" without a click.
 *
 * Composes the other dashboard services rather than querying anything itself.
 * The alternative — a bespoke set of overview queries — is how two pages end
 * up disagreeing about the win rate, which destroys trust in both.
 */
final class OverviewService
{
    public function __construct(
        private readonly MarketBoardService $market,
        private readonly SignalBoardService $signals,
        private readonly PerformanceService $performance,
        private readonly CalendarBoardService $calendar,
        private readonly TelegramBoardService $telegram,
        private readonly ApiUsageService $apiUsage,
        private readonly HealthService $health,
        private readonly SignalRepositoryInterface $signalRepository,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function board(): array
    {
        $instrument = $this->market->defaultInstrument();
        $instrumentId = (int) $instrument['id'];

        return [
            'instrument'  => [
                'symbol' => (string) $instrument['symbol'],
                'name'   => (string) $instrument['name'],
            ],
            'quote'       => $this->market->quote($instrumentId),
            'timeframes'  => $this->market->timeframeSummary($instrumentId),
            'sessions'    => $this->market->sessions(),
            'open'        => $this->signals->openSignals(5),
            'open_count'  => $this->signalRepository->countOpen(),
            'performance' => $this->performance->headline(30),
            'next_event'  => $this->calendar->nextHighImpact(),
            'telegram'    => $this->telegram->queueSummary(),
            'providers'   => $this->apiUsage->summary(),
            'health'      => $this->health->summary(),
            'generated_at' => $this->clock->now()->format(DATE_ATOM),
        ];
    }

    /**
     * The subset the Overview polls for, so a refresh moves a handful of
     * numbers rather than re-running the whole page's queries every 30
     * seconds.
     *
     * @return array<string,mixed>
     */
    public function live(): array
    {
        $instrumentId = (int) $this->market->defaultInstrument()['id'];

        return [
            'quote'      => $this->market->quote($instrumentId),
            'open_count' => $this->signalRepository->countOpen(),
            'telegram'   => $this->telegram->queueSummary(),
            'health'     => $this->health->summary(),
            'at'         => $this->clock->now()->format(DATE_ATOM),
        ];
    }
}
