<?php

declare(strict_types=1);

namespace GoldBot\Tests\Feature;

final class HttpKernelTest extends FeatureTestCase
{
    public function test_an_unknown_path_is_a_404(): void
    {
        self::assertSame(404, $this->get('/definitely-not-a-route')->status());
    }

    /**
     * A 405 rather than a 404 when the path exists but the verb does not —
     * far more useful when debugging a form that posts to the wrong place.
     */
    public function test_a_wrong_verb_on_a_known_path_is_a_405(): void
    {
        self::assertSame(405, $this->request('DELETE', '/login')->status());
    }

    public function test_security_headers_are_present_on_every_response(): void
    {
        $response = $this->get('/login');

        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
        self::assertNotNull($response->header('Content-Security-Policy'));
        self::assertNotNull($response->header('Permissions-Policy'));
    }

    /**
     * Error responses reflect user input more often than any other page, so
     * the headers must survive the short-circuit path too.
     */
    public function test_security_headers_are_present_on_a_redirect(): void
    {
        $response = $this->get('/');

        self::assertSame(302, $response->status());
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function test_the_csp_forbids_inline_script(): void
    {
        $csp = (string) $this->get('/login')->header('Content-Security-Policy');

        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        // Advertising HSTS on a plain-HTTP response is meaningless, and would
        // lock out a local http:// setup.
        self::assertNull($this->get('/login')->header('Strict-Transport-Security'));
    }

    public function test_rate_limit_headers_are_applied_to_the_login_post(): void
    {
        $response = $this->post('/login', ['email' => 'x@phpunit.test', 'password' => 'wrong']);

        self::assertNotNull($response->header('X-RateLimit-Limit'));
        self::assertNotNull($response->header('X-RateLimit-Remaining'));
    }

    public function test_route_parameters_do_not_match_across_a_slash(): void
    {
        // A {param} placeholder must not swallow path segments, or /a/b would
        // match a route intended for /a only.
        self::assertSame(404, $this->get('/login/extra/segments')->status());
    }
}
