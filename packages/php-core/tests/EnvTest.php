<?php

declare(strict_types=1);

namespace Paragon\Core\Tests;

use Paragon\Core\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::clear();
    }

    /**
     * The reason this class exists. getenv('APP_DEBUG') returns the string
     * "false", which is truthy in PHP — so a naive read enables debug mode in
     * production, exposing stack traces (docs/01 §10).
     */
    public function test_the_string_false_is_coerced_to_boolean_false(): void
    {
        Env::seed(['APP_DEBUG' => 'false']);

        self::assertFalse(Env::get('APP_DEBUG'));
        self::assertFalse(Env::bool('APP_DEBUG', true));
    }

    public function test_it_coerces_the_documented_literals(): void
    {
        Env::seed([
            'A' => 'true',
            'B' => 'false',
            'C' => 'null',
            'D' => 'empty',
        ]);

        self::assertTrue(Env::get('A'));
        self::assertFalse(Env::get('B'));
        self::assertNull(Env::get('C'));
        self::assertSame('', Env::get('D'));
    }

    public function test_bool_accepts_the_common_truthy_spellings(): void
    {
        Env::seed(['A' => '1', 'B' => 'yes', 'C' => 'on', 'D' => 'TRUE', 'E' => 'no']);

        self::assertTrue(Env::bool('A'));
        self::assertTrue(Env::bool('B'));
        self::assertTrue(Env::bool('C'));
        self::assertTrue(Env::bool('D'));
        self::assertFalse(Env::bool('E'));
    }

    public function test_int_and_float_fall_back_when_the_value_is_not_numeric(): void
    {
        Env::seed(['GOOD' => '42', 'BAD' => 'not-a-number', 'DECIMAL' => '2.5']);

        self::assertSame(42, Env::int('GOOD'));
        self::assertSame(9, Env::int('BAD', 9));
        self::assertSame(2.5, Env::float('DECIMAL'));
        self::assertSame(1.5, Env::float('MISSING', 1.5));
    }

    public function test_it_strips_surrounding_quotes(): void
    {
        Env::seed(['QUOTED' => '"a value"']);

        self::assertSame('a value', Env::string('QUOTED'));
    }

    public function test_require_throws_for_a_missing_value(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_PASSWORD');

        Env::require('DB_PASSWORD');
    }

    public function test_require_treats_an_empty_value_as_missing(): void
    {
        Env::seed(['DB_USERNAME' => '']);

        $this->expectException(RuntimeException::class);

        Env::require('DB_USERNAME');
    }

    public function test_defaults_are_returned_for_absent_keys(): void
    {
        self::assertSame('fallback', Env::string('DEFINITELY_NOT_SET', 'fallback'));
        self::assertTrue(Env::bool('DEFINITELY_NOT_SET', true));
    }
}
