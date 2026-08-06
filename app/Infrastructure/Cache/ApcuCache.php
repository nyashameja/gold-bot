<?php

declare(strict_types=1);

namespace GoldBot\Infrastructure\Cache;

/**
 * APCu-backed cache. Preferred when the extension is present.
 *
 * Note that APCu is per-process-pool and is NOT shared between the web tier
 * and CLI cron: a value cached by a cron task is invisible to the dashboard
 * and vice versa. That is acceptable because nothing in Gold Bot uses the
 * cache for coordination — cross-process state lives in MySQL by design
 * (docs/01 §2). It is only ever a read accelerator.
 */
final class ApcuCache implements CacheInterface
{
    public function __construct(private readonly string $prefix = 'goldbot:')
    {
    }

    public static function isSupported(): bool
    {
        return extension_loaded('apcu')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);

        return $success ? $value : $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return apcu_store($this->prefix . $key, $value, max(0, $ttl));
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->prefix . $key);
    }

    public function delete(string $key): bool
    {
        return apcu_delete($this->prefix . $key) || !$this->has($key);
    }

    public function clear(): bool
    {
        // Clearing only our own prefix, not the whole cache — other
        // applications may share this APCu instance on shared hosting.
        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/');

        return apcu_delete($iterator);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);

        if ($success) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }
}
