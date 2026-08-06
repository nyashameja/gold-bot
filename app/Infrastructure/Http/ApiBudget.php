<?php

declare(strict_types=1);

namespace GoldBot\Infrastructure\Http;

use GoldBot\Core\Database;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;

/**
 * Provider request budget, enforced against the usage ledger (docs/01 §5).
 *
 * Every provider call passes through here first. Exhausted budget defers the
 * task rather than spending a request that will come back 429 — a refused
 * request still counts against most providers' quotas, so retrying into a
 * limit makes the outage longer, not shorter.
 *
 * Counts come from api_usage_log rather than a counter in cache, because the
 * ledger is shared between the web tier and CLI cron while APCu is not.
 */
final class ApiBudget
{
    /** @var array<string,array{id:int,daily:?int,perMinute:?int}> */
    private array $providers = [];

    public function __construct(
        private readonly Database $database,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether a call may be made now.
     */
    public function canSpend(string $providerCode, int $credits = 1): bool
    {
        $provider = $this->provider($providerCode);

        if ($provider === null) {
            // An unknown provider has no configured limits, so there is
            // nothing to enforce. Better to proceed than to block ingest on a
            // missing reference row.
            return true;
        }

        if ($provider['perMinute'] !== null) {
            $used = $this->usedSince($provider['id'], 60);

            if (($used + $credits) > $provider['perMinute']) {
                $this->logger->notice('Per-minute API budget reached', [
                    'event'    => 'api.budget_minute',
                    'provider' => $providerCode,
                    'used'     => $used,
                    'limit'    => $provider['perMinute'],
                ]);

                return false;
            }
        }

        if ($provider['daily'] !== null) {
            $used = $this->usedSince($provider['id'], 86400);

            if (($used + $credits) > $provider['daily']) {
                $this->logger->warning('Daily API budget exhausted', [
                    'event'    => 'api.budget_daily',
                    'provider' => $providerCode,
                    'used'     => $used,
                    'limit'    => $provider['daily'],
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Record a call. Always called, success or failure — a failed request
     * still consumed quota, and pretending otherwise makes the next budget
     * check optimistic in exactly the wrong direction.
     */
    public function record(
        string $providerCode,
        string $endpoint,
        HttpResponse $response,
        int $credits = 1,
        string $method = 'GET'
    ): void {
        $provider = $this->provider($providerCode);

        if ($provider === null) {
            return;
        }

        $this->database->insert('api_usage_log', [
            'provider_id'      => $provider['id'],
            'endpoint'         => substr($endpoint, 0, 120),
            'method'           => $method,
            'http_status'      => $response->status > 0 ? $response->status : null,
            'succeeded'        => $response->isSuccess() ? 1 : 0,
            'response_time_ms' => $response->durationMs,
            'error_message'    => $response->error === null ? null : substr($response->error, 0, 255),
            'credits_used'     => $credits,
            'requested_at'     => $this->clock->now()->format('Y-m-d H:i:s.v'),
        ]);
    }

    /** @return array{daily_used:int,daily_limit:?int,minute_used:int,minute_limit:?int,remaining:?int} */
    public function status(string $providerCode): array
    {
        $provider = $this->provider($providerCode);

        if ($provider === null) {
            return [
                'daily_used'   => 0,
                'daily_limit'  => null,
                'minute_used'  => 0,
                'minute_limit' => null,
                'remaining'    => null,
            ];
        }

        $dailyUsed = $this->usedSince($provider['id'], 86400);

        return [
            'daily_used'   => $dailyUsed,
            'daily_limit'  => $provider['daily'],
            'minute_used'  => $this->usedSince($provider['id'], 60),
            'minute_limit' => $provider['perMinute'],
            'remaining'    => $provider['daily'] === null ? null : max(0, $provider['daily'] - $dailyUsed),
        ];
    }

    private function usedSince(int $providerId, int $seconds): int
    {
        $since = $this->clock->now()
            ->modify(sprintf('-%d seconds', $seconds))
            ->format('Y-m-d H:i:s.v');

        return (int) $this->database->scalar(
            'SELECT COALESCE(SUM(credits_used), 0) FROM api_usage_log
             WHERE provider_id = ? AND requested_at >= ?',
            [$providerId, $since]
        );
    }

    /** @return array{id:int,daily:?int,perMinute:?int}|null */
    private function provider(string $code): ?array
    {
        if (isset($this->providers[$code])) {
            return $this->providers[$code];
        }

        $row = $this->database->selectOne(
            'SELECT id, daily_limit, per_minute_limit FROM api_providers WHERE code = ? AND is_active = 1',
            [$code]
        );

        if ($row === null) {
            return null;
        }

        return $this->providers[$code] = [
            'id'        => (int) $row['id'],
            'daily'     => $row['daily_limit'] === null ? null : (int) $row['daily_limit'],
            'perMinute' => $row['per_minute_limit'] === null ? null : (int) $row['per_minute_limit'],
        ];
    }
}
