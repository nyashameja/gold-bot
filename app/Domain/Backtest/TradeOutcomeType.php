<?php

declare(strict_types=1);

namespace GoldBot\Domain\Backtest;

/**
 * How a simulated trade ended.
 *
 * `Open` and `Pending` are outcomes, not omissions. A run that ends while a
 * position is still running must say so — closing it at the last available
 * price would flatter any strategy that happened to be holding a winner when
 * the data ran out, and that bias is invisible in the headline figures.
 */
enum TradeOutcomeType: string
{
    /** Entry never filled before the run ended. */
    case Pending = 'PENDING';
    /** Filled and still running when the data ran out. */
    case Open    = 'OPEN';
    case Win     = 'WIN';
    case Loss    = 'LOSS';
    case Breakeven = 'BREAKEVEN';
    /** Entry never filled and the signal's expiry passed. */
    case Expired = 'EXPIRED';

    /** Whether this trade contributes to the performance figures. */
    public function isMeasurable(): bool
    {
        return $this === self::Win || $this === self::Loss || $this === self::Breakeven;
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }

    public function tone(): string
    {
        return match ($this) {
            self::Win       => 'bull',
            self::Loss      => 'bear',
            self::Open      => 'gold',
            default         => 'neutral',
        };
    }
}
