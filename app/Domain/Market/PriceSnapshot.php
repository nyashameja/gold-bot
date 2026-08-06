<?php

declare(strict_types=1);

namespace GoldBot\Domain\Market;

use DateTimeImmutable;

/**
 * A point-in-time quote.
 *
 * Carries both the provider's timestamp and our capture time so the dashboard
 * can display genuine data age. Showing a stale price as current is the single
 * most misleading thing a trading dashboard can do (docs/01 §8).
 */
final class PriceSnapshot
{
    public function __construct(
        public readonly string $price,
        public readonly DateTimeImmutable $capturedAt,
        public readonly ?DateTimeImmutable $providerTime = null,
        public readonly ?string $bid = null,
        public readonly ?string $ask = null,
        public readonly ?string $dayHigh = null,
        public readonly ?string $dayLow = null,
        public readonly ?string $changeAbsolute = null,
        public readonly ?string $changePercent = null
    ) {
    }

    /** Bid-ask spread, or null when the provider gives no two-sided quote. */
    public function spread(): ?string
    {
        if ($this->bid === null || $this->ask === null) {
            return null;
        }

        return number_format((float) $this->ask - (float) $this->bid, 5, '.', '');
    }

    /** Seconds since the data was true at the provider, falling back to capture. */
    public function ageSeconds(DateTimeImmutable $now): int
    {
        $reference = $this->providerTime ?? $this->capturedAt;

        return max(0, $now->getTimestamp() - $reference->getTimestamp());
    }

    /**
     * Whether the quote is too old to present as live.
     *
     * The caller supplies the threshold, because what counts as stale differs
     * by context: a 90-second-old price is fine on the Overview tile and not
     * fine for deciding whether a stop was hit.
     */
    public function isStale(DateTimeImmutable $now, int $maxAgeSeconds): bool
    {
        return $this->ageSeconds($now) > $maxAgeSeconds;
    }
}
