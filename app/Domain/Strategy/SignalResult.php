<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy;

use GoldBot\Domain\Market\Enums\Direction;

/**
 * The outcome of evaluating one strategy against one context.
 *
 * Returned for EVERY evaluation, not only when a setup qualifies. A rejected
 * result still carries its score and pillar breakdown, because those are
 * exactly what answers "why did nothing fire today?" — the most common
 * operational question — and what makes threshold tuning empirical rather than
 * a guess (docs/02 §7).
 */
final class SignalResult
{
    /**
     * @param list<PillarScore>   $pillars
     * @param list<SignalTarget>  $targets
     */
    private function __construct(
        public readonly bool $qualified,
        public readonly float $score,
        public readonly array $pillars = [],
        public readonly ?Direction $direction = null,
        public readonly ?float $entryPrice = null,
        public readonly ?float $stopLoss = null,
        public readonly array $targets = [],
        public readonly ?string $rejectionReason = null
    ) {
    }

    /**
     * @param list<PillarScore>  $pillars
     * @param list<SignalTarget> $targets
     */
    public static function signal(
        Direction $direction,
        float $score,
        array $pillars,
        float $entryPrice,
        float $stopLoss,
        array $targets
    ): self {
        return new self(true, $score, $pillars, $direction, $entryPrice, $stopLoss, $targets);
    }

    /**
     * A setup that was evaluated and did not qualify.
     *
     * @param list<PillarScore> $pillars
     */
    public static function rejected(string $reason, float $score = 0.0, array $pillars = [], ?Direction $direction = null): self
    {
        return new self(false, $score, $pillars, $direction, rejectionReason: $reason);
    }

    /** Distance from entry to stop — one unit of risk. */
    public function riskDistance(): ?float
    {
        if ($this->entryPrice === null || $this->stopLoss === null) {
            return null;
        }

        return abs($this->entryPrice - $this->stopLoss);
    }

    /** Reward-to-risk at the furthest target. */
    public function riskReward(): ?float
    {
        $risk = $this->riskDistance();

        if ($risk === null || $risk <= 0.0 || $this->targets === []) {
            return null;
        }

        $furthest = $this->targets[count($this->targets) - 1];

        return round(abs($furthest->price - (float) $this->entryPrice) / $risk, 2);
    }

    /** @return array<string,float> Pillar name => weighted contribution. */
    public function pillarBreakdown(): array
    {
        $breakdown = [];

        foreach ($this->pillars as $pillar) {
            $breakdown[$pillar->pillar] = $pillar->weighted();
        }

        return $breakdown;
    }

    /** Whether any pillar acting as a hard gate failed. */
    public function hasFailedGate(): bool
    {
        foreach ($this->pillars as $pillar) {
            if (!$pillar->passed) {
                return true;
            }
        }

        return false;
    }
}
