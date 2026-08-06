<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

use Closure;
use GoldBot\Core\Request;
use GoldBot\Core\Response;

interface MiddlewareInterface
{
    /**
     * Handle the request, optionally short-circuiting.
     *
     * Return $next($request) to continue, or a Response to stop the pipeline
     * — which is how Authenticate redirects without the controller running.
     */
    public function handle(Request $request, Closure $next): Response;
}
