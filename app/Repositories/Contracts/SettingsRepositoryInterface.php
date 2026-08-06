<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

interface SettingsRepositoryInterface
{
    /** @return array<string,mixed> Key => typed value. */
    public function all(): array;

    /** @return list<array<string,mixed>> Full rows, for the Settings page. */
    public function allWithMetadata(): array;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, ?int $updatedBy = null): void;

    public function has(string $key): bool;
}
