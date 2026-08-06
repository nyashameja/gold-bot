<?php

declare(strict_types=1);

namespace GoldBot\Integrations\Calendar;

use RuntimeException;
use Throwable;

final class CalendarException extends RuntimeException
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
}
