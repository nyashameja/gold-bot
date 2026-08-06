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
    /**
     * Stop moved to entry — the position is risk-free but STILL RUNNING.
     * Distinct from ClosedBreakeven, which is the terminal outcome. Conflating
     * the two stops the tracker dead after TP1, because the signal reads as
     * finished while its remaining targets are still reachable.
     */
    case Breakeven   = 'BREAKEVEN';
    case ClosedWin   = 'CLOSED_WIN';
    case ClosedLoss  = 'CLOSED_LOSS';
    /** Closed at entry for zero — neither a win nor a loss. */
    case ClosedBreakeven = 'CLOSED_BREAKEVEN';
    case Cancelled   = 'CANCELLED';
    case Expired     = 'EXPIRED';

    /** Still capable of changing — what the lifecycle tracker polls. */
    public function isOpen(): bool
    {
        return $this === self::Pending
            || $this === self::Active
            || $this === self::Breakeven;
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
            || $this === self::ClosedBreakeven;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending         => 'Pending',
            self::Active          => 'Active',
            self::Breakeven       => 'Risk-free',
            self::ClosedWin       => 'Win',
            self::ClosedLoss      => 'Loss',
            self::ClosedBreakeven => 'Breakeven',
            self::Cancelled       => 'Cancelled',
            self::Expired         => 'Expired',
        };
    }
}
