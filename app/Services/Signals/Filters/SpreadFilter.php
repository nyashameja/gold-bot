<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals\Filters;

use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;

/**
 * Suppresses signals when the spread is too wide to trade economically.
 *
 * An unknown spread does not suppress: the provider does not always return a
 * two-sided quote, and failing closed on missing data would silently stop the
 * platform producing anything at all.
 */
final class SpreadFilter implements FilterInterface
{
    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function reject(SignalResult $result, StrategyContext $context): ?string
    {
        $spread = $context->spread;

        if ($spread === null) {
            return null;
        }

        $maximum = (float) $this->settings->get('risk.max_spread', 0.5);

        if ($maximum <= 0.0) {
            return null;
        }

        return (float) $spread > $maximum
            ? sprintf('spread_too_wide:%.5f>%.5f', (float) $spread, $maximum)
            : null;
    }

    public function name(): string
    {
        return 'spread';
    }
}
