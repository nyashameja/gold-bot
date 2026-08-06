<?php

declare(strict_types=1);

namespace GoldBot\Repositories\Contracts;

interface AuditRepositoryInterface
{
    /**
     * Append an audit entry.
     *
     * There is deliberately no update or delete method: an audit trail that
     * can be edited is not an audit trail (docs/02 §3).
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function record(
        ?int $userId,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ipBinary = null,
        ?string $userAgent = null
    ): void;

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50, int $offset = 0): array;

    /** @return list<array<string,mixed>> */
    public function forSubject(string $subjectType, string $subjectId, int $limit = 50): array;

    public function recordLoginAttempt(string $email, ?string $ipBinary, bool $succeeded, ?string $userAgent): void;

    /** Failed attempts for an email since a UTC timestamp. */
    public function failedAttemptsSince(string $email, string $since): int;

    /** Failed attempts from an IP since a UTC timestamp. */
    public function failedAttemptsFromIpSince(string $ipBinary, string $since): int;
}
