<?php

declare(strict_types=1);

namespace GoldBot\Repositories\MySql;

use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use Paragon\Core\Database;

final class MySqlAuditRepository implements AuditRepositoryInterface
{
    /** Keys whose values must never be written to the audit trail. */
    private const REDACT = ['password', 'password_hash', 'token', 'api_key', 'secret', 'bot_token'];

    public function __construct(private readonly Database $database)
    {
    }

    public function record(
        ?int $userId,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ipBinary = null,
        ?string $userAgent = null
    ): void {
        $this->database->insert('audit_logs', [
            'user_id'      => $userId,
            'action'       => $action,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'before'       => $this->encode($before),
            'after'        => $this->encode($after),
            'ip_address'   => $ipBinary,
            'user_agent'   => $userAgent === null ? null : substr($userAgent, 0, 255),
        ]);
    }

    public function recent(int $limit = 50, int $offset = 0): array
    {
        return $this->database->select(
            'SELECT a.id, a.action, a.subject_type, a.subject_id, a.`before`, a.`after`,
                    a.created_at, u.name AS user_name, u.email AS user_email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC
             LIMIT ? OFFSET ?',
            [max(1, min($limit, 500)), max(0, $offset)]
        );
    }

    public function forSubject(string $subjectType, string $subjectId, int $limit = 50): array
    {
        return $this->database->select(
            'SELECT a.id, a.action, a.`before`, a.`after`, a.created_at,
                    u.name AS user_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.subject_type = ? AND a.subject_id = ?
             ORDER BY a.id DESC
             LIMIT ?',
            [$subjectType, $subjectId, max(1, min($limit, 500))]
        );
    }

    public function recordLoginAttempt(string $email, ?string $ipBinary, bool $succeeded, ?string $userAgent): void
    {
        $this->database->insert('login_attempts', [
            'email'      => mb_strtolower(trim($email)),
            'ip_address' => $ipBinary,
            'succeeded'  => $succeeded ? 1 : 0,
            'user_agent' => $userAgent === null ? null : substr($userAgent, 0, 255),
        ]);
    }

    public function failedAttemptsSince(string $email, string $since): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND succeeded = 0 AND attempted_at >= ?',
            [mb_strtolower(trim($email)), $since]
        );
    }

    public function failedAttemptsFromIpSince(string $ipBinary, string $since): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND succeeded = 0 AND attempted_at >= ?',
            [$ipBinary, $since]
        );
    }

    /** @param array<string,mixed>|null $values */
    private function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            foreach (self::REDACT as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $values[$key] = '[redacted]';

                    continue 2;
                }
            }
        }

        return json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }
}
