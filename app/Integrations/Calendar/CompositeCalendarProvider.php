<?php

declare(strict_types=1);

namespace GoldBot\Integrations\Calendar;

use DateTimeImmutable;
use GoldBot\Domain\Calendar\EconomicEvent;
use Paragon\Core\Logging\LoggerInterface;

/**
 * Fans a calendar query out across every configured provider.
 *
 * Events from different sources are kept as separate rows rather than merged:
 * `source` is provenance, and losing it would make a disagreement between
 * ForexFactory and FRED invisible. Deduplication happens later, in the
 * blackout filter, where overlapping windows collapse naturally.
 *
 * One provider failing does not fail the import. Losing ForexFactory costs the
 * consensus forecast; losing FRED costs corroboration. Losing both is an
 * error — but losing one and discarding the other's data would be worse than
 * either, because the archive cannot be backfilled (ADR-15).
 */
final class CompositeCalendarProvider implements EconomicCalendarProviderInterface
{
    /** @param list<EconomicCalendarProviderInterface> $providers */
    public function __construct(
        private readonly array $providers,
        private readonly LoggerInterface $logger
    ) {
    }

    public function code(): string
    {
        return 'COMPOSITE';
    }

    public function isEnabled(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->isEnabled()) {
                return true;
            }
        }

        return false;
    }

    public function events(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $events = [];
        $failures = [];
        $succeeded = 0;

        foreach ($this->providers as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }

            try {
                $fetched = $provider->events($from, $to);
                $events = [...$events, ...$fetched];
                $succeeded++;

                $this->logger->info('Calendar provider returned events', [
                    'event'    => 'calendar.fetched',
                    'provider' => $provider->code(),
                    'count'    => count($fetched),
                ]);
            } catch (CalendarException $e) {
                $failures[] = $provider->code() . ': ' . $e->getMessage();

                $this->logger->warning('Calendar provider failed', [
                    'event'     => 'calendar.provider_failed',
                    'provider'  => $provider->code(),
                    'retryable' => $e->retryable,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($succeeded === 0 && $failures !== []) {
            throw CalendarException::transport(
                'Every calendar provider failed: ' . implode('; ', $failures)
            );
        }

        usort(
            $events,
            static fn (EconomicEvent $a, EconomicEvent $b): int => $a->scheduledAt <=> $b->scheduledAt
        );

        return $events;
    }

    /** @return list<string> Codes of the providers that are enabled. */
    public function enabledProviders(): array
    {
        $codes = [];

        foreach ($this->providers as $provider) {
            if ($provider->isEnabled()) {
                $codes[] = $provider->code();
            }
        }

        return $codes;
    }
}
