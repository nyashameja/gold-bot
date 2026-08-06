<?php

declare(strict_types=1);

/**
 * Web routes.
 *
 * Middleware order matters and is deliberate:
 *   SecurityHeaders  outermost, so headers reach error responses too
 *   StartSession     everything below needs the session
 *   ShareViewData    consumes flash data before a view can render
 *   VerifyCsrf       before any controller can act on a POST
 *   Authenticate     before Authorize, which needs a user
 *   Require*         the specific permission gate
 */

use GoldBot\Core\Router;
use GoldBot\Http\Controllers\AuditController;
use GoldBot\Http\Controllers\AuthController;
use GoldBot\Http\Controllers\OverviewController;
use GoldBot\Http\Middleware\Authenticate;
use GoldBot\Http\Middleware\RateLimit;
use GoldBot\Http\Middleware\RequireAuditView;
use GoldBot\Http\Middleware\SecurityHeaders;
use GoldBot\Http\Middleware\ShareViewData;
use GoldBot\Http\Middleware\StartSession;
use GoldBot\Http\Middleware\VerifyCsrf;

return static function (Router $router): void {
    $base = [SecurityHeaders::class, StartSession::class, ShareViewData::class];

    // ── Guest ────────────────────────────────────────────────────────────────
    $router->group('', [...$base, VerifyCsrf::class], static function (Router $r): void {
        $r->get('/login', AuthController::class, 'showLogin', [], 'login');
        // Rate limited: the login form is the one endpoint worth grinding.
        $r->post('/login', AuthController::class, 'login', [RateLimit::class]);
    });

    // ── Authenticated ────────────────────────────────────────────────────────
    $router->group('', [...$base, VerifyCsrf::class, Authenticate::class], static function (Router $r): void {
        $r->get('/', OverviewController::class, 'index', [], 'overview');
        $r->post('/logout', AuthController::class, 'logout', [], 'logout');

        // Permission-gated. The middleware is the first of two checks; the
        // controller checks again before acting (docs/01 §10).
        $r->get('/audit', AuditController::class, 'index', [RequireAuditView::class], 'audit');
    });
};
