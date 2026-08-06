<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy;

use RuntimeException;

/**
 * An immutable, versioned strategy configuration (ADR-06).
 *
 * Every tunable parameter lives here rather than in code, so tuning never
 * requires a deploy — and every signal is permanently attributable to the
 * exact version that produced it, which is what keeps the performance history
 * from quietly becoming fiction when the weights change.
 */
final class StrategyConfig
{
    /** @param array<string,mixed> $values */
    public function __construct(
        public readonly int $id,
        public readonly int $strategyId,
        public readonly int $version,
        private readonly array $values
    ) {
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values, int $id = 0, int $strategyId = 0, int $version = 1): self
    {
        return new self($id, $strategyId, $version, $values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function require(string $key): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->get($key, $sentinel);

        if ($value === $sentinel) {
            throw new RuntimeException(
                sprintf('Strategy config version %d is missing required key [%s].', $this->version, $key)
            );
        }

        return $value;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /** @return array<mixed> */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
