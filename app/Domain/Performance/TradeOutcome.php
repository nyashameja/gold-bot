<?php

declare(strict_types=1);

namespace GoldBot\Domain\Performance;

use DateTimeImmutable;

/**
 * One closed signal, reduced to what a metric needs (docs/02 §9).
 *
 * Only signals that actually traded become outcomes. A cancelled or expired
 * signal never held a position, so it has no result to measure — constructing
 * one is impossible rather than merely discouraged, which is why the filtering
 * happens before this type and not inside the calculator.
 *
 * Immutable and free of I/O (ADR-03): the calculator that consumes these is
 * pure, so every metric on the platform can be checked against hand-computed
 * values without a database.
 */
final readonly class TradeOutcome
{
    /**
     * @param float $realisedR The result in R multiples. Positive is a win,
     *                         negative a loss, exactly zero a scratch.
     * @param float|null $plannedRiskReward The R:R the signal was published
     *                         with, kept separate from the realised figure:
     *                         one is the plan, the other is what happened.
     */
    public function __construct(
        public DateTimeImmutable $closedAt,
        public float $realisedR,
        public ?float $plannedRiskReward = null,
        public ?float $score = null
    ) {
    }

    public function isWin(): bool
    {
        return $this->realisedR > 0.0;
    }

    public function isLoss(): bool
    {
        return $this->realisedR < 0.0;
    }

    /**
     * Neither a win nor a loss.
     *
     * Counted separately everywhere. Folding scratches into losses understates
     * the win rate; folding them into wins overstates it. They are their own
     * outcome and the brief asks for them that way.
     */
    public function isBreakeven(): bool
    {
        return $this->realisedR === 0.0;
    }

    /** @param array<string,mixed> $row A signals row. */
    public static function fromRow(array $row): self
    {
        return new self(
            new DateTimeImmutable((string) $row['closed_at'], new \DateTimeZone('UTC')),
            (float) $row['realised_r'],
            $row['risk_reward'] === null ? null : (float) $row['risk_reward'],
            $row['score'] === null ? null : (float) $row['score']
        );
    }
}
