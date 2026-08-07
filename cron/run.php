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
use GoldBot\Services\Backup\BackupService;
use GoldBot\Services\Health\HealthMonitor;
use GoldBot\Services\Performance\SnapshotBuilder;
use GoldBot\Core\Database;
use GoldBot\Database\Migrator;
use GoldBot\Database\Seeder;
use GoldBot\Infrastructure\Cache\CacheInterface;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Lock\LockInterface;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Domain\Performance\PeriodType;
use GoldBot\Domain\Performance\SnapshotScope;
use GoldBot\Repositories\Contracts\PerformanceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
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

        case 'backup:run':
            /** @var BackupService $backups */
            $backups = $container->get(BackupService::class);

            $out('Backing up the database…');
            $backup = $backups->create();
            $removed = $backups->rotate();

            $out(sprintf('  %s (%s bytes)', basename($backup['file']), number_format($backup['bytes'])));

            if ($removed !== []) {
                $out(sprintf('  Rotated out %d old backup(s).', count($removed)));
            }
            break;

        case 'backup:list':
            /** @var BackupService $backups */
            $backups = $container->get(BackupService::class);
            $files = $backups->list();

            if ($files === []) {
                $out('No backups yet. Run backup:run.');
                break;
            }

            $out(sprintf('%d backup(s), newest first:', count($files)));

            foreach ($files as $file) {
                $out(sprintf(
                    '  %-34s %10s  %s',
                    $file['name'],
                    number_format($file['bytes']),
                    $file['at']->format('Y-m-d H:i:s')
                ));
            }
            break;

        case 'backup:restore':
            /** @var BackupService $backups */
            $backups = $container->get(BackupService::class);

            $file = $argv[2] ?? '';
            $target = $argv[3] ?? '';

            if ($file === '' || $target === '') {
                $err('Usage: php cron/run.php backup:restore <file.sql.gz> <target-database>');
                $err('');
                $err('The target is REQUIRED and has no default. Restoring is destructive,');
                $err('and a command that defaults to the live database is an accident');
                $err('waiting to be typed. Restore into a scratch database and verify it');
                $err('before pointing anything at it.');
                exit(1);
            }

            $out(sprintf('Restoring %s into %s…', basename($file), $target));
            $restored = $backups->restore($file, $target);

            $out(sprintf(
                '  %d table(s) restored in %dms.',
                $restored['tables'],
                $restored['duration_ms']
            ));
            break;

        case 'health:check':
            /** @var HealthMonitor $monitor */
            $monitor = $container->get(HealthMonitor::class);

            // Alerting is off by default from the CLI: running this by hand to
            // look at the output should not page anyone. Pass --alert to
            // exercise the real path.
            $result = $monitor->run(alert: in_array('--alert', $argv, true));

            $out(sprintf('Overall: %s', $result['overall']->value));

            foreach ($result['reports'] as $report) {
                $out(sprintf(
                    '  %-9s %-18s %s',
                    $report->status->value,
                    $report->component,
                    $report->message
                ));
            }

            foreach ($result['transitions'] as $transition) {
                $out(sprintf(
                    '  TRANSITION %s: %s → %s',
                    $transition['component'],
                    $transition['from'],
                    $transition['to']
                ));
            }

            // Non-zero when anything is degraded, so an operator can wire this
            // into an external monitor — or a plain cPanel cron, which emails
            // on a non-zero exit and gives alerting with no extra machinery.
            if ($result['overall']->isDegraded()) {
                exit(1);
            }
            break;

        case 'performance:rebuild':
            /** @var SnapshotBuilder $builder */
            $builder = $container->get(SnapshotBuilder::class);

            $out('Rebuilding performance snapshots from the traded record…');
            $result = $builder->rebuildAll();

            if ($result['snapshots'] === 0) {
                $out('  No closed signals to measure yet.');
                break;
            }

            $out(sprintf(
                '  %d snapshot(s) across %d period(s), %s to %s.',
                $result['snapshots'],
                $result['periods'],
                substr((string) $result['from'], 0, 10),
                substr((string) $result['to'], 0, 10)
            ));
            break;

        case 'performance:show':
            /** @var PerformanceSnapshotRepositoryInterface $snapshots */
            $snapshots = $container->get(PerformanceSnapshotRepositoryInterface::class);

            $period = PeriodType::tryFrom(strtoupper($argv[2] ?? 'ALL_TIME'));

            if ($period === null) {
                $err('Usage: php cron/run.php performance:show [DAILY|WEEKLY|MONTHLY|ALL_TIME]');
                exit(1);
            }

            $series = $snapshots->series($period, SnapshotScope::overall(), 30);

            if ($series === []) {
                $out('No snapshots. Run performance:rebuild first.');
                break;
            }

            $out(sprintf('%s — overall, most recent %d period(s)', $period->label(), count($series)));
            $out(sprintf('  %-12s %5s %5s %5s %8s %9s %9s', 'PERIOD', 'N', 'W', 'L', 'WIN%', 'NET R', 'MAX DD'));

            foreach ($series as $row) {
                $m = $row['metrics'];

                $out(sprintf(
                    '  %-12s %5d %5d %5d %8s %9s %9s',
                    substr($row['start'], 0, 10),
                    $m->total,
                    $m->wins,
                    $m->losses,
                    $m->winRate === null ? '-' : number_format($m->winRate, 1),
                    number_format($m->totalR, 2),
                    number_format($m->maxDrawdownR, 2)
                ));
            }
            break;

        case 'strategy:list':
            /** @var StrategyRepositoryInterface $strategies */
            $strategies = $container->get(StrategyRepositoryInterface::class);
            $db = $container->get(Database::class);

            foreach ($db->select('SELECT id, code, name, is_enabled FROM strategies WHERE deleted_at IS NULL ORDER BY sort_order') as $row) {
                $active = $strategies->activeConfig((int) $row['id']);

                $out(sprintf(
                    '  %-10s %-28s %-9s config v%-3s min_score=%s',
                    $row['code'],
                    $row['name'],
                    $row['is_enabled'] ? 'enabled' : 'disabled',
                    $active?->version ?? '-',
                    $active === null ? '-' : (string) $active->int('min_score')
                ));
            }
            break;

        case 'strategy:config':
            /** @var StrategyRepositoryInterface $strategies */
            $strategies = $container->get(StrategyRepositoryInterface::class);

            $code = $argv[2] ?? '';
            $file = $argv[3] ?? '';

            if ($code === '') {
                $err('Usage: php cron/run.php strategy:config <code> [path/to/config.json]');
                $err('Without a file, prints the active configuration.');
                exit(1);
            }

            $strategy = $strategies->findByCode($code);

            if ($strategy === null) {
                $err(sprintf('Unknown strategy [%s]. Try: php cron/run.php strategy:list', $code));
                exit(1);
            }

            if ($file === '') {
                $active = $strategies->activeConfig($strategy['id']);

                if ($active === null) {
                    $err('No active configuration.');
                    exit(1);
                }

                $out(sprintf('# %s config version %d', $strategy['code'], $active->version));
                $out((string) json_encode($active->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                break;
            }

            if (!is_file($file)) {
                $err(sprintf('File [%s] does not exist.', $file));
                exit(1);
            }

            $decoded = json_decode((string) file_get_contents($file), true);

            if (!is_array($decoded)) {
                $err(sprintf('File [%s] is not valid JSON.', $file));
                exit(1);
            }

            // Configs are immutable (ADR-06): this appends a new version and
            // moves the active pointer, leaving every past signal attributable
            // to what actually produced it.
            $versionId = $strategies->addConfigVersion(
                $strategy['id'],
                $decoded,
                sprintf('Imported from %s', basename($file))
            );

            $active = $strategies->configById($versionId);

            $logger->info('Strategy config version added', [
                'event'     => 'strategy.config_added',
                'strategy'  => $strategy['code'],
                'version'   => $active?->version,
                'config_id' => $versionId,
            ]);

            $out(sprintf(
                'Activated %s config version %d. Previous versions are retained.',
                $strategy['code'],
                $active?->version ?? 0
            ));
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
            $out('  strategy:list      List strategies and their active config');
            $out('  strategy:config    Show or replace a strategy config: <code> [file.json]');
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
