<?php

declare(strict_types=1);

namespace GoldBot\Infrastructure\Session;

use GoldBot\Core\Database;
use GoldBot\Infrastructure\Clock\ClockInterface;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Sessions stored in MySQL rather than on disk.
 *
 * Two reasons this is worth the extra query (docs/02 §3):
 *
 * 1. An administrator can revoke a session. With file-based sessions the only
 *    way to log somebody out is to delete a file nobody can identify.
 * 2. Sessions survive a PHP-FPM restart and any move to a second host.
 *
 * Implements the timestamp-update interface so a read-only request refreshes
 * last_activity_at without rewriting the whole payload.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly ClockInterface $clock,
        private readonly int $lifetimeMinutes = 120
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function create_sid(): string
    {
        // 32 bytes of entropy, hex encoded. PHP's default generator is fine,
        // but being explicit means the strength does not depend on ini values
        // the host controls.
        return bin2hex(random_bytes(32));
    }

    public function read(string $id): string
    {
        $payload = $this->database->scalar(
            'SELECT payload FROM sessions WHERE session_id = ? AND expires_at > UTC_TIMESTAMP()',
            [$id]
        );

        return $payload === null ? '' : (string) $payload;
    }

    public function write(string $id, string $data): bool
    {
        $expires = $this->clock->now()
            ->modify(sprintf('+%d minutes', $this->lifetimeMinutes))
            ->format('Y-m-d H:i:s');

        // user_id is denormalised from the payload so an administrator can
        // list and revoke a given user's sessions without unserialising
        // every row.
        $userId = $this->userIdFrom($data);

        $this->database->upsert(
            'sessions',
            [
                'session_id'       => $id,
                'user_id'          => $userId,
                'payload'          => $data,
                'ip_address'       => $this->currentIpBinary(),
                'user_agent'       => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'last_activity_at' => $this->clock->now()->format('Y-m-d H:i:s'),
                'expires_at'       => $expires,
            ],
            ['user_id', 'payload', 'ip_address', 'user_agent', 'last_activity_at', 'expires_at']
        );

        return true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $expires = $this->clock->now()
            ->modify(sprintf('+%d minutes', $this->lifetimeMinutes))
            ->format('Y-m-d H:i:s');

        $this->database->run(
            'UPDATE sessions SET last_activity_at = UTC_TIMESTAMP(), expires_at = ? WHERE session_id = ?',
            [$expires, $id]
        );

        return true;
    }

    public function validateId(string $id): bool
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM sessions WHERE session_id = ? AND expires_at > UTC_TIMESTAMP()',
            [$id]
        ) > 0;
    }

    public function destroy(string $id): bool
    {
        $this->database->run('DELETE FROM sessions WHERE session_id = ?', [$id]);

        return true;
    }

    /** @return int|false */
    public function gc(int $maxLifetime): int|false
    {
        return $this->database->run('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP()');
    }

    /** Revoke every session belonging to a user. */
    public function destroyForUser(int $userId): int
    {
        return $this->database->run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }

    /**
     * Extract the authenticated user id from a serialised session payload
     * without unserialising it — the payload is untrusted input as far as
     * unserialize() is concerned, and a regex is enough for one integer.
     */
    private function userIdFrom(string $data): ?int
    {
        if (preg_match('/user_id\|i:(\d+);/', $data, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function currentIpBinary(): ?string
    {
        $packed = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        return $packed === false ? null : $packed;
    }
}
