<?php

declare(strict_types=1);

namespace GoldBot\Domain\Backtest;

use DateTimeImmutable;
use GoldBot\Domain\Market\Enums\Direction;
use GoldBot\Domain\Performance\TradeOutcome;

/**
 * One trade a backtest produced.
 *
 * Converts to the same TradeOutcome the live record produces, so a backtest is
 * measured by the identical calculator and its figures are directly comparable
 * with a live period's. If the two used different arithmetic, "the backtest
 * says 1.4R expectancy" would be a claim about the backtester rather than
 * about the strategy.
 */
final class SimulatedTrade
{
    public ?DateTimeImmutable $activatedAt = null;

    public ?DateTimeImmutable $closedAt = null;

    public TradeOutcomeType $outcome = TradeOutcomeType::Pending;

    public ?float $exitPrice = null;

    public ?float $realisedR = null;

    public int $targetsHit = 0;

    public int $barsHeld = 0;

    /** @param list<array{level:int,price:float,rMultiple:float|null}> $targets */
    public function __construct(
        public readonly DateTimeImmutable $signalledAt,
        public readonly Direction $direction,
        public readonly float $score,
        public readonly float $entryPrice,
        public readonly float $stopLoss,
        public readonly float $riskDistance,
        public readonly ?float $riskReward,
        public readonly array $targets,
        public readonly ?string $sessionCode,
        public readonly ?DateTimeImmutable $expiresAt
    ) {
    }

    public function isOpen(): bool
    {
        return $this->outcome === TradeOutcomeType::Pending
            || $this->outcome === TradeOutcomeType::Open;
    }

    /**
     * Record the close and derive the result in R.
     *
     * R rather than price distance, because two trades with different stop
     * distances are not comparable in any other unit.
     */
    public function close(DateTimeImmutable $at, float $exitPrice): void
    {
        $this->closedAt = $at;
        $this->exitPrice = $exitPrice;

        if ($this->riskDistance <= 0.0) {
            $this->realisedR = 0.0;
            $this->outcome = TradeOutcomeType::Breakeven;

            return;
        }

        $this->realisedR = round(
            (($exitPrice - $this->entryPrice) * $this->direction->sign()) / $this->riskDistance,
            3
        );

        $this->outcome = match (true) {
            $this->realisedR > 0.0 => TradeOutcomeType::Win,
            $this->realisedR < 0.0 => TradeOutcomeType::Loss,
            default                => TradeOutcomeType::Breakeven,
        };
    }

    /** Null for anything that did not trade — those are not measurable. */
    public function toOutcome(): ?TradeOutcome
    {
        if (!$this->outcome->isMeasurable() || $this->closedAt === null || $this->realisedR === null) {
            return null;
        }

        return new TradeOutcome($this->closedAt, $this->realisedR, $this->riskReward, $this->score);
    }

    /** @return array<string,mixed> */
    public function toColumns(): array
    {
        return [
            'direction'     => $this->direction->value,
            'score'         => $this->score,
            'entry_price'   => number_format($this->entryPrice, 5, '.', ''),
            'stop_loss'     => number_format($this->stopLoss, 5, '.', ''),
            'risk_distance' => number_format($this->riskDistance, 5, '.', ''),
            'risk_reward'   => $this->riskReward,
            'signalled_at'  => $this->signalledAt->format('Y-m-d H:i:s'),
            'activated_at'  => $this->activatedAt?->format('Y-m-d H:i:s'),
            'closed_at'     => $this->closedAt?->format('Y-m-d H:i:s'),
            'outcome'       => $this->outcome->value,
            'exit_price'    => $this->exitPrice === null ? null : number_format($this->exitPrice, 5, '.', ''),
            'realised_r'    => $this->realisedR,
            'targets_hit'   => $this->targetsHit,
            'bars_held'     => $this->barsHeld,
            'session_code'  => $this->sessionCode,
        ];
    }
}
