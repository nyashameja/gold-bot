<?php

declare(strict_types=1);

namespace GoldBot\Domain\Structure;

use DateTimeImmutable;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\Enums\LevelType;

/**
 * A price level or zone.
 *
 * Single levels carry the same value in `from` and `to`, so one shape serves
 * both and callers need no special case (docs/02 §5).
 */
final class PriceLevel
{
    public function __construct(
        public readonly LevelType $type,
        public readonly float $from,
        public readonly float $to,
        public readonly DateTimeImmutable $formedAt,
        public readonly int $strength = 1,
        public readonly int $touchCount = 0
    ) {
    }

    public function midpoint(): float
    {
        return ($this->from + $this->to) / 2;
    }

    public function contains(float $price): bool
    {
        return $price >= min($this->from, $this->to) && $price <= max($this->from, $this->to);
    }

    public function distanceFrom(float $price): float
    {
        if ($this->contains($price)) {
            return 0.0;
        }

        return min(abs($price - $this->from), abs($price - $this->to));
    }
}
