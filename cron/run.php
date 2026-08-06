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
use GoldBot\Console\TaskDispatcher;
use GoldBot\Core\Database;
use GoldBot\Database\Migrator;
use GoldBot\Database\Seeder;
use GoldBot\Infrastructure\Cache\CacheInterface;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Lock\LockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\UserRepositoryInterface;
use GoldBot\Services\MarketData\CandleIngestService;
use GoldBot\Services\Auth\AuthService;
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

        case 'schedule':
            // The single cPanel cron entry (ADR-08):
            //   * * * * * php /home/USER/gold-bot/cron/run.php schedule
            /** @var TaskDispatcher $dispatcher */
            $dispatcher = $container->get(TaskDispatcher::class);
            $results = $dispatcher->runDue();

            if ($results === []) {
                break; // Nothing due — stay silent so the cron log stays useful.
            }

            foreach ($results as $code => $result) {
                $out(sprintf(
                    '[%s] %-18s %-15s %s',
                    date('c'),
                    $code,
                    $result->status,
                    $result->errorMessage ?? $result->output
                ));
            }
            break;

        case 'task':
            $code = $argv[2] ?? '';

            if ($code === '') {
                $err('Usage: php cron/run.php task <code>');
                $rows = $container->get(Database::class)->select(
                    'SELECT code, name, cadence_minutes, is_enabled, last_success_at FROM scheduled_tasks ORDER BY sort_order'
                );

                foreach ($rows as $row) {
                    $err(sprintf(
                        '  %-18s %-28s every %5dm  %s  last ok: %s',
                        $row['code'],
                        $row['name'],
                        $row['cadence_minutes'],
                        $row['is_enabled'] ? 'enabled ' : 'disabled',
                        $row['last_success_at'] ?? 'never'
                    ));
                }

                exit(1);
            }

            $result = $container->get(TaskDispatcher::class)->runOne($code, ignoreLock: true);
            $out(sprintf('%s: %s', $result->status, $result->errorMessage ?? $result->output));
            exit($result->status === 'FAILED' ? 1 : 0);

        case 'market:backfill':
            /** @var MarketReferenceRepositoryInterface $reference */
            $reference = $container->get(MarketReferenceRepositoryInterface::class);
            /** @var CandleIngestService $ingest */
            $ingest = $container->get(CandleIngestService::class);

            $symbol = $argv[2] ?? 'XAU/USD';
            $days = (int) ($argv[3] ?? 365);

            $instrument = $reference->instrumentBySymbol($symbol);

            if ($instrument === null) {
                $err(sprintf('Unknown instrument [%s].', $symbol));
                exit(1);
            }

            $from = new DateTimeImmutable(sprintf('-%d days', max(1, $days)), new DateTimeZone('UTC'));
            $out(sprintf('Backfilling %s from %s.', $symbol, $from->format('Y-m-d')));

            foreach ($reference->activeTimeframes() as $timeframe) {
                try {
                    $r = $ingest->backfill($instrument['id'], $timeframe, $from);
                    $out(sprintf('  %-4s fetched %5d, inserted %5d', $timeframe->code, $r['fetched'], $r['inserted']));
                } catch (Throwable $e) {
                    $err(sprintf('  %-4s FAILED: %s', $timeframe->code, $e->getMessage()));
                }
            }
            break;

        case 'user:create':
            /** @var UserRepositoryInterface $users */
            $users = $container->get(UserRepositoryInterface::class);
            /** @var AuthService $auth */
            $auth = $container->get(AuthService::class);

            $email = $argv[2] ?? '';
            $name = $argv[3] ?? '';
            $role = $argv[4] ?? 'administrator';

            if ($email === '' || $name === '') {
                $err('Usage: php cron/run.php user:create <email> "<Full Name>" [role]');
                $err('Roles: administrator, analyst, viewer');
                exit(1);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $err(sprintf('[%s] is not a valid email address.', $email));
                exit(1);
            }

            if (!in_array($role, ['administrator', 'analyst', 'viewer'], true)) {
                $err(sprintf('Unknown role [%s]. Use administrator, analyst or viewer.', $role));
                exit(1);
            }

            if ($users->emailExists($email)) {
                $err(sprintf('A user with email [%s] already exists.', $email));
                exit(1);
            }

            // Read the password without echoing it, and never accept it as an
            // argument — arguments land in shell history and in `ps` output.
            $out(sprintf('Creating %s (%s) as %s.', $name, $email, $role));
            fwrite(STDOUT, 'Password: ');
            shell_exec('stty -echo 2>/dev/null');
            $password = trim((string) fgets(STDIN));
            fwrite(STDOUT, PHP_EOL . 'Confirm password: ');
            $confirm = trim((string) fgets(STDIN));
            shell_exec('stty echo 2>/dev/null');
            $out('');

            if ($password === '' || $password !== $confirm) {
                $err('Passwords did not match.');
                exit(1);
            }

            if (strlen($password) < 12) {
                $err('Password must be at least 12 characters.');
                exit(1);
            }

            $userId = $users->create($email, $name, $auth->hash($password), [$role]);

            $logger->info('User created via CLI', [
                'event'   => 'user.created',
                'user_id' => $userId,
                'email'   => $email,
                'role'    => $role,
            ]);

            $out(sprintf('Created user #%d. You can now sign in.', $userId));
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
            $out('  user:create        Create a user: <email> "<Name>" [role]');
            $out('  schedule           Run all due scheduled tasks (the cPanel cron entry)');
            $out('  task <code>        Run one task now, ignoring its lock');
            $out('  market:backfill    Seed history: [symbol] [days]');
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
