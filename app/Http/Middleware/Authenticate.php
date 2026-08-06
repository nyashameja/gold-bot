<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

use Closure;
use GoldBot\Core\Config;
use GoldBot\Core\JsonResponse;
use GoldBot\Core\RedirectResponse;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Services\Auth\AuthService;

final class Authenticate implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Config $config
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->auth->check()) {
            return $this->reject($request, 'Please sign in to continue.');
        }

        // An idle session is ended here rather than left to the cookie's
        // absolute lifetime, so an unattended dashboard does not stay open.
        if ($this->auth->isIdle($this->config->int('app.session.idle_timeout', 30))) {
            $this->auth->logout($request);

            return $this->reject($request, 'Your session timed out. Please sign in again.');
        }

        $this->auth->touch();

        return $next($request);
    }

    private function reject(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return JsonResponse::error($message, 401);
        }

        // Remember where they were headed so login can return them there.
        $_SESSION['_intended'] = $request->path();

        return (new RedirectResponse('/login'))->with('error', $message);
    }
}
