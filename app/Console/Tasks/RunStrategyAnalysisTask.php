<?php

declare(strict_types=1);

namespace GoldBot\Console\Tasks;

use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Services\Signals\SignalEngine;

/**
 * Evaluates enabled strategies against new closed candles.
 *
 * Makes no network calls: it works entirely from stored candles, indicators
 * and calendar events, so it costs no API budget and keeps running while a
 * provider is unreachable. That separation is why ingest and analysis are
 * distinct tiers (docs/01 §3).
 */
final class RunStrategyAnalysisTask implements TaskInterface
{
    public function __construct(
        private readonly SignalEngine $engine,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(): TaskResult
    {
        $result = $this->engine->run();

        // Errors alongside successful evaluations are a partial success: one
        // misconfigured strategy must not mark the task failed and inflate
        // consecutive_failures for the healthy ones.
        if ($result['errors'] !== [] && $result['evaluated'] === 0) {
            return TaskResult::failed(implode('; ', $result['errors']));
        }

        return TaskResult::success(
            $result['evaluated'],
            sprintf(
                '%d evaluated, %d published, %d rejected%s',
                $result['evaluated'],
                $result['published'],
                $result['rejected'],
                $result['errors'] === [] ? '' : sprintf(', %d error(s): %s', count($result['errors']), implode('; ', $result['errors']))
            )
        );
    }
}
