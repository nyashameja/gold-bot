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
 *
 * Every Require* gate is the FIRST of two checks. Each controller action
 * checks the same permission again before acting (docs/01 §10) — not
 * redundancy, but the check that still holds if an action is ever reached
 * through a different route.
 */

use GoldBot\Core\Router;
use GoldBot\Http\Controllers\ApiUsageController;
use GoldBot\Http\Controllers\AuditController;
use GoldBot\Http\Controllers\AuthController;
use GoldBot\Http\Controllers\CalendarController;
use GoldBot\Http\Controllers\HealthController;
use GoldBot\Http\Controllers\MarketController;
use GoldBot\Http\Controllers\MethodController;
use GoldBot\Http\Controllers\OverviewController;
use GoldBot\Http\Controllers\PerformanceController;
use GoldBot\Http\Controllers\SettingsController;
use GoldBot\Http\Controllers\SignalController;
use GoldBot\Http\Controllers\TelegramController;
use GoldBot\Http\Controllers\UserController;
use GoldBot\Http\Middleware\Authenticate;
use GoldBot\Http\Middleware\RateLimit;
use GoldBot\Http\Middleware\RequireApiView;
use GoldBot\Http\Middleware\RequireAuditView;
use GoldBot\Http\Middleware\RequireCalendarView;
use GoldBot\Http\Middleware\RequireHealthView;
use GoldBot\Http\Middleware\RequireMarketView;
use GoldBot\Http\Middleware\RequirePerformanceView;
use GoldBot\Http\Middleware\RequireSettingsEdit;
use GoldBot\Http\Middleware\RequireSignalsCancel;
use GoldBot\Http\Middleware\RequireSignalsView;
use GoldBot\Http\Middleware\RequireStrategiesView;
use GoldBot\Http\Middleware\RequireTasksRun;
use GoldBot\Http\Middleware\RequireTelegramView;
use GoldBot\Http\Middleware\RequireUsersManage;
use GoldBot\Http\Middleware\RequireUsersView;
use GoldBot\Http\Middleware\SecurityHeaders;
use GoldBot\Http\Middleware\ShareViewData;
use GoldBot\Http\Middleware\StartSession;
use GoldBot\Http\Middleware\VerifyCsrf;

return static function (Router $router): void {
    $base = [SecurityHeaders::class, StartSession::class, ShareViewData::class];

    // A UUID pattern on the route rather than validation in the controller:
    // a malformed id is then a 404 from the router, and never reaches a query.
    $uuid = '{uuid:[0-9a-fA-F-]{36}}';

    // ── Guest ────────────────────────────────────────────────────────────────
    $router->group('', [...$base, VerifyCsrf::class], static function (Router $r): void {
        $r->get('/login', AuthController::class, 'showLogin', [], 'login');
        // Rate limited: the login form is the one endpoint worth grinding.
        $r->post('/login', AuthController::class, 'login', [RateLimit::class]);
    });

    // ── Authenticated ────────────────────────────────────────────────────────
    $router->group('', [...$base, VerifyCsrf::class, Authenticate::class], static function (Router $r) use ($uuid): void {
        $r->get('/', OverviewController::class, 'index', [], 'overview');
        $r->get('/api/overview', OverviewController::class, 'live', [], 'overview.live');
        $r->post('/logout', AuthController::class, 'logout', [], 'logout');

        // ── Live Market ──────────────────────────────────────────────────────
        $r->get('/market', MarketController::class, 'index', [RequireMarketView::class], 'market');
        $r->get('/api/market/quote', MarketController::class, 'quote', [RequireMarketView::class], 'market.quote');
        $r->get('/api/market/series', MarketController::class, 'series', [RequireMarketView::class], 'market.series');

        // ── Signals ──────────────────────────────────────────────────────────
        $r->get('/signals', SignalController::class, 'index', [RequireSignalsView::class], 'signals');
        $r->get('/api/signals/open', SignalController::class, 'open', [RequireSignalsView::class], 'signals.open');
        $r->get('/signals/' . $uuid, SignalController::class, 'show', [RequireSignalsView::class], 'signals.show');
        $r->post('/signals/' . $uuid . '/cancel', SignalController::class, 'cancel', [RequireSignalsCancel::class], 'signals.cancel');

        // ── 714 Method ───────────────────────────────────────────────────────
        $r->get('/method', MethodController::class, 'index', [RequireStrategiesView::class], 'method');

        // ── Economic Calendar ────────────────────────────────────────────────
        $r->get('/calendar', CalendarController::class, 'index', [RequireCalendarView::class], 'calendar');
        $r->get('/api/calendar/next', CalendarController::class, 'next', [RequireCalendarView::class], 'calendar.next');

        // ── Performance ──────────────────────────────────────────────────────
        $r->get('/performance', PerformanceController::class, 'index', [RequirePerformanceView::class], 'performance');

        // ── Telegram ─────────────────────────────────────────────────────────
        $r->get('/telegram', TelegramController::class, 'index', [RequireTelegramView::class], 'telegram');
        $r->post('/telegram/{id:\d+}/retry', TelegramController::class, 'retry', [RequireTelegramView::class], 'telegram.retry');

        // ── API Usage ────────────────────────────────────────────────────────
        $r->get('/api-usage', ApiUsageController::class, 'index', [RequireApiView::class], 'api-usage');

        // ── System Health ────────────────────────────────────────────────────
        $r->get('/health', HealthController::class, 'index', [RequireHealthView::class], 'health');
        $r->get('/api/health', HealthController::class, 'status', [RequireHealthView::class], 'health.status');
        $r->post('/health/tasks/{code:[a-z.\-_]+}/run', HealthController::class, 'runTask', [RequireTasksRun::class], 'health.run-task');

        // ── Users ────────────────────────────────────────────────────────────
        $r->get('/users', UserController::class, 'index', [RequireUsersView::class], 'users');
        $r->post('/users', UserController::class, 'store', [RequireUsersManage::class], 'users.store');
        $r->post('/users/{id:\d+}/active', UserController::class, 'setActive', [RequireUsersManage::class], 'users.active');
        $r->post('/users/{id:\d+}/roles', UserController::class, 'setRoles', [RequireUsersManage::class], 'users.roles');

        // ── Audit ────────────────────────────────────────────────────────────
        $r->get('/audit', AuditController::class, 'index', [RequireAuditView::class], 'audit');

        // ── Settings ─────────────────────────────────────────────────────────
        $r->get('/settings', SettingsController::class, 'index', [RequireSettingsEdit::class], 'settings');
        $r->post('/settings', SettingsController::class, 'update', [RequireSettingsEdit::class], 'settings.update');
    });
};
