<?php

declare(strict_types=1);

namespace Paragon\Core;

use RuntimeException;
use Throwable;

/**
 * An exception carrying an HTTP status code.
 *
 * Distinguishes "this request is invalid" from "the application broke", so the
 * error handler can show a 403 or 404 page without treating it as an incident.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode = 500,
        string $message = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($statusCode), $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message);
    }

    public static function unauthorised(string $message = ''): self
    {
        return new self(401, $message);
    }

    public static function tooManyRequests(string $message = ''): self
    {
        return new self(429, $message);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400     => 'The request could not be understood.',
            401     => 'Authentication is required.',
            403     => 'You do not have permission to do that.',
            404     => 'The page you requested does not exist.',
            405     => 'That method is not allowed here.',
            419     => 'Your session has expired. Please try again.',
            429     => 'Too many requests. Please slow down.',
            default => 'An unexpected error occurred.',
        };
    }
}
