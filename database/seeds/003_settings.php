<?php

declare(strict_types=1);

/**
 * Default application settings (docs/02 §4).
 *
 * These are runtime-mutable values edited through the Settings page, as
 * distinct from config/*.php which is deployment configuration. Anything an
 * operator should be able to change without a deploy belongs here.
 *
 * Existing rows keep their value — only labels and descriptions are refreshed
 * — so a deploy never silently reverts an operator's tuning.
 */

use GoldBot\Core\Database;

return static function (Database $db): int {
    $settings = [
        // Signal engine
        ['signals.enabled', '1', 'bool', 'signals', 'Signal generation enabled', 'Master switch. When off, strategies still evaluate and record runs but no signal is published.'],
        ['signals.min_score', '70', 'int', 'signals', 'Minimum score to publish', 'Score out of 100 a setup must reach. Do not tune this before the backtester exists (ADR-04).'],
        ['signals.max_open', '3', 'int', 'signals', 'Maximum concurrent open signals', 'Signals beyond this are suppressed and recorded with a rejection reason.'],
        ['signals.expiry_minutes', '240', 'int', 'signals', 'Pending signal expiry', 'A pending signal whose entry is never touched expires after this many minutes.'],
        ['signals.cooldown_minutes', '60', 'int', 'signals', 'Cooldown between signals', 'Minimum gap between two signals on the same instrument and direction.'],

        // Risk
        ['risk.default_rr', '2.0', 'float', 'risk', 'Default reward-to-risk ratio', 'Used to derive take-profit levels when a strategy does not specify them.'],
        ['risk.max_spread', '0.50', 'float', 'risk', 'Maximum spread to trade', 'Setups are suppressed when the live spread exceeds this, in price units.'],

        // News filter — blackout defaults, overridable per event category
        ['news.filter_enabled', '1', 'bool', 'news', 'Suppress signals around high-impact news', 'Applies the blackout windows configured per event category.'],
        ['news.blackout_before_minutes', '30', 'int', 'news', 'Default blackout before an event', 'Minutes before a high-impact event during which signals are suppressed.'],
        ['news.blackout_after_minutes', '30', 'int', 'news', 'Default blackout after an event', 'Minutes after a high-impact event during which signals are suppressed.'],
        ['news.minimum_impact', 'HIGH', 'string', 'news', 'Minimum impact to blackout', 'Only events at or above this impact suppress signals. HOLIDAY always counts — thin liquidity is its own reason.'],
        ['news.currencies', '["USD"]', 'json', 'news', 'Currencies that move gold', 'Releases in these currencies are considered. Gold is dollar-priced, so USD dominates.'],
        ['news.approximate_padding_minutes', '240', 'int', 'news', 'Padding for approximate times', 'Events published without a precise time — "Tentative", or a FRED date-only release — are blacked out this far either side instead. A narrow window around a time nobody published is false confidence.'],

        // Telegram
        ['telegram.enabled', '0', 'bool', 'telegram', 'Telegram delivery enabled', 'Off until a bot token and at least one chat are configured.'],
        ['telegram.max_attempts', '5', 'int', 'telegram', 'Maximum delivery attempts', 'After this many failures a message is marked DEAD and surfaced on System Health.'],
        ['telegram.retry_base_seconds', '30', 'int', 'telegram', 'Retry backoff base', 'Delay before the first retry; doubles on each subsequent attempt.'],

        // Data retention (docs/02 §10)
        ['retention.price_snapshots_days', '30', 'int', 'retention', 'Price snapshot retention', 'High-frequency data with little long-term value.'],
        ['retention.strategy_runs_days', '180', 'int', 'retention', 'Strategy run retention', 'Balances future ML value against table volume.'],
        ['retention.api_usage_days', '90', 'int', 'retention', 'API usage log retention', 'Aggregated into performance snapshots before pruning.'],
        ['retention.task_runs_days', '90', 'int', 'retention', 'Task run retention', 'Operational forensics window.'],
        ['retention.system_logs_days', '90', 'int', 'retention', 'System log retention', 'Log files are retained separately and pruned on their own schedule.'],

        // Health thresholds
        ['health.task_stale_multiplier', '3', 'int', 'health', 'Task staleness multiplier', 'A task is stale when its last success is older than this many times its cadence. This is what detects a cron that stopped silently.'],
        ['health.disk_warning_percent', '85', 'int', 'health', 'Disk usage warning threshold', 'Percentage of the account quota at which a warning is raised.'],
        ['health.queue_depth_warning', '50', 'int', 'health', 'Telegram queue depth warning', 'Pending message count above which the queue is considered backed up.'],
    ];

    $affected = 0;

    foreach ($settings as [$key, $value, $type, $group, $label, $description]) {
        $exists = $db->scalar('SELECT COUNT(*) FROM settings WHERE `key` = ?', [$key]);

        $affected += $db->upsert(
            'settings',
            [
                'key'         => $key,
                'value'       => $value,
                'type'        => $type,
                'group'       => $group,
                'label'       => $label,
                'description' => $description,
                'is_secret'   => 0,
            ],
            // Never overwrite an operator's tuned value on re-seed.
            (int) $exists > 0
                ? ['type', 'group', 'label', 'description']
                : ['value', 'type', 'group', 'label', 'description', 'is_secret']
        );
    }

    return $affected;
};
