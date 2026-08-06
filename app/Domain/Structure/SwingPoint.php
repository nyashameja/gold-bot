<?php

declare(strict_types=1);

namespace GoldBot\Domain\Structure;

use DateTimeImmutable;
use GoldBot\Domain\Market\Enums\StructureType;

/**
 * A confirmed swing high or low.
 */
final class SwingPoint
{
    public function __construct(
        public readonly StructureType $type,
        public readonly float $price,
        public readonly DateTimeImmutable $occurredAt,
        public readonly int $index,
        public readonly int $strength = 1,
        public readonly ?int $candleId = null
    ) {
    }

    public function isHigh(): bool
    {
        return $this->type === StructureType::SwingHigh;
    }

    public function isLow(): bool
    {
        return $this->type === StructureType::SwingLow;
    }
}
