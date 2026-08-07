<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Signal\SignalEventType;
use GoldBot\Domain\Signal\SignalState;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;

/**
 * Advances open signals through their lifecycle (docs/01 §7).
 *
 * Tracking walks CANDLES rather than sampling the live quote. A quote is a
 * point sample taken once a minute; between two samples price can travel
 * through a stop and back, and the signal would never notice. A candle's
 * high and low cover the whole interval, so nothing is missed — and because
 * the input is stored data, the same code can replay history in a backtest
 * (ADR-04).
 *
 * The unavoidable ambiguity: when a single candle's range spans both the stop
 * and a target, the order within that candle is unknowable. This assumes the
 * STOP came first. That is the pessimistic reading, and the right default —
 * a tracker that resolves ambiguity in its own favour reports a win rate the
 * live account will never reproduce.
 */
final class SignalLifecycleService
{
    public function __construct(
        private readonly SignalRepositoryInterface $signals,
        private readonly CandleRepositoryInterface $candles,
        private readonly SignalPublisher $publisher,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{checked:int,activated:int,targets:int,stopped:int,expired:int,closed:list<DateTimeImmutable>}
     */
    public function track(): array
    {
        $checked = 0;
        $activated = 0;
        $targetsHit = 0;
        $stopped = 0;
        $expired = 0;

        // Close times of anything that finished in this run. Returned rather
        // than acted on here: rebuilding a performance snapshot is reporting
        // work, and a service that advances signal state has no business
        // reaching into it (docs/01 §4). The task above decides what to do.
        $closed = [];

        $now = $this->clock->now();

        foreach ($this->signals->open() as $signal) {
            $checked++;

            $signalId = (int) $signal['id'];
            $state = SignalState::from((string) $signal['state']);
            $direction = Direction::from((string) $signal['direction']);

            $since = $this->lastEventTime($signal, $signalId);

            $candles = $this->candles->between(
                (int) $signal['instrument_id'],
                (int) $signal['timeframe_id'],
                $since,
                $now,
                closedOnly: true
            );

            foreach ($candles as $candle) {
                if ($candle->openTime < $since) {
                    continue;
                }

                $outcome = $this->applyCandle($signal, $signalId, $state, $direction, $candle);

                $state = $outcome['state'];
                $activated += $outcome['activated'];
                $targetsHit += $outcome['targets'];
                $stopped += $outcome['stopped'];

                if ($state->isClosed()) {
                    $closed[] = $candle->closeTime;
                    break;
                }
            }

            // Expiry is checked last: a signal whose entry filled in the same
            // window has activated, and an activated signal does not expire.
            if ($state === SignalState::Pending && $this->hasExpired($signal, $now)) {
                if ($this->publisher->recordTransition($signalId, SignalEventType::Expired, $now)) {
                    $expired++;
                    // An expired signal never traded, so it changes no metric.
                    // Not recorded as a close for that reason.
                }
            }
        }

        return [
            'checked'   => $checked,
            'activated' => $activated,
            'targets'   => $targetsHit,
            'stopped'   => $stopped,
            'expired'   => $expired,
            'closed'    => $closed,
        ];
    }

    /**
     * @param array<string,mixed> $signal
     * @return array{state:SignalState,activated:int,targets:int,stopped:int}
     */
    private function applyCandle(
        array $signal,
        int $signalId,
        SignalState $state,
        Direction $direction,
        Candle $candle
    ): array {
        $activated = 0;
        $targets = 0;
        $stopped = 0;

        $high = (float) $candle->high;
        $low = (float) $candle->low;
        $entry = (float) $signal['entry_price'];
        $stop = (float) $signal['stop_loss'];

        // Entry fills when the candle's range covers the entry price.
        if ($state === SignalState::Pending && $high >= $entry && $low <= $entry) {
            if ($this->publisher->recordTransition($signalId, SignalEventType::EntryActivated, $candle->closeTime, $entry)) {
                $state = SignalState::Active;
                $activated++;
            }
        }

        if (!$state->isOpen()) {
            return ['state' => $state, 'activated' => $activated, 'targets' => $targets, 'stopped' => $stopped];
        }

        if ($state === SignalState::Pending) {
            return ['state' => $state, 'activated' => $activated, 'targets' => $targets, 'stopped' => $stopped];
        }

        // Stop first — see the class comment on intra-candle ambiguity.
        $stopHit = $direction->isBuy() ? $low <= $stop : $high >= $stop;

        if ($stopHit) {
            // At breakeven the stop sits at entry, so that is the exit price.
            $exit = $state === SignalState::Breakeven ? $entry : $stop;

            if ($this->publisher->recordTransition($signalId, SignalEventType::StopLossHit, $candle->closeTime, $exit)) {
                $closed = $this->recordRealisedR($signalId, $signal, $exit, $direction);
                $stopped++;

                return ['state' => $closed, 'activated' => $activated, 'targets' => $targets, 'stopped' => $stopped];
            }
        }

        foreach ($this->signals->targets($signalId) as $target) {
            if ($target['hit_at'] !== null) {
                continue;
            }

            $level = (int) $target['level'];
            $price = (float) $target['price'];
            $reached = $direction->isBuy() ? $high >= $price : $low <= $price;

            if (!$reached) {
                // Targets are ordered, so the first unreached one ends the walk.
                break;
            }

            $this->signals->markTargetHit($signalId, $level, $candle->closeTime, $price);

            $event = SignalEventType::forTarget($level);

            if ($this->publisher->recordTransition($signalId, $event, $candle->closeTime, $price)) {
                $targets++;
            }

            if ($event === SignalEventType::Tp3Hit) {
                $closed = $this->recordRealisedR($signalId, $signal, $price, $direction);

                return ['state' => $closed, 'activated' => $activated, 'targets' => $targets, 'stopped' => $stopped];
            }

            // Move the stop to entry after the first target, if configured.
            if ($level === 1 && (bool) $this->settings->get('signals.breakeven_after_tp1', true)) {
                if ($this->publisher->recordTransition($signalId, SignalEventType::MovedToBreakeven, $candle->closeTime, $entry)) {
                    $state = SignalState::Breakeven;
                }
            }
        }

        return ['state' => $state, 'activated' => $activated, 'targets' => $targets, 'stopped' => $stopped];
    }

    /**
     * Outcome in R multiples — the currency of all performance reporting,
     * because percentages mislead once position sizes differ.
     *
     * @param array<string,mixed> $signal
     */
    private function recordRealisedR(int $signalId, array $signal, float $exitPrice, Direction $direction): SignalState
    {
        $entry = (float) $signal['entry_price'];
        $risk = (float) $signal['risk_distance'];

        if ($risk <= 0.0) {
            return SignalState::ClosedBreakeven;
        }

        $realised = round((($exitPrice - $entry) * $direction->sign()) / $risk, 3);

        $closed = match (true) {
            $realised > 0.0 => SignalState::ClosedWin,
            $realised < 0.0 => SignalState::ClosedLoss,
            default         => SignalState::ClosedBreakeven,
        };

        $this->signals->updateState($signalId, $closed, $this->clock->now(), null, $realised);

        $this->logger->info('Signal closed', [
            'event'      => 'signal.closed',
            'signal_id'  => $signalId,
            'realised_r' => $realised,
            'exit'       => $exitPrice,
        ]);

        return $closed;
    }

    /**
     * Where to resume from: the last recorded event, so a candle is never
     * evaluated twice and a restart cannot re-fire a transition.
     *
     * @param array<string,mixed> $signal
     */
    private function lastEventTime(array $signal, int $signalId): DateTimeImmutable
    {
        $events = $this->signals->events($signalId);
        $utc = new DateTimeZone('UTC');

        if ($events === []) {
            return new DateTimeImmutable((string) $signal['generated_at'], $utc);
        }

        return new DateTimeImmutable((string) $events[count($events) - 1]['occurred_at'], $utc);
    }

    /** @param array<string,mixed> $signal */
    private function hasExpired(array $signal, DateTimeImmutable $now): bool
    {
        if ($signal['expires_at'] === null) {
            return false;
        }

        return new DateTimeImmutable((string) $signal['expires_at'], new DateTimeZone('UTC')) < $now;
    }
}
