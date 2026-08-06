<?php

declare(strict_types=1);

use GoldBot\Core\Env;

return [
    // 'apcu' silently falls back to 'file' when the extension is absent, which
    // is common on shared cPanel hosting (see config/services.php).
    'driver' => Env::string('CACHE_DRIVER', 'apcu'),
    'path'   => 'storage/cache',
    'prefix' => 'goldbot:',
];
