<?php

declare(strict_types=1);

namespace GoldBot\Tests\Feature;

final class AuthorizationTest extends FeatureTestCase
{
    public function test_an_administrator_can_view_the_audit_log(): void
    {
        $this->actingAs($this->createUser('administrator'));

        $response = $this->get('/audit');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Audit Log', $response->body());
    }

    public function test_a_viewer_is_denied_the_audit_log(): void
    {
        $this->actingAs($this->createUser('viewer'));

        self::assertSame(403, $this->get('/audit')->status());
    }

    public function test_an_analyst_is_denied_the_audit_log(): void
    {
        $this->actingAs($this->createUser('analyst'));

        self::assertSame(403, $this->get('/audit')->status());
    }

    public function test_an_unauthenticated_request_to_a_gated_route_redirects_rather_than_403s(): void
    {
        // Authenticate runs before Authorize, so an anonymous visitor gets a
        // login redirect — telling them "forbidden" would leak that the route
        // exists and what it needs.
        $response = $this->get('/audit');

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    /**
     * Hiding a nav link is usability, not security. The middleware is the
     * control — this asserts both halves behave consistently.
     */
    public function test_navigation_hides_links_the_user_cannot_use(): void
    {
        $this->actingAs($this->createUser('viewer'));
        $viewerBody = $this->get('/')->body();

        self::assertStringNotContainsString('href="/audit"', $viewerBody);
        self::assertStringNotContainsString('href="/settings"', $viewerBody);
        self::assertStringContainsString('href="/signals"', $viewerBody, 'A viewer does have signals.view.');
    }

    public function test_navigation_shows_links_an_administrator_can_use(): void
    {
        $this->actingAs($this->createUser('administrator'));
        $body = $this->get('/')->body();

        self::assertStringContainsString('href="/audit"', $body);
        self::assertStringContainsString('href="/settings"', $body);
    }

    public function test_roles_carry_the_expected_permission_sets(): void
    {
        $container = $this->app->container();
        /** @var \GoldBot\Repositories\Contracts\UserRepositoryInterface $users */
        $users = $container->get(\GoldBot\Repositories\Contracts\UserRepositoryInterface::class);

        $admin = $users->findById($this->createUser('administrator'));
        $analyst = $users->findById($this->createUser('analyst'));
        $viewer = $users->findById($this->createUser('viewer'));

        self::assertNotNull($admin);
        self::assertNotNull($analyst);
        self::assertNotNull($viewer);

        self::assertTrue($admin->can('settings.edit'));
        self::assertTrue($admin->can('users.manage'));
        self::assertTrue($admin->isAdministrator());

        self::assertTrue($analyst->can('signals.cancel'), 'An analyst operates the trading side.');
        self::assertFalse($analyst->can('settings.edit'), 'An analyst must not change configuration.');
        self::assertFalse($analyst->can('users.manage'));

        self::assertTrue($viewer->can('signals.view'));
        self::assertFalse($viewer->can('signals.cancel'), 'A viewer is read-only.');
        self::assertFalse($viewer->can('audit.view'));
    }
}
