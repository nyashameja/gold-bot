<?php

declare(strict_types=1);

namespace Paragon\Core\Clock;

use DateTimeImmutable;

/**
 * The current time, as a dependency.
 *
 * Nothing in the application calls time(), date() or `new DateTime()` directly.
 * Session boundaries, news blackout windows, signal expiry and candle-close
 * detection are all time-dependent, and without this interface each of them
 * could only be tested by waiting. With it they become ordinary assertions
 * (docs/03 §2).
 *
 * The backtester (ADR-04) substitutes FrozenClock to replay history through
 * the same code paths the live engine uses.
 */
interface ClockInterface
{
    /** Current time, always UTC. */
    public function now(): DateTimeImmutable;

    /** Unix timestamp in whole seconds. */
    public function timestamp(): int;
}
