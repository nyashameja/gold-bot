<?php

declare(strict_types=1);

namespace GoldBot\Integrations\MarketData;

use RuntimeException;
use Throwable;

/**
 * A market data fetch failed.
 *
 * `retryable` distinguishes a transient fault from a permanent one so the
 * caller knows whether backing off is worth anything. A bad API key will fail
 * identically forever, and retrying it only burns quota.
 */
final class MarketDataException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transport(string $message, ?int $status = null): self
    {
        return new self($message, retryable: true, httpStatus: $status);
    }

    public static function badResponse(string $message, ?int $status = null): self
    {
        return new self($message, retryable: false, httpStatus: $status);
    }

    public static function rateLimited(string $message): self
    {
        return new self($message, retryable: true, httpStatus: 429);
    }
}
