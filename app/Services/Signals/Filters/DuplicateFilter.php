<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals\Filters;

use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyContext;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;

/**
 * Suppresses a signal that duplicates one already open.
 *
 * Distinct from the cooldown: this catches an open position in the same
 * direction regardless of age. Two live signals on the same instrument and
 * side are one trade expressed twice, and would double-count in performance.
 */
final class DuplicateFilter implements FilterInterface
{
    public function __construct(private readonly SignalRepositoryInterface $signals)
    {
    }

    public function reject(SignalResult $result, StrategyContext $context): ?string
    {
        if ($result->direction === null) {
            return null;
        }

        return $this->signals->hasOpenInDirection($context->instrumentId, $result->direction)
            ? 'duplicate_open_signal'
            : null;
    }

    public function name(): string
    {
        return 'duplicate';
    }
}
