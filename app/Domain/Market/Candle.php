<?php

declare(strict_types=1);

namespace GoldBot\Domain\Market;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single OHLCV bar.
 *
 * Prices are strings, not floats (ADR-11). They arrive from the database as
 * DECIMAL strings and stay that way through comparison and storage; only
 * arithmetic converts, and only where a small rounding error cannot become a
 * wrong stop-loss decision.
 *
 * Immutable and free of I/O — Domain layer (ADR-03).
 */
final class Candle
{
    public function __construct(
        public readonly DateTimeImmutable $openTime,
        public readonly DateTimeImmutable $closeTime,
        public readonly string $open,
        public readonly string $high,
        public readonly string $low,
        public readonly string $close,
        public readonly string $volume = '0',
        public readonly bool $isClosed = false,
        public readonly ?int $id = null
    ) {
        // A bar whose high is below its low, or whose open sits outside the
        // range, is corrupt. Catching it here stops bad provider data reaching
        // the indicator pipeline looking plausible.
        if ((float) $this->high < (float) $this->low) {
            throw new InvalidArgumentException(
                sprintf('Candle at %s has high (%s) below low (%s).', $openTime->format('c'), $high, $low)
            );
        }

        foreach (['open' => $open, 'close' => $close] as $label => $value) {
            if ((float) $value > (float) $high || (float) $value < (float) $low) {
                throw new InvalidArgumentException(
                    sprintf('Candle at %s has %s (%s) outside its high/low range.', $openTime->format('c'), $label, $value)
                );
            }
        }
    }

    public function isBullish(): bool
    {
        return (float) $this->close > (float) $this->open;
    }

    public function isBearish(): bool
    {
        return (float) $this->close < (float) $this->open;
    }

    /** High minus low — the bar's full range. */
    public function range(): float
    {
        return (float) $this->high - (float) $this->low;
    }

    /** Absolute open-to-close distance. */
    public function bodySize(): float
    {
        return abs((float) $this->close - (float) $this->open);
    }

    public function upperWick(): float
    {
        return (float) $this->high - max((float) $this->open, (float) $this->close);
    }

    public function lowerWick(): float
    {
        return min((float) $this->open, (float) $this->close) - (float) $this->low;
    }

    /** The midpoint of the bar's range. */
    public function midpoint(): float
    {
        return ((float) $this->high + (float) $this->low) / 2;
    }

    public function closedAsFloat(): float
    {
        return (float) $this->close;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'open_time'  => $this->openTime->format('Y-m-d H:i:s'),
            'close_time' => $this->closeTime->format('Y-m-d H:i:s'),
            'open'       => $this->open,
            'high'       => $this->high,
            'low'        => $this->low,
            'close'      => $this->close,
            'volume'     => $this->volume,
            'is_closed'  => $this->isClosed,
        ];
    }
}
