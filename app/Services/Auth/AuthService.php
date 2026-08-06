<?php

declare(strict_types=1);

namespace GoldBot\Services\Auth;

use GoldBot\Core\Request;
use GoldBot\Domain\Identity\User;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Infrastructure\Session\DatabaseSessionHandler;
use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use GoldBot\Repositories\Contracts\UserRepositoryInterface;

/**
 * Authentication: login, logout, and the current user.
 *
 * Throttling is applied per account and per IP. Both are needed: per-account
 * alone lets one attacker spray a password across many accounts from one host,
 * and per-IP alone lets a distributed attacker grind a single account.
 */
final class AuthService
{
    private ?User $cachedUser = null;

    private bool $resolved = false;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditRepositoryInterface $audit,
        private readonly DatabaseSessionHandler $sessions,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts = 5,
        private readonly int $lockoutMinutes = 15
    ) {
    }

    public function attempt(string $email, string $password, Request $request): AuthResult
    {
        $email = mb_strtolower(trim($email));
        $ipBinary = $request->ipBinary();

        if ($this->isThrottled($email, $ipBinary)) {
            $this->audit->recordLoginAttempt($email, $ipBinary, false, $request->userAgent());

            $this->logger->warning('Login blocked by throttle', [
                'event' => 'auth.throttled',
                'email' => $email,
                'ip'    => $request->ip(),
            ]);

            return AuthResult::throttled($this->lockoutMinutes);
        }

        $state = $this->users->lockStateFor($email);
        $hash = $this->users->passwordHashFor($email);

        // Always run a verification, even when the account does not exist, so
        // the response time does not reveal which emails are registered.
        $verified = password_verify(
            $password,
            $hash ?? '$argon2id$v=19$m=65536,t=4,p=2$ZmFrZXNhbHRmYWtlc2E$0000000000000000000000000000000000000000000'
        );

        if ($hash === null || !$verified) {
            $this->recordFailure($email, $ipBinary, $request->userAgent());

            return AuthResult::invalidCredentials();
        }

        if ($state !== null && (int) $state['is_active'] !== 1) {
            $this->audit->recordLoginAttempt($email, $ipBinary, false, $request->userAgent());

            return AuthResult::inactive();
        }

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            return AuthResult::invalidCredentials();
        }

        // Rehash if the cost parameters have since been raised, so existing
        // accounts strengthen on next login rather than staying on old costs.
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID, (array) config('app.auth.hash_options', []))) {
            $this->users->updatePassword($user->id, $this->hash($password));
        }

        $this->login($user, $request);

        return AuthResult::success($user);
    }

    public function login(User $user, Request $request): void
    {
        // Regenerate on privilege change to prevent session fixation: an
        // attacker who planted a session id before login must not inherit the
        // authenticated session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = $user->id;
        $_SESSION['logged_in_at'] = $this->clock->timestamp();
        $_SESSION['last_activity'] = $this->clock->timestamp();

        $this->cachedUser = $user;
        $this->resolved = true;

        $this->users->recordSuccessfulLogin($user->id, $request->ipBinary());
        $this->audit->recordLoginAttempt($user->email, $request->ipBinary(), true, $request->userAgent());
        $this->audit->record(
            $user->id,
            'auth.login',
            'user',
            (string) $user->id,
            null,
            null,
            $request->ipBinary(),
            $request->userAgent()
        );

        $this->logger->info('User logged in', [
            'event'   => 'auth.login',
            'user_id' => $user->id,
            'email'   => $user->email,
            'ip'      => $request->ip(),
        ]);
    }

    public function logout(Request $request): void
    {
        $user = $this->user();

        if ($user !== null) {
            $this->audit->record(
                $user->id,
                'auth.logout',
                'user',
                (string) $user->id,
                null,
                null,
                $request->ipBinary(),
                $request->userAgent()
            );

            $this->logger->info('User logged out', [
                'event'   => 'auth.logout',
                'user_id' => $user->id,
            ]);
        }

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->cachedUser = null;
        $this->resolved = true;
    }

    /** Revoke every session for a user — used when deactivating an account. */
    public function logoutEverywhere(int $userId): int
    {
        return $this->sessions->destroyForUser($userId);
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->cachedUser;
        }

        $this->resolved = true;

        $userId = $_SESSION['user_id'] ?? null;

        if (!is_int($userId)) {
            return $this->cachedUser = null;
        }

        $user = $this->users->findById($userId);

        // A user deactivated mid-session must lose access at the next request,
        // not at the next login.
        if ($user === null || !$user->isActive) {
            $_SESSION = [];

            return $this->cachedUser = null;
        }

        return $this->cachedUser = $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        return $this->user()?->id;
    }

    /** True when the session has been idle beyond the configured timeout. */
    public function isIdle(int $idleTimeoutMinutes): bool
    {
        $last = $_SESSION['last_activity'] ?? null;

        if (!is_int($last)) {
            return false;
        }

        return ($this->clock->timestamp() - $last) > ($idleTimeoutMinutes * 60);
    }

    public function touch(): void
    {
        $_SESSION['last_activity'] = $this->clock->timestamp();
    }

    public function hash(string $password): string
    {
        return password_hash(
            $password,
            PASSWORD_ARGON2ID,
            (array) config('app.auth.hash_options', [])
        );
    }

    private function isThrottled(string $email, ?string $ipBinary): bool
    {
        $state = $this->users->lockStateFor($email);

        if ($state !== null && $state['locked_until'] !== null && $state['locked_until'] > $this->nowString()) {
            return true;
        }

        $since = $this->clock->now()
            ->modify(sprintf('-%d minutes', $this->lockoutMinutes))
            ->format('Y-m-d H:i:s');

        if ($this->audit->failedAttemptsSince($email, $since) >= $this->maxAttempts) {
            return true;
        }

        // The IP allowance is deliberately wider than the per-account one:
        // several people behind one office NAT must not lock each other out.
        return $ipBinary !== null
            && $this->audit->failedAttemptsFromIpSince($ipBinary, $since) >= ($this->maxAttempts * 4);
    }

    private function recordFailure(string $email, ?string $ipBinary, string $userAgent): void
    {
        $this->audit->recordLoginAttempt($email, $ipBinary, false, $userAgent);

        // Only counts up for a real account; a non-existent email has nothing
        // to lock, and pretending otherwise would leak which emails exist.
        if ($this->users->lockStateFor($email) !== null) {
            $failures = $this->users->recordFailedLogin($email);

            if ($failures >= $this->maxAttempts) {
                $this->users->lockUntil(
                    $email,
                    $this->clock->now()
                        ->modify(sprintf('+%d minutes', $this->lockoutMinutes))
                        ->format('Y-m-d H:i:s')
                );

                $this->logger->warning('Account locked after repeated failures', [
                    'event'    => 'auth.locked',
                    'email'    => $email,
                    'failures' => $failures,
                ]);
            }
        }

        $this->logger->notice('Failed login', ['event' => 'auth.failed', 'email' => $email]);
    }

    private function nowString(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
