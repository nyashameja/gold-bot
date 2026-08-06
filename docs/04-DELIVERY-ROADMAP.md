# Gold Bot — Delivery Roadmap

Document 04 of 05 · Status: **Awaiting approval**

Eleven phases. Each is independently verifiable and leaves the system in a working state. Nothing is a placeholder and nothing is pseudocode — a phase is complete when its verification steps pass.

---

## Phase 0 — Repository & Foundations

Create the repository under the approved name. `composer.json` with PSR-4 `GoldBot\` → `app/`, PHP 8.3 platform requirement, PHPUnit 11 in dev. Extract `paragon/php-core` per ADR-02 and require it. `.gitignore`, `.env.example`, `.htaccess`, `phpunit.xml`, README, and these five design documents as the seed commit.

**Verify:** `composer install` succeeds; `composer test` runs an empty suite green; a repository clone bootstraps on a clean machine following the README alone.

---

## Phase 1 — Kernel, Configuration & Database Foundation

Wire the container and bind every interface in `config/services.php`. Environment loading, typed config, error handler, structured logger, cache with APCu-plus-file-fallback, `SystemClock` and `FrozenClock`, `MySqlNamedLock`.

Build the migrator and write the migrations for the Identity and Reference sections of document 02. Seed roles, permissions, instruments (XAU/USD), timeframes (including inactive M5), market sessions and settings.

**Verify:** migrations run clean on an empty database and are idempotent on re-run; the container resolves every binding; two concurrent processes contend correctly for a named lock; the session resolver returns correct London and New York sessions across a DST boundary (the specific bug document 02, §4 exists to prevent).

---

## Phase 2 — Authentication, RBAC & Application Shell

Login, logout, password reset, session management, login throttling. Role and permission enforcement in middleware **and** in the service layer. Audit logging. CSRF, security headers, rate limiting.

The application shell: layout, sidebar, topbar, flash messages, error pages, and the Tailwind design system — dark palette, gold accents, typographic scale, spacing, glass and card treatments, transitions. Built mobile-first, then progressively enhanced for tablet and desktop.

**Verify:** an unauthenticated request to any dashboard route redirects to login; a user without `settings.edit` receives 403 from both the UI and a direct POST; CSRF rejection on a forged token; lockout after the configured failed attempts; audit rows for login, logout and settings change; the shell renders correctly at 375px, 768px and 1440px.

---

## Phase 3 — Market Data Ingestion

Migrations for the Market Data section. `TwelveDataProvider` behind `MarketDataProviderInterface`, with `HttpClient`, `RetryPolicy` and the `ApiBudget` gate. Candle upsert on the unique key, `is_closed` handling, watermark advancement, price snapshots, historical backfill, and `api_usage_log` recording.

The single `cron/run.php` entry point, `TaskDispatcher`, `scheduled_tasks` and `task_runs`, with the market data import task registered.

**Verify:** backfill seeds all four timeframes from empty; a re-run of the same window creates zero duplicate rows and zero errors (the idempotency guarantee); the budget gate defers rather than exceeding the per-minute limit; a task already holding its lock records `SKIPPED_LOCKED` rather than running twice; killing a task mid-run releases its lock automatically.

---

## Phase 4 — Indicators, Market Structure & Levels

Indicator calculators — EMA, RSI, ATR, MACD, Bollinger, volume SMA — as pure domain classes, persisted to `candle_indicators`. Swing detection, break-of-structure and change-of-character, support and resistance, supply and demand zones, and daily/weekly extremes. Per-stage watermarks.

**Verify:** each indicator matches a reference series to defined precision on a fixture (these are unit tests with no database); indicators are never computed on an unclosed candle; a second run over unchanged data processes zero candles — proving incremental behaviour rather than assuming it; rewinding one watermark replays exactly one stage.

---

## Phase 5 — Economic Calendar

Migrations for the Calendar section. `TradingEconomicsProvider` behind `EconomicCalendarProviderInterface`, idempotent upsert keyed on the provider event id, revision handling as actuals publish, event category mapping and blackout windows. `NewsBlackoutService`.

**Blocked on Q2** (document 00) — confirm the subscription before this phase starts.

**Verify:** import is idempotent across repeated runs; a revised event updates in place rather than duplicating; the blackout service correctly reports an active window for a seeded high-impact event and correctly reports none outside it.

---

## Phase 6 — Signal Engine & the 714 Strategy

`StrategyInterface`, `StrategyContext`, `SignalResult`, the context builder, the strategy registry, and `SignalFilterChain` — news, session, spread, cooldown and duplicate filters applied outside the strategies (document 01, §6). The 714 strategy as five configurable pillars with weighted scoring to 100, versioned immutable configuration, `strategy_runs` written on every evaluation, and signal persistence with per-pillar score breakdown.

**Blocked on Q1** (document 00) — the 714 rules. Everything above is buildable without them; the pillar bodies are not.

**Verify:** a fixture context with a known-good setup produces the expected score and direction; a fixture that should fail produces no signal and a recorded `rejection_reason`; an active news blackout suppresses an otherwise-qualifying signal; changing configuration creates a new version and leaves prior signals attributed to the old one (ADR-06); the same context evaluated twice yields byte-identical results.

---

## Phase 7 — Signal Lifecycle & Telegram Delivery

Lifecycle tracking against live price: entry activation, TP1–TP3, breakeven, stop loss, expiry, cancellation — each appending to `signal_events`. The transactional outbox: enqueue in the same transaction as the signal, drain with exponential backoff, idempotency keys, priority lanes, dead-letter handling. Message templates for every type in the brief, including daily, weekly and monthly summaries and system alerts.

**Verify:** a signal driven through a scripted price path emits exactly one event and one message per transition; enqueuing the same logical message twice results in one row and one send; a simulated API failure retries with growing delay and lands in `DEAD` after the maximum, visible on the health page; a rolled-back signal transaction leaves no orphaned queued message — the property the outbox exists to guarantee.

---

## Phase 8 — Dashboard

All eleven pages: Overview, Live Market, Signals, 714 Method, Economic Calendar, Performance, Telegram, API Usage, System Health, Users, Settings. The Overview widgets from the brief. Live Market with the TradingView chart and overlays for EMAs, entry, stop, targets, levels, zones, extremes and structure labels. Chart.js performance visualisations, Alpine.js interactivity, and the internal JSON endpoints for polling.

**Verify:** every page reads exclusively from MySQL — confirmed by running the dashboard with outbound network blocked and observing that it renders fully; every live-updating widget displays its data age; all pages responsive at the three breakpoints; no N+1 query on any index page.

---

## Phase 9 — Performance Analytics

`performance_snapshots` and the snapshot builder. Win rate, loss rate, profit factor, average RR, maximum drawdown, consecutive wins and losses, and breakdowns by session, weekday, strategy and market regime. Nightly rebuild plus recompute on signal close.

**Verify:** metrics computed from a seeded set of closed signals match hand-calculated expected values — including the edge cases that are usually wrong: zero losses (profit factor undefined, not infinite), zero signals in a period, and breakeven outcomes excluded from both win and loss counts.

---

## Phase 10 — Health, Operations & Hardening

Health checks for every component in document 01, §11, with the task-staleness check that detects a silently dead cron. Alert transitions on the priority lane. Cleanup with the retention policy from document 02, §10. Nightly `mysqldump` backups with rotation. Full security review, deployment guide, installation guide and operations runbook.

**Verify:** disabling a scheduled task raises a staleness warning within its expected window; a full restore from a nightly backup into an empty database is performed and confirmed working — not assumed (document 01, §12); the security review passes with no unresolved findings; a fresh installation succeeds following only the installation guide.

---

## Phase 11 — Backtesting Harness *(recommended, ADR-04)*

`BacktestRunner` replaying stored candles through the same strategy objects the live engine uses, with `FrozenClock`, simulated fills, and a report of the same metrics as Phase 9. A CLI task and a results view.

**Verify:** a backtest over a period containing known live signals reproduces those signals exactly — which is simultaneously the test of the backtester and the proof that ADR-03's purity guarantee holds. Threshold sweeps produce a score-versus-outcome distribution to set the 714 threshold empirically.

---

## Sequencing

```
0 ─ 1 ─ 2 ─ 3 ─ 4 ─┬─ 6 ─ 7 ─ 8 ─ 9 ─ 10 ─ 11
                   │
             5 ────┘        (Phase 5 parallels 4; Phase 6 needs both)
```

**Critical path:** 0 → 1 → 3 → 4 → 6 → 7. Phases 2, 5 and 8 can move in parallel with adjacent work if capacity allows.

**A usable system exists at the end of Phase 7:** signals generate and reach Telegram, operated by CLI. Phase 8 makes it pleasant; Phases 9–11 make it measurable and trustworthy.

**Do not tune the 714 threshold before Phase 11.** Any number chosen earlier is a guess, and tuning on top of a guess compounds the error (ADR-04).

### Blockers

| Blocker | Blocks | Needed by |
|---|---|---|
| Q1 — 714 rules | Phase 6 | Start of Phase 6 |
| Q2 — Trading Economics subscription | Phase 5 | Start of Phase 5 |
| Q3 — Twelve Data plan | Phase 3 defaults | Start of Phase 3 |
| Repository name | Phase 0 | Immediately |

Phases 0–4 represent substantial work that none of Q1 or Q2 blocks. If the 714 rules need time to specify, that costs nothing yet.

---

## Definition of Done

A phase is complete when all of the following hold — no exceptions, because each one is a thing that is otherwise discovered later at higher cost:

1. All code written — no placeholders, no `TODO`, no pseudocode.
2. Migrations run clean on an empty database and are idempotent on re-run.
3. Unit tests cover domain logic; integration tests cover repositories and I/O.
4. The phase's verification steps pass and are recorded.
5. `declare(strict_types=1)` in every file; no undeclared mixed types.
6. No secret in version control.
7. Documentation updated in the same commit as the change.
