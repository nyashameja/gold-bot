<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

use Closure;
use GoldBot\Core\HttpException;
use GoldBot\Core\JsonResponse;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Services\Auth\AuthService;

/**
 * Permission enforcement.
 *
 * Route-level middleware is the first of two checks; services check again
 * before acting (docs/01 §10). Two checks because a UI-only check is not an
 * authorisation control — anyone can issue the POST directly.
 *
 * Subclasses bind a specific permission, since the router addresses
 * middleware by class name.
 */
abstract class Authorize implements MiddlewareInterface
{
    public function __construct(
        protected readonly AuthService $auth,
        protected readonly LoggerInterface $logger
    ) {
    }

    /** The permission slug this middleware requires. */
    abstract protected function permission(): string;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->user();
        $permission = $this->permission();

        if ($user === null || !$user->can($permission)) {
            $this->logger->warning('Authorisation denied', [
                'event'      => 'security.denied',
                'permission' => $permission,
                'user_id'    => $user?->id,
                'path'       => $request->path(),
                'ip'         => $request->ip(),
            ]);

            if ($request->wantsJson()) {
                return JsonResponse::error('You do not have permission to do that.', 403);
            }

            throw HttpException::forbidden();
        }

        return $next($request);
    }
}
