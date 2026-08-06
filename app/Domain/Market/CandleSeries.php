<?php

declare(strict_types=1);

namespace GoldBot\Domain\Market;

use Countable;
use DateTimeImmutable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * An ordered, gap-checked sequence of candles.
 *
 * Always oldest-first, because every indicator is defined that way and a
 * silently reversed series produces plausible-looking but entirely wrong
 * values — the kind of bug that survives review.
 *
 * @implements IteratorAggregate<int,Candle>
 */
final class CandleSeries implements Countable, IteratorAggregate
{
    /** @var list<Candle> */
    private readonly array $candles;

    /** @param list<Candle> $candles */
    public function __construct(array $candles)
    {
        $sorted = $candles;

        usort(
            $sorted,
            static fn (Candle $a, Candle $b): int => $a->openTime <=> $b->openTime
        );

        $this->candles = array_values($sorted);
    }

    /** @return Traversable<int,Candle> */
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->candles);
    }

    public function count(): int
    {
        return count($this->candles);
    }

    public function isEmpty(): bool
    {
        return $this->candles === [];
    }

    /** @return list<Candle> */
    public function all(): array
    {
        return $this->candles;
    }

    public function first(): ?Candle
    {
        return $this->candles[0] ?? null;
    }

    public function last(): ?Candle
    {
        return $this->candles === [] ? null : $this->candles[count($this->candles) - 1];
    }

    public function at(int $index): ?Candle
    {
        return $this->candles[$index] ?? null;
    }

    /** Closing prices as floats, oldest first — the usual indicator input. */
    public function closes(): array
    {
        return array_map(static fn (Candle $c): float => (float) $c->close, $this->candles);
    }

    public function highs(): array
    {
        return array_map(static fn (Candle $c): float => (float) $c->high, $this->candles);
    }

    public function lows(): array
    {
        return array_map(static fn (Candle $c): float => (float) $c->low, $this->candles);
    }

    /** Only closed bars — what indicators and strategies may consume (ADR-14). */
    public function closedOnly(): self
    {
        return new self(array_values(array_filter(
            $this->candles,
            static fn (Candle $c): bool => $c->isClosed
        )));
    }

    /** The most recent $count candles. */
    public function tail(int $count): self
    {
        return new self(array_slice($this->candles, -$count));
    }

    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->candles, $offset, $length));
    }

    /**
     * Open times where an expected bar is missing, given the timeframe.
     *
     * Gaps are normal for gold — the market closes at weekends and takes a
     * daily break — so this reports rather than throws. Its value is telling a
     * weekend apart from a provider outage during the trading week.
     *
     * @return list<DateTimeImmutable>
     */
    public function gaps(int $timeframeMinutes): array
    {
        if ($timeframeMinutes < 1) {
            throw new InvalidArgumentException('Timeframe must be at least one minute.');
        }

        $expectedStep = $timeframeMinutes * 60;
        $gaps = [];

        for ($i = 1, $n = count($this->candles); $i < $n; $i++) {
            $previous = $this->candles[$i - 1]->openTime->getTimestamp();
            $current = $this->candles[$i]->openTime->getTimestamp();

            for ($t = $previous + $expectedStep; $t < $current; $t += $expectedStep) {
                $gaps[] = (new DateTimeImmutable())->setTimestamp($t)
                    ->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        return $gaps;
    }

    public function highestHigh(): ?float
    {
        $highs = $this->highs();

        return $highs === [] ? null : max($highs);
    }

    public function lowestLow(): ?float
    {
        $lows = $this->lows();

        return $lows === [] ? null : min($lows);
    }
}
