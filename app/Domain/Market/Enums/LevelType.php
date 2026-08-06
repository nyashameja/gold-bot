<?php

declare(strict_types=1);

namespace GoldBot\Domain\Market\Enums;

enum LevelType: string
{
    case Support     = 'SUPPORT';
    case Resistance  = 'RESISTANCE';
    case SupplyZone  = 'SUPPLY_ZONE';
    case DemandZone  = 'DEMAND_ZONE';
    case DailyHigh   = 'DAILY_HIGH';
    case DailyLow    = 'DAILY_LOW';
    case WeeklyHigh  = 'WEEKLY_HIGH';
    case WeeklyLow   = 'WEEKLY_LOW';

    /** Whether the level is a band rather than a single price. */
    public function isZone(): bool
    {
        return $this === self::SupplyZone || $this === self::DemandZone;
    }

    /** Whether price is expected to find support (below) or resistance (above). */
    public function isBelowPrice(): bool
    {
        return match ($this) {
            self::Support, self::DemandZone, self::DailyLow, self::WeeklyLow => true,
            default => false,
        };
    }
}
