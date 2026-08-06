<?php

declare(strict_types=1);

namespace GoldBot\Services\Auth;

use GoldBot\Domain\Identity\User;

/**
 * The outcome of a login attempt.
 *
 * The public message is deliberately identical for a wrong password and an
 * unknown email — anything else is an account-enumeration oracle.
 */
final class AuthResult
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?User $user = null,
        public readonly string $message = '',
        public readonly string $reason = ''
    ) {
    }

    public static function success(User $user): self
    {
        return new self(true, $user, '', 'success');
    }

    public static function invalidCredentials(): self
    {
        return new self(false, null, 'Those credentials do not match our records.', 'invalid_credentials');
    }

    public static function inactive(): self
    {
        return new self(false, null, 'This account has been deactivated.', 'inactive');
    }

    public static function throttled(int $minutes): self
    {
        return new self(
            false,
            null,
            sprintf('Too many failed attempts. Try again in %d minutes.', $minutes),
            'throttled'
        );
    }
}
