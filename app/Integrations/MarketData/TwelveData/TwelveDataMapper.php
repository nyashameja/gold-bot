<?php

declare(strict_types=1);

namespace GoldBot\Integrations\MarketData\TwelveData;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Market\Candle;
use GoldBot\Domain\Market\CandleSeries;
use GoldBot\Domain\Market\PriceSnapshot;
use GoldBot\Domain\Market\Timeframe;
use GoldBot\Integrations\MarketData\MarketDataException;

/**
 * Translates Twelve Data payloads into domain objects.
 *
 * Separated from the provider so it can be tested against recorded fixtures
 * with no network — which is the only way to assert mapping behaviour for
 * responses that are awkward to provoke on demand (a partial bar, a missing
 * volume field, an error envelope returned with HTTP 200).
 *
 * Pure: no I/O, no clock.
 */
final class TwelveDataMapper
{
    /**
     * Map a `time_series` response.
     *
     * Twelve Data returns newest-first; CandleSeries re-sorts to oldest-first,
     * which every indicator assumes.
     *
     * @param array<string,mixed> $payload
     */
    public function toCandleSeries(array $payload, Timeframe $timeframe, DateTimeImmutable $now, int $settleSeconds = 0): CandleSeries
    {
        $values = $payload['values'] ?? null;

        if (!is_array($values)) {
            throw MarketDataException::badResponse(
                'time_series response has no `values` array.'
            );
        }

        $utc = new DateTimeZone('UTC');
        $candles = [];

        foreach ($values as $index => $value) {
            if (!is_array($value)) {
                continue;
            }

            foreach (['datetime', 'open', 'high', 'low', 'close'] as $field) {
                if (!isset($value[$field])) {
                    throw MarketDataException::badResponse(
                        sprintf('Bar at index %s is missing `%s`.', (string) $index, $field)
                    );
                }
            }

            // Twelve Data returns 'Y-m-d H:i:s' for intraday and 'Y-m-d' for
            // daily bars; both are UTC when the request sets timezone=UTC.
            $openTime = $this->parseDateTime((string) $value['datetime'], $utc);

            $candles[] = new Candle(
                openTime:  $openTime,
                closeTime: $timeframe->candleCloseFor($openTime),
                open:      $this->decimal($value['open']),
                high:      $this->decimal($value['high']),
                low:       $this->decimal($value['low']),
                close:     $this->decimal($value['close']),
                // Spot gold has no central exchange, so volume is frequently
                // absent or zero. Not an error — just not a usable signal.
                volume:    isset($value['volume']) ? $this->decimal($value['volume'], 5) : '0',
                // Closure is decided against our clock, never taken from the
                // provider, so the forming bar cannot slip into analysis.
                isClosed:  $timeframe->isClosedAt($openTime, $now, $settleSeconds)
            );
        }

        return new CandleSeries($candles);
    }

    /**
     * Map a `quote` response.
     *
     * @param array<string,mixed> $payload
     */
    public function toPriceSnapshot(array $payload, DateTimeImmutable $capturedAt): PriceSnapshot
    {
        $price = $payload['close'] ?? $payload['price'] ?? null;

        if ($price === null || !is_numeric($price)) {
            throw MarketDataException::badResponse('quote response has no usable price.');
        }

        $providerTime = null;

        if (isset($payload['timestamp']) && is_numeric($payload['timestamp'])) {
            $providerTime = (new DateTimeImmutable('@' . (int) $payload['timestamp']))
                ->setTimezone(new DateTimeZone('UTC'));
        } elseif (isset($payload['datetime']) && is_string($payload['datetime'])) {
            $providerTime = $this->parseDateTime($payload['datetime'], new DateTimeZone('UTC'));
        }

        return new PriceSnapshot(
            price:          $this->decimal($price),
            capturedAt:     $capturedAt,
            providerTime:   $providerTime,
            bid:            isset($payload['bid']) && is_numeric($payload['bid']) ? $this->decimal($payload['bid']) : null,
            ask:            isset($payload['ask']) && is_numeric($payload['ask']) ? $this->decimal($payload['ask']) : null,
            dayHigh:        isset($payload['high']) && is_numeric($payload['high']) ? $this->decimal($payload['high']) : null,
            dayLow:         isset($payload['low']) && is_numeric($payload['low']) ? $this->decimal($payload['low']) : null,
            changeAbsolute: isset($payload['change']) && is_numeric($payload['change']) ? $this->decimal($payload['change']) : null,
            changePercent:  isset($payload['percent_change']) && is_numeric($payload['percent_change'])
                ? number_format((float) $payload['percent_change'], 4, '.', '')
                : null
        );
    }

    /**
     * Twelve Data signals errors inside a 200 response body as often as by
     * status code, so every payload is inspected before it is mapped.
     *
     * @param array<string,mixed> $payload
     */
    public function assertNotAnError(array $payload): void
    {
        $status = $payload['status'] ?? null;

        if ($status !== 'error') {
            return;
        }

        $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'Unknown provider error.';
        $code = isset($payload['code']) ? (int) $payload['code'] : null;

        if ($code === 429 || stripos($message, 'api credits') !== false || stripos($message, 'rate limit') !== false) {
            throw MarketDataException::rateLimited($message);
        }

        // 401/403 mean a bad or unentitled key: permanent until a human acts,
        // so retrying only wastes quota.
        if (in_array($code, [400, 401, 403, 404], true)) {
            throw MarketDataException::badResponse($message, $code);
        }

        throw MarketDataException::transport($message, $code);
    }

    private function parseDateTime(string $value, DateTimeZone $utc): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $utc)
            ?: DateTimeImmutable::createFromFormat('Y-m-d', $value, $utc)?->setTime(0, 0);

        if ($parsed === false || $parsed === null) {
            throw MarketDataException::badResponse(
                sprintf('Could not parse datetime [%s].', $value)
            );
        }

        return $parsed;
    }

    /**
     * Normalise to a fixed-precision decimal string.
     *
     * Values stay strings end to end (ADR-11) — this is the boundary where a
     * provider's float becomes the exact decimal the database stores.
     */
    private function decimal(mixed $value, int $scale = 5): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
