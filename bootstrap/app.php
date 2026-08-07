<?php

declare(strict_types=1);

/**
 * Application bootstrap — required by public/index.php and cron/run.php.
 *
 * Returns the booted Application. Both entry points go through here so the
 * web tier and CLI share identical wiring; a service that works in one and
 * not the other is a wiring bug, and this file is where it would be caught.
 */

use Paragon\Core\Application;

$basePath = dirname(__DIR__);

$autoloader = $basePath . '/vendor/autoload.php';

if (!is_file($autoloader)) {
    $message = "Dependencies are not installed. Run: composer install --no-dev --optimize-autoloader\n";

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }

    exit(1);
}

require $autoloader;

return Application::create($basePath);
