<?php

declare(strict_types=1);

namespace GoldBot\Domain\Market;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A timeframe and the candle-boundary arithmetic that goes with it.
 *
 * `minutes` is stored so boundaries are computed arithmetically rather than
 * with a per-timeframe branch (docs/02 §4) — adding M5 or M30 needs a row,
 * not a code change.
 */
final class Timeframe
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly int $minutes,
        public readonly string $providerInterval,
        public readonly bool $isActive = true
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (int) $row['minutes'],
            (string) $row['provider_interval'],
            (int) $row['is_active'] === 1
        );
    }

    public function seconds(): int
    {
        return $this->minutes * 60;
    }

    /**
     * The open time of the candle containing $moment.
     *
     * Floors against the Unix epoch, which aligns correctly for every
     * timeframe that divides a day evenly — all of ours do. D1 bars align to
     * 00:00 UTC, which is what Twelve Data returns for XAU/USD.
     */
    public function candleOpenFor(DateTimeImmutable $moment): DateTimeImmutable
    {
        $step = $this->seconds();
        $floored = intdiv($moment->getTimestamp(), $step) * $step;

        return (new DateTimeImmutable('@' . $floored))->setTimezone(new DateTimeZone('UTC'));
    }

    public function candleCloseFor(DateTimeImmutable $openTime): DateTimeImmutable
    {
        // Close is the last instant *within* the bar, so a 15-minute candle
        // opening at 10:00 closes at 10:14:59 — not 10:15, which is the next
        // bar's open. Getting this wrong makes adjacent bars overlap by a
        // second, and every "which candle is this tick in" test disagree.
        return $openTime->modify(sprintf('+%d seconds', $this->seconds() - 1));
    }

    /**
     * Whether the candle opening at $openTime has finished, as of $now.
     *
     * A settle margin lets the provider publish the closed bar: requesting at
     * exactly the boundary frequently returns the previous candle still marked
     * open (docs/01 §5).
     */
    public function isClosedAt(DateTimeImmutable $openTime, DateTimeImmutable $now, int $settleSeconds = 0): bool
    {
        return $now->getTimestamp() >= ($openTime->getTimestamp() + $this->seconds() + $settleSeconds);
    }
}
