<?php

declare(strict_types=1);

namespace GoldBot\Domain\Structure;

use DateTimeImmutable;
use GoldBot\Domain\Market\Enums\StructureType;
use GoldBot\Domain\Market\Enums\TrendState;

/**
 * A structural event: a break of structure or a change of character.
 */
final class StructureBreak
{
    public function __construct(
        public readonly StructureType $type,
        public readonly float $price,
        public readonly DateTimeImmutable $occurredAt,
        public readonly int $index,
        /** The direction the break implies. */
        public readonly TrendState $impliedTrend,
        public readonly ?int $candleId = null
    ) {
    }

    public function isChangeOfCharacter(): bool
    {
        return $this->type === StructureType::Choch;
    }
}
