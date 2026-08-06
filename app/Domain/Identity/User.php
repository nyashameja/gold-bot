<?php

declare(strict_types=1);

namespace GoldBot\Domain\Identity;

use DateTimeImmutable;

/**
 * An authenticated user.
 *
 * Immutable, and deliberately carries no password hash: once authentication
 * has happened the hash has no further purpose, and an object that holds it
 * will eventually be logged, serialised into a session, or dumped in an error
 * page. The hash stays in the repository layer.
 */
final class User
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $email,
        public readonly string $name,
        public readonly bool $isActive,
        public readonly string $timezone,
        public readonly array $roles = [],
        public readonly array $permissions = [],
        public readonly ?DateTimeImmutable $lastLoginAt = null
    ) {
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole('administrator');
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @param list<string> $permissions */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials === '' ? mb_strtoupper(mb_substr($this->email, 0, 1)) : $initials;
    }
}
