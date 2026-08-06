<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

use Closure;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Core\View;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Support\Security\Csrf;

/**
 * Shares request-scoped values with every view, and consumes flash messages.
 *
 * Flash data is read and cleared here rather than in each template, so a
 * message cannot survive into a second page because one view forgot to
 * consume it.
 */
final class ShareViewData implements MiddlewareInterface
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly Csrf $csrf
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        $this->view->share('csrf', $this->csrf);
        $this->view->share('csrfToken', $this->csrf->token());
        $this->view->share('authUser', $this->auth->user());
        $this->view->share('currentPath', $request->path());
        $this->view->share('flash', is_array($flash) ? $flash : []);
        $this->view->share('errors', is_array($flash['errors'] ?? null) ? $flash['errors'] : []);
        $this->view->share('old', is_array($flash['old'] ?? null) ? $flash['old'] : []);

        return $next($request);
    }
}
