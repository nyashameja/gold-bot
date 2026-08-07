<?php

declare(strict_types=1);

namespace Paragon\Core\Support;

/**
 * Per-session CSRF token.
 *
 * One token per session rather than per form: rotating per form breaks the
 * back button and multiple tabs, and the security gain is negligible once the
 * token is unguessable and compared in constant time.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public function isValid(?string $candidate): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($expected) || !is_string($candidate) || $candidate === '') {
            return false;
        }

        // Constant-time comparison: a timing-sensitive === would leak the
        // token a character at a time.
        return hash_equals($expected, $candidate);
    }

    /** Rotate the token — called on login, alongside session regeneration. */
    public function rotate(): string
    {
        unset($_SESSION[self::SESSION_KEY]);

        return $this->token();
    }

    /** A ready-made hidden input for forms. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="_token" value="%s">',
            htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}
