<?php

declare(strict_types=1);

namespace Paragon\Core\Http;

use Paragon\Core\Logging\LoggerInterface;

/**
 * cURL-based HTTP client for outbound provider calls.
 *
 * Deliberately minimal — GET with query parameters is all a read-only
 * provider integration needs. TLS verification is on and there is no option
 * to disable it: an unverified connection to a market data feed is an
 * invitation to be fed fabricated prices.
 */
final class HttpClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 15,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly string $userAgent = 'ParagonCore/1.0'
    ) {
    }

    /**
     * @param array<string,scalar|null> $query
     * @param array<string,string>      $headers
     */
    public function get(string $url, array $query = [], array $headers = []): HttpResponse
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query(
                array_filter($query, static fn (mixed $v): bool => $v !== null)
            );
        }

        $startedAt = microtime(true);
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => $this->formatHeaders($headers),
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle) !== 0 ? curl_error($handle) : null;

        curl_close($handle);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($error !== null) {
            $this->logger->warning('Outbound request failed', [
                'event'       => 'http.failed',
                // The host only — a full URL can carry the API key in its query.
                'host'        => parse_url($url, PHP_URL_HOST),
                'error'       => $error,
                'duration_ms' => $durationMs,
            ]);
        }

        return new HttpResponse(
            $status,
            is_string($body) ? $body : '',
            $durationMs,
            $error
        );
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = ['Accept: application/json'];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }
}
