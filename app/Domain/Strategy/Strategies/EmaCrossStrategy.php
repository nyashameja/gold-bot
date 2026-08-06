<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy\Strategies;

use GoldBot\Domain\Strategy\RuleEvaluator;

/**
 * A plain EMA trend-following strategy.
 *
 * Its purpose is to be unambiguous. Because the 714 rules are still open
 * (docs/00 §3, Q1), the engine needs at least one strategy whose behaviour is
 * fully specified so the pipeline — context building, scoring, filtering,
 * persistence, lifecycle — can be verified end to end against a known answer
 * rather than against a placeholder.
 *
 * It is a real strategy, not a stub, and it stays useful afterwards as the
 * baseline any tuned 714 configuration should be expected to beat. A method
 * that cannot outperform "trade with the EMA trend" is not earning its
 * complexity.
 */
final class EmaCrossStrategy extends RubricStrategy
{
    public const CODE = 'EMA_CROSS';

    public function __construct(RuleEvaluator $rules)
    {
        parent::__construct($rules, self::CODE);
    }
}
