<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Repositories\Contracts\OperationsRepositoryInterface;

/**
 * The API Usage page.
 *
 * Both providers are on free tiers with hard quotas, so this page is not a
 * curiosity — running out of credits at 14:00 means no market data until
 * midnight, and the first warning of that has to arrive well before the wall.
 * Hence the projection: consumption so far, extrapolated over the rest of the
 * day, against the limit.
 */
final class ApiUsageService
{
    public function __construct(
        private readonly OperationsRepositoryInterface $operations,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function board(int $hours = 48): array
    {
        $now = $this->clock->now();
        $hours = max(6, min($hours, 720));
        $since = $now->modify("-{$hours} hours");

        $providers = array_map(
            fn (array $row): array => $this->decorateProvider($row, $now),
            $this->operations->providerUsage($now)
        );

        return [
            'providers' => $providers,
            'series'    => $this->operations->usageByHour($since, $now),
            'endpoints' => array_map(
                static fn (array $row): array => [
                    'provider' => (string) $row['provider_code'],
                    'endpoint' => (string) $row['endpoint'],
                    'calls'    => (int) $row['calls'],
                    'credits'  => (int) $row['credits'],
                    'failures' => (int) $row['failures'],
                    'avg_ms'   => $row['avg_ms'] === null ? null : (float) $row['avg_ms'],
                    'last_call_at' => (string) $row['last_call_at'],
                ],
                $this->operations->usageByEndpoint($since)
            ),
            'failures' => array_map(
                static fn (array $row): array => [
                    'provider' => (string) $row['provider_code'],
                    'endpoint' => (string) $row['endpoint'],
                    'status'   => $row['http_status'] === null ? null : (int) $row['http_status'],
                    'error'    => $row['error_message'] === null ? null : (string) $row['error_message'],
                    'at'       => (string) $row['requested_at'],
                ],
                $this->operations->recentApiFailures()
            ),
            'window' => ['hours' => $hours, 'since' => $since->format(DATE_ATOM)],
            'age'    => $this->freshestCall($providers, $now),
        ];
    }

    /**
     * Compact tiles for the Overview: one line per provider.
     *
     * @return list<array<string,mixed>>
     */
    public function summary(): array
    {
        $now = $this->clock->now();

        return array_map(
            fn (array $row): array => $this->decorateProvider($row, $now),
            $this->operations->providerUsage($now)
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decorateProvider(array $row, DateTimeImmutable $now): array
    {
        $dailyLimit = $row['daily_limit'] === null ? null : (int) $row['daily_limit'];
        $used = (int) $row['credits_today'];

        $percent = $dailyLimit === null || $dailyLimit === 0
            ? null
            : round(($used / $dailyLimit) * 100, 1);

        $lastCall = $row['last_call_at'] === null
            ? null
            : new DateTimeImmutable((string) $row['last_call_at'], new DateTimeZone('UTC'));

        return [
            'code'        => (string) $row['code'],
            'name'        => (string) $row['name'],
            'active'      => (int) $row['is_active'] === 1,
            'daily_limit' => $dailyLimit,
            'per_minute_limit' => $row['per_minute_limit'] === null ? null : (int) $row['per_minute_limit'],
            'credits_today'     => $used,
            'calls_today'       => (int) $row['calls_today'],
            'calls_last_minute' => (int) $row['calls_last_minute'],
            'failures_last_hour' => (int) $row['failures_last_hour'],
            'avg_ms_last_hour'  => $row['avg_ms_last_hour'] === null ? null : (int) $row['avg_ms_last_hour'],
            'percent_used'      => $percent,
            'remaining'         => $dailyLimit === null ? null : max(0, $dailyLimit - $used),
            'projected_percent' => $this->projectedPercent($used, $dailyLimit, $now),
            'status'            => $this->status($percent, (int) $row['failures_last_hour']),
            'last_call_at'      => $lastCall?->format(DATE_ATOM),
            'age'               => DataAge::since($lastCall, $now, 3600)->toArray(),
        ];
    }

    /**
     * Where today's consumption lands if the current rate holds.
     *
     * Extrapolated from elapsed time rather than from the last hour, which
     * would swing wildly around a backfill. Null before the day has enough
     * elapsed time to extrapolate from — a projection taken at 00:03 is
     * arithmetic, not information.
     */
    private function projectedPercent(int $used, ?int $dailyLimit, DateTimeImmutable $now): ?float
    {
        if ($dailyLimit === null || $dailyLimit === 0) {
            return null;
        }

        $elapsed = $now->getTimestamp() - $now->setTime(0, 0)->getTimestamp();

        if ($elapsed < 1800) {
            return null;
        }

        $projected = $used * (86400 / $elapsed);

        return round(($projected / $dailyLimit) * 100, 1);
    }

    private function status(?float $percent, int $failuresLastHour): string
    {
        return match (true) {
            $percent !== null && $percent >= 90 => 'CRITICAL',
            $failuresLastHour >= 5              => 'CRITICAL',
            $percent !== null && $percent >= 70 => 'WARNING',
            $failuresLastHour > 0               => 'WARNING',
            default                             => 'OK',
        };
    }

    /**
     * @param list<array<string,mixed>> $providers
     * @return array<string,mixed>
     */
    private function freshestCall(array $providers, DateTimeImmutable $now): array
    {
        $newest = null;

        foreach ($providers as $provider) {
            if ($provider['last_call_at'] === null) {
                continue;
            }

            $at = new DateTimeImmutable((string) $provider['last_call_at']);

            if ($newest === null || $at > $newest) {
                $newest = $at;
            }
        }

        return DataAge::since($newest, $now, 300)->toArray();
    }
}
