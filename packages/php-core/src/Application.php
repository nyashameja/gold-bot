<?php

declare(strict_types=1);

namespace Paragon\Core;

use Dotenv\Dotenv;
use Paragon\Core\Logging\LoggerInterface;
use RuntimeException;

/**
 * Application bootstrap and service locator of last resort.
 *
 * The static instance() accessor exists only for the helper functions in
 * packages/php-core/src/helpers.php and for the two entry points. Application code
 * receives its dependencies through the constructor — reaching for the static
 * accessor inside a service is a design smell, not a shortcut.
 */
final class Application
{
    private static ?self $instance = null;

    private bool $booted = false;

    private function __construct(
        private readonly string $basePath,
        private readonly Container $container
    ) {
    }

    public static function create(string $basePath): self
    {
        $application = new self(rtrim($basePath, '/'), new Container());
        $application->boot();

        self::$instance = $application;

        return $application;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException(
                'The application has not been bootstrapped. Require bootstrap/app.php first.'
            );
        }

        return self::$instance;
    }

    /** Test-facing: discard the global instance between cases. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->loadEnvironment();

        $config = new Config($this->basePath('config'));
        $config->load();

        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Config::class, $config);

        // Bindings live in config/services.php so that every interface-to-
        // implementation mapping is visible in one file (docs/03 §3).
        $register = require $this->basePath('config/services.php');
        $register($this->container, $config, $this);

        $this->registerErrorHandling($config);

        $this->booted = true;
    }

    private function loadEnvironment(): void
    {
        if (!is_file($this->basePath('.env'))) {
            // Absent .env is legitimate: cPanel and CI may inject variables
            // directly. Missing *required* values still fail loudly at first
            // use via Env::require().
            return;
        }

        Dotenv::createImmutable($this->basePath)->safeLoad();
    }

    private function registerErrorHandling(Config $config): void
    {
        $debug = $config->bool('app.debug');

        if ($debug && $config->string('app.env') === 'production') {
            // A production deploy with debug on leaks stack traces to anyone
            // who can trigger an error. Refuse rather than serve that.
            throw new RuntimeException(
                'APP_DEBUG must be false when APP_ENV is production.'
            );
        }

        /** @var LoggerInterface $logger */
        $logger = $this->container->get(LoggerInterface::class);

        $handler = new ErrorHandler($logger, $debug, PHP_SAPI === 'cli');
        $handler->register();

        $this->container->instance(ErrorHandler::class, $handler);

        date_default_timezone_set('UTC');
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    public function isProduction(): bool
    {
        /** @var Config $config */
        $config = $this->container->get(Config::class);

        return $config->string('app.env') === 'production';
    }

    /**
     * Resolve every explicitly bound service, asserting the container is
     * correctly wired. Run by `cron/run.php check` and by the Phase 1
     * verification step in docs/04.
     *
     * @return array<string,string> Binding id => error message, empty if healthy.
     */
    public function verifyBindings(): array
    {
        $failures = [];

        foreach ($this->container->bindingIds() as $id) {
            try {
                $this->container->get($id);
            } catch (\Throwable $e) {
                $failures[$id] = $e->getMessage();
            }
        }

        return $failures;
    }
}
