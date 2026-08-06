<?php

declare(strict_types=1);

namespace GoldBot\Core;

use GoldBot\Services\Auth\AuthService;

/**
 * Base controller.
 *
 * Controllers hold no business logic: they validate input, call one service,
 * and return a response (docs/01 §4). A controller that queries the database
 * directly is a bug, not a shortcut.
 */
abstract class Controller
{
    public function __construct(
        protected readonly View $view,
        protected readonly AuthService $auth
    ) {
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        return $this->view->render($template, [
            'user'        => $this->auth->user(),
            'currentPath' => $data['currentPath'] ?? null,
            ...$data,
        ], $layout);
    }

    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    protected function redirect(string $location, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($location, $status);
    }

    protected function back(Request $request, string $fallback = '/'): RedirectResponse
    {
        $referer = $request->header('Referer');

        // Only follow a same-origin referer: an off-site value would turn any
        // form failure into an open redirect.
        if ($referer !== null && str_starts_with($referer, (string) config('app.url'))) {
            return new RedirectResponse($referer);
        }

        return new RedirectResponse($fallback);
    }
}
