<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Integrations\Calendar\CalendarException;
use GoldBot\Services\Calendar\CalendarService;
use Paragon\Core\Logging\LoggerInterface;

/**
 * Imports the economic calendar.
 *
 * Runs on a modest cadence — events are revised as actuals publish, but not
 * minute by minute. What matters is that it runs *reliably*: the upstream feed
 * is a rolling window, so a poll missed is history permanently lost (ADR-15).
 */
final class ImportCalendarTask implements TaskInterface
{
    public function __construct(
        private readonly CalendarService $calendar,
        private readonly LoggerInterface $logger,
        private readonly int $daysBack = 7,
        private readonly int $daysForward = 14
    ) {
    }

    public function run(): TaskResult
    {
        try {
            $result = $this->calendar->import($this->daysBack, $this->daysForward);
        } catch (CalendarException $e) {
            // Not retryable means a permanent condition — a disabled provider
            // or an exhausted budget — which is a skip, not a failure.
            if (!$e->retryable) {
                return TaskResult::skipped($e->getMessage());
            }

            return TaskResult::failed($e->getMessage());
        }

        return TaskResult::success(
            $result['fetched'],
            sprintf(
                '%d fetched, %d new, %d updated, %d retired, %d categorised',
                $result['fetched'],
                $result['inserted'],
                $result['updated'],
                $result['retired'],
                $result['categorised']
            )
        );
    }
}
