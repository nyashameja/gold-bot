# Gold Bot — System Architecture

Document 01 of 05 · Status: **Awaiting approval**

---

## 1. What the System Does

Gold Bot ingests XAU/USD market data and a high-impact economic calendar, computes indicators and market structure, evaluates configurable strategies against them, and publishes qualifying setups to Telegram. It then tracks each published signal through its lifecycle and reports on the results.

It does not execute trades. V1 is signals only.

## 2. Governing Principles

1. **The web tier never calls an external API.** Cron ingests; the dashboard reads MySQL. A Twelve Data outage degrades data freshness, not availability — the dashboard still renders, showing its data age honestly.
2. **The database is the only shared state.** No in-process coordination between web and cron. This is what makes the design survive cPanel, where you control neither process lifetime nor concurrency.
3. **Analysis is deterministic and replayable.** Same inputs, same config version ⇒ same signal, always.
4. **Every boundary is an interface.** Market data, calendar, notifications, cache and storage are ports with adapters. Vendors are replaceable without touching the core.
5. **Incremental by construction.** Watermarks, not full rescans.

## 3. Runtime Topology

Three processes share one MySQL database and never talk to each other directly.

```
                     ┌───────────────────────────────────────────┐
   Twelve Data ─────▶│                                           │
   Trading Economics▶│   INGEST  (cron, every minute)            │
                     │   fetch → normalise → upsert → watermark  │
                     └────────────────────┬──────────────────────┘
                                          │ writes
                                          ▼
                     ┌───────────────────────────────────────────┐
                     │              MySQL 8                      │
                     │  candles · indicators · levels · events   │
                     │  signals · signal_events · queue · logs   │
                     └───────▲──────────────────────┬────────────┘
                     reads   │                      │ reads/writes
                             │                      ▼
   Browser ─────────▶┌───────┴────────┐   ┌──────────────────────┐
   (Apache/PHP-FPM)  │   WEB          │   │  ANALYSE + PUBLISH   │
                     │   MVC, reads   │   │  indicators → strategy│
                     │   only         │   │  → signal → outbox    │
                     └────────────────┘   └──────────┬───────────┘
                                                     │ drains queue
                                                     ▼
                                              Telegram Bot API
```

**Why the analysis tier is separated from ingest:** ingest is I/O-bound and fails in vendor-specific ways; analysis is CPU-bound and must be deterministic. Keeping them apart means a rate-limited API call cannot leave analysis half-run, and analysis can be replayed over stored data without touching the network — which is precisely what makes ADR-04's backtester possible.

## 4. Application Layers

Dependencies point downward only. Nothing below reaches upward.

```
  HTTP / CLI entry points        public/index.php · cron/run.php
          │
  Controllers · Cron Tasks       thin: parse input, call a service, render
          │
  Services                       all business logic; transaction boundaries
          │
  Domain                         Strategies · Indicators · DTOs · Enums
          │                      pure, no I/O, fully unit-testable
  Repositories (interfaces)      persistence contracts
          │
  Infrastructure                 MySQL · HTTP · Telegram · Cache · Logging
```

### Layer responsibilities

**Controllers** hold no business logic. They validate input, delegate to one service, and return a response. A controller that queries the database directly is a bug.

**Services** own use cases and transaction boundaries — `SignalService`, `MarketDataService`, `CalendarService`, `TelegramService`, `PerformanceService`, `HealthService`. A service is the only place a transaction begins.

**Domain** is the valuable part and it is pure: strategy implementations, indicator calculators, market-structure detection, DTOs, enums. No database, no HTTP, no clock, no globals. Everything it needs arrives as a constructor argument or a method parameter. This is what makes the trading logic testable without infrastructure — and it is where the product's correctness actually lives.

**Repositories** are interfaces in the domain-facing namespace with MySQL implementations in infrastructure. Services depend on the interface.

**Infrastructure** implements the ports. It is the only layer that knows a vendor's name.

### Ports and their adapters

| Port | V1 adapter | Why it is a port |
|---|---|---|
| `MarketDataProviderInterface` | Twelve Data | Vendor limits and pricing change; a second source enables cross-validation |
| `EconomicCalendarProviderInterface` | Trading Economics | ADR-12 — cost risk, swappable |
| `NotifierInterface` | Telegram | V2 adds email, Discord, webhooks |
| `CacheInterface` | APCu, file fallback | Shared hosting may lack APCu |
| `StrategyInterface` | 714, Breakout, EMA, Liquidity Sweep | The core extension point |
| `ClockInterface` | System clock | Backtests inject a simulated clock; time-dependent logic becomes testable |

`ClockInterface` deserves a note: without it, any logic touching sessions, news windows or signal expiry can only be tested by waiting. With it, those become ordinary assertions.

## 5. Data Flow — Ingest

Twelve Data is called on a cadence aligned to candle closes, not on a fixed clock. Fetching daily candles every minute wastes quota to learn nothing.

| Task | Cadence | Rationale |
|---|---|---|
| Price snapshot | 1 min | Drives the dashboard's live price and spread |
| M15 candles | :00 :15 :30 :45 + 20s | Just after close, with a settle margin |
| H1 candles | Hourly + 20s | Same |
| H4 candles | 4-hourly + 30s | Same |
| D1 candles | Once daily after close | Same |
| Economic calendar | Every 30 min | Events are revised; a fixed schedule is sufficient |

Each fetch: request → validate shape → normalise to internal DTO → **upsert** on `(instrument_id, timeframe_id, open_time)` → mark `is_closed` → advance the ingest watermark → record usage in `api_usage_log`.

**Upsert, not insert**, because vendors revise recent bars. `ON DUPLICATE KEY UPDATE` makes re-fetching an already-stored bar harmless, which in turn makes the whole ingest path safely retryable — a property worth more than the microseconds it costs.

**Backfill** is a separate one-shot task. Twelve Data returns several thousand bars per request, so seeding full history across four timeframes costs a handful of calls, not thousands.

**Budget enforcement:** every provider call passes through an `ApiBudget` gate that checks the rolling per-minute and per-day windows in `api_usage_log` before spending. Exhausted budget defers the task rather than burning a failed request — and raises a health warning rather than failing silently.

## 6. Data Flow — Analysis

Runs after ingest, on closed candles only (ADR-14).

```
 1. Read watermark            last processed candle per (instrument, timeframe)
 2. Load new closed candles   plus the lookback window the indicators require
 3. Compute indicators        EMA 50/200, RSI 14, ATR 14, volume profile
 4. Detect structure          swing highs/lows, BOS, CHoCH, trend state
 5. Derive levels             S/R, supply/demand zones, D/W highs and lows
 6. Build StrategyContext     immutable snapshot of everything above
 7. Evaluate each strategy    pure; returns SignalResult with score breakdown
 8. Apply global filters      news blackout, session, spread, cooldown, duplicates
 9. Persist                   signal + score breakdown + enqueue Telegram
10. Advance watermark
```

Steps 1–6 are shared infrastructure; steps 7–9 are per-strategy. The `StrategyContext` is built once per run and passed to every strategy, so adding a strategy costs one class and one row in `strategies` — no changes to the pipeline.

**Global filters sit outside the strategies** deliberately. A news blackout should not be reimplemented (and misimplemented) in each strategy. Strategies decide whether a setup exists; the engine decides whether it is publishable.

**Lifecycle tracking** is a separate task on the price snapshot cadence: it walks open signals, compares live price against entry, stop and target levels, appends `signal_events`, and enqueues the corresponding Telegram message.

## 7. Signal Lifecycle

```
 PENDING ──entry touched──▶ ACTIVE ──▶ TP1 ──▶ TP2 ──▶ TP3 ──▶ CLOSED_WIN
    │                          │
    │ not filled in window     └──stop hit──▶ CLOSED_LOSS
    ▼                          │
 EXPIRED                       └──manual/filter──▶ CANCELLED
```

Every transition appends to `signal_events` with timestamp, trigger price and cause, and enqueues exactly one Telegram message. The `signals` row carries the current state as a read-optimised projection — derived from the log, never the source of truth for history (ADR-05).

## 8. Web Tier

Front controller `public/index.php`; only `public/` is web-exposed. Application code, `.env`, storage and vendor sit above the document root, enforced by `.htaccess` and by the cPanel document root setting.

Request path: front controller → router → middleware stack → controller → service → repository → view.

**Middleware:** `SecurityHeaders` → `SessionStart` → `RateLimit` → `Authenticate` → `Authorize (RBAC)` → `VerifyCsrf` → `AuditLog`.

**Rendering** is server-side PHP templates with a layout and partials. Alpine.js handles local interactivity; Chart.js renders performance charts; the TradingView widget provides the price chart. There is no SPA and no build-time JavaScript framework — page-level state is small enough that server rendering is simpler, faster on shared hosting, and avoids a Node toolchain the brief excludes.

**Tailwind** is compiled to a static CSS file and committed. cPanel has no Node runtime, so the build runs locally or in CI and only the output ships. This is the standard approach for the constraint and costs nothing at runtime.

**The dashboard reads MySQL only.** Widgets that appear live (price, spread) poll a lightweight internal JSON endpoint that itself reads MySQL. Every such payload carries the data's age so the UI can show staleness rather than quietly displaying an old price as current — a small detail that matters a great deal on a trading dashboard.

## 9. Extension Points for V2

Recorded to show the seams exist; none is built in V1.

| V2 capability | Seam it uses |
|---|---|
| MT5 bridge / broker execution | New `ExecutionInterface`; signal lifecycle already emits the events an executor consumes |
| Paper trading | An `ExecutionInterface` adapter writing to a virtual account — no core change |
| AI confidence engine | An additional `StrategyInterface`, or a scorer consuming `StrategyContext` |
| Machine learning | `strategy_runs` already stores every scored feature set — the training data accumulates from day one |
| Additional instruments | `instruments` table is already a first-class dimension on every market table |
| Additional timeframes | `timeframes` lookup; M5 needs a row, not a migration |
| Subscription / client portal | `users` + RBAC extend; signals gain a visibility scope |
| Public REST API | Controllers already thin; add a versioned API route group and token auth |
| Multi-channel alerts | `NotifierInterface` — the outbox is already channel-agnostic |

The ML row is worth emphasising: because `strategy_runs` persists the full feature vector and outcome for every evaluation, a training dataset builds itself from the day V1 ships. Adding that table now costs almost nothing and cannot be reconstructed retroactively.

## 10. Security Architecture

| Concern | Mechanism |
|---|---|
| Secrets | `.env` above web root, `vlucas/phpdotenv`, never in VCS. `.env.example` documents keys with no values. |
| API tokens at rest | Encrypted with libsodium (`sodium_crypto_secretbox`), key from env. Compromised DB dump does not yield a working Telegram bot. |
| SQL injection | PDO prepared statements exclusively; no string interpolation into SQL, enforced by review. |
| XSS | Auto-escaping view helper; raw output requires an explicit, greppable call. |
| CSRF | Per-session token, required on every state-changing request, verified in middleware. |
| Auth | `password_hash` with Argon2id; login throttling per account and per IP. |
| Sessions | `HttpOnly`, `Secure`, `SameSite=Lax`, regenerated on privilege change, absolute and idle timeouts. |
| RBAC | Role → permission tables, checked in middleware and again in the service layer. Two checks because a UI-only check is not an authorisation control. |
| Audit | Append-only `audit_logs`: actor, action, subject, before/after, IP, user agent. |
| Rate limiting | Per-IP and per-user token bucket on auth and JSON endpoints. |
| Transport | HTTPS enforced, HSTS, strict security headers, TLS verification never disabled on outbound calls. |

## 11. Observability

**Logging** is PSR-3 compatible, writing to daily-rotated files with structured context, plus a `system_logs` table for anything surfaced in the UI. Every item the brief lists — signal generated/sent/cancelled, cron started/finished, API failed, Telegram failed, database failed, user login, settings changed — maps to a defined event name, so the set is greppable rather than ad hoc.

**Health checks** run on a cron and persist results to `health_checks`: database connectivity and latency, each scheduled task's last success against its expected cadence, Telegram reachability, both API providers' recent success rate, disk usage, log directory size, queue depth and age of oldest pending message, and error rate over the last hour.

The task-staleness check is the one that catches the failure mode that actually hurts: a cron that stopped running produces no errors at all. Comparing last-success against expected cadence is the only way to notice.

**Alerting:** health transitions to a degraded state enqueue a Telegram message on a separate priority lane, so an alert about a broken queue is not stuck behind the queue it is reporting on.

## 12. Deployment

cPanel + Apache + PHP-FPM 8.3, document root pointed at `public/`. Release by git pull plus `composer install --no-dev --optimize-autoloader`; migrations run through a CLI task. One cPanel cron entry invokes `cron/run.php` each minute (ADR-08).

**Backups** run as a scheduled task: nightly `mysqldump` of the full schema, retained on a rolling window, with `.env` and storage excluded from VCS but included in the backup set. A restore procedure is documented and — this is the part usually skipped — verified once during Phase 9, because an unverified backup is a hypothesis, not a backup.
