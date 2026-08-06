<?php

declare(strict_types=1);

namespace GoldBot\Domain\Strategy\Strategies;

use GoldBot\Domain\Strategy\RuleEvaluator;

/**
 * The 714 Method.
 *
 * The five pillars the brief names — Trend, Structure, Pullback, Confirmation
 * and Risk — are scored as a weighted rubric out of 100, with a configurable
 * publish threshold. None of that logic lives here: the pillars, their rules,
 * weights and gates are all defined in the active strategy config, so the
 * method can be tuned without a deploy and every past signal stays attributed
 * to the version that produced it (ADR-06).
 *
 * OPEN QUESTION (docs/00 §3, Q1). The shipped default configuration is a
 * documented placeholder built from conventional trend-pullback logic. It is
 * NOT the 714 method, and must not be treated as it. Supplying the real rules
 * means writing a new config version — no code changes here.
 *
 * What is still needed, per pillar: what is measured, how it scores, its
 * weight, whether it is a hard gate, and how entry, stop and TP1-3 are derived
 * once a setup passes.
 */
final class SevenFourteenStrategy extends RubricStrategy
{
    public const CODE = '714';

    /** The pillars the method is defined in terms of. */
    public const PILLARS = ['TREND', 'STRUCTURE', 'PULLBACK', 'CONFIRMATION', 'RISK'];

    public function __construct(RuleEvaluator $rules)
    {
        parent::__construct($rules, self::CODE);
    }
}
