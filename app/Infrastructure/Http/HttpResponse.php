<?php

declare(strict_types=1);

namespace GoldBot\Infrastructure\Http;

/**
 * An outbound HTTP response.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly int $durationMs,
        public readonly ?string $error = null
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * Whether retrying could plausibly succeed.
     *
     * 4xx other than 408 and 429 are the caller's fault and will fail
     * identically forever — retrying them just burns API budget.
     */
    public function isRetryable(): bool
    {
        if ($this->error !== null) {
            return true; // Transport-level failure: timeout, DNS, connection reset.
        }

        return $this->status === 408
            || $this->status === 429
            || $this->status >= 500;
    }

    /** @return array<string,mixed>|null */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
