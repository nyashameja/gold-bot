<?php

declare(strict_types=1);

namespace Paragon\Core\Tests;

use Paragon\Core\Route;
use PHPUnit\Framework\TestCase;

/**
 * Route pattern compilation.
 *
 * This exists because of a bug that shipped silently: the compiler quoted the
 * whole pattern with preg_quote BEFORE substituting placeholders, so `{uuid}`
 * became `\{uuid\}`, the substitution stopped matching it, and no
 * parameterised route matched anything. The symptom was a 404, which is
 * indistinguishable from a missing record — so it survived until a page that
 * needed one was built.
 *
 * Every case below is a route the application actually declares.
 */
final class RouteTest extends TestCase
{
    private function route(string $pattern): Route
    {
        return new Route('GET', $pattern, 'TestController', 'index');
    }

    public function test_a_bare_placeholder_captures_one_segment(): void
    {
        $route = $this->route('/signals/{uuid}');

        self::assertTrue($route->matches('GET', '/signals/abc123'));
        self::assertSame(['uuid' => 'abc123'], $route->parameters());
    }

    /** A placeholder must not swallow a slash, or /a/b would match /{x}. */
    public function test_a_placeholder_does_not_cross_a_segment_boundary(): void
    {
        self::assertFalse($this->route('/signals/{uuid}')->matches('GET', '/signals/a/b'));
    }

    /**
     * The case that exposed the bug: a constraint containing its own braces.
     * A naive `[^}]+` stops at the first closing brace and truncates the
     * quantifier into an invalid character class.
     */
    public function test_a_constraint_may_contain_braces(): void
    {
        $route = $this->route('/signals/{uuid:[0-9a-fA-F-]{36}}');
        $uuid = '3f2b1a04-5c6d-4e7f-8a9b-0c1d2e3f4a5b';

        self::assertTrue($route->matches('GET', '/signals/' . $uuid));
        self::assertSame(['uuid' => $uuid], $route->parameters());

        self::assertFalse($route->matches('GET', '/signals/too-short'));
    }

    public function test_a_numeric_constraint_rejects_non_numeric_input(): void
    {
        $route = $this->route('/users/{id:\d+}/active');

        self::assertTrue($route->matches('GET', '/users/18/active'));
        self::assertSame(['id' => '18'], $route->parameters());

        self::assertFalse($route->matches('GET', '/users/abc/active'));
    }

    public function test_a_constraint_may_contain_escaped_metacharacters(): void
    {
        $route = $this->route('/health/tasks/{code:[a-z.\-_]+}/run');

        self::assertTrue($route->matches('GET', '/health/tasks/market.candles/run'));
        self::assertSame(['code' => 'market.candles'], $route->parameters());
    }

    /**
     * Literal segments stay literal. A dot in a path must not act as a
     * wildcard, or /api-usage would also answer /apiXusage.
     */
    public function test_literal_dots_are_not_wildcards(): void
    {
        self::assertFalse($this->route('/a.b/{x}')->matches('GET', '/aXb/1'));
        self::assertTrue($this->route('/a.b/{x}')->matches('GET', '/a.b/1'));
    }

    public function test_multiple_placeholders_are_all_captured(): void
    {
        $route = $this->route('/{instrument}/candles/{timeframe:[A-Z0-9]+}');

        self::assertTrue($route->matches('GET', '/XAUUSD/candles/H4'));
        self::assertSame(['instrument' => 'XAUUSD', 'timeframe' => 'H4'], $route->parameters());
    }

    public function test_a_route_with_no_placeholder_still_matches_exactly(): void
    {
        $route = $this->route('/performance');

        self::assertTrue($route->matches('GET', '/performance'));
        self::assertFalse($route->matches('GET', '/performance/extra'));
        self::assertSame([], $route->parameters());
    }

    public function test_the_method_must_match(): void
    {
        self::assertFalse($this->route('/signals/{uuid}')->matches('POST', '/signals/abc'));
    }

    /** url() is the inverse: it fills a pattern back in for link generation. */
    public function test_url_substitutes_parameters_including_constrained_ones(): void
    {
        self::assertSame(
            '/signals/abc/cancel',
            $this->route('/signals/{uuid:[0-9a-fA-F-]{36}}/cancel')->url(['uuid' => 'abc'])
        );

        self::assertSame(
            '/users/7/roles',
            $this->route('/users/{id:\d+}/roles')->url(['id' => 7])
        );
    }
}
