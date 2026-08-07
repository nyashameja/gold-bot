<?php

declare(strict_types=1);

namespace Paragon\Core\Cache;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;

    /** @param int $ttl Seconds. 0 means no expiry. */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    public function has(string $key): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    /**
     * Return the cached value, computing and storing it on a miss.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function remember(string $key, int $ttl, callable $callback): mixed;
}
