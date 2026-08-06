<?php

declare(strict_types=1);

namespace GoldBot\Tests\Feature;

use GoldBot\Core\Application;
use GoldBot\Core\Database;
use GoldBot\Core\ErrorHandler;
use GoldBot\Core\HttpException;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Core\Router;
use GoldBot\Repositories\Contracts\UserRepositoryInterface;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Support\Security\Csrf;
use PHPUnit\Framework\TestCase;

/**
 * Drives requests through the real router and middleware stack.
 *
 * No HTTP server: a Request is constructed and dispatched directly, so these
 * run in CI with nothing but PHP and MySQL. StartSession skips under CLI, so
 * $_SESSION is manipulated directly here — which is what the session would
 * contain anyway.
 */
abstract class FeatureTestCase extends TestCase
{
    protected Application $app;

    protected Database $db;

    protected Router $router;

    /** @var array<string,int> Role slug => created user id. */
    protected array $users = [];

    protected function setUp(): void
    {
        Application::reset();

        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        $this->app = Application::create(dirname(__DIR__, 2));
        $this->db = $this->app->container()->get(Database::class);

        try {
            $this->db->scalar('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('No database available: ' . $e->getMessage());
        }

        if (!$this->db->tableExists('users')) {
            self::markTestSkipped('Schema not migrated. Run: php cron/run.php install');
        }

        $this->router = $this->app->container()->get(Router::class);
    }

    protected function tearDown(): void
    {
        // Remove only what this test created, so a developer's own account in
        // a local database survives the suite.
        foreach ($this->users as $id) {
            $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
        }

        $this->db->run("DELETE FROM login_attempts WHERE email LIKE '%@phpunit.test'");

        $this->users = [];
        $_SESSION = [];

        $this->db->disconnect();
        $this->app->container()->get(ErrorHandler::class)->restore();

        Application::reset();
    }

    /**
     * Rebuild the application, simulating a fresh request.
     *
     * Services cache per-request state — AuthService memoises the resolved
     * user — so a test asserting behaviour *across* requests must rebuild
     * rather than reuse. Handlers are restored first so the reboot does not
     * stack a second set of global error handlers.
     */
    protected function rebootApplication(): void
    {
        $session = $_SESSION;

        $this->db->disconnect();
        $this->app->container()->get(ErrorHandler::class)->restore();
        Application::reset();

        $this->app = Application::create(dirname(__DIR__, 2));
        $this->db = $this->app->container()->get(Database::class);
        $this->router = $this->app->container()->get(Router::class);

        $_SESSION = $session;
    }

    /** Create a user with the given role and return their id. */
    protected function createUser(string $role, string $password = 'TestPassword123!'): int
    {
        /** @var UserRepositoryInterface $users */
        $users = $this->app->container()->get(UserRepositoryInterface::class);
        /** @var AuthService $auth */
        $auth = $this->app->container()->get(AuthService::class);

        $email = sprintf('%s-%s@phpunit.test', $role, bin2hex(random_bytes(4)));
        $id = $users->create($email, ucfirst($role) . ' Tester', $auth->hash($password), [$role]);

        $this->users[$email] = $id;

        return $id;
    }

    protected function emailFor(int $userId): string
    {
        return (string) array_search($userId, $this->users, true);
    }

    /** Put a user into the session, as a completed login would. */
    protected function actingAs(int $userId): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['last_activity'] = time();
    }

    protected function csrfToken(): string
    {
        return $this->app->container()->get(Csrf::class)->token();
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $query
     */
    protected function request(string $method, string $path, array $post = [], array $query = []): Response
    {
        $request = new Request(
            $method,
            $path,
            $query,
            $post,
            ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit'],
        );

        try {
            return $this->router->dispatch($request);
        } catch (HttpException $e) {
            // Mirrors what public/index.php does with an expected HTTP
            // condition, so tests observe the same status the browser would.
            return new Response($e->getMessage(), $e->statusCode());
        }
    }

    /** @param array<string,mixed> $post */
    protected function post(string $path, array $post = [], bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $post['_token'] = $this->csrfToken();
        }

        return $this->request('POST', $path, $post);
    }

    /** @param array<string,mixed> $query */
    protected function get(string $path, array $query = []): Response
    {
        return $this->request('GET', $path, [], $query);
    }
}
