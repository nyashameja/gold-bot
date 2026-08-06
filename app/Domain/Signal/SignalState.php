<?php

declare(strict_types=1);

namespace GoldBot\Domain\Signal;

/**
 * Where a signal is in its life (docs/01 §7).
 *
 * A read-optimised projection of signal_events, which is the source of truth
 * (ADR-05).
 */
enum SignalState: string
{
    case Pending     = 'PENDING';
    case Active      = 'ACTIVE';
    case ClosedWin   = 'CLOSED_WIN';
    case ClosedLoss  = 'CLOSED_LOSS';
    case Breakeven   = 'BREAKEVEN';
    case Cancelled   = 'CANCELLED';
    case Expired     = 'EXPIRED';

    /** Still capable of changing — what the lifecycle tracker polls. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Active;
    }

    public function isClosed(): bool
    {
        return !$this->isOpen();
    }

    /** Counts toward win rate. Cancelled and expired never traded. */
    public function countsTowardPerformance(): bool
    {
        return $this === self::ClosedWin
            || $this === self::ClosedLoss
            || $this === self::Breakeven;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pending',
            self::Active     => 'Active',
            self::ClosedWin  => 'Win',
            self::ClosedLoss => 'Loss',
            self::Breakeven  => 'Breakeven',
            self::Cancelled  => 'Cancelled',
            self::Expired    => 'Expired',
        };
    }
}
