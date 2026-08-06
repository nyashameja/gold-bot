<?php

declare(strict_types=1);

namespace GoldBot\Tests\Feature;

final class AuthenticationTest extends FeatureTestCase
{
    public function test_an_unauthenticated_request_to_the_dashboard_redirects_to_login(): void
    {
        $response = $this->get('/');

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function test_the_intended_path_is_remembered_for_after_login(): void
    {
        $this->get('/audit');

        self::assertSame('/audit', $_SESSION['_intended'] ?? null);
    }

    public function test_the_login_page_renders_with_a_csrf_field(): void
    {
        $response = $this->get('/login');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="_token"', $response->body());
        self::assertStringContainsString('Sign in', $response->body());
    }

    public function test_a_post_without_a_csrf_token_is_rejected(): void
    {
        $response = $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'], withCsrf: false);

        self::assertSame(419, $response->status());
    }

    public function test_a_post_with_a_forged_csrf_token_is_rejected(): void
    {
        $response = $this->request('POST', '/login', [
            '_token'   => str_repeat('a', 64),
            'email'    => 'a@b.test',
            'password' => 'x',
        ]);

        self::assertSame(419, $response->status());
    }

    public function test_valid_credentials_sign_the_user_in(): void
    {
        $id = $this->createUser('administrator');

        $response = $this->post('/login', [
            'email'    => $this->emailFor($id),
            'password' => 'TestPassword123!',
        ]);

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('Location'));
        self::assertSame($id, $_SESSION['user_id'] ?? null);
    }

    public function test_an_incorrect_password_does_not_sign_the_user_in(): void
    {
        $id = $this->createUser('administrator');

        $response = $this->post('/login', [
            'email'    => $this->emailFor($id),
            'password' => 'definitely-wrong',
        ]);

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    /**
     * An unknown email and a wrong password must be indistinguishable, or the
     * login form becomes an account-enumeration oracle.
     */
    public function test_an_unknown_email_gives_the_same_message_as_a_wrong_password(): void
    {
        $id = $this->createUser('administrator');

        $this->post('/login', ['email' => $this->emailFor($id), 'password' => 'wrong']);
        $wrongPassword = $_SESSION['_flash']['error'] ?? null;

        $_SESSION = [];

        $this->post('/login', ['email' => 'nobody@phpunit.test', 'password' => 'wrong']);
        $unknownEmail = $_SESSION['_flash']['error'] ?? null;

        self::assertNotNull($wrongPassword);
        self::assertSame($wrongPassword, $unknownEmail);
    }

    public function test_an_authenticated_user_reaches_the_dashboard(): void
    {
        $this->actingAs($this->createUser('administrator'));

        $response = $this->get('/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Welcome back', $response->body());
    }

    public function test_an_authenticated_user_visiting_login_is_redirected_away(): void
    {
        $this->actingAs($this->createUser('administrator'));

        $response = $this->get('/login');

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('Location'));
    }

    public function test_logout_clears_the_session(): void
    {
        $this->actingAs($this->createUser('administrator'));

        $response = $this->post('/logout');

        self::assertSame(302, $response->status());
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    /**
     * A user deactivated mid-session must lose access on the next request,
     * not at their next login.
     */
    public function test_a_deactivated_user_loses_access_immediately(): void
    {
        $id = $this->createUser('administrator');
        $this->actingAs($id);

        self::assertSame(200, $this->get('/')->status());

        $this->db->run('UPDATE users SET is_active = 0 WHERE id = ?', [$id]);

        // The next request builds a fresh AuthService, so the deactivation is
        // observed rather than served from the memoised user.
        $this->rebootApplication();

        self::assertSame(302, $this->get('/')->status());
        self::assertArrayNotHasKey('user_id', $_SESSION, 'The stale session must be cleared.');
    }

    public function test_an_idle_session_is_ended(): void
    {
        $this->actingAs($this->createUser('administrator'));

        // Older than the configured idle timeout.
        $_SESSION['last_activity'] = time() - (60 * 60 * 24);

        $response = $this->get('/');

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function test_repeated_failures_lock_the_account(): void
    {
        $id = $this->createUser('administrator');
        $email = $this->emailFor($id);

        for ($i = 0; $i < 5; $i++) {
            $_SESSION['_flash'] = [];
            $this->post('/login', ['email' => $email, 'password' => 'wrong']);
        }

        // The correct password must now be refused too — that is what lockout
        // means, and it is the control that actually stops a brute force.
        $this->post('/login', ['email' => $email, 'password' => 'TestPassword123!']);

        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertStringContainsString(
            'Too many failed attempts',
            (string) ($_SESSION['_flash']['error'] ?? '')
        );
    }
}
