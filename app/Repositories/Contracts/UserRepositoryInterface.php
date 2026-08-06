<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

use GoldBot\Domain\Identity\User;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    public function findByUuid(string $uuid): ?User;

    /**
     * The stored password hash, or null if the user does not exist.
     *
     * Separated from findByEmail() so the hash never travels inside a User
     * object that might be logged or serialised into a session.
     */
    public function passwordHashFor(string $email): ?string;

    /** @return array{failed_login_count:int,locked_until:?string,is_active:int}|null */
    public function lockStateFor(string $email): ?array;

    /**
     * @param list<string> $roles Role slugs.
     * @return int The new user's id.
     */
    public function create(string $email, string $name, string $passwordHash, array $roles, string $timezone = 'UTC'): int;

    public function updatePassword(int $userId, string $passwordHash): void;

    public function recordSuccessfulLogin(int $userId, ?string $ipBinary): void;

    /** @return int The resulting consecutive failure count. */
    public function recordFailedLogin(string $email): int;

    public function lockUntil(string $email, string $until): void;

    /** @return list<User> */
    public function all(bool $includeInactive = false): array;

    public function emailExists(string $email): bool;
}
