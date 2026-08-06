<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals\Filters;

use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyContext;

/**
 * Suppresses signals inside a news blackout window.
 *
 * The blackout is resolved once when the context is built, so every strategy
 * evaluated against that context sees the same answer — and the reason names
 * the event rather than saying only "news".
 */
final class NewsFilter implements FilterInterface
{
    public function reject(SignalResult $result, StrategyContext $context): ?string
    {
        $event = $context->blockingEvent;

        if ($event === null) {
            return null;
        }

        return 'news_blackout:' . mb_substr($event->title, 0, 60);
    }

    public function name(): string
    {
        return 'news';
    }
}
