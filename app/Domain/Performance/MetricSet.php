<?php

declare(strict_types=1);

namespace GoldBot\Domain\Performance;

/**
 * The result of measuring a set of closed signals.
 *
 * Every rate that can be undefined is NULLABLE rather than defaulted. That is
 * the whole design of this class: a win rate of 0% and a win rate that does
 * not exist yet are different claims, and a dashboard that renders both as
 * "0%" tells the reader something false about a strategy that has simply not
 * traded. Null renders as an em dash; zero renders as zero.
 */
final readonly class MetricSet
{
    /**
     * @param int         $total         Signals that actually traded.
     * @param float|null  $winRate       Percent, or null with nothing to divide.
     * @param float|null  $profitFactor  Gross profit ÷ gross loss, or null when
     *                                   there are no losses — undefined, not
     *                                   infinite.
     * @param float|null  $expectancy    Average R per signal.
     * @param float       $maxDrawdownR  Peak-to-trough of the equity curve.
     */
    public function __construct(
        public int $total = 0,
        public int $wins = 0,
        public int $losses = 0,
        public int $breakeven = 0,
        public float $grossProfitR = 0.0,
        public float $grossLossR = 0.0,
        public float $totalR = 0.0,
        public ?float $winRate = null,
        public ?float $lossRate = null,
        public ?float $profitFactor = null,
        public ?float $expectancy = null,
        public ?float $averageWinR = null,
        public ?float $averageLossR = null,
        public ?float $averageRiskReward = null,
        public ?float $averageScore = null,
        public ?float $bestR = null,
        public ?float $worstR = null,
        public float $maxDrawdownR = 0.0,
        public int $maxConsecutiveWins = 0,
        public int $maxConsecutiveLosses = 0,
        public int $currentStreak = 0
    ) {
    }

    /** An empty period. Explicit, so callers never fabricate one field by field. */
    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /**
     * Whether the sample is large enough for the rates to mean anything.
     *
     * Not a statistical test — a plain guard against reading a 100% win rate
     * over three signals as evidence of anything. The UI shows the rate either
     * way and uses this to say how much weight it deserves.
     */
    public function isSignificant(int $minimum = 30): bool
    {
        return $this->total >= $minimum;
    }

    /** @return array<string,mixed> Column-shaped, for performance_snapshots. */
    public function toColumns(): array
    {
        return [
            'total_signals'          => $this->total,
            'wins'                   => $this->wins,
            'losses'                 => $this->losses,
            'breakeven'              => $this->breakeven,
            'win_rate'               => $this->winRate,
            'loss_rate'              => $this->lossRate,
            'profit_factor'          => $this->profitFactor,
            'average_rr'             => $this->averageRiskReward,
            'average_win_r'          => $this->averageWinR,
            'average_loss_r'         => $this->averageLossR,
            'expectancy_r'           => $this->expectancy,
            'gross_profit_r'         => $this->grossProfitR,
            'gross_loss_r'           => $this->grossLossR,
            'total_r'                => $this->totalR,
            'best_r'                 => $this->bestR,
            'worst_r'                => $this->worstR,
            'average_score'          => $this->averageScore,
            'max_drawdown_r'         => $this->maxDrawdownR,
            'max_consecutive_wins'   => $this->maxConsecutiveWins,
            'max_consecutive_losses' => $this->maxConsecutiveLosses,
        ];
    }

    /** @param array<string,mixed> $row A performance_snapshots row. */
    public static function fromColumns(array $row): self
    {
        $float = static fn (string $key): ?float => $row[$key] === null ? null : (float) $row[$key];

        return new self(
            total: (int) $row['total_signals'],
            wins: (int) $row['wins'],
            losses: (int) $row['losses'],
            breakeven: (int) $row['breakeven'],
            grossProfitR: (float) $row['gross_profit_r'],
            grossLossR: (float) $row['gross_loss_r'],
            totalR: (float) $row['total_r'],
            winRate: $float('win_rate'),
            lossRate: $float('loss_rate'),
            profitFactor: $float('profit_factor'),
            expectancy: $float('expectancy_r'),
            averageWinR: $float('average_win_r'),
            averageLossR: $float('average_loss_r'),
            averageRiskReward: $float('average_rr'),
            averageScore: $float('average_score'),
            bestR: $float('best_r'),
            worstR: $float('worst_r'),
            maxDrawdownR: (float) $row['max_drawdown_r'],
            maxConsecutiveWins: (int) $row['max_consecutive_wins'],
            maxConsecutiveLosses: (int) $row['max_consecutive_losses'],
        );
    }

    /** @return array<string,mixed> The shape the dashboard and JSON use. */
    public function toArray(): array
    {
        return [
            'total'                  => $this->total,
            'wins'                   => $this->wins,
            'losses'                 => $this->losses,
            'breakeven'              => $this->breakeven,
            'win_rate'               => $this->winRate,
            'loss_rate'              => $this->lossRate,
            'profit_factor'          => $this->profitFactor,
            'expectancy_r'           => $this->expectancy,
            'average_rr'             => $this->averageRiskReward,
            'avg_win_r'              => $this->averageWinR,
            'avg_loss_r'             => $this->averageLossR,
            'avg_score'              => $this->averageScore,
            'gross_profit_r'         => $this->grossProfitR,
            'gross_loss_r'           => $this->grossLossR,
            'net_r'                  => $this->totalR,
            'best_r'                 => $this->bestR,
            'worst_r'                => $this->worstR,
            'max_drawdown_r'         => $this->maxDrawdownR,
            'longest_win_streak'     => $this->maxConsecutiveWins,
            'longest_loss_streak'    => $this->maxConsecutiveLosses,
            'current_streak'         => $this->currentStreak,
            'significant'            => $this->isSignificant(),
        ];
    }
}
