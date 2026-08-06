<?php

declare(strict_types=1);

namespace GoldBot\Domain\Signal;

/**
 * Every transition a signal can record (ADR-05).
 *
 * Each maps to exactly one Telegram message type, so the delivery layer needs
 * no parallel vocabulary of its own.
 */
enum SignalEventType: string
{
    case Generated        = 'GENERATED';
    case Sent             = 'SENT';
    case EntryActivated   = 'ENTRY_ACTIVATED';
    case Tp1Hit           = 'TP1_HIT';
    case Tp2Hit           = 'TP2_HIT';
    case Tp3Hit           = 'TP3_HIT';
    case MovedToBreakeven = 'MOVED_TO_BREAKEVEN';
    case StopLossHit      = 'STOP_LOSS_HIT';
    case Cancelled        = 'CANCELLED';
    case Expired          = 'EXPIRED';

    public static function forTarget(int $level): self
    {
        return match ($level) {
            1       => self::Tp1Hit,
            2       => self::Tp2Hit,
            default => self::Tp3Hit,
        };
    }

    /** Whether this transition ends the signal's life. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Tp3Hit, self::StopLossHit, self::Cancelled, self::Expired => true,
            default => false,
        };
    }
}
