<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use GoldBot\Domain\Signal\SignalEventType;
use GoldBot\Domain\Signal\SignalLifecycle;
use GoldBot\Domain\Signal\SignalState;
use PHPUnit\Framework\TestCase;

/**
 * The signal state machine (docs/01 §7).
 *
 * Held as an explicit transition table so an illegal move is impossible to
 * write by accident — a late or out-of-order tick must never resurrect a
 * closed signal.
 */
final class SignalLifecycleTest extends TestCase
{
    private SignalLifecycle $lifecycle;

    protected function setUp(): void
    {
        $this->lifecycle = new SignalLifecycle();
    }

    public function test_the_happy_path_is_allowed(): void
    {
        self::assertTrue($this->lifecycle->canTransition(SignalState::Pending, SignalState::Active));
        self::assertTrue($this->lifecycle->canTransition(SignalState::Active, SignalState::ClosedWin));
    }

    public function test_a_pending_signal_can_expire_or_be_cancelled(): void
    {
        self::assertTrue($this->lifecycle->canTransition(SignalState::Pending, SignalState::Expired));
        self::assertTrue($this->lifecycle->canTransition(SignalState::Pending, SignalState::Cancelled));
    }

    /**
     * The property the table exists to guarantee: a closed signal is closed.
     */
    public function test_a_closed_signal_cannot_reopen(): void
    {
        foreach ([SignalState::ClosedWin, SignalState::ClosedLoss, SignalState::ClosedBreakeven, SignalState::Expired, SignalState::Cancelled] as $terminal) {
            foreach (SignalState::cases() as $target) {
                self::assertFalse(
                    $this->lifecycle->canTransition($terminal, $target),
                    sprintf('%s must not transition to %s.', $terminal->value, $target->value)
                );
            }
        }
    }

    public function test_a_pending_signal_cannot_close_without_activating(): void
    {
        self::assertFalse($this->lifecycle->canTransition(SignalState::Pending, SignalState::ClosedWin));
        self::assertFalse($this->lifecycle->canTransition(SignalState::Pending, SignalState::ClosedLoss));
    }

    /**
     * Breakeven means "stop moved to entry", not "finished". Treating it as
     * closed stops the tracker dead after TP1, while TP2 and TP3 are still
     * reachable — which is exactly the bug this separation prevents.
     */
    public function test_breakeven_is_an_open_state_distinct_from_closing_flat(): void
    {
        self::assertTrue(SignalState::Breakeven->isOpen(), 'The position is still running.');
        self::assertTrue(SignalState::ClosedBreakeven->isClosed());

        self::assertTrue($this->lifecycle->canTransition(SignalState::Breakeven, SignalState::ClosedWin));
        self::assertTrue($this->lifecycle->canTransition(SignalState::Breakeven, SignalState::ClosedBreakeven));
    }

    /** A stop already at entry closes flat — not as a loss. */
    public function test_a_stop_from_breakeven_closes_flat(): void
    {
        self::assertSame(
            SignalState::ClosedBreakeven,
            $this->lifecycle->stateAfter(SignalEventType::StopLossHit, SignalState::Breakeven)
        );
    }

    public function test_entry_activation_moves_a_pending_signal_to_active(): void
    {
        self::assertSame(
            SignalState::Active,
            $this->lifecycle->stateAfter(SignalEventType::EntryActivated, SignalState::Pending)
        );
    }

    /** Partial targets do not change state: the signal stays open. */
    public function test_tp1_and_tp2_do_not_change_state(): void
    {
        self::assertNull($this->lifecycle->stateAfter(SignalEventType::Tp1Hit, SignalState::Active));
        self::assertNull($this->lifecycle->stateAfter(SignalEventType::Tp2Hit, SignalState::Active));
    }

    public function test_the_final_target_closes_the_signal_as_a_win(): void
    {
        self::assertSame(
            SignalState::ClosedWin,
            $this->lifecycle->stateAfter(SignalEventType::Tp3Hit, SignalState::Active)
        );
    }

    /**
     * A stop already moved to entry closes flat, not at a loss. Recording it
     * as a loss would understate the strategy's win rate.
     */
    public function test_a_stop_after_breakeven_closes_flat_rather_than_at_a_loss(): void
    {
        self::assertSame(
            SignalState::ClosedLoss,
            $this->lifecycle->stateAfter(SignalEventType::StopLossHit, SignalState::Active)
        );

        self::assertSame(
            SignalState::ClosedBreakeven,
            $this->lifecycle->stateAfter(SignalEventType::StopLossHit, SignalState::Breakeven)
        );
    }

    public function test_an_event_implying_an_illegal_transition_yields_null(): void
    {
        // A stop cannot fire on a signal that never activated.
        self::assertNull($this->lifecycle->stateAfter(SignalEventType::StopLossHit, SignalState::Pending));

        // Nor on one already closed.
        self::assertNull($this->lifecycle->stateAfter(SignalEventType::Tp3Hit, SignalState::ClosedLoss));
    }

    public function test_open_and_closed_states_are_classified(): void
    {
        self::assertTrue(SignalState::Pending->isOpen());
        self::assertTrue(SignalState::Active->isOpen());
        self::assertTrue(SignalState::ClosedWin->isClosed());
        self::assertTrue(SignalState::Expired->isClosed());
    }

    /** Cancelled and expired signals never traded, so they cannot be scored. */
    public function test_only_traded_outcomes_count_toward_performance(): void
    {
        self::assertTrue(SignalState::ClosedWin->countsTowardPerformance());
        self::assertTrue(SignalState::ClosedLoss->countsTowardPerformance());
        self::assertTrue(SignalState::ClosedBreakeven->countsTowardPerformance());
        self::assertFalse(SignalState::Breakeven->countsTowardPerformance(), 'Still running.');

        self::assertFalse(SignalState::Cancelled->countsTowardPerformance());
        self::assertFalse(SignalState::Expired->countsTowardPerformance());
        self::assertFalse(SignalState::Pending->countsTowardPerformance());
    }

    public function test_target_events_map_by_level(): void
    {
        self::assertSame(SignalEventType::Tp1Hit, SignalEventType::forTarget(1));
        self::assertSame(SignalEventType::Tp2Hit, SignalEventType::forTarget(2));
        self::assertSame(SignalEventType::Tp3Hit, SignalEventType::forTarget(3));
    }

    public function test_terminal_events_are_identified(): void
    {
        self::assertTrue(SignalEventType::StopLossHit->isTerminal());
        self::assertTrue(SignalEventType::Tp3Hit->isTerminal());
        self::assertTrue(SignalEventType::Expired->isTerminal());
        self::assertFalse(SignalEventType::Tp1Hit->isTerminal());
        self::assertFalse(SignalEventType::EntryActivated->isTerminal());
    }
}
