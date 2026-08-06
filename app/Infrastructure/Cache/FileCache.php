<?php

declare(strict_types=1);

namespace GoldBot\Infrastructure\Cache;

/**
 * Filesystem cache — the fallback when APCu is unavailable.
 *
 * Shared cPanel hosting frequently lacks APCu, so this is not a rarely-taken
 * path; it is the likely default in production and is written accordingly.
 *
 * Writes go through a temporary file and rename(), which is atomic on POSIX.
 * Without that, a reader can observe a half-written file and decode garbage.
 */
final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0750, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return $default;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return $default;
        }

        /** @var array{expires:int,value:mixed}|null $entry */
        $entry = @unserialize($contents, ['allowed_classes' => false]);

        if (!is_array($entry) || !array_key_exists('expires', $entry)) {
            // Corrupt or truncated entry — treat as a miss and clean up.
            @unlink($path);

            return $default;
        }

        if ($entry['expires'] !== 0 && $entry['expires'] < time()) {
            @unlink($path);

            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $path = $this->path($key);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return false;
        }

        $payload = serialize([
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value'   => $value,
        ]);

        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $payload, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        @chmod($path, 0640);

        return true;
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();

        return $this->get($key, $sentinel) !== $sentinel;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);

        return !is_file($path) || @unlink($path);
    }

    public function clear(): bool
    {
        $ok = true;

        foreach (glob($this->directory . '/*/*.cache') ?: [] as $file) {
            $ok = @unlink($file) && $ok;
        }

        return $ok;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->get($key, $sentinel);

        if ($value !== $sentinel) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Hash the key so arbitrary cache keys cannot escape the cache directory
     * via path separators or traversal sequences. The two-character prefix
     * directory keeps any single directory from accumulating a huge entry
     * count, which some filesystems handle poorly.
     */
    private function path(string $key): string
    {
        $hash = hash('sha256', $key);

        return sprintf('%s/%s/%s.cache', rtrim($this->directory, '/'), substr($hash, 0, 2), $hash);
    }
}
