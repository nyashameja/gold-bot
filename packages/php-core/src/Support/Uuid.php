<?php

declare(strict_types=1);

namespace Paragon\Core\Support;

use InvalidArgumentException;

/**
 * UUID generation and BINARY(16) conversion.
 *
 * Public identifiers are stored as BINARY(16) rather than CHAR(36) — 16 bytes
 * against 36 on an index that is read constantly (ADR-10, docs/02 §1). These
 * helpers are the boundary between the database representation and the string
 * form used in URLs and API responses.
 */
final class Uuid
{
    /**
     * Generate a random (version 4) UUID.
     *
     * Used for user-facing aggregates where the identifier must not encode
     * creation order — a v7 UUID would leak signal timing into public URLs.
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant

        return self::toString($bytes);
    }

    /** Pack a canonical UUID string into 16 raw bytes for storage. */
    public static function toBinary(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);

        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            throw new InvalidArgumentException("Value [{$uuid}] is not a valid UUID.");
        }

        $binary = hex2bin($hex);

        if ($binary === false) {
            throw new InvalidArgumentException("Value [{$uuid}] is not a valid UUID.");
        }

        return $binary;
    }

    /** Unpack 16 raw bytes into a canonical UUID string. */
    public static function toString(string $binary): string
    {
        if (strlen($binary) !== 16) {
            throw new InvalidArgumentException('A binary UUID must be exactly 16 bytes.');
        }

        $hex = bin2hex($binary);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function isValid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
