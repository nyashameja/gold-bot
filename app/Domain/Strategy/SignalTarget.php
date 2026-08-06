<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy;

/**
 * A take-profit level.
 *
 * `rMultiple` records the distance in units of risk, which is how performance
 * is reported: percentages mislead once position sizes differ.
 */
final class SignalTarget
{
    public function __construct(
        public readonly int $level,
        public readonly float $price,
        public readonly float $closePercent = 100.0,
        public readonly ?float $rMultiple = null
    ) {
    }
}
