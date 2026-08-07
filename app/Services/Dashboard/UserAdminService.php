<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use GoldBot\Domain\Identity\User;
use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use GoldBot\Repositories\Contracts\UserRepositoryInterface;
use GoldBot\Services\Auth\AuthService;
use Paragon\Core\Database;

/**
 * The Users page.
 *
 * Two rules are enforced here rather than only in the UI, because a form is
 * not a security boundary (docs/01 §10):
 *
 *  1. Nobody may remove their own administrator role or deactivate themselves.
 *     Both are one click from locking the last admin out of a system with no
 *     recovery path but a database console.
 *  2. The last active administrator cannot be demoted or deactivated at all.
 *
 * Passwords are hashed by AuthService with Argon2id; no hash is ever returned
 * from this service, and none is written to the audit log.
 */
final class UserAdminService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuthService $auth,
        private readonly AuditRepositoryInterface $audit,
        private readonly Database $database
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function board(): array
    {
        $rows = $this->users->listing(includeInactive: true);
        $roleCounts = $this->roleCounts();

        return [
            'users' => array_map(
                static fn (array $row): array => [
                    'id'         => (int) $row['id'],
                    'uuid'       => (string) $row['uuid'],
                    'email'      => (string) $row['email'],
                    'name'       => (string) $row['name'],
                    'is_active'  => (int) $row['is_active'] === 1,
                    'timezone'   => (string) $row['timezone'],
                    'roles'      => $row['roles'] === null || $row['roles'] === ''
                        ? []
                        : explode(',', (string) $row['roles']),
                    'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
                    'created_at' => (string) $row['created_at'],
                ],
                $rows
            ),
            'roles'       => $this->roles(),
            'role_counts' => $roleCounts,
            'admin_count' => $this->activeAdministrators(),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,errors:array<string,string>,user_id:int|null}
     */
    public function create(array $input, User $actor, ?string $ipBinary = null): array
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $roles = array_values(array_filter((array) ($input['roles'] ?? []), 'is_string'));
        $timezone = trim((string) ($input['timezone'] ?? 'UTC'));

        $errors = [];

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A valid email address is required.';
        } elseif ($this->users->emailExists($email)) {
            $errors['email'] = 'That email address is already registered.';
        }

        if ($name === '') {
            $errors['name'] = 'A name is required.';
        }

        // Length over composition rules. A 12-character passphrase resists
        // guessing better than "P@ss1!" and is far likelier to be remembered
        // rather than written on a note beside the terminal.
        if (strlen($password) < 12) {
            $errors['password'] = 'The password must be at least 12 characters.';
        }

        $known = array_column($this->roles(), 'code');
        $roles = array_values(array_intersect($roles, $known));

        if ($roles === []) {
            $errors['roles'] = 'Select at least one role.';
        }

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Unknown timezone.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'user_id' => null];
        }

        $userId = $this->users->create($email, $name, $this->auth->hash($password), $roles, $timezone);

        $this->audit->record(
            $actor->id,
            'user.created',
            'user',
            (string) $userId,
            null,
            // No password, no hash. An audit log is not a credential store.
            ['email' => $email, 'name' => $name, 'roles' => $roles],
            $ipBinary
        );

        return ['ok' => true, 'errors' => [], 'user_id' => $userId];
    }

    /**
     * Activate or deactivate a user.
     *
     * @return array{ok:bool,error:string|null}
     */
    public function setActive(int $userId, bool $active, User $actor, ?string $ipBinary = null): array
    {
        $target = $this->users->findById($userId);

        if ($target === null) {
            return ['ok' => false, 'error' => 'No such user.'];
        }

        if (!$active && $userId === $actor->id) {
            return ['ok' => false, 'error' => 'You cannot deactivate your own account.'];
        }

        if (!$active && $target->isAdministrator() && $this->activeAdministrators() <= 1) {
            return ['ok' => false, 'error' => 'This is the last active administrator.'];
        }

        $this->database->run(
            'UPDATE users SET is_active = ? WHERE id = ?',
            [$active ? 1 : 0, $userId]
        );

        // A deactivated user with a live session is still a logged-in user
        // until the session is destroyed.
        if (!$active) {
            $this->auth->logoutEverywhere($userId);
        }

        $this->audit->record(
            $actor->id,
            $active ? 'user.activated' : 'user.deactivated',
            'user',
            (string) $userId,
            ['is_active' => !$active],
            ['is_active' => $active],
            $ipBinary
        );

        return ['ok' => true, 'error' => null];
    }

    /**
     * Replace a user's roles.
     *
     * @param list<string> $roles
     * @return array{ok:bool,error:string|null}
     */
    public function setRoles(int $userId, array $roles, User $actor, ?string $ipBinary = null): array
    {
        $target = $this->users->findById($userId);

        if ($target === null) {
            return ['ok' => false, 'error' => 'No such user.'];
        }

        $known = array_column($this->roles(), 'code');
        $roles = array_values(array_intersect($roles, $known));

        if ($roles === []) {
            return ['ok' => false, 'error' => 'A user must hold at least one role.'];
        }

        $losesAdmin = $target->isAdministrator() && !in_array('administrator', $roles, true);

        if ($losesAdmin && $userId === $actor->id) {
            return ['ok' => false, 'error' => 'You cannot remove your own administrator role.'];
        }

        if ($losesAdmin && $this->activeAdministrators() <= 1) {
            return ['ok' => false, 'error' => 'This is the last active administrator.'];
        }

        $before = $target->roles;

        $this->database->transaction(function () use ($userId, $roles): void {
            $this->database->run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);

            foreach ($roles as $code) {
                $this->database->run(
                    'INSERT INTO user_roles (user_id, role_id)
                     SELECT ?, id FROM roles WHERE slug = ?',
                    [$userId, $code]
                );
            }
        });

        $this->audit->record(
            $actor->id,
            'user.roles_changed',
            'user',
            (string) $userId,
            ['roles' => $before],
            ['roles' => $roles],
            $ipBinary
        );

        return ['ok' => true, 'error' => null];
    }

    /** @return list<array{code:string,name:string,description:string|null}> */
    public function roles(): array
    {
        return array_map(
            static fn (array $row): array => [
                'code'        => (string) $row['slug'],
                'name'        => (string) $row['name'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
            ],
            $this->database->select('SELECT slug, name, description FROM roles ORDER BY id')
        );
    }

    /** @return array<string,int> */
    private function roleCounts(): array
    {
        $counts = [];

        foreach ($this->database->select(
            'SELECT r.slug, COUNT(ur.user_id) AS total
             FROM roles r LEFT JOIN user_roles ur ON ur.role_id = r.id
             GROUP BY r.id, r.slug ORDER BY r.id'
        ) as $row) {
            $counts[(string) $row['slug']] = (int) $row['total'];
        }

        return $counts;
    }

    private function activeAdministrators(): int
    {
        return (int) $this->database->scalar(
            "SELECT COUNT(DISTINCT u.id)
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.slug = 'administrator' AND u.is_active = 1 AND u.deleted_at IS NULL"
        );
    }
}
