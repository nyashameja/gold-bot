<?php

declare(strict_types=1);

namespace Paragon\Core\Tests;

use InvalidArgumentException;
use Paragon\Core\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function test_v4_produces_a_valid_canonical_uuid(): void
    {
        $uuid = Uuid::v4();

        self::assertTrue(Uuid::isValid($uuid));
        self::assertSame(36, strlen($uuid));
        self::assertSame('4', $uuid[14], 'The version nibble must be 4.');
        self::assertContains($uuid[19], ['8', '9', 'a', 'b'], 'The variant nibble must be RFC 4122.');
    }

    public function test_v4_values_are_distinct(): void
    {
        $values = [];

        for ($i = 0; $i < 500; $i++) {
            $values[Uuid::v4()] = true;
        }

        self::assertCount(500, $values);
    }

    public function test_it_round_trips_through_the_binary_representation(): void
    {
        $uuid = Uuid::v4();
        $binary = Uuid::toBinary($uuid);

        self::assertSame(16, strlen($binary), 'Storage is BINARY(16), not CHAR(36).');
        self::assertSame($uuid, Uuid::toString($binary));
    }

    public function test_it_accepts_an_unhyphenated_uuid(): void
    {
        $uuid = Uuid::v4();

        self::assertSame($uuid, Uuid::toString(Uuid::toBinary(str_replace('-', '', $uuid))));
    }

    public function test_it_rejects_a_malformed_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Uuid::toBinary('not-a-uuid');
    }

    public function test_it_rejects_non_hexadecimal_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Uuid::toBinary('zzzzzzzz-zzzz-zzzz-zzzz-zzzzzzzzzzzz');
    }

    public function test_it_rejects_binary_of_the_wrong_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('16 bytes');

        Uuid::toString('short');
    }

    public function test_is_valid_rejects_obvious_non_uuids(): void
    {
        self::assertFalse(Uuid::isValid(''));
        self::assertFalse(Uuid::isValid('12345'));
        self::assertFalse(Uuid::isValid(str_repeat('a', 36)));
    }
}
