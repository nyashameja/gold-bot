<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

use Closure;
use Paragon\Core\HttpException;
use Paragon\Core\Logging\LoggerInterface;
use Paragon\Core\MiddlewareInterface;
use Paragon\Core\Request;
use Paragon\Core\Response;
use Paragon\Core\Support\Csrf;

/**
 * Rejects state-changing requests without a valid CSRF token.
 */
final class VerifyCsrf implements MiddlewareInterface
{
    /** Verbs that cannot change state and so need no token. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly Csrf $csrf,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        // The header form covers fetch/XHR posts, which cannot easily carry a
        // form field.
        $token = $request->input('_token') ?? $request->header('X-CSRF-Token');

        if (!$this->csrf->isValid(is_string($token) ? $token : null)) {
            $this->logger->warning('CSRF verification failed', [
                'event'  => 'security.csrf_failed',
                'path'   => $request->path(),
                'method' => $request->method(),
                'ip'     => $request->ip(),
            ]);

            // 419 rather than 403: the overwhelmingly common cause is an
            // expired session, and the error page can say so.
            throw new HttpException(419, 'Your session has expired. Please refresh and try again.');
        }

        return $next($request);
    }
}
