<?php

declare(strict_types=1);

namespace Paragon\Core;

use RuntimeException;

/**
 * Environment variable access with type coercion.
 *
 * Values arrive from the process environment as strings; this is the single
 * place where they become the types the application actually wants. Reading
 * getenv() directly anywhere else re-implements this coercion badly — in
 * particular the string "false", which is truthy in PHP and has caused an
 * APP_DEBUG=false to enable debug mode in more than one codebase.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $overrides = [];

    /**
     * Seed values directly, bypassing the process environment.
     *
     * Used by tests to exercise configuration without mutating global state.
     *
     * @param array<string,string> $values
     */
    public static function seed(array $values): void
    {
        self::$overrides = $values;
    }

    public static function clear(): void
    {
        self::$overrides = [];
    }

    public static function get(string $key, string|int|float|bool|null $default = null): string|int|float|bool|null
    {
        $raw = self::raw($key);

        if ($raw === null) {
            return $default;
        }

        return match (strtolower($raw)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $raw,
        };
    }

    /**
     * Fetch a value that the application cannot start without.
     *
     * Failing loudly at boot beats failing obscurely at the first use — a
     * missing DB_PASSWORD should not first surface as a connection error
     * three layers down.
     */
    public static function require(string $key): string
    {
        $value = self::raw($key);

        if ($value === null || $value === '') {
            throw new RuntimeException(
                "Required environment variable [{$key}] is not set. "
                . 'Copy .env.example to .env and populate it.'
            );
        }

        return $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? $default : (string) $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::raw($key);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::raw($key);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function raw(string $key): ?string
    {
        // Seeded values take the same normalisation path as real ones. Reading
        // overrides straight out of the array would make every seeded test
        // subtly unfaithful to production — which is worse than no test.
        $value = array_key_exists($key, self::$overrides)
            ? self::$overrides[$key]
            // $_ENV and $_SERVER are populated by phpdotenv; getenv() covers
            // values exported by the shell or set in the cPanel environment.
            : ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key));

        if ($value === false || $value === null) {
            return null;
        }

        return trim((string) $value, "\"'");
    }
}
