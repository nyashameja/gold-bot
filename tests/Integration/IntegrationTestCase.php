<?php

declare(strict_types=1);

namespace GoldBot\Tests\Integration;

use GoldBot\Core\Application;
use GoldBot\Core\Database;
use GoldBot\Core\ErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real database.
 *
 * These are skipped rather than failed when no database is configured, so the
 * Unit suite remains runnable anywhere — on a laptop, in CI, or on a host with
 * no MySQL — while the Integration suite still exercises the real thing when
 * one is available.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Application $app;

    protected Database $db;

    protected function setUp(): void
    {
        Application::reset();

        $this->app = Application::create(dirname(__DIR__, 2));
        $this->db = $this->app->container()->get(Database::class);

        try {
            $this->db->scalar('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('No database available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();

        // Booting registers global error and exception handlers. Leaving them
        // installed leaks state between cases, which PHPUnit rightly flags.
        $this->app->container()->get(ErrorHandler::class)->restore();

        Application::reset();
    }

    /** A second, independent connection — used to simulate another process. */
    protected function separateConnection(): Database
    {
        /** @var array<string,mixed> $config */
        $config = $this->app->container()->get(\GoldBot\Core\Config::class)->array('database');

        return new Database($config);
    }
}
