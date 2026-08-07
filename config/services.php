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

use GoldBot\Console\TaskDispatcher;
use GoldBot\Console\Tasks\BackupDatabaseTask;
use GoldBot\Console\Tasks\CalculateIndicatorsTask;
use GoldBot\Console\Tasks\RunHealthChecksTask;
use GoldBot\Console\Tasks\ImportCalendarTask;
use GoldBot\Console\Tasks\ImportMarketDataTask;
use GoldBot\Console\Tasks\DrainTelegramQueueTask;
use GoldBot\Console\Tasks\RebuildPerformanceTask;
use GoldBot\Console\Tasks\RunStrategyAnalysisTask;
use GoldBot\Console\Tasks\TrackSignalLifecycleTask;
use GoldBot\Core\Application;
use GoldBot\Core\Config;
use GoldBot\Core\Container;
use GoldBot\Core\Database;
use GoldBot\Core\Env;
use GoldBot\Infrastructure\Http\ApiBudget;
use GoldBot\Infrastructure\Http\HttpClient;
use GoldBot\Integrations\Calendar\CompositeCalendarProvider;
use GoldBot\Integrations\Calendar\EventIdentityHasher;
use GoldBot\Integrations\Calendar\ForexFactory\ForexFactoryMapper;
use GoldBot\Integrations\Calendar\ForexFactory\ForexFactoryProvider;
use GoldBot\Integrations\Calendar\Fred\FredProvider;
use GoldBot\Integrations\MarketData\MarketDataProviderInterface;
use GoldBot\Integrations\Telegram\TelegramClient;
use GoldBot\Integrations\Telegram\TelegramClientInterface;
use GoldBot\Integrations\MarketData\TwelveData\TwelveDataMapper;
use GoldBot\Integrations\MarketData\TwelveData\TwelveDataProvider;
use GoldBot\Domain\Session\SessionResolver;
use GoldBot\Domain\Performance\PerformanceCalculator;
use GoldBot\Domain\Signal\SignalLifecycle;
use GoldBot\Domain\Strategy\RuleEvaluator;
use GoldBot\Domain\Strategy\Strategies\EmaCrossStrategy;
use GoldBot\Domain\Strategy\Strategies\SevenFourteenStrategy;
use GoldBot\Domain\Structure\LevelBuilder;
use GoldBot\Domain\Structure\StructureAnalyser;
use GoldBot\Domain\Structure\SwingDetector;
use GoldBot\Repositories\Contracts\CandleRepositoryInterface;
use GoldBot\Repositories\Contracts\EconomicEventRepositoryInterface;
use GoldBot\Repositories\Contracts\IndicatorRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketReferenceRepositoryInterface;
use GoldBot\Repositories\Contracts\MarketStructureRepositoryInterface;
use GoldBot\Repositories\Contracts\OperationsRepositoryInterface;
use GoldBot\Repositories\Contracts\PerformanceRepositoryInterface;
use GoldBot\Repositories\Contracts\PerformanceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\PriceSnapshotRepositoryInterface;
use GoldBot\Repositories\Contracts\WatermarkRepositoryInterface;
use GoldBot\Repositories\MySql\MySqlCandleRepository;
use GoldBot\Repositories\MySql\MySqlEconomicEventRepository;
use GoldBot\Repositories\MySql\MySqlIndicatorRepository;
use GoldBot\Repositories\MySql\MySqlMarketReferenceRepository;
use GoldBot\Repositories\MySql\MySqlMarketStructureRepository;
use GoldBot\Repositories\MySql\MySqlOperationsRepository;
use GoldBot\Repositories\MySql\MySqlPerformanceRepository;
use GoldBot\Repositories\MySql\MySqlPerformanceSnapshotRepository;
use GoldBot\Repositories\MySql\MySqlPriceSnapshotRepository;
use GoldBot\Services\Dashboard\ApiUsageService;
use GoldBot\Services\Dashboard\CalendarBoardService;
use GoldBot\Services\Dashboard\HealthService;
use GoldBot\Services\Dashboard\MarketBoardService;
use GoldBot\Services\Dashboard\MethodService;
use GoldBot\Services\Dashboard\OverviewService;
use GoldBot\Services\Dashboard\PerformanceService;
use GoldBot\Services\Dashboard\SettingsAdminService;
use GoldBot\Services\Dashboard\SignalBoardService;
use GoldBot\Services\Dashboard\TelegramBoardService;
use GoldBot\Services\Dashboard\UserAdminService;
use GoldBot\Repositories\MySql\MySqlWatermarkRepository;
use GoldBot\Services\MarketData\CandleIngestService;
use GoldBot\Services\MarketData\IndicatorService;
use GoldBot\Services\Calendar\CalendarService;
use GoldBot\Services\Signals\Filters\CooldownFilter;
use GoldBot\Services\Signals\Filters\DuplicateFilter;
use GoldBot\Services\Signals\Filters\EnabledFilter;
use GoldBot\Services\Signals\Filters\MaxOpenFilter;
use GoldBot\Services\Signals\Filters\NewsFilter;
use GoldBot\Services\Signals\Filters\SessionFilter;
use GoldBot\Services\Signals\Filters\SignalFilterChain;
use GoldBot\Services\Signals\Filters\SpreadFilter;
use GoldBot\Services\Backup\BackupService;
use GoldBot\Services\Health\HealthChecker;
use GoldBot\Services\Health\HealthMonitor;
use GoldBot\Services\Performance\SnapshotBuilder;
use GoldBot\Services\Signals\SignalEngine;
use GoldBot\Services\Signals\SignalLifecycleService;
use GoldBot\Services\Signals\SignalPublisher;
use GoldBot\Services\Telegram\MessageRenderer;
use GoldBot\Services\Telegram\SignalMessagePayload;
use GoldBot\Services\Telegram\TelegramService;
use GoldBot\Services\Signals\StrategyContextBuilder;
use GoldBot\Services\Calendar\NewsBlackoutService;
use GoldBot\Services\MarketData\StructureService;
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
use GoldBot\Core\Router;
use GoldBot\Core\View;
use GoldBot\Http\Middleware\RateLimit;
use GoldBot\Infrastructure\Session\DatabaseSessionHandler;
use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;
use GoldBot\Repositories\Contracts\StrategyRepositoryInterface;
use GoldBot\Repositories\Contracts\TelegramRepositoryInterface;
use GoldBot\Repositories\Contracts\UserRepositoryInterface;
use GoldBot\Repositories\MySql\MySqlAuditRepository;
use GoldBot\Repositories\MySql\MySqlSettingsRepository;
use GoldBot\Repositories\MySql\MySqlSignalRepository;
use GoldBot\Repositories\MySql\MySqlStrategyRepository;
use GoldBot\Repositories\MySql\MySqlTelegramRepository;
use GoldBot\Repositories\MySql\MySqlUserRepository;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Support\Encryption;
use GoldBot\Support\Security\Csrf;

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

    // ── Repositories ─────────────────────────────────────────────────────────
    // Services depend on the interface; only this file names the MySQL class.
    $container->singleton(
        UserRepositoryInterface::class,
        static fn (Container $c): UserRepositoryInterface => new MySqlUserRepository($c->get(Database::class))
    );

    $container->singleton(
        AuditRepositoryInterface::class,
        static fn (Container $c): AuditRepositoryInterface => new MySqlAuditRepository($c->get(Database::class))
    );

    $container->singleton(
        SettingsRepositoryInterface::class,
        static fn (Container $c): SettingsRepositoryInterface => new MySqlSettingsRepository(
            $c->get(Database::class),
            $c->get(CacheInterface::class)
        )
    );

    // ── Sessions & authentication ────────────────────────────────────────────
    $container->singleton(DatabaseSessionHandler::class, static fn (Container $c): DatabaseSessionHandler => new DatabaseSessionHandler(
        $c->get(Database::class),
        $c->get(ClockInterface::class),
        $config->int('app.session.lifetime', 120)
    ));

    $container->singleton(Csrf::class, static fn (): Csrf => new Csrf());

    $container->singleton(AuthService::class, static fn (Container $c): AuthService => new AuthService(
        $c->get(UserRepositoryInterface::class),
        $c->get(AuditRepositoryInterface::class),
        $c->get(DatabaseSessionHandler::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class),
        $config->int('app.auth.max_login_attempts', 5),
        $config->int('app.auth.lockout_minutes', 15)
    ));

    // ── Views ────────────────────────────────────────────────────────────────
    $container->singleton(View::class, static fn (): View => new View(
        $app->basePath('resources/views')
    ));

    // ── Rate limiting ────────────────────────────────────────────────────────
    // Constructed explicitly because its limits are configuration, not
    // autowirable constructor types.
    $container->singleton(RateLimit::class, static fn (Container $c): RateLimit => new RateLimit(
        $c->get(CacheInterface::class),
        $c->get(ClockInterface::class),
        $config->int('app.rate_limit.max_requests', 60),
        $config->int('app.rate_limit.window_seconds', 60)
    ));

    // ── Market data ──────────────────────────────────────────────────────────
    $container->singleton(HttpClient::class, static fn (Container $c): HttpClient => new HttpClient(
        $c->get(LoggerInterface::class),
        $config->int('market.http.timeout_seconds', 15),
        $config->int('market.http.connect_timeout_seconds', 5)
    ));

    $container->singleton(ApiBudget::class, static fn (Container $c): ApiBudget => new ApiBudget(
        $c->get(Database::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(TwelveDataMapper::class, static fn (): TwelveDataMapper => new TwelveDataMapper());

    // The one place Twelve Data is named. Swapping providers is this binding.
    $container->singleton(
        MarketDataProviderInterface::class,
        static fn (Container $c): MarketDataProviderInterface => new TwelveDataProvider(
            $c->get(HttpClient::class),
            $c->get(TwelveDataMapper::class),
            $c->get(ApiBudget::class),
            $c->get(ClockInterface::class),
            $c->get(LoggerInterface::class),
            Env::string('TWELVE_DATA_API_KEY'),
            Env::string('TWELVE_DATA_BASE_URL', 'https://api.twelvedata.com'),
            $config->array('market.fetch.settle_seconds', [])
        )
    );

    $container->singleton(
        CandleRepositoryInterface::class,
        static fn (Container $c): CandleRepositoryInterface => new MySqlCandleRepository($c->get(Database::class))
    );

    $container->singleton(
        PriceSnapshotRepositoryInterface::class,
        static fn (Container $c): PriceSnapshotRepositoryInterface => new MySqlPriceSnapshotRepository(
            $c->get(Database::class)
        )
    );

    $container->singleton(
        MarketReferenceRepositoryInterface::class,
        static fn (Container $c): MarketReferenceRepositoryInterface => new MySqlMarketReferenceRepository(
            $c->get(Database::class)
        )
    );

    $container->singleton(
        WatermarkRepositoryInterface::class,
        static fn (Container $c): WatermarkRepositoryInterface => new MySqlWatermarkRepository($c->get(Database::class))
    );

    $container->singleton(CandleIngestService::class, static fn (Container $c): CandleIngestService => new CandleIngestService(
        $c->get(MarketDataProviderInterface::class),
        $c->get(CandleRepositoryInterface::class),
        $c->get(PriceSnapshotRepositoryInterface::class),
        $c->get(MarketReferenceRepositoryInterface::class),
        $c->get(WatermarkRepositoryInterface::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class),
        $config->int('market.fetch.poll_output_size', 100)
    ));

    // ── Analysis ─────────────────────────────────────────────────────────────
    // Swing lookback is configuration: it is the single knob that decides how
    // much noise counts as structure, and different timeframes want different
    // answers once V2 tunes per-instrument.
    $container->singleton(SwingDetector::class, static fn (): SwingDetector => new SwingDetector(
        $config->int('market.structure.swing_lookback', 3)
    ));

    $container->singleton(StructureAnalyser::class, static fn (Container $c): StructureAnalyser => new StructureAnalyser(
        $c->get(SwingDetector::class)
    ));

    $container->singleton(LevelBuilder::class, static fn (Container $c): LevelBuilder => new LevelBuilder(
        $c->get(SwingDetector::class),
        (float) $config->get('market.structure.cluster_tolerance', 0.001),
        $config->int('market.structure.max_levels', 8)
    ));

    $container->singleton(
        IndicatorRepositoryInterface::class,
        static fn (Container $c): IndicatorRepositoryInterface => new MySqlIndicatorRepository($c->get(Database::class))
    );

    $container->singleton(IndicatorService::class, static fn (Container $c): IndicatorService => new IndicatorService(
        $c->get(CandleRepositoryInterface::class),
        $c->get(IndicatorRepositoryInterface::class),
        $c->get(WatermarkRepositoryInterface::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(StructureService::class, static fn (Container $c): StructureService => new StructureService(
        $c->get(CandleRepositoryInterface::class),
        $c->get(WatermarkRepositoryInterface::class),
        $c->get(Database::class),
        $c->get(SwingDetector::class),
        $c->get(StructureAnalyser::class),
        $c->get(LevelBuilder::class),
        $c->get(LoggerInterface::class)
    ));

    // ── Economic calendar (ADR-12) ───────────────────────────────────────────
    // Two free adapters behind one port. ForexFactory carries the consensus
    // forecast; FRED is authoritative but time-imprecise. Swapping either — or
    // adding Trading Economics if a subscription is ever bought — is a change
    // to this block and nothing else.
    $container->singleton(EventIdentityHasher::class, static fn (): EventIdentityHasher => new EventIdentityHasher());

    $container->singleton(ForexFactoryMapper::class, static fn (Container $c): ForexFactoryMapper => new ForexFactoryMapper(
        $c->get(EventIdentityHasher::class)
    ));

    $container->singleton(ForexFactoryProvider::class, static fn (Container $c): ForexFactoryProvider => new ForexFactoryProvider(
        $c->get(HttpClient::class),
        $c->get(ForexFactoryMapper::class),
        $c->get(ApiBudget::class),
        $c->get(LoggerInterface::class),
        Env::string('FOREX_FACTORY_BASE_URL', 'https://nfs.faireconomy.media'),
        Env::bool('FOREX_FACTORY_ENABLED', true)
    ));

    $container->singleton(FredProvider::class, static fn (Container $c): FredProvider => new FredProvider(
        $c->get(HttpClient::class),
        $c->get(EventIdentityHasher::class),
        $c->get(ApiBudget::class),
        $c->get(LoggerInterface::class),
        Env::string('FRED_API_KEY'),
        Env::string('FRED_BASE_URL', 'https://api.stlouisfed.org/fred'),
        Env::bool('FRED_ENABLED', true)
    ));

    $container->singleton(CompositeCalendarProvider::class, static fn (Container $c): CompositeCalendarProvider => new CompositeCalendarProvider(
        [$c->get(ForexFactoryProvider::class), $c->get(FredProvider::class)],
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(
        EconomicEventRepositoryInterface::class,
        static fn (Container $c): EconomicEventRepositoryInterface => new MySqlEconomicEventRepository(
            $c->get(Database::class)
        )
    );

    $container->singleton(CalendarService::class, static fn (Container $c): CalendarService => new CalendarService(
        $c->get(CompositeCalendarProvider::class),
        $c->get(EconomicEventRepositoryInterface::class),
        $c->get(EventIdentityHasher::class),
        $c->get(Database::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(NewsBlackoutService::class, static fn (Container $c): NewsBlackoutService => new NewsBlackoutService(
        $c->get(EconomicEventRepositoryInterface::class),
        $c->get(SettingsRepositoryInterface::class),
        $c->get(Database::class)
    ));

    $container->singleton(ImportCalendarTask::class, static fn (Container $c): ImportCalendarTask => new ImportCalendarTask(
        $c->get(CalendarService::class),
        $c->get(LoggerInterface::class),
        $config->int('calendar.days_back', 7),
        $config->int('calendar.days_forward', 14)
    ));

    // ── Sessions ─────────────────────────────────────────────────────────────
    // Built from the seeded rows so DST is applied by the timezone database
    // rather than by arithmetic (docs/02 §4).
    $container->singleton(SessionResolver::class, static function (Container $c): SessionResolver {
        /** @var list<array{code:string,name:string,open_time:string,close_time:string,timezone:string}> $rows */
        $rows = $c->get(Database::class)->select(
            'SELECT code, name, open_time, close_time, timezone FROM market_sessions WHERE is_active = 1 ORDER BY id'
        );

        return SessionResolver::fromRows($rows);
    });

    // ── Strategies & signals ─────────────────────────────────────────────────
    $container->singleton(RuleEvaluator::class, static fn (): RuleEvaluator => new RuleEvaluator());
    $container->singleton(SignalLifecycle::class, static fn (): SignalLifecycle => new SignalLifecycle());

    // Resolved by class name from strategies.handler_class, so registering a
    // strategy is one row plus one binding.
    $container->singleton(SevenFourteenStrategy::class, static fn (Container $c): SevenFourteenStrategy => new SevenFourteenStrategy(
        $c->get(RuleEvaluator::class)
    ));

    $container->singleton(EmaCrossStrategy::class, static fn (Container $c): EmaCrossStrategy => new EmaCrossStrategy(
        $c->get(RuleEvaluator::class)
    ));

    $container->singleton(
        StrategyRepositoryInterface::class,
        static fn (Container $c): StrategyRepositoryInterface => new MySqlStrategyRepository($c->get(Database::class))
    );

    $container->singleton(
        SignalRepositoryInterface::class,
        static fn (Container $c): SignalRepositoryInterface => new MySqlSignalRepository(
            $c->get(Database::class),
            $c->get(SignalLifecycle::class)
        )
    );

    $container->singleton(StrategyContextBuilder::class, static fn (Container $c): StrategyContextBuilder => new StrategyContextBuilder(
        $c->get(CandleRepositoryInterface::class),
        $c->get(IndicatorRepositoryInterface::class),
        $c->get(MarketReferenceRepositoryInterface::class),
        $c->get(PriceSnapshotRepositoryInterface::class),
        $c->get(StructureAnalyser::class),
        $c->get(LevelBuilder::class),
        $c->get(SessionResolver::class),
        $c->get(NewsBlackoutService::class)
    ));

    // Order matters: the cheapest and most decisive checks run first, the
    // portfolio caps last.
    $container->singleton(SignalFilterChain::class, static fn (Container $c): SignalFilterChain => new SignalFilterChain([
        new EnabledFilter($c->get(SettingsRepositoryInterface::class)),
        new NewsFilter(),
        new SessionFilter($c->get(SettingsRepositoryInterface::class)),
        new SpreadFilter($c->get(SettingsRepositoryInterface::class)),
        new DuplicateFilter($c->get(SignalRepositoryInterface::class)),
        new CooldownFilter($c->get(SignalRepositoryInterface::class), $c->get(SettingsRepositoryInterface::class)),
        new MaxOpenFilter($c->get(SignalRepositoryInterface::class), $c->get(SettingsRepositoryInterface::class)),
    ]));

    // ── Telegram (ADR-07) ────────────────────────────────────────────────────
    $container->singleton(TelegramClientInterface::class, static fn (Container $c): TelegramClientInterface => new TelegramClient(
        $c->get(HttpClient::class),
        $c->get(ApiBudget::class),
        $c->get(LoggerInterface::class),
        Env::string('TELEGRAM_BOT_TOKEN'),
        Env::string('TELEGRAM_BASE_URL', 'https://api.telegram.org')
    ));

    $container->singleton(
        TelegramRepositoryInterface::class,
        static fn (Container $c): TelegramRepositoryInterface => new MySqlTelegramRepository($c->get(Database::class))
    );

    $container->singleton(MessageRenderer::class, static fn (Container $c): MessageRenderer => new MessageRenderer(
        $c->get(Database::class)
    ));

    $container->singleton(SignalMessagePayload::class, static fn (): SignalMessagePayload => new SignalMessagePayload(
        $config->int('market.instruments.0.price_precision', 2)
    ));

    $container->singleton(TelegramService::class, static fn (Container $c): TelegramService => new TelegramService(
        $c->get(TelegramRepositoryInterface::class),
        $c->get(TelegramClientInterface::class),
        $c->get(MessageRenderer::class),
        $c->get(SettingsRepositoryInterface::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class)
    ));

    // Owns the transaction that makes signal + message atomic (ADR-07).
    $container->singleton(SignalPublisher::class, static fn (Container $c): SignalPublisher => new SignalPublisher(
        $c->get(Database::class),
        $c->get(SignalRepositoryInterface::class),
        $c->get(TelegramService::class),
        $c->get(SignalMessagePayload::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(SignalLifecycleService::class, static fn (Container $c): SignalLifecycleService => new SignalLifecycleService(
        $c->get(SignalRepositoryInterface::class),
        $c->get(CandleRepositoryInterface::class),
        $c->get(SignalPublisher::class),
        $c->get(SettingsRepositoryInterface::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(TrackSignalLifecycleTask::class, static fn (Container $c): TrackSignalLifecycleTask => new TrackSignalLifecycleTask(
        $c->get(SignalLifecycleService::class),
        $c->get(SnapshotBuilder::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(DrainTelegramQueueTask::class, static fn (Container $c): DrainTelegramQueueTask => new DrainTelegramQueueTask(
        $c->get(TelegramService::class),
        $config->int('telegram.batch_size', 20)
    ));

    $container->singleton(SignalEngine::class, static fn (Container $c): SignalEngine => new SignalEngine(
        $c,
        $c->get(StrategyRepositoryInterface::class),
        $c->get(CandleRepositoryInterface::class),
        $c->get(MarketReferenceRepositoryInterface::class),
        $c->get(WatermarkRepositoryInterface::class),
        $c->get(SettingsRepositoryInterface::class),
        $c->get(StrategyContextBuilder::class),
        $c->get(SignalFilterChain::class),
        $c->get(SignalPublisher::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(RunStrategyAnalysisTask::class, static fn (Container $c): RunStrategyAnalysisTask => new RunStrategyAnalysisTask(
        $c->get(SignalEngine::class),
        $c->get(LoggerInterface::class)
    ));

    // ── Scheduler ────────────────────────────────────────────────────────────
    $container->singleton(TaskDispatcher::class, static fn (Container $c): TaskDispatcher => new TaskDispatcher(
        $c,
        $c->get(Database::class),
        $c->get(LockInterface::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(ImportMarketDataTask::class, static fn (Container $c): ImportMarketDataTask => new ImportMarketDataTask(
        $c->get(CandleIngestService::class),
        $c->get(MarketReferenceRepositoryInterface::class),
        $c->get(ApiBudget::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class),
        $config->array('market.fetch.settle_seconds', [])
    ));

    // ── Health, operations and backups (Phase 10) ────────────────────────────
    // One checker, used by the System Health page and the cron alike, so the
    // page and the alert cannot report different things about a component.
    $container->singleton(HealthChecker::class, static fn (Container $c): HealthChecker => new HealthChecker(
        $c->get(Database::class),
        $c->get(OperationsRepositoryInterface::class),
        $c->get(MarketReferenceRepositoryInterface::class),
        $c->get(CandleRepositoryInterface::class),
        $c->get(PriceSnapshotRepositoryInterface::class),
        $c->get(TelegramRepositoryInterface::class),
        $c->get(TelegramClientInterface::class),
        $c->get(ClockInterface::class),
        $app->basePath('storage'),
        $app->basePath($config->string('logging.path', 'storage/logs'))
    ));

    $container->singleton(HealthMonitor::class, static fn (Container $c): HealthMonitor => new HealthMonitor(
        $c->get(HealthChecker::class),
        $c->get(OperationsRepositoryInterface::class),
        $c->get(TelegramService::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(RunHealthChecksTask::class, static fn (Container $c): RunHealthChecksTask => new RunHealthChecksTask(
        $c->get(HealthMonitor::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(BackupService::class, static fn (Container $c): BackupService => new BackupService(
        // The connection details come from config, and the password reaches
        // mysqldump through MYSQL_PWD rather than the command line, where it
        // would be visible in `ps` to every account on a shared host.
        $config->array('database', []),
        $app->basePath('storage/backups'),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class),
        (int) $c->get(SettingsRepositoryInterface::class)->get('backup.keep', 7)
    ));

    $container->singleton(BackupDatabaseTask::class, static fn (Container $c): BackupDatabaseTask => new BackupDatabaseTask(
        $c->get(BackupService::class),
        $c->get(SettingsRepositoryInterface::class),
        $c->get(LoggerInterface::class)
    ));

    // ── Performance rollups (Phase 9) ────────────────────────────────────────
    // The calculator is stateless and pure, so one instance serves the live
    // dashboard and the nightly builder alike — which is the point: a single
    // implementation of every metric definition.
    $container->singleton(
        PerformanceCalculator::class,
        static fn (): PerformanceCalculator => new PerformanceCalculator()
    );

    $container->singleton(
        PerformanceSnapshotRepositoryInterface::class,
        static fn (Container $c): PerformanceSnapshotRepositoryInterface => new MySqlPerformanceSnapshotRepository(
            $c->get(Database::class)
        )
    );

    $container->singleton(SnapshotBuilder::class, static fn (Container $c): SnapshotBuilder => new SnapshotBuilder(
        $c->get(PerformanceSnapshotRepositoryInterface::class),
        $c->get(PerformanceCalculator::class),
        $c->get(ClockInterface::class),
        $c->get(LoggerInterface::class)
    ));

    $container->singleton(RebuildPerformanceTask::class, static fn (Container $c): RebuildPerformanceTask => new RebuildPerformanceTask(
        $c->get(SnapshotBuilder::class),
        $c->get(LoggerInterface::class)
    ));

    // ── Dashboard read side (Phase 8) ────────────────────────────────────────
    // Read-only repositories, separate from the writers on the cron hot path.
    $container->singleton(
        OperationsRepositoryInterface::class,
        static fn (Container $c): OperationsRepositoryInterface => new MySqlOperationsRepository($c->get(Database::class))
    );

    $container->singleton(
        PerformanceRepositoryInterface::class,
        static fn (Container $c): PerformanceRepositoryInterface => new MySqlPerformanceRepository($c->get(Database::class))
    );

    $container->singleton(
        MarketStructureRepositoryInterface::class,
        static fn (Container $c): MarketStructureRepositoryInterface => new MySqlMarketStructureRepository(
            $c->get(Database::class)
        )
    );

    $container->singleton(MarketBoardService::class, static fn (Container $c): MarketBoardService => new MarketBoardService(
        $c->get(MarketReferenceRepositoryInterface::class),
        $c->get(PriceSnapshotRepositoryInterface::class),
        $c->get(CandleRepositoryInterface::class),
        $c->get(IndicatorRepositoryInterface::class),
        $c->get(MarketStructureRepositoryInterface::class),
        $c->get(SignalRepositoryInterface::class),
        $c->get(StructureService::class),
        $c->get(SessionResolver::class),
        $c->get(ClockInterface::class)
    ));

    $container->singleton(SignalBoardService::class, static fn (Container $c): SignalBoardService => new SignalBoardService(
        $c->get(SignalRepositoryInterface::class),
        $c->get(StrategyRepositoryInterface::class),
        $c->get(MarketReferenceRepositoryInterface::class),
        $c->get(PriceSnapshotRepositoryInterface::class),
        $c->get(ClockInterface::class)
    ));

    $container->singleton(PerformanceService::class, static fn (Container $c): PerformanceService => new PerformanceService(
        $c->get(PerformanceRepositoryInterface::class),
        $c->get(StrategyRepositoryInterface::class),
        $c->get(PerformanceCalculator::class),
        $c->get(PerformanceSnapshotRepositoryInterface::class),
        $c->get(ClockInterface::class)
    ));

    $container->singleton(MethodService::class, static fn (Container $c): MethodService => new MethodService(
        $c->get(StrategyRepositoryInterface::class),
        $c->get(ClockInterface::class)
    ));

    $container->singleton(CalendarBoardService::class, static fn (Container $c): CalendarBoardService => new CalendarBoardService(
        $c->get(EconomicEventRepositoryInterface::class),
        $c->get(NewsBlackoutService::class),
        $c->get(CalendarService::class),
        $c->get(SettingsRepositoryInterface::class),
        $c->get(ClockInterface::class)
    ));

    $container->singleton(TelegramBoardService::class, static fn (Container $c): TelegramBoardService => new TelegramBoardService(
        $c->get(TelegramRepositoryInterface::class),
        $c->get(TelegramClientInterface::class),
        $c->get(Database::class),
        $c->get(ClockInterface::class)
    ));

    $container->singleton(ApiUsageService::class, static fn (Container $c): ApiUsageService => new ApiUsageService(
        $c->get(OperationsRepositoryInterface::class),
        $c->get(ClockInterface::class)
    ));

    $container->singleton(HealthService::class, static fn (Container $c): HealthService => new HealthService(
        $c->get(HealthChecker::class),
        $c->get(OperationsRepositoryInterface::class),
        $c->get(ClockInterface::class)
    ));

    $container->singleton(OverviewService::class, static fn (Container $c): OverviewService => new OverviewService(
        $c->get(MarketBoardService::class),
        $c->get(SignalBoardService::class),
        $c->get(PerformanceService::class),
        $c->get(CalendarBoardService::class),
        $c->get(TelegramBoardService::class),
        $c->get(ApiUsageService::class),
        $c->get(HealthService::class),
        $c->get(SignalRepositoryInterface::class),
        $c->get(ClockInterface::class)
    ));

    $container->singleton(SettingsAdminService::class, static fn (Container $c): SettingsAdminService => new SettingsAdminService(
        $c->get(SettingsRepositoryInterface::class),
        $c->get(AuditRepositoryInterface::class)
    ));

    $container->singleton(UserAdminService::class, static fn (Container $c): UserAdminService => new UserAdminService(
        $c->get(UserRepositoryInterface::class),
        $c->get(AuthService::class),
        $c->get(AuditRepositoryInterface::class),
        $c->get(Database::class)
    ));

    // ── Router ───────────────────────────────────────────────────────────────
    $container->singleton(Router::class, static function (Container $c) use ($app): Router {
        $router = new Router($c);

        (require $app->basePath('config/routes/web.php'))($router);

        return $router;
    });
};
