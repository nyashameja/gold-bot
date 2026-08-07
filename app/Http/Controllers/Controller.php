<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Services\Auth\AuthService;
use Paragon\Core\Controller as KernelController;
use Paragon\Core\Response;
use Paragon\Core\View;

/**
 * Gold Bot's base controller.
 *
 * The kernel's controller is deliberately unaware of authentication (ADR-02):
 * a kernel that knows what a user is has stopped being a kernel. The one thing
 * Gold Bot adds here is the signed-in user, because every page's layout needs
 * it and threading it through fifteen render() calls invites the one omission
 * that renders a page with no user menu.
 */
abstract class Controller extends KernelController
{
    public function __construct(
        View $view,
        protected readonly AuthService $auth
    ) {
        parent::__construct($view);
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        // currentPath is deliberately NOT defaulted here. ShareViewData has
        // already shared the real request path; passing a null through $data
        // overwrote it, and the layout's `$currentPath ?? '/'` then fell back
        // to the root — which silently left every page's sidebar highlighting
        // Overview.
        return parent::render($template, [
            'user' => $this->auth->user(),
            ...$data,
        ], $layout);
    }
}
