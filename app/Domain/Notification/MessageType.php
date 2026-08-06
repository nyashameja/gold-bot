<?php

declare(strict_types=1);

namespace GoldBot\Domain\Notification;

use GoldBot\Domain\Signal\SignalEventType;

/**
 * Every message Gold Bot can send.
 *
 * Doubles as the template code, so adding a message type means adding a
 * template row rather than a branch in the sender.
 */
enum MessageType: string
{
    case NewSignal        = 'signal.new';
    case EntryActivated   = 'signal.entry_activated';
    case Tp1Hit           = 'signal.tp1';
    case Tp2Hit           = 'signal.tp2';
    case Tp3Hit           = 'signal.tp3';
    case Breakeven        = 'signal.breakeven';
    case StopLoss         = 'signal.stop_loss';
    case Cancelled        = 'signal.cancelled';
    case Expired          = 'signal.expired';
    case DailySummary     = 'summary.daily';
    case WeeklySummary    = 'summary.weekly';
    case MonthlySummary   = 'summary.monthly';
    case SystemError      = 'system.error';
    case NewsWarning      = 'system.news_warning';
    case ApiFailure       = 'system.api_failure';

    /** The message a signal transition produces, if any. */
    public static function forSignalEvent(SignalEventType $event): ?self
    {
        return match ($event) {
            SignalEventType::Generated        => self::NewSignal,
            SignalEventType::EntryActivated   => self::EntryActivated,
            SignalEventType::Tp1Hit           => self::Tp1Hit,
            SignalEventType::Tp2Hit           => self::Tp2Hit,
            SignalEventType::Tp3Hit           => self::Tp3Hit,
            SignalEventType::MovedToBreakeven => self::Breakeven,
            SignalEventType::StopLossHit      => self::StopLoss,
            SignalEventType::Cancelled        => self::Cancelled,
            SignalEventType::Expired          => self::Expired,
            // SENT is our own bookkeeping, not something to announce.
            SignalEventType::Sent             => null,
        };
    }

    /**
     * Which subscription flag governs this message.
     *
     * Operational alerts must be routable away from a subscriber channel.
     */
    public function audience(): string
    {
        return match ($this) {
            self::DailySummary, self::WeeklySummary, self::MonthlySummary => 'receives_summaries',
            self::SystemError, self::NewsWarning, self::ApiFailure        => 'receives_alerts',
            default                                                       => 'receives_signals',
        };
    }

    /**
     * Delivery priority — lower drains first.
     *
     * System alerts outrank signals deliberately: a warning that the platform
     * is broken is worth more than one more trade idea.
     */
    public function priority(): int
    {
        return match ($this) {
            self::SystemError, self::ApiFailure => 1,
            self::NewsWarning                   => 3,
            self::NewSignal                     => 4,
            self::StopLoss, self::Tp3Hit        => 4,
            default                             => 5,
        };
    }
}
