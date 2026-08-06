<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use GoldBot\Core\Database;
use GoldBot\Infrastructure\Cache\CacheInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;

/**
 * Settings storage with a short-lived cache.
 *
 * Settings are read on nearly every request and change rarely, so the whole
 * table is loaded once and cached. The TTL is deliberately short: APCu is not
 * shared between the web tier and CLI cron (see ApcuCache), so a setting
 * changed in the dashboard must reach the next cron run without an explicit
 * invalidation the CLI process would never see.
 */
final class MySqlSettingsRepository implements SettingsRepositoryInterface
{
    private const CACHE_KEY = 'settings:all';

    private const CACHE_TTL = 60;

    /** @var array<string,mixed>|null Per-request memoisation. */
    private ?array $memo = null;

    public function __construct(
        private readonly Database $database,
        private readonly CacheInterface $cache
    ) {
    }

    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        /** @var array<string,mixed> $values */
        $values = $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $rows = $this->database->select('SELECT `key`, `value`, `type` FROM settings');
            $values = [];

            foreach ($rows as $row) {
                $values[(string) $row['key']] = $this->cast($row['value'], (string) $row['type']);
            }

            return $values;
        });

        return $this->memo = $values;
    }

    public function allWithMetadata(): array
    {
        return $this->database->select(
            'SELECT s.`key`, s.`value`, s.`type`, s.`group`, s.label, s.description,
                    s.is_secret, s.updated_at, u.name AS updated_by_name
             FROM settings s
             LEFT JOIN users u ON u.id = s.updated_by
             ORDER BY s.`group`, s.`key`'
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        $this->database->run(
            'UPDATE settings SET `value` = ?, updated_by = ? WHERE `key` = ?',
            [$this->serialise($value), $updatedBy, $key]
        );

        $this->cache->delete(self::CACHE_KEY);
        $this->memo = null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true),
            'json'  => json_decode((string) $value, true) ?? [],
            default => (string) $value,
        };
    }

    private function serialise(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return (string) $value;
    }
}
