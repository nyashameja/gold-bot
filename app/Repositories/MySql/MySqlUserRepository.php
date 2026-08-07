<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use DateTimeImmutable;
use DateTimeZone;
use GoldBot\Domain\Identity\User;
use GoldBot\Repositories\Contracts\UserRepositoryInterface;
use Paragon\Core\Database;
use Paragon\Core\Support\Uuid;

final class MySqlUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findById(int $id): ?User
    {
        return $this->findBy('u.id = ?', [$id]);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findBy('u.email = ?', [$this->normaliseEmail($email)]);
    }

    public function findByUuid(string $uuid): ?User
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->findBy('u.uuid = ?', [Uuid::toBinary($uuid)]);
    }

    public function passwordHashFor(string $email): ?string
    {
        $hash = $this->database->scalar(
            'SELECT password_hash FROM users WHERE email = ? AND deleted_at IS NULL',
            [$this->normaliseEmail($email)]
        );

        return $hash === null ? null : (string) $hash;
    }

    public function lockStateFor(string $email): ?array
    {
        /** @var array{failed_login_count:int,locked_until:?string,is_active:int}|null $row */
        $row = $this->database->selectOne(
            'SELECT failed_login_count, locked_until, is_active
             FROM users WHERE email = ? AND deleted_at IS NULL',
            [$this->normaliseEmail($email)]
        );

        return $row;
    }

    public function create(string $email, string $name, string $passwordHash, array $roles, string $timezone = 'UTC'): int
    {
        return $this->database->transaction(function () use ($email, $name, $passwordHash, $roles, $timezone): int {
            $userId = $this->database->insert('users', [
                'uuid'          => Uuid::toBinary(Uuid::v4()),
                'email'         => $this->normaliseEmail($email),
                'name'          => $name,
                'password_hash' => $passwordHash,
                'timezone'      => $timezone,
                'is_active'     => 1,
            ]);

            foreach ($roles as $slug) {
                $roleId = $this->database->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);

                if ($roleId !== null) {
                    $this->database->run(
                        'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)',
                        [$userId, (int) $roleId]
                    );
                }
            }

            return $userId;
        });
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $this->database->run(
            'UPDATE users SET password_hash = ?, failed_login_count = 0, locked_until = NULL WHERE id = ?',
            [$passwordHash, $userId]
        );
    }

    public function recordSuccessfulLogin(int $userId, ?string $ipBinary): void
    {
        $this->database->run(
            'UPDATE users
             SET last_login_at = UTC_TIMESTAMP(), last_login_ip = ?,
                 failed_login_count = 0, locked_until = NULL
             WHERE id = ?',
            [$ipBinary, $userId]
        );
    }

    public function recordFailedLogin(string $email): int
    {
        $normalised = $this->normaliseEmail($email);

        $this->database->run(
            'UPDATE users SET failed_login_count = failed_login_count + 1
             WHERE email = ? AND deleted_at IS NULL',
            [$normalised]
        );

        return (int) $this->database->scalar(
            'SELECT failed_login_count FROM users WHERE email = ?',
            [$normalised]
        );
    }

    public function lockUntil(string $email, string $until): void
    {
        $this->database->run(
            'UPDATE users SET locked_until = ? WHERE email = ? AND deleted_at IS NULL',
            [$until, $this->normaliseEmail($email)]
        );
    }

    public function all(bool $includeInactive = false): array
    {
        $rows = $this->database->select(
            'SELECT u.id, u.uuid, u.email, u.name, u.is_active, u.timezone, u.last_login_at
             FROM users u
             WHERE u.deleted_at IS NULL' . ($includeInactive ? '' : ' AND u.is_active = 1') . '
             ORDER BY u.name'
        );

        return array_map(fn (array $row): User => $this->hydrate($row), $rows);
    }

    public function listing(bool $includeInactive = true): array
    {
        // GROUP_CONCAT rather than hydrate() in a loop. all() builds a full
        // User per row, and each one costs two further queries for its roles
        // and permissions — right for one user, an N+1 on a list page. The
        // permission set is not needed to render the table at all.
        return $this->database->select(
            'SELECT u.id, u.uuid, u.email, u.name, u.is_active, u.timezone,
                    u.last_login_at, u.created_at,
                    GROUP_CONCAT(r.slug ORDER BY r.id) AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.deleted_at IS NULL' . ($includeInactive ? '' : ' AND u.is_active = 1') . '
             GROUP BY u.id, u.uuid, u.email, u.name, u.is_active, u.timezone,
                      u.last_login_at, u.created_at
             ORDER BY u.name'
        );
    }

    public function emailExists(string $email): bool
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM users WHERE email = ?',
            [$this->normaliseEmail($email)]
        ) > 0;
    }

    /** @param list<mixed> $bindings */
    private function findBy(string $where, array $bindings): ?User
    {
        $row = $this->database->selectOne(
            "SELECT u.id, u.uuid, u.email, u.name, u.is_active, u.timezone, u.last_login_at
             FROM users u
             WHERE {$where} AND u.deleted_at IS NULL",
            $bindings
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): User
    {
        $userId = (int) $row['id'];

        $roles = array_column(
            $this->database->select(
                'SELECT r.slug FROM roles r
                 JOIN user_roles ur ON ur.role_id = r.id
                 WHERE ur.user_id = ?',
                [$userId]
            ),
            'slug'
        );

        // Permissions are the union across the user's roles. Resolved here in
        // one query rather than per-check, so an authorisation test is an
        // in-memory array lookup and can be applied liberally.
        $permissions = array_column(
            $this->database->select(
                'SELECT DISTINCT p.slug FROM permissions p
                 JOIN role_permissions rp ON rp.permission_id = p.id
                 JOIN user_roles ur ON ur.role_id = rp.role_id
                 WHERE ur.user_id = ?',
                [$userId]
            ),
            'slug'
        );

        return new User(
            $userId,
            Uuid::toString((string) $row['uuid']),
            (string) $row['email'],
            (string) $row['name'],
            (int) $row['is_active'] === 1,
            (string) $row['timezone'],
            $roles,
            $permissions,
            $row['last_login_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['last_login_at'], new DateTimeZone('UTC'))
        );
    }

    private function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
