<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy;

/**
 * One pillar's contribution to a score out of 100.
 *
 * `raw` is 0-100 within the pillar; `weight` is its share of the total;
 * `weighted` is the product. Keeping all three lets the 714 page explain a
 * score rather than assert it — and makes it visible when a pillar is
 * contributing nothing, which no single total ever would.
 */
final class PillarScore
{
    /** @param array<string,mixed> $detail Per-rule outcomes, for display and audit. */
    public function __construct(
        public readonly string $pillar,
        public readonly float $raw,
        public readonly float $weight,
        public readonly bool $passed = true,
        public readonly array $detail = []
    ) {
    }

    public function weighted(): float
    {
        return round(($this->raw / 100) * $this->weight, 2);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'pillar'         => $this->pillar,
            'raw_score'      => round($this->raw, 2),
            'weight'         => round($this->weight, 2),
            'weighted_score' => $this->weighted(),
            'passed'         => $this->passed,
            'detail'         => $this->detail,
        ];
    }
}
