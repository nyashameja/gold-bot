<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy;

/**
 * A trading strategy (ADR-03).
 *
 * Implementations must be pure functions of (context, config). No database, no
 * HTTP, no clock, no globals — everything needed arrives as an argument.
 *
 * The engine, not the strategy, decides publishability: news blackouts,
 * session windows, spread, cooldown and duplicate suppression are applied
 * outside as a filter chain (docs/01 §6). A blackout reimplemented in each
 * strategy is a blackout misimplemented in at least one of them. Strategies
 * answer only "is there a setup here?".
 */
interface StrategyInterface
{
    /** Matches strategies.code. */
    public function code(): string;

    /**
     * Timeframe codes this strategy reads.
     *
     * The context builder loads exactly these, so a strategy needing H4 trend
     * and M15 entry declares both and gets both — and one needing only H1 does
     * not pay for the rest.
     *
     * @return list<string>
     */
    public function requiredTimeframes(StrategyConfig $config): array;

    /**
     * Evaluate the context. Never throws for an ordinary "no setup" — that is
     * a rejected SignalResult carrying its score and reason.
     */
    public function evaluate(StrategyContext $context, StrategyConfig $config): SignalResult;
}
