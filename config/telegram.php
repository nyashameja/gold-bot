<?php

declare(strict_types=1);

/**
 * Telegram delivery. Runtime-tunable values live in `settings`; this is
 * deployment configuration.
 */
return [
    // Kept modest so one cron run cannot exceed Telegram's per-chat rate limit.
    'batch_size' => 20,
];
