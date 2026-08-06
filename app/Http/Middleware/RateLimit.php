<?php

declare(strict_types=1);

namespace GoldBot\Http\Middleware;

use Closure;
use GoldBot\Core\HttpException;
use GoldBot\Core\JsonResponse;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Infrastructure\Cache\CacheInterface;
use GoldBot\Infrastructure\Clock\ClockInterface;

/**
 * Fixed-window rate limiting, keyed by IP and path.
 *
 * A fixed window is less precise than a sliding one — a caller can send two
 * full allowances either side of a boundary — but it needs one cache entry
 * instead of a list of timestamps. For protecting a login form and a handful
 * of polling endpoints on shared hosting, that trade is right.
 *
 * The cache is per-process on APCu, so this bounds a single PHP-FPM worker
 * pool rather than the cluster. It is a courtesy limit and a brute-force
 * speed bump, not a security boundary — the account lockout in AuthService is
 * the actual control.
 */
final class RateLimit implements MiddlewareInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly int $maxRequests = 60,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $window = intdiv($this->clock->timestamp(), $this->windowSeconds);
        $key = sprintf('ratelimit:%s:%s:%d', $request->ip(), $request->path(), $window);

        $hits = (int) $this->cache->get($key, 0) + 1;
        $this->cache->set($key, $hits, $this->windowSeconds * 2);

        $remaining = max(0, $this->maxRequests - $hits);
        $resetsIn = (($window + 1) * $this->windowSeconds) - $this->clock->timestamp();

        if ($hits > $this->maxRequests) {
            if ($request->wantsJson()) {
                return JsonResponse::error('Too many requests. Please slow down.', 429)
                    ->withHeader('Retry-After', (string) $resetsIn);
            }

            throw HttpException::tooManyRequests();
        }

        return $next($request)->withHeaders([
            'X-RateLimit-Limit'     => (string) $this->maxRequests,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset'     => (string) $resetsIn,
        ]);
    }
}
