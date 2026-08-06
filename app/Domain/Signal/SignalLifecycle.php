<?php

declare(strict_types=1);

namespace GoldBot\Domain\Signal;

/**
 * The legal transitions of a signal (docs/01 §7).
 *
 * Kept as an explicit table rather than scattered conditionals so an illegal
 * transition is impossible to write by accident — a signal must never move
 * from CLOSED_LOSS back to ACTIVE because a late tick arrived out of order.
 *
 * Pure: no I/O (ADR-03).
 */
final class SignalLifecycle
{
    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'PENDING' => ['ACTIVE', 'CANCELLED', 'EXPIRED'],
        'ACTIVE'  => ['CLOSED_WIN', 'CLOSED_LOSS', 'BREAKEVEN', 'CANCELLED'],
        // Breakeven is not terminal: the stop has moved to entry but targets
        // above it can still be reached.
        'BREAKEVEN' => ['CLOSED_WIN', 'CLOSED_LOSS', 'CANCELLED'],
    ];

    public function canTransition(SignalState $from, SignalState $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /** @return list<SignalState> */
    public function allowedFrom(SignalState $state): array
    {
        return array_map(
            static fn (string $value): SignalState => SignalState::from($value),
            self::TRANSITIONS[$state->value] ?? []
        );
    }

    /** The state an event implies, or null if the event does not change state. */
    public function stateAfter(SignalEventType $event, SignalState $current): ?SignalState
    {
        $next = match ($event) {
            SignalEventType::EntryActivated   => SignalState::Active,
            SignalEventType::StopLossHit      => $current === SignalState::Breakeven
                // A stop already at entry closes flat, not at a loss.
                ? SignalState::Breakeven
                : SignalState::ClosedLoss,
            SignalEventType::Tp3Hit           => SignalState::ClosedWin,
            SignalEventType::MovedToBreakeven => SignalState::Breakeven,
            SignalEventType::Cancelled        => SignalState::Cancelled,
            SignalEventType::Expired          => SignalState::Expired,
            // TP1 and TP2 are partial: the signal remains open.
            default                           => null,
        };

        if ($next === null || $next === $current) {
            return null;
        }

        return $this->canTransition($current, $next) ? $next : null;
    }
}
