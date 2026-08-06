<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals\Filters;

use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;

/**
 * Restricts publishing to configured trading sessions.
 *
 * Gold's thin hours produce wider spreads and less reliable structure, so most
 * methods trade London and New York only. Empty configuration means no
 * restriction rather than "none allowed" — failing closed here would silently
 * suppress everything.
 */
final class SessionFilter implements FilterInterface
{
    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function reject(SignalResult $result, StrategyContext $context): ?string
    {
        $allowed = $this->settings->get('signals.sessions');

        if (!is_array($allowed) || $allowed === []) {
            return null;
        }

        $current = $context->sessionCode();

        if ($current === null) {
            return 'outside_session';
        }

        return in_array($current, array_map('strtoupper', array_map('strval', $allowed)), true)
            ? null
            : 'session_not_allowed:' . $current;
    }

    public function name(): string
    {
        return 'session';
    }
}
