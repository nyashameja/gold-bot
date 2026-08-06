<?php

declare(strict_types=1);

/**
 * Front controller — the only PHP file reachable over HTTP.
 *
 * The Apache document root points here; application code, .env, storage and
 * vendor all sit above it (docs/03 §1).
 */

use GoldBot\Core\Application;
use GoldBot\Core\ErrorHandler;
use GoldBot\Core\HttpException;
use GoldBot\Core\Request;
use GoldBot\Core\Router;
use GoldBot\Core\View;

/**
 * Let PHP's built-in server serve existing files itself.
 *
 * `php -S host:port -t public public/index.php` routes *every* request through
 * this script, including /assets/css/app.css — which would otherwise be
 * answered with a 404 HTML page and leave the dashboard completely unstyled.
 * In production Apache handles this via public/.htaccess; the SAPI check means
 * this branch is never taken there.
 */
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . urldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

    if (is_file($file)) {
        return false;
    }
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$container = $app->container();
$request = Request::capture();

try {
    /** @var Router $router */
    $router = $container->get(Router::class);

    $router->dispatch($request)->send();
} catch (HttpException $e) {
    // An expected HTTP condition (404, 403, 419) — a response, not an incident.
    /** @var View $view */
    $view = $container->get(View::class);

    $view->render('errors.error', [
        'status'  => $e->statusCode(),
        'message' => $e->getMessage(),
    ], 'layouts.auth')->withStatus($e->statusCode())->send();
} catch (Throwable $e) {
    // Anything else is a genuine fault: log it and show nothing internal.
    $container->get(ErrorHandler::class)->handleException($e);
}
