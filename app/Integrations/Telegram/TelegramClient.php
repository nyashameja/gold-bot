<?php

declare(strict_types=1);

namespace GoldBot\Integrations\Telegram;

use GoldBot\Infrastructure\Http\ApiBudget;
use GoldBot\Infrastructure\Http\HttpClient;
use GoldBot\Infrastructure\Logging\LoggerInterface;

/**
 * Telegram Bot API client.
 *
 * Deliberately thin: the outbox owns retries, backoff and dead-lettering, so
 * this reports what happened and nothing more. A client that retried on its own
 * would compete with the queue and double-send.
 */
final class TelegramClient implements TelegramClientInterface
{
    public const CODE = 'TELEGRAM';

    public function __construct(
        private readonly HttpClient $http,
        private readonly ApiBudget $budget,
        private readonly LoggerInterface $logger,
        private readonly string $botToken = '',
        private readonly string $baseUrl = 'https://api.telegram.org'
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->botToken !== '';
    }

    /**
     * Send a message.
     *
     * `retryable` distinguishes a transient fault from a permanent one. A 400
     * for a malformed message will fail identically forever, so retrying it
     * only delays the dead-letter and burns quota; a 429 or 5xx will not.
     *
     * @return array{ok:bool,messageId:?string,error:?string,retryable:bool}
     */
    public function sendMessage(string $chatId, string $text, string $parseMode = 'HTML'): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok'        => false,
                'messageId' => null,
                'error'     => 'Telegram bot token is not configured.',
                // Not retryable: no amount of waiting supplies a token.
                'retryable' => false,
            ];
        }

        if (!$this->budget->canSpend(self::CODE)) {
            return [
                'ok'        => false,
                'messageId' => null,
                'error'     => 'Telegram rate budget exhausted.',
                // Retryable — the window rolls.
                'retryable' => true,
            ];
        }

        $response = $this->http->get($this->endpoint('sendMessage'), [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => 'true',
        ]);

        $this->budget->record(self::CODE, '/sendMessage', $response);

        $payload = $response->json();

        if ($response->isSuccess() && ($payload['ok'] ?? false) === true) {
            return [
                'ok'        => true,
                'messageId' => isset($payload['result']['message_id'])
                    ? (string) $payload['result']['message_id']
                    : null,
                'error'     => null,
                'retryable' => false,
            ];
        }

        // Telegram reports failures in the body as often as by status code.
        $description = is_string($payload['description'] ?? null)
            ? $payload['description']
            : ($response->error ?? sprintf('HTTP %d', $response->status));

        $this->logger->warning('Telegram send failed', [
            'event'   => 'telegram.failed',
            'chat_id' => $chatId,
            'status'  => $response->status,
            'error'   => $description,
        ]);

        return [
            'ok'        => false,
            'messageId' => null,
            'error'     => mb_substr($description, 0, 255),
            'retryable' => $response->isRetryable() || $response->status === 429,
        ];
    }

    /** Verify the token and return the bot's identity. */
    public function getMe(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'Telegram bot token is not configured.'];
        }

        $response = $this->http->get($this->endpoint('getMe'));
        $this->budget->record(self::CODE, '/getMe', $response);

        $payload = $response->json();

        if ($response->isSuccess() && ($payload['ok'] ?? false) === true) {
            return [
                'ok'       => true,
                'username' => $payload['result']['username'] ?? null,
                'name'     => $payload['result']['first_name'] ?? null,
            ];
        }

        return [
            'ok'    => false,
            'error' => is_string($payload['description'] ?? null)
                ? $payload['description']
                : ($response->error ?? sprintf('HTTP %d', $response->status)),
        ];
    }

    /**
     * The token is a credential, so it lives in the path rather than a query
     * string — query strings are far more likely to be logged by proxies.
     */
    private function endpoint(string $method): string
    {
        return sprintf('%s/bot%s/%s', rtrim($this->baseUrl, '/'), $this->botToken, $method);
    }
}
