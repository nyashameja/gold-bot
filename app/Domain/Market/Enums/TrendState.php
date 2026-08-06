<?php

declare(strict_types=1);

namespace GoldBot\Domain\Market\Enums;

/**
 * Market structure read from swing points.
 *
 * Ranging is a first-class state, not a fallback: most setups are invalid
 * without a trend, so "we do not know" must be distinguishable from "flat".
 */
enum TrendState: string
{
    case Uptrend   = 'UPTREND';
    case Downtrend = 'DOWNTREND';
    case Ranging   = 'RANGING';
    case Unknown   = 'UNKNOWN';

    public function isTrending(): bool
    {
        return $this === self::Uptrend || $this === self::Downtrend;
    }

    public function agreesWith(Direction $direction): bool
    {
        return match ($this) {
            self::Uptrend   => $direction === Direction::Buy,
            self::Downtrend => $direction === Direction::Sell,
            default         => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Uptrend   => 'Uptrend',
            self::Downtrend => 'Downtrend',
            self::Ranging   => 'Ranging',
            self::Unknown   => 'Unknown',
        };
    }
}
