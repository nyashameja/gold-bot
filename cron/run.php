<?php

declare(strict_types=1);

/**
 * Gold Bot CLI — the single entry point for all scheduled work (ADR-08).
 *
 * One cPanel cron entry drives everything:
 *   * * * * * /usr/local/bin/php /home/USER/gold-bot/cron/run.php schedule
 *       >> /home/USER/gold-bot/storage/logs/cron.log 2>&1
 *
 * The `schedule` command and the task dispatcher arrive in Phase 3, once
 * scheduled_tasks and task_runs exist. Phase 1 provides the commands needed
 * to install and verify the system.
 *
 * Usage:
 *   php cron/run.php migrate            Apply pending migrations
 *   php cron/run.php migrate:status     Show migration state
 *   php cron/run.php seed               Apply reference-data seeds
 *   php cron/run.php install            migrate + seed, for a fresh deployment
 *   php cron/run.php check              Verify configuration and wiring
 *   php cron/run.php key:generate       Print a new APP_KEY
 */

use GoldBot\Core\Application;
use GoldBot\Core\Config;
use GoldBot\Core\Database;
use GoldBot\Database\Migrator;
use GoldBot\Database\Seeder;
use GoldBot\Infrastructure\Cache\CacheInterface;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Lock\LockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Support\Encryption;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

// key:generate must work before the application can boot — it produces the
// APP_KEY that booting requires.
if (($argv[1] ?? '') === 'key:generate') {
    require dirname(__DIR__) . '/vendor/autoload.php';
    fwrite(STDOUT, 'APP_KEY=' . Encryption::generateKey() . PHP_EOL);
    exit(0);
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$container = $app->container();
$command = $argv[1] ?? 'help';

/** @var Config $config */
$config = $container->get(Config::class);
/** @var LoggerInterface $logger */
$logger = $container->get(LoggerInterface::class);

$out = static fn (string $line = ''): int|false => fwrite(STDOUT, $line . PHP_EOL);
$err = static fn (string $line): int|false => fwrite(STDERR, $line . PHP_EOL);

$makeMigrator = static fn (): Migrator => new Migrator(
    $container->get(Database::class),
    $app->basePath($config->string('database.migrations.path', 'database/migrations')),
    $logger,
    $config->string('database.migrations.table', 'migrations')
);

$makeSeeder = static fn (): Seeder => new Seeder(
    $container->get(Database::class),
    $app->basePath($config->string('database.seeds.path', 'database/seeds')),
    $logger
);

try {
    switch ($command) {
        case 'migrate':
            $applied = $makeMigrator()->migrate();

            if ($applied === []) {
                $out('Nothing to migrate — the schema is up to date.');
                break;
            }

            foreach ($applied as $name) {
                $out('  applied  ' . $name);
            }

            $out(sprintf('%d migration(s) applied.', count($applied)));
            break;

        case 'migrate:status':
            $migrator = $makeMigrator();

            foreach ($migrator->status() as $row) {
                $out(sprintf('  %-45s batch %-3d %s', $row['migration'], $row['batch'], $row['applied_at']));
            }

            $pending = $migrator->pending();

            foreach ($pending as $name) {
                $out(sprintf('  %-45s %s', $name, 'PENDING'));
            }

            $out(sprintf('%d pending.', count($pending)));
            break;

        case 'seed':
            foreach ($makeSeeder()->run() as $name => $affected) {
                $out(sprintf('  seeded   %-40s %d row(s) affected', $name, $affected));
            }
            break;

        case 'install':
            $out('Running migrations…');
            $applied = $makeMigrator()->migrate();
            $out(sprintf('  %d migration(s) applied.', count($applied)));

            $out('Seeding reference data…');

            foreach ($makeSeeder()->run() as $name => $affected) {
                $out(sprintf('  %-40s %d row(s) affected', $name, $affected));
            }

            $out('');
            $out('Installation complete. Create your first administrator with:');
            $out('  php cron/run.php user:create   (Phase 2)');
            break;

        case 'check':
            $failures = 0;

            $out('Gold Bot — configuration check');
            $out('');

            // Environment
            $env = $config->string('app.env');
            $debug = $config->bool('app.debug');
            $out(sprintf('  environment      %s%s', $env, $debug ? '  (debug ON)' : ''));

            if ($env === 'production' && $debug) {
                $err('  FAIL  APP_DEBUG must be false in production.');
                $failures++;
            }

            // Encryption key
            try {
                $container->get(Encryption::class);
                $out('  app key          ok');
            } catch (Throwable $e) {
                $err('  FAIL  app key      ' . $e->getMessage());
                $failures++;
            }

            // Database
            try {
                $db = $container->get(Database::class);
                $version = $db->scalar('SELECT VERSION()');
                $out(sprintf('  database         connected (%s)', (string) $version));

                $pending = $makeMigrator()->pending();
                $out(sprintf(
                    '  migrations       %s',
                    $pending === [] ? 'up to date' : count($pending) . ' pending'
                ));
            } catch (Throwable $e) {
                $err('  FAIL  database     ' . $e->getMessage());
                $failures++;
            }

            // Named locking — the mechanism the whole scheduler depends on.
            try {
                /** @var LockInterface $lock */
                $lock = $container->get(LockInterface::class);
                $acquired = $lock->acquire('check', 0);
                $lock->release('check');
                $out(sprintf('  locking          %s', $acquired ? 'ok' : 'could not acquire test lock'));

                if (!$acquired) {
                    $failures++;
                }
            } catch (Throwable $e) {
                $err('  FAIL  locking      ' . $e->getMessage());
                $failures++;
            }

            // Cache — reports which driver actually resolved, since 'apcu'
            // silently falls back to file when the extension is absent.
            $cache = $container->get(CacheInterface::class);
            $cache->set('check', 'ok', 5);
            $out(sprintf(
                '  cache            %s (%s)',
                $cache->get('check') === 'ok' ? 'ok' : 'FAILED',
                (new ReflectionClass($cache))->getShortName()
            ));
            $cache->delete('check');

            // Writable paths
            foreach (['storage/logs', 'storage/cache', 'storage/backups', 'storage/tmp'] as $relative) {
                $path = $app->basePath($relative);

                if (!is_dir($path) || !is_writable($path)) {
                    $err(sprintf('  FAIL  %-12s is not writable: %s', basename($relative), $path));
                    $failures++;
                }
            }

            // Container wiring (docs/04, Phase 1 verification).
            $bindingFailures = $app->verifyBindings();

            foreach ($bindingFailures as $id => $message) {
                $err(sprintf('  FAIL  binding      %s — %s', $id, $message));
                $failures++;
            }

            $out(sprintf('  bindings         %d resolved', count($container->bindingIds()) - count($bindingFailures)));

            /** @var ClockInterface $clock */
            $clock = $container->get(ClockInterface::class);
            $out(sprintf('  clock            %s UTC', $clock->now()->format('Y-m-d H:i:s')));

            $out('');
            $out($failures === 0 ? 'All checks passed.' : sprintf('%d check(s) failed.', $failures));

            exit($failures === 0 ? 0 : 1);

        case 'help':
        default:
            $out('Gold Bot CLI');
            $out('');
            $out('  migrate            Apply pending migrations');
            $out('  migrate:status     Show migration state');
            $out('  seed               Apply reference-data seeds');
            $out('  install            migrate + seed, for a fresh deployment');
            $out('  check              Verify configuration and wiring');
            $out('  key:generate       Print a new APP_KEY');
            $out('');

            if ($command !== 'help') {
                $err(sprintf('Unknown command [%s].', $command));
                exit(1);
            }
    }
} catch (Throwable $e) {
    $logger->critical('CLI command failed', [
        'event'     => 'cli.failed',
        'command'   => $command,
        'exception' => $e,
    ]);

    $err(sprintf('%s: %s', $e::class, $e->getMessage()));

    exit(1);
}

exit(0);
