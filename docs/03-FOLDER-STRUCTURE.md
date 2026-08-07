# Gold Bot — Folder Structure

Document 03 of 05 · Status: **Awaiting approval** · PSR-4: `GoldBot\` → `app/`, `Paragon\Core\` → `packages/php-core/src/`

---

## 1. Root Layout

```
gold-bot/
├── app/                    Application code (NOT web-accessible)
├── bootstrap/              Bootstrap & container wiring
├── config/                 Configuration files
├── cron/                   CLI entry points
├── database/               Migrations & seeds
├── docs/                   Architecture & operational documentation
├── packages/php-core/      The kernel — paragon/php-core (ADR-02), consumed
│                           through a Composer path repository until it is
│                           split into its own private repository
├── public/                 ← Apache document root. Everything else sits above it.
├── resources/              Views & uncompiled front-end sources
├── storage/                Logs, cache, backups (writable, gitignored)
├── tests/                  Unit, Integration, Feature
├── .env                    Secrets — never committed
├── .env.example            Documented keys, no values
├── .htaccess               Root-level deny-all safety net
├── composer.json
└── phpunit.xml
```

**Only `public/` is web-exposed.** The cPanel document root points at it, and the root `.htaccess` denies direct access as a second line of defence in case the document root is ever misconfigured — the failure that turns a `.env` file into a public one.

---

## 2. `app/` — Application Code

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── OverviewController.php
│   │   ├── LiveMarketController.php
│   │   ├── SignalController.php
│   │   ├── MethodController.php          714 Method page
│   │   ├── CalendarController.php
│   │   ├── PerformanceController.php
│   │   ├── TelegramController.php
│   │   ├── ApiUsageController.php
│   │   ├── HealthController.php
│   │   ├── UserController.php
│   │   ├── SettingsController.php
│   │   └── Internal/                     JSON endpoints for dashboard polling
│   │       ├── PriceController.php
│   │       ├── SignalFeedController.php
│   │       └── ChartDataController.php
│   ├── Middleware/
│   │   ├── SecurityHeaders.php
│   │   ├── StartSession.php
│   │   ├── Authenticate.php
│   │   ├── Authorize.php                 RBAC
│   │   ├── VerifyCsrf.php
│   │   ├── RateLimit.php
│   │   └── AuditRequest.php
│   └── Requests/                         Input validation objects
│
├── Domain/                     ← Pure. No I/O, no database, no clock (ADR-03).
│   ├── Market/
│   │   ├── Candle.php  CandleSeries.php
│   │   ├── PriceSnapshot.php
│   │   ├── Level.php  StructurePoint.php
│   │   └── Enums/ Direction.php  TrendState.php  MarketRegime.php
│   ├── Indicators/
│   │   ├── IndicatorInterface.php
│   │   ├── Ema.php  Rsi.php  Atr.php  Macd.php  BollingerBands.php
│   │   ├── VolumeSma.php
│   │   └── IndicatorSet.php
│   ├── Structure/
│   │   ├── SwingDetector.php
│   │   ├── BreakOfStructureDetector.php
│   │   ├── LevelDetector.php
│   │   └── SupplyDemandDetector.php
│   ├── Strategy/
│   │   ├── StrategyInterface.php
│   │   ├── StrategyContext.php           Immutable input snapshot
│   │   ├── SignalResult.php              Immutable output
│   │   ├── PillarScore.php
│   │   ├── StrategyConfig.php
│   │   └── Strategies/
│   │       ├── SevenFourteen/
│   │       │   ├── SevenFourteenStrategy.php
│   │       │   └── Pillars/
│   │       │       ├── PillarInterface.php
│   │       │       ├── TrendPillar.php
│   │       │       ├── StructurePillar.php
│   │       │       ├── PullbackPillar.php
│   │       │       ├── ConfirmationPillar.php
│   │       │       └── RiskPillar.php
│   │       ├── BreakoutStrategy.php
│   │       ├── EmaCrossStrategy.php
│   │       └── LiquiditySweepStrategy.php
│   ├── Signal/
│   │   ├── SignalState.php  SignalEventType.php
│   │   ├── SignalLifecycle.php           Legal transitions
│   │   └── RiskCalculator.php
│   └── Session/
│       ├── SessionResolver.php           DST-aware (document 02, §4)
│       └── TradingSession.php
│
├── Services/                   Use cases. Transaction boundaries.
│   ├── MarketData/ MarketDataService.php  CandleIngestService.php
│   │                BackfillService.php  IndicatorService.php
│   │                StructureService.php  LevelService.php
│   ├── Calendar/   CalendarService.php  NewsBlackoutService.php
│   ├── Signals/    SignalEngine.php  SignalService.php
│   │                SignalLifecycleService.php  SignalFilterChain.php
│   │                Filters/ NewsFilter.php  SessionFilter.php
│   │                         SpreadFilter.php  CooldownFilter.php
│   │                         DuplicateFilter.php
│   ├── Telegram/   TelegramService.php  MessageQueueService.php
│   │                MessageRenderer.php
│   ├── Performance/ PerformanceService.php  SnapshotBuilder.php
│   ├── Backtest/   BacktestRunner.php  BacktestReport.php     (ADR-04)
│   ├── Auth/       AuthService.php  PasswordService.php  RbacService.php
│   ├── Health/     HealthService.php  Checks/*.php
│   ├── Audit/      AuditService.php
│   ├── Settings/   SettingsService.php
│   └── Backup/     BackupService.php
│
├── Repositories/
│   ├── Contracts/              Interfaces — depended on by Services
│   └── MySql/                  PDO implementations
│
├── Integrations/
│   ├── MarketData/ MarketDataProviderInterface.php
│   │                TwelveData/ TwelveDataProvider.php  TwelveDataMapper.php
│   ├── Calendar/   EconomicCalendarProviderInterface.php
│   │                ForexFactory/ ForexFactoryProvider.php     (ADR-12, primary)
│   │                              ForexFactoryMapper.php
│   │                              EventIdentityHasher.php      (ADR-16)
│   │                Fred/ FredProvider.php  FredMapper.php     (corroborating)
│   │                CompositeCalendarProvider.php   Merges + deduplicates
│   └── Telegram/   TelegramClient.php  TelegramFormatter.php
│
├── Infrastructure/
│   └── Http/       ApiBudget.php    Reads api_providers, writes api_usage_log —
│                                    a service about two tables, not plumbing,
│                                    so it stayed when the rest of
│                                    Infrastructure/ moved to the kernel
│
├── Console/Tasks/              One class per scheduled task
│   ├── TaskInterface.php
│   ├── ImportMarketDataTask.php
│   ├── ImportCalendarTask.php
│   ├── CalculateIndicatorsTask.php
│   ├── RunStrategyAnalysisTask.php
│   ├── TrackSignalLifecycleTask.php
│   ├── DrainTelegramQueueTask.php
│   ├── BuildPerformanceSnapshotsTask.php
│   ├── HealthCheckTask.php
│   ├── CleanupTask.php
│   └── BackupTask.php
│
└── (no Core/ or Support/ — see below)
```

### The kernel is not in `app/`

`app/Core/`, most of `app/Infrastructure/` and all of `app/Support/` were extracted to **`paragon/php-core`** under ADR-02. They live at `packages/php-core/src/` in namespace `Paragon\Core\`, and Gold Bot requires the package through a Composer path repository:

```
packages/php-core/
├── composer.json                paragon/php-core · PSR-4 Paragon\Core\ → src/
├── phpunit.xml                  For running the kernel standalone
├── README.md                    What is in it, what is not, how to split it out
├── src/
│   ├── Application.php  Container.php  Config.php  Env.php
│   ├── Database.php  ErrorHandler.php  HttpException.php
│   ├── Router.php  Route.php  MiddlewareInterface.php
│   ├── Request.php  Response.php  JsonResponse.php  RedirectResponse.php
│   ├── View.php  Controller.php  helpers.php
│   ├── Clock/    ClockInterface.php  SystemClock.php  FrozenClock.php
│   ├── Cache/    CacheInterface.php  FileCache.php  ApcuCache.php
│   ├── Lock/     LockInterface.php  MySqlNamedLock.php          (ADR-09)
│   ├── Logging/  LoggerInterface.php  LogLevel.php  FileLogger.php
│   ├── Session/  DatabaseSessionHandler.php
│   ├── Http/     HttpClient.php  HttpResponse.php
│   └── Support/  Uuid.php  Encryption.php  Csrf.php
└── tests/                       Runs in Gold Bot's Unit suite and standalone
```

Two things stayed behind that look like they should not have. `MiddlewareInterface` is kernel because it is the router's contract, but every implementation of it is in `app/Http/Middleware/` — `SecurityHeaders` names TradingView in its CSP. And `Controller` exists in both places: the kernel's takes a `View`, and `GoldBot\Http\Controllers\Controller` subclasses it to add `AuthService`, because a kernel that knows what a user is has stopped being a kernel.

### Why `Domain/` is separated from `Services/`

`Domain/` is pure and `Services/` is not. That line is load-bearing: it is what allows every indicator, detector, pillar and strategy to be unit-tested with no database, and it is what makes the backtester (ADR-04) a thin loop rather than a parallel implementation of the trading logic.

The test for whether a class is in the right place: **if it needs a database connection, an HTTP client or the current time, it is not domain.** Time is obtained through `ClockInterface`, injected — which is what lets session, expiry and news-window logic be tested by assertion rather than by waiting.

---

## 3. Remaining Directories

```
bootstrap/
├── app.php                 Builds the container, returns Application
└── helpers.php

config/
├── app.php  database.php  auth.php  logging.php  cache.php
├── services.php            ← Interface → implementation bindings. The swap seam.
├── market.php              Instruments, timeframes, fetch cadences
├── strategies.php          Strategy registry & defaults
├── telegram.php  providers.php  schedule.php
└── routes/ web.php  internal.php

cron/
└── run.php                 The single cPanel entry point (ADR-08)

database/
├── migrations/             NNN_description.php — numbered, forward-only
├── seeds/                  Roles, permissions, instruments, timeframes,
│                           sessions, event categories, strategies,
│                           telegram templates, settings, scheduled tasks
└── Migrator.php

public/
├── index.php               Front controller — the only PHP entry point
├── .htaccess               Rewrite to index.php; deny dotfiles
├── assets/css/app.css      Compiled Tailwind (committed — no Node on cPanel)
├── assets/js/              app.js · charts.js · tradingview.js · alpine-components.js
├── assets/img/  fonts/  favicon.ico  robots.txt

resources/
├── views/
│   ├── layouts/ app.php  auth.php  partials/ head · sidebar · topbar · flash
│   ├── components/ card · stat-tile · badge · table · modal · chart · empty-state
│   ├── auth/  overview/  market/  signals/  method/  calendar/
│   ├── performance/  telegram/  api-usage/  health/  users/  settings/  errors/
├── css/ tailwind source
└── js/ uncompiled sources

storage/                    All gitignored, all writable
├── logs/  cache/  backups/  locks/  tmp/

tests/
├── Unit/          Indicators · Structure · Strategies · Pillars · Risk · Session
├── Integration/   Repositories · Ingest · Queue · Migrations
├── Feature/       HTTP routes, auth, RBAC, CSRF
├── Fixtures/      Candle series, API responses, strategy contexts
└── TestCase.php
```

**`config/services.php`** is the single file where every interface is bound to a concrete class. Changing calendar provider, cache driver or notification channel is an edit to one line there — the practical payoff of the port-and-adapter design in document 01, §4.

**`database/migrations/`** are numbered and forward-only. No down-migrations: on a single-server cPanel deployment they are rarely correct and never tested, and their presence encourages relying on a rollback path that will not work when it is needed. Recovery is restore-from-backup, which Phase 9 verifies for real.

**Test fixtures matter more here than in a typical CRUD application.** A stored candle series with a known outcome is the only way to assert that an EMA is correct or that a pillar scores a setup as intended. The fixtures are part of the deliverable, not an afterthought.
