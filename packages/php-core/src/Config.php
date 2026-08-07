<?php

declare(strict_types=1);

namespace Paragon\Core;

use RuntimeException;

/**
 * Typed configuration loaded from config/*.php.
 *
 * Files return arrays; keys are addressed in dot notation where the first
 * segment is the filename — config('database.connections.mysql.host').
 *
 * Configuration is read-only after boot. Runtime-mutable values belong in the
 * `settings` table, which is a different thing with different semantics: it is
 * audited, editable in the UI, and survives a deploy.
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $items = [];

    /** @var array<string,mixed> Flattened cache of resolved dot-paths. */
    private array $resolved = [];

    /**
     * Files in config/ that are not configuration arrays.
     *
     * services.php returns a closure that registers container bindings, and
     * routes/ holds route definitions. Both live here because that is where an
     * operator expects to find them, but neither is a settings file.
     */
    private const NOT_CONFIG = ['services'];

    public function __construct(private readonly string $path)
    {
    }

    public function load(): void
    {
        $files = glob(rtrim($this->path, '/') . '/*.php');

        if ($files === false) {
            throw new RuntimeException("Configuration directory [{$this->path}] is not readable.");
        }

        foreach ($files as $file) {
            $key = basename($file, '.php');

            if (in_array($key, self::NOT_CONFIG, true)) {
                continue;
            }

            $values = require $file;

            if (!is_array($values)) {
                throw new RuntimeException("Configuration file [{$file}] must return an array.");
            }

            $this->items[$key] = $values;
        }

        $this->resolved = [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $this->resolved[$key] = $value;
    }

    /**
     * Fetch a value the application cannot run without.
     *
     * Distinct from get() with a default: this asserts the key exists, so a
     * typo in a config path fails at boot rather than silently yielding null.
     */
    public function require(string $key): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->get($key, $sentinel);

        if ($value === $sentinel) {
            throw new RuntimeException("Required configuration key [{$key}] is not defined.");
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /** @return array<mixed> */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();

        return $this->get($key, $sentinel) !== $sentinel;
    }

    /**
     * Override a value in memory. Test-facing; not used at runtime.
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
        $this->resolved = [];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
