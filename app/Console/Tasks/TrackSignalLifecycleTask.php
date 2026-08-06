<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Services\Signals\SignalLifecycleService;
use Throwable;

/**
 * Advances open signals: entry fills, targets, stops and expiry.
 *
 * Works from stored candles, so it costs no API budget and can replay a gap
 * after an outage rather than losing the transitions that happened during it.
 */
final class TrackSignalLifecycleTask implements TaskInterface
{
    public function __construct(
        private readonly SignalLifecycleService $lifecycle,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): TaskResult
    {
        try {
            $result = $this->lifecycle->track();
        } catch (Throwable $e) {
            $this->logger->error('Signal lifecycle tracking failed', [
                'event'     => 'signal.tracking_failed',
                'exception' => $e,
            ]);

            return TaskResult::failed($e->getMessage());
        }

        return TaskResult::success(
            $result['checked'],
            sprintf(
                '%d open, %d activated, %d target(s), %d stopped, %d expired',
                $result['checked'],
                $result['activated'],
                $result['targets'],
                $result['stopped'],
                $result['expired']
            )
        );
    }
}
