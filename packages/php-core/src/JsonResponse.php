<?php

declare(strict_types=1);

namespace Paragon\Core;

/**
 * A JSON response.
 *
 * Dashboard polling endpoints return these. Every payload built by the
 * internal controllers carries a data-age field so the UI can show staleness
 * rather than presenting an old price as current (docs/01 §8).
 */
final class JsonResponse extends Response
{
    /** @param array<string,string> $headers */
    public function __construct(mixed $data = null, int $status = 200, array $headers = [])
    {
        parent::__construct(
            json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ) ?: '{}',
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                // Market data must never be cached by an intermediary — a
                // stale price served from cache is worse than no price.
                'Cache-Control' => 'no-store, private',
                ...$headers,
            ]
        );
    }

    /** @param array<string,mixed> $extra */
    public static function error(string $message, int $status = 400, array $extra = []): self
    {
        return new self(['error' => $message, ...$extra], $status);
    }
}
