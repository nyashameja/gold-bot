<?php

declare(strict_types=1);

/**
 * Container bindings — the single seam where interfaces meet implementations.
 *
 * Every port described in docs/01 §4 is wired here. Changing the cache driver,
 * the calendar provider or the notification channel is an edit to this file
 * and nothing else; that is the whole point of the port-and-adapter design.
 *
 * Adapters for market data, calendar and Telegram are registered in Phases 3,
 * 5 and 7 respectively. Their interfaces are deliberately not bound to stubs
 * in the meantime: an unbound interface fails loudly at resolution, whereas a
 * stub would let a caller silently receive fabricated market data.
 */

use GoldBot\Core\Application;
use GoldBot\Core\Config;
use GoldBot\Core\Container;
use GoldBot\Core\Database;
use GoldBot\Infrastructure\Cache\ApcuCache;
use GoldBot\Infrastructure\Cache\CacheInterface;
use GoldBot\Infrastructure\Cache\FileCache;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Infrastructure\Clock\SystemClock;
use GoldBot\Infrastructure\Lock\LockInterface;
use GoldBot\Infrastructure\Lock\MySqlNamedLock;
use GoldBot\Infrastructure\Logging\FileLogger;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Infrastructure\Logging\LogLevel;
use GoldBot\Support\Encryption;

return static function (Container $container, Config $config, Application $app): void {
    // ── Clock ────────────────────────────────────────────────────────────────
    // Bound first: the logger depends on it.
    $container->singleton(ClockInterface::class, static fn (): ClockInterface => new SystemClock());

    // ── Logging ──────────────────────────────────────────────────────────────
    $container->singleton(LoggerInterface::class, static fn (Container $c): LoggerInterface => new FileLogger(
        $app->basePath($config->string('logging.path', 'storage/logs')),
        $c->get(ClockInterface::class),
        LogLevel::fromName($config->string('logging.level', 'info')),
        $config->string('logging.channel', 'app'),
        $config->int('logging.retention_days', 90)
    ));

    // ── Database ─────────────────────────────────────────────────────────────
    // Lazily connected, so building this does not open a socket.
    $container->singleton(Database::class, static fn (): Database => new Database(
        $config->array('database')
    ));

    // ── Cache ────────────────────────────────────────────────────────────────
    // APCu is frequently absent on shared cPanel hosting, so 'apcu' means
    // "prefer APCu" rather than "require it". Falling back silently is correct
    // here: the cache is only ever a read accelerator, never coordination
    // state (docs/01 §2), so the application is functionally identical either
    // way and refusing to boot would be a worse outcome than being slower.
    $container->singleton(CacheInterface::class, static function () use ($config, $app): CacheInterface {
        $driver = $config->string('cache.driver', 'apcu');

        if ($driver === 'apcu' && ApcuCache::isSupported()) {
            return new ApcuCache($config->string('cache.prefix', 'goldbot:'));
        }

        return new FileCache($app->basePath($config->string('cache.path', 'storage/cache')));
    });

    // ── Locking ──────────────────────────────────────────────────────────────
    $container->singleton(LockInterface::class, static fn (Container $c): LockInterface => new MySqlNamedLock(
        $c->get(Database::class)
    ));

    // ── Encryption ───────────────────────────────────────────────────────────
    $container->singleton(Encryption::class, static fn (): Encryption => new Encryption(
        $config->string('app.key')
    ));
};
