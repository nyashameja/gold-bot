<?php

declare(strict_types=1);

/**
 * API providers and the task schedule (docs/02 §9).
 *
 * Cadences are the initial state; once seeded the table is authoritative, so
 * an operator retuning a schedule in the UI is not reverted by a deploy.
 */

use GoldBot\Console\Tasks\CalculateIndicatorsTask;
use GoldBot\Console\Tasks\CleanupTask;
use GoldBot\Console\Tasks\ImportMarketDataTask;
use GoldBot\Console\Tasks\ImportPriceSnapshotTask;
use GoldBot\Core\Database;
use GoldBot\Core\Env;

return static function (Database $db): int {
    $affected = 0;

    // Limits default to the Twelve Data free tier and are overridable by env,
    // because the budget gate is only as good as the numbers it is given.
    $providers = [
        [
            'code'             => 'TWELVE_DATA',
            'name'             => 'Twelve Data',
            'base_url'         => Env::string('TWELVE_DATA_BASE_URL', 'https://api.twelvedata.com'),
            'daily_limit'      => Env::int('TWELVE_DATA_DAILY_LIMIT', 800),
            'per_minute_limit' => Env::int('TWELVE_DATA_PER_MINUTE_LIMIT', 8),
            'is_active'        => 1,
        ],
        [
            'code'             => 'FOREX_FACTORY',
            'name'             => 'ForexFactory Calendar',
            'base_url'         => Env::string('FOREX_FACTORY_BASE_URL', 'https://nfs.faireconomy.media'),
            // A free courtesy feed with no published quota. Left unlimited so
            // the gate does not invent a limit, but polled infrequently below.
            'daily_limit'      => null,
            'per_minute_limit' => 4,
            'is_active'        => Env::bool('FOREX_FACTORY_ENABLED', true) ? 1 : 0,
        ],
        [
            'code'             => 'FRED',
            'name'             => 'FRED (St. Louis Fed)',
            'base_url'         => Env::string('FRED_BASE_URL', 'https://api.stlouisfed.org/fred'),
            'daily_limit'      => null,
            'per_minute_limit' => 60,
            'is_active'        => Env::bool('FRED_ENABLED', true) ? 1 : 0,
        ],
        [
            'code'             => 'TELEGRAM',
            'name'             => 'Telegram Bot API',
            'base_url'         => Env::string('TELEGRAM_BASE_URL', 'https://api.telegram.org'),
            'daily_limit'      => null,
            // Telegram allows ~30 messages/second overall; 20/minute to one
            // chat is the constraint that actually bites a signal channel.
            'per_minute_limit' => 20,
            'is_active'        => 1,
        ],
    ];

    foreach ($providers as $provider) {
        $exists = $db->scalar('SELECT COUNT(*) FROM api_providers WHERE code = ?', [$provider['code']]);

        $affected += $db->upsert(
            'api_providers',
            $provider,
            (int) $exists > 0
                ? ['name', 'base_url']
                : ['name', 'base_url', 'daily_limit', 'per_minute_limit', 'is_active']
        );
    }

    // handler_class is how a row maps to its implementation, so registering a
    // task is one row plus a container binding (ADR-08).
    $tasks = [
        [
            'code'            => 'market.price',
            'name'            => 'Capture price snapshot',
            'handler_class'   => ImportPriceSnapshotTask::class,
            'cadence_minutes' => 1,
            'timeout_seconds' => 60,
            'sort_order'      => 10,
        ],
        [
            'code'            => 'market.candles',
            'name'            => 'Import market data',
            'handler_class'   => ImportMarketDataTask::class,
            // Runs every minute but only fetches a timeframe once its bar has
            // closed — the task decides, not the schedule (docs/01 §5).
            'cadence_minutes' => 1,
            'timeout_seconds' => 300,
            'sort_order'      => 20,
        ],
        [
            'code'            => 'market.analyse',
            'name'            => 'Compute indicators & structure',
            'handler_class'   => CalculateIndicatorsTask::class,
            // No network: works from stored candles, so it costs no API
            // budget and runs even while the provider is unreachable.
            'cadence_minutes' => 1,
            'timeout_seconds' => 300,
            'sort_order'      => 30,
        ],
        [
            'code'            => 'system.cleanup',
            'name'            => 'Prune expired data',
            'handler_class'   => CleanupTask::class,
            'cadence_minutes' => 1440,
            'timeout_seconds' => 600,
            'sort_order'      => 90,
        ],
    ];

    foreach ($tasks as $task) {
        $exists = $db->scalar('SELECT COUNT(*) FROM scheduled_tasks WHERE code = ?', [$task['code']]);

        $affected += $db->upsert(
            'scheduled_tasks',
            [...$task, 'is_enabled' => 1],
            // Never overwrite an operator's cadence or enabled flag on re-seed;
            // the handler class is ours and must follow the code.
            (int) $exists > 0
                ? ['name', 'handler_class', 'sort_order']
                : ['name', 'handler_class', 'cadence_minutes', 'timeout_seconds', 'sort_order', 'is_enabled']
        );
    }

    return $affected;
};
