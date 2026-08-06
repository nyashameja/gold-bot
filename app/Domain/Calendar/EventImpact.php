<?php

declare(strict_types=1);

namespace GoldBot\Domain\Calendar;

/**
 * Expected market impact of an economic event.
 *
 * Holiday is a fourth level rather than noise: a bank holiday means thin
 * liquidity, which is a legitimate reason to suppress signals even though no
 * data is released (docs/02 §6).
 */
enum EventImpact: string
{
    case Low     = 'LOW';
    case Medium  = 'MEDIUM';
    case High    = 'HIGH';
    case Holiday = 'HOLIDAY';

    /** Whether this impact level should trigger a blackout by default. */
    public function isTradeRelevant(): bool
    {
        return $this === self::High || $this === self::Holiday;
    }

    public function weight(): int
    {
        return match ($this) {
            self::Low     => 1,
            self::Medium  => 2,
            self::High    => 3,
            self::Holiday => 3,
        };
    }

    /**
     * Parse a provider's impact label.
     *
     * ForexFactory uses High/Medium/Low/Holiday; some feeds use numbers or
     * "med". Unrecognised values fall back to Low rather than throwing — a new
     * label upstream must not stop the whole import.
     */
    public static function parse(?string $value, self $fallback = self::Low): self
    {
        if ($value === null) {
            return $fallback;
        }

        return match (strtolower(trim($value))) {
            'high', '3'             => self::High,
            'medium', 'med', '2'    => self::Medium,
            'low', '1'              => self::Low,
            'holiday', 'non-economic', '0' => self::Holiday,
            default                 => $fallback,
        };
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
