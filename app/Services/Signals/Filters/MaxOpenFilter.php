<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals\Filters;

use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;

/**
 * Caps concurrent open signals.
 *
 * A portfolio-level control rather than a per-setup one: each individual signal
 * may be sound while the aggregate exposure is not.
 */
final class MaxOpenFilter implements FilterInterface
{
    public function __construct(
        private readonly SignalRepositoryInterface $signals,
        private readonly SettingsRepositoryInterface $settings
    ) {
    }

    public function reject(SignalResult $result, StrategyContext $context): ?string
    {
        $maximum = (int) $this->settings->get('signals.max_open', 3);

        if ($maximum <= 0) {
            return null;
        }

        $open = $this->signals->countOpen();

        return $open >= $maximum
            ? sprintf('max_open_reached:%d', $maximum)
            : null;
    }

    public function name(): string
    {
        return 'max_open';
    }
}
