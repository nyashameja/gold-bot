<?php

declare(strict_types=1);

namespace GoldBot\Domain\Performance;

/**
 * Which slice of the traded record a snapshot measures.
 *
 * Every dimension is optional; null means "all". The overall figures are just
 * the scope with nothing set, which is why one table serves both the headline
 * numbers and every breakdown.
 *
 * The key() below exists because MySQL treats NULLs as distinct in a UNIQUE
 * index — a natural key over the nullable columns alone would let the same
 * overall snapshot be stored a hundred times without complaint. Rendering the
 * tuple to a string with a literal for "all" makes the constraint constrain.
 */
final readonly class SnapshotScope
{
    private const ALL = '*';

    public function __construct(
        public ?int $strategyId = null,
        public ?int $instrumentId = null,
        public ?string $sessionCode = null,
        public ?int $timeframeId = null,
        public ?string $direction = null
    ) {
    }

    public static function overall(): self
    {
        return new self();
    }

    public static function forStrategy(int $strategyId): self
    {
        return new self(strategyId: $strategyId);
    }

    public static function forSession(string $sessionCode): self
    {
        return new self(sessionCode: $sessionCode);
    }

    public static function forDirection(string $direction): self
    {
        return new self(direction: $direction);
    }

    public static function forTimeframe(int $timeframeId): self
    {
        return new self(timeframeId: $timeframeId);
    }

    /** Stable, deterministic, and short enough for an indexed column. */
    public function key(): string
    {
        return implode('|', [
            $this->strategyId ?? self::ALL,
            $this->instrumentId ?? self::ALL,
            $this->sessionCode ?? self::ALL,
            $this->timeframeId ?? self::ALL,
            $this->direction ?? self::ALL,
        ]);
    }

    public function isOverall(): bool
    {
        return $this->key() === implode('|', array_fill(0, 5, self::ALL));
    }

    /** @return array<string,mixed> */
    public function toColumns(): array
    {
        return [
            'strategy_id'   => $this->strategyId,
            'instrument_id' => $this->instrumentId,
            'session_code'  => $this->sessionCode,
            'timeframe_id'  => $this->timeframeId,
            'direction'     => $this->direction,
            'scope_key'     => $this->key(),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function fromColumns(array $row): self
    {
        return new self(
            $row['strategy_id'] === null ? null : (int) $row['strategy_id'],
            $row['instrument_id'] === null ? null : (int) $row['instrument_id'],
            $row['session_code'] === null ? null : (string) $row['session_code'],
            $row['timeframe_id'] === null ? null : (int) $row['timeframe_id'],
            $row['direction'] === null ? null : (string) $row['direction'],
        );
    }
}
