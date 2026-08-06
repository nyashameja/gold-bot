<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals\Filters;

use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;

/**
 * The master switch.
 *
 * When off, strategies still evaluate and strategy_runs is still written —
 * only publication stops. That is deliberate: turning the platform off must
 * not also blind you to what it would have done, which is exactly what you
 * want to review before turning it back on.
 */
final class EnabledFilter implements FilterInterface
{
    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function reject(SignalResult $result, StrategyContext $context): ?string
    {
        return (bool) $this->settings->get('signals.enabled', true)
            ? null
            : 'signals_disabled';
    }

    public function name(): string
    {
        return 'enabled';
    }
}
