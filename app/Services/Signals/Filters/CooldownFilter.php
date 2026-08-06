<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals\Filters;

use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;

/**
 * Enforces a minimum gap between signals in the same direction.
 *
 * Without it a strategy that likes the current conditions re-fires on every
 * candle, turning one opinion into a stream of near-identical alerts and
 * concentrating risk in a single view of the market.
 */
final class CooldownFilter implements FilterInterface
{
    public function __construct(
        private readonly SignalRepositoryInterface $signals,
        private readonly SettingsRepositoryInterface $settings
    ) {
    }

    public function reject(SignalResult $result, StrategyContext $context): ?string
    {
        if ($result->direction === null) {
            return null;
        }

        $minutes = (int) $this->settings->get('signals.cooldown_minutes', 60);

        if ($minutes <= 0) {
            return null;
        }

        $since = $context->at->modify(sprintf('-%d minutes', $minutes));

        $recent = $this->signals->countSince($context->instrumentId, $result->direction, $since);

        return $recent > 0
            ? sprintf('cooldown_active:%dm', $minutes)
            : null;
    }

    public function name(): string
    {
        return 'cooldown';
    }
}
