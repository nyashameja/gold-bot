<?php

declare(strict_types=1);

namespace GoldBot\Domain\Health;

/**
 * The result of one health check.
 *
 * The message is written for a human reading an alert at an awkward hour: it
 * names what is wrong and, where possible, the number that says so. "Scheduler
 * degraded" tells an operator nothing they can act on; "Overdue: market.price,
 * market.candles" tells them where to look.
 */
final readonly class HealthReport
{
    /** @param array<string,mixed> $metrics */
    public function __construct(
        public string $component,
        public string $label,
        public HealthStatus $status,
        public string $message,
        public array $metrics = [],
        public ?int $durationMs = null
    ) {
    }

    /** @param array<string,mixed> $metrics */
    public static function ok(string $component, string $label, string $message, array $metrics = []): self
    {
        return new self($component, $label, HealthStatus::Ok, $message, $metrics);
    }

    /** @param array<string,mixed> $metrics */
    public static function warning(string $component, string $label, string $message, array $metrics = []): self
    {
        return new self($component, $label, HealthStatus::Warning, $message, $metrics);
    }

    /** @param array<string,mixed> $metrics */
    public static function critical(string $component, string $label, string $message, array $metrics = []): self
    {
        return new self($component, $label, HealthStatus::Critical, $message, $metrics);
    }

    /** @param array<string,mixed> $metrics */
    public static function unknown(string $component, string $label, string $message, array $metrics = []): self
    {
        return new self($component, $label, HealthStatus::Unknown, $message, $metrics);
    }

    public function withDuration(int $milliseconds): self
    {
        return new self(
            $this->component,
            $this->label,
            $this->status,
            $this->message,
            $this->metrics,
            $milliseconds
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'component'   => $this->component,
            'label'       => $this->label,
            'status'      => $this->status->value,
            'message'     => $this->message,
            'metrics'     => $this->metrics,
            'duration_ms' => $this->durationMs,
        ];
    }
}
