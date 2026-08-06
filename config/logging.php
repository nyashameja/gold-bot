<?php

declare(strict_types=1);

use GoldBot\Core\Env;

return [
    'level'          => Env::string('LOG_LEVEL', 'info'),
    'channel'        => Env::string('LOG_CHANNEL', 'app'),
    'path'           => 'storage/logs',
    'retention_days' => 90,
];
