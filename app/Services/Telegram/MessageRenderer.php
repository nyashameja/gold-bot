<?php

declare(strict_types=1);

namespace GoldBot\Services\Telegram;

use GoldBot\Core\Database;

/**
 * Renders a stored template against a payload.
 *
 * Placeholders are {{ name }}. Values are HTML-escaped by default because
 * Telegram's HTML parse mode will reject the whole message on a stray '<' —
 * a signal that fails to send because an instrument name contained an
 * ampersand is a silent outage.
 *
 * {{{ name }}} emits raw, for the few values that are deliberately markup
 * (a pre-formatted block of levels, say). Rare and greppable, by design.
 */
final class MessageRenderer
{
    /** @var array<string,array{body:string,parse_mode:string}>|null */
    private ?array $templates = null;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{text:string,parse_mode:string}
     */
    public function render(string $templateCode, array $payload): array
    {
        $template = $this->template($templateCode);

        if ($template === null) {
            // A missing template must still produce something sendable: losing
            // a stop-loss alert because a template row was deleted would be
            // far worse than an ugly message.
            return [
                'text'       => $this->fallback($templateCode, $payload),
                'parse_mode' => 'HTML',
            ];
        }

        return [
            'text'       => $this->interpolate($template['body'], $payload),
            'parse_mode' => $template['parse_mode'],
        ];
    }

    /** @param array<string,mixed> $payload */
    public function interpolate(string $body, array $payload): string
    {
        // Raw placeholders first, so their braces are consumed before the
        // escaping pass sees them.
        $body = preg_replace_callback(
            '/\{\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}\}/',
            fn (array $m): string => $this->stringify($this->lookup($payload, $m[1])),
            $body
        ) ?? $body;

        $body = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            fn (array $m): string => htmlspecialchars(
                $this->stringify($this->lookup($payload, $m[1])),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ),
            $body
        ) ?? $body;

        // Collapse blank runs left by absent optional sections, so a message
        // with no targets does not arrive with a hole in it.
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        return trim($body);
    }

    /** @param array<string,mixed> $payload */
    private function lookup(array $payload, string $key): mixed
    {
        $value = $payload;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            return implode("\n", array_map($this->stringify(...), $value));
        }

        return (string) $value;
    }

    /** @param array<string,mixed> $payload */
    private function fallback(string $templateCode, array $payload): string
    {
        $lines = ['<b>' . htmlspecialchars($templateCode, ENT_QUOTES, 'UTF-8') . '</b>'];

        foreach ($payload as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = htmlspecialchars(
                    sprintf('%s: %s', $key, (string) $value),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
            }
        }

        return implode("\n", $lines);
    }

    /** @return array{body:string,parse_mode:string}|null */
    private function template(string $code): ?array
    {
        if ($this->templates === null) {
            $this->templates = [];

            foreach ($this->database->select(
                'SELECT code, body, parse_mode FROM telegram_templates WHERE is_active = 1'
            ) as $row) {
                $this->templates[(string) $row['code']] = [
                    'body'       => (string) $row['body'],
                    'parse_mode' => (string) $row['parse_mode'],
                ];
            }
        }

        return $this->templates[$code] ?? null;
    }
}
