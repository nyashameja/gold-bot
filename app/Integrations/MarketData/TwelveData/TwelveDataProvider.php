<?php

declare(strict_types=1);

namespace GoldBot\Integrations\MarketData\TwelveData;

use DateTimeImmutable;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\PriceSnapshot;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Http\ApiBudget;
use GoldBot\Infrastructure\Http\HttpClient;
use GoldBot\Infrastructure\Http\HttpResponse;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Integrations\MarketData\MarketDataException;
use GoldBot\Integrations\MarketData\MarketDataProviderInterface;

/**
 * Twelve Data adapter.
 *
 * Every call goes through the budget gate first and is recorded afterwards,
 * success or failure (docs/01 §5).
 */
final class TwelveDataProvider implements MarketDataProviderInterface
{
    public const CODE = 'TWELVE_DATA';

    public function __construct(
        private readonly HttpClient $http,
        private readonly TwelveDataMapper $mapper,
        private readonly ApiBudget $budget,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.twelvedata.com',
        /** @var array<string,int> Settle margin per timeframe code. */
        private readonly array $settleSeconds = []
    ) {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function candles(string $symbol, Timeframe $timeframe, int $limit = 100): CandleSeries
    {
        $payload = $this->request('/time_series', [
            'symbol'     => $symbol,
            'interval'   => $timeframe->providerInterval,
            'outputsize' => max(1, min($limit, 5000)),
            'timezone'   => 'UTC',
            'order'      => 'desc',
        ]);

        return $this->mapper->toCandleSeries(
            $payload,
            $timeframe,
            $this->clock->now(),
            $this->settleSeconds[$timeframe->code] ?? 0
        );
    }

    public function candlesBetween(
        string $symbol,
        Timeframe $timeframe,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 5000
    ): CandleSeries {
        $payload = $this->request('/time_series', [
            'symbol'     => $symbol,
            'interval'   => $timeframe->providerInterval,
            'start_date' => $from->format('Y-m-d H:i:s'),
            'end_date'   => $to->format('Y-m-d H:i:s'),
            'outputsize' => max(1, min($limit, 5000)),
            'timezone'   => 'UTC',
            'order'      => 'desc',
        ]);

        return $this->mapper->toCandleSeries(
            $payload,
            $timeframe,
            $this->clock->now(),
            $this->settleSeconds[$timeframe->code] ?? 0
        );
    }

    public function quote(string $symbol): PriceSnapshot
    {
        $payload = $this->request('/quote', [
            'symbol'   => $symbol,
            'timezone' => 'UTC',
        ]);

        return $this->mapper->toPriceSnapshot($payload, $this->clock->now());
    }

    /**
     * @param array<string,scalar|null> $query
     * @return array<string,mixed>
     */
    private function request(string $endpoint, array $query): array
    {
        if (!$this->budget->canSpend(self::CODE)) {
            // Not retryable: the budget will not free up within a retry loop,
            // and spending into a limit makes the outage longer.
            throw new MarketDataException(
                'Twelve Data request budget is exhausted; deferring.',
                retryable: false
            );
        }

        $response = $this->http->get($this->baseUrl . $endpoint, [
            ...$query,
            'apikey' => $this->apiKey,
        ]);

        $this->budget->record(self::CODE, $endpoint, $response);

        if (!$response->isSuccess()) {
            $message = sprintf(
                'Twelve Data %s failed (HTTP %d)%s',
                $endpoint,
                $response->status,
                $response->error === null ? '' : ': ' . $response->error
            );

            $this->logger->warning('Market data request failed', [
                'event'    => 'api.failed',
                'provider' => self::CODE,
                'endpoint' => $endpoint,
                'status'   => $response->status,
            ]);

            throw new MarketDataException(
                $message,
                retryable: $response->isRetryable(),
                httpStatus: $response->status
            );
        }

        $payload = $response->json();

        if ($payload === null) {
            throw MarketDataException::badResponse(
                sprintf('Twelve Data %s returned a non-JSON body.', $endpoint),
                $response->status
            );
        }

        // Twelve Data reports errors inside a 200 body as often as by status.
        $this->mapper->assertNotAnError($payload);

        return $payload;
    }

    /** Exposed for the health check, which needs the raw response. */
    public function ping(): HttpResponse
    {
        return $this->http->get($this->baseUrl . '/quote', [
            'symbol' => 'XAU/USD',
            'apikey' => $this->apiKey,
        ]);
    }
}
