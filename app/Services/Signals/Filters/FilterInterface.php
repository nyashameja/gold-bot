<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals\Filters;

use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyContext;

/**
 * A publishability check applied outside the strategies (docs/01 §6).
 *
 * Strategies decide whether a setup exists; filters decide whether it may be
 * published. Keeping them separate means a news blackout is implemented once
 * rather than once per strategy — and misimplemented in at most one place.
 */
interface FilterInterface
{
    /**
     * Return null to allow, or a short machine-readable reason to suppress.
     *
     * The reason is stored in strategy_runs.rejection_reason, so it must
     * identify the cause specifically enough to answer "why did nothing fire?"
     */
    public function reject(SignalResult $result, StrategyContext $context): ?string;

    public function name(): string;
}
