<?php

declare(strict_types=1);

namespace Paragon\Core\Clock;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock that only moves when told to.
 *
 * Used by tests and by the backtester (ADR-04), which advances it candle by
 * candle so that strategy code observing "now" sees the historical moment
 * being replayed rather than the present.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|string $now = 'now')
    {
        $this->now = is_string($now)
            ? new DateTimeImmutable($now, new DateTimeZone('UTC'))
            : $now->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    public function setTo(DateTimeImmutable|string $moment): void
    {
        $this->now = is_string($moment)
            ? new DateTimeImmutable($moment, new DateTimeZone('UTC'))
            : $moment->setTimezone(new DateTimeZone('UTC'));
    }

    /** Advance by an ISO-8601 duration, e.g. 'PT15M' for one M15 candle. */
    public function advance(string $duration): void
    {
        $this->now = $this->now->add(new DateInterval($duration));
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
