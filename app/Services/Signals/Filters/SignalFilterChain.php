<?php

declare(strict_types=1);

namespace GoldBot\Services\Signals\Filters;

use GoldBot\Domain\Strategy\SignalResult;
use GoldBot\Domain\Strategy\StrategyContext;

/**
 * Applies every filter in order, stopping at the first rejection.
 *
 * Order matters and is chosen so the cheapest and most decisive checks run
 * first: the master switch before any database query, and the portfolio caps
 * last.
 */
final class SignalFilterChain
{
    /** @param list<FilterInterface> $filters */
    public function __construct(private readonly array $filters)
    {
    }

    /** @return string|null The first rejection reason, or null to publish. */
    public function reject(SignalResult $result, StrategyContext $context): ?string
    {
        foreach ($this->filters as $filter) {
            $reason = $filter->reject($result, $context);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function filterNames(): array
    {
        return array_map(static fn (FilterInterface $f): string => $f->name(), $this->filters);
    }
}
