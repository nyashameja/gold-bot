<?php

declare(strict_types=1);

namespace GoldBot\Domain\Market\Enums;

enum Direction: string
{
    case Buy  = 'BUY';
    case Sell = 'SELL';

    public function isBuy(): bool
    {
        return $this === self::Buy;
    }

    public function opposite(): self
    {
        return $this === self::Buy ? self::Sell : self::Buy;
    }

    /** +1 for a long, -1 for a short — for signed distance arithmetic. */
    public function sign(): int
    {
        return $this === self::Buy ? 1 : -1;
    }
}
