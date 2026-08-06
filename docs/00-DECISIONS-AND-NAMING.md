# Gold Bot — Naming, Architectural Decisions & Open Questions

Document 00 of 05 · Status: **Awaiting approval** · Owner: The Paragon Design

---

## 1. Repository Name Candidates

| # | Name | Reading | Notes |
|---|------|---------|-------|
| 1 | `gold-bot` | Product name, exactly | Zero ambiguity. Matches the app name you chose. |
| 2 | `goldbot` | Same, unhyphenated | Slightly harder to scan; loses the word boundary. |
| 3 | `xau-engine` | Instrument-led | Precise to traders, opaque to everyone else. |
| 4 | `aurum-signals` | *Aurum* = Latin for gold | Commercial-sounding, brandable, not gold-locked in tone. |
| 5 | `midas-engine` | Mythological | Memorable; "everything it touches turns to gold" is a bold claim for a signal product. |
| 6 | `bullion-desk` | Trading-desk framing | Pairs well with NexusDesk as a house naming family. |
| 7 | `gold-signal-engine` | Fully descriptive | Accurate but long; awkward as a directory and package name. |
| 8 | `auric-terminal` | *Auric* = of gold | Matches the Bloomberg-terminal UI ambition. |
| 9 | `paragon-gold` | Owner-prefixed | Prefix is redundant inside your own GitHub account. |
| 10 | `xau-desk` | Short, instrument + desk | Clean, but presumes the reader knows `XAU`. |

### Recommendation: **`gold-bot`**

The reasoning, in priority order:

1. **The repository should be named after the product.** The application is called Gold Bot. Every time the repo name and the product name diverge, somebody pays a small tax forever — in docs, in deploy paths, in onboarding, in support conversations. That tax compounds and buys nothing.
2. **It reads correctly at every layer of the stack.** GitHub `nyashameja/gold-bot`; Composer package `paragon/gold-bot`; cPanel path `/home/user/gold-bot`; database `paragon_goldbot`; PHP namespace `GoldBot\`. No layer needs a translation step.
3. **Hyphenated lowercase is the correct convention** for the ecosystem it lives in (Composer package names, Linux paths, DNS labels all forbid or discourage the alternatives).
4. **Renaming later is cheap.** GitHub permanently redirects the old URL after a rename, so picking the honest name now costs nothing if you rebrand at commercialisation.

**The one argument against it,** stated plainly: `gold-bot` names an instrument the architecture is explicitly designed to outgrow. You asked for multi-instrument support in V2. If you are confident this becomes a multi-market platform, **`aurum-signals`** is the runner-up — it keeps the gold association in the name while describing what the product actually *does* (produces signals) rather than what it currently trades.

My recommendation stands at `gold-bot`: name it for what it is today, and rename at the point you actually ship a second instrument, not in anticipation of it.

> **Deliverable 1 gate:** confirm the name before the repository is created.

---

## 2. Architectural Decisions

These are the points where I am recommending something other than a literal reading of the brief. Each states the decision, the reasoning, and what it costs.

### ADR-01 — Gold Bot lives in its own repository *(confirmed)*

Gold Bot is a separate product from NexusDesk: different domain, different database, different deploy target, different release cadence. Sharing a repository would couple two unrelated release cycles and make the history of both harder to read.

**Decision:** new repository. NexusDesk is untouched.

### ADR-02 — Extract the kernel into a shared package rather than copying it

NexusDesk already contains a proven, framework-free PHP 8.3 kernel: `Container` (PSR-11-flavoured, autowiring), `Router`, `Request`/`Response`, `Database` (PDO with prepared statements), `View`, `Config`, `Env`, `ErrorHandler`, `Kernel`. Gold Bot needs all of it.

There are three ways to get it, and only one of them is right:

| Option | Consequence |
|---|---|
| Copy the files into Gold Bot | Two divergent copies of the kernel within six months. Every security fix must be applied twice, and eventually is not. |
| Share a repository | Rejected — see ADR-01. |
| **Extract to `paragon/php-core` and require via Composer** | One kernel, versioned, `composer update` propagates fixes. Costs ~half a day of extraction work up front. |

**Decision:** extract to a small private package `paragon/php-core`, consumed via a Composer VCS repository (a private GitHub repo works; no Packagist or Satis needed). Gold Bot pins a version, so a NexusDesk-driven kernel change can never break Gold Bot unannounced.

**If you would rather not pay the extraction cost now,** the fallback is to copy the kernel into Gold Bot and accept the divergence — but do it knowingly, and revisit at the point a third product needs the same code.

### ADR-03 — Strategies are pure functions over an immutable context

Every strategy receives a `StrategyContext` (candles, indicators, levels, market structure, calendar events, session state) and returns a `SignalResult`. It reads nothing else — no database access, no clock, no HTTP, no globals.

This is not stylistic. It buys three things that are otherwise expensive or impossible:

- **Unit tests without a database.** Build a context fixture, assert the result.
- **The same code runs live and in backtest.** A backtest is just the loop replaying historical contexts. Without purity you end up with two implementations of the strategy that drift, and your backtest stops describing your live system — which makes it worse than no backtest at all.
- **Reproducibility.** Given the stored context watermark and config version, any past signal can be re-derived exactly.

**Decision:** enforced by the `StrategyInterface` signature. No strategy takes a `Database` or `HttpClient` in its constructor.

### ADR-04 — A backtesting harness belongs in V1, not V2

You did not ask for this. I am recommending it as the single highest-value addition to the plan.

The 714 strategy produces a score out of 100 and fires above a configurable threshold. **There is no way to choose that threshold responsibly without backtesting it.** Ship without a backtester and the number gets picked by intuition, and then every subsequent tuning decision is guesswork layered on guesswork. You will not be able to answer "is 72 better than 68?" — which is the central question of the entire product.

Because of ADR-03 the harness is cheap: it replays stored candles through the same strategy objects the live engine uses. The data is already in MySQL. It is roughly one phase of work and it makes every later phase's output trustworthy.

**Decision:** Phase 10, before go-live tuning. Flagged as recommended-not-requested; drop it if you disagree, but drop it deliberately.

### ADR-05 — Signals are an append-only event log, not a mutable status column

A signal moves through: generated → sent → entry activated → TP1 → TP2 → TP3 → breakeven → stop loss / cancelled / expired. Modelling that as `signals.status` and overwriting it destroys the history you need for the Performance page.

**Decision:** `signals` holds the current projection for fast reads; `signal_events` is an append-only log of every transition with its timestamp, trigger price and cause. Performance analytics, the Telegram message triggers, and the audit trail all derive from the log. Overwriting is never correct here — you cannot compute "average time to TP1" from a status column.

### ADR-06 — Strategy configuration is versioned and immutable

If you tune the 714 weights in March, every signal generated in February must remain attributable to the configuration that actually produced it. Otherwise your performance history silently becomes fiction.

**Decision:** `strategy_configs` rows are immutable. Editing a strategy writes a new version and repoints the active pointer. `signals.strategy_config_id` is a hard FK to the exact version used. This makes "did the change help?" an answerable question.

### ADR-07 — Telegram delivery uses the transactional outbox pattern

Calling the Telegram API inline from the strategy engine means a network timeout can produce a signal with no notification, or a duplicate notification, with no record of which.

**Decision:** the strategy writes the signal and enqueues the message in `telegram_messages` **in the same database transaction**. A separate cron drains the queue with exponential backoff and a per-message idempotency key. Delivery becomes at-least-once with dedupe, and every send attempt is auditable.

### ADR-08 — One cron entry point, database-driven schedule

You listed eight cron jobs. Registering eight cPanel cron entries means every schedule change requires cPanel access, and there is no central record of what should be running.

**Decision:** a single `cron/run.php` invoked every minute by one cPanel entry. A `scheduled_tasks` table defines each task's cadence, enabled flag and timeout; the dispatcher runs what is due. Schedule changes become a settings edit. The System Health page can then show what *should* have run versus what did — which is the only way to detect a task that silently stopped.

### ADR-09 — Cron mutual exclusion via MySQL named locks

PID files and `flock` are unreliable on shared hosting: a killed process leaves a stale lock file and the task never runs again until someone notices.

**Decision:** `GET_LOCK()` / `RELEASE_LOCK()`. The lock is bound to the database session and is released automatically if the PHP process dies for any reason. Self-healing, no stale state, and it works identically on MySQL 8 and MariaDB.

### ADR-10 — Identifiers: BIGINT primary keys, UUIDs on public surfaces only

You asked for UUIDs "where appropriate". The appropriate scope is narrower than it first appears.

**Decision:**
- Internal primary keys are `BIGINT UNSIGNED AUTO_INCREMENT` everywhere.
- User-facing aggregates (`users`, `signals`, `api_tokens`) additionally carry a `uuid BINARY(16)` with a unique index, used in URLs and API responses so internal counts and record ordering are not leaked.
- **Time-series tables — `candles`, `candle_indicators`, `api_usage_log` — get no UUID at all.** A random primary key on an append-heavy table scatters inserts across the B-tree instead of appending to the rightmost page, which inflates the index and hurts exactly the range scans ("last 300 candles") that the strategy engine performs constantly. There is no benefit to trade against it: nothing ever addresses a candle by public identifier.

### ADR-11 — Prices are `DECIMAL`, never `FLOAT`

Binary floating point cannot represent decimal fractions exactly. In a system that compares a price to a stop loss and computes risk-reward ratios, that is a correctness bug waiting for an unlucky rounding.

**Decision:** `DECIMAL(14,5)` for all prices. Five decimal places accommodates FX pairs when V2 adds them, without changing the schema.

### ADR-12 — The economic calendar uses free sources behind a port

Trading Economics was specified, but there is no subscription and its calendar API is priced for institutional use. The port defined here is exactly what makes that a non-event: the system needs "high-impact events affecting USD in a date range", and several free sources provide that.

**Decision:** define `EconomicCalendarProviderInterface` with two free adapters.

**Primary — ForexFactory weekly JSON feed** (`nfs.faireconomy.media`, XML/JSON/CSV/ICS). Free, no authentication, no quota. It publishes date, UTC timestamp, currency, impact rating, title, actual, forecast (consensus), previous, revised, unit and source — which covers every event type in the brief (CPI, PPI, NFP, GDP, retail sales, rate decisions, Fed speeches) and maps almost exactly onto the `economic_events` table. Consensus forecast is the field that matters most for a news filter and the one most free sources omit.

**Corroborating — FRED API** (Federal Reserve Bank of St. Louis). Free, permanent API key, no meaningful quota, and authoritative: it is the issuing institution. `fred/releases/dates` gives official US release schedules and the series endpoints give actual values. It carries no consensus forecast, so it does not replace ForexFactory — it validates it. When the two disagree on whether a release happened or when, FRED wins.

Using both costs one extra adapter and removes the single point of failure that an unofficial feed would otherwise be.

**Two caveats, stated plainly:**

1. The ForexFactory feed is a courtesy feed with no SLA and no support contract. It can change or disappear without notice. That is acceptable for an internal tool with FRED as corroboration and a health check on feed freshness — but **if Gold Bot is commercialised, its terms of use must be reviewed before signals are redistributed to paying subscribers.** At that point a paid provider becomes a licensing question, not a technical one, and the port means it is a one-class change.
2. I could not reach either endpoint from the build environment to confirm response shapes — outbound network policy blocks those hosts. Field lists above come from documentation and published usage. **Phase 5 begins by capturing live responses into test fixtures and asserting the mapping against them,** rather than trusting this document.

Trading Economics remains a supported upgrade path: if a subscription is ever bought, it is a third adapter and one line in `config/services.php`.

### ADR-15 — The calendar feed is a rolling window, so we archive every poll

This follows directly from ADR-12 and is easy to miss. The ForexFactory feed exposes only the current week (with separate last-week and next-week endpoints). It is not a queryable historical archive.

The consequence: **the news blackout filter cannot be backtested over any period we did not observe live.** A backtest of 2024 would silently run with no news filter at all and report better results than the live system would have produced — the most dangerous kind of wrong, because it looks like success.

**Decision:** every calendar poll upserts into `economic_events` permanently, and that table is never pruned (document 02, §10). History accumulates from the day Phase 5 ships. The backtester refuses to run a news-filtered strategy over a period predating the earliest archived event, rather than silently producing a flattering number.

This costs nothing now and cannot be recovered later. It is the single strongest argument for building Phase 5 early even though nothing depends on it until Phase 6.

### ADR-16 — Calendar events need a synthetic identity key

Trading Economics supplies a stable event id. ForexFactory does not — the feed has no identifier field at all, so the `UNIQUE (source, provider_event_id)` constraint that makes import idempotent has nothing to hold onto.

**Decision:** the provider adapter computes `provider_event_id` as a deterministic hash of `(source, currency, normalised_title, scheduled_at)`. Re-polling the same event produces the same key and updates in place; a genuinely new event produces a new key.

The tradeoff is honest: if a provider reschedules an event, the changed timestamp yields a new key and the old row remains as a stale duplicate. The calendar import task therefore reconciles by removing archived-but-unreleased events for a window that the latest poll no longer lists. Rescheduling is rare, but silently carrying a phantom event into a blackout filter would suppress real signals for no reason.

### ADR-13 — Indicators are precomputed into a wide table

Two options: a long/narrow `indicator_values(candle_id, name, value)` table, or a wide `candle_indicators` table with a typed column per indicator.

The indicator set here is small and stable (EMA 50, EMA 200, RSI 14, ATR 14, and a handful more). The dashboard and the strategy engine both need many indicators for the same candle at once — which is one row read from a wide table, versus a pivot over N rows from a long one.

**Decision:** wide table. A long table is the right call when the attribute set is open-ended and sparse; here it is neither, and it would cost read performance on the hottest query in the system.

### ADR-14 — Indicators and strategies run only on closed candles

The current 15-minute candle is still forming. Computing an EMA on it produces a value that changes every tick, and a strategy that evaluates it will fire, unfire and refire.

**Decision:** candles carry an `is_closed` flag. Indicators and strategies consume closed candles exclusively. A per-instrument-per-timeframe watermark records the last processed candle, so each run processes only what is new — satisfying "never reprocess historical candles unnecessarily" as a structural property rather than a convention someone has to remember.

---

## 3. Open Questions — blocking

### Q1. The 714 Method rules *(blocks Phase 5)*

This is the core intellectual property of the product and I will not invent it. The brief specifies the five pillars to evaluate — Trend, Market Structure, Pullback, Confirmation, Risk — and that the result is a score out of 100 with a configurable threshold. The architecture in document 02 supports that as a fully configurable weighted rubric, with no rules hardcoded.

To implement it I need, for each pillar:

- **What is measured** (e.g. "price above EMA 200 on H4 and H1")
- **How it scores** (binary pass/fail, or graduated — and if graduated, the bands)
- **Its weight** toward the 100 points
- **Whether it is a hard gate** (fails ⇒ no signal regardless of total score)
- **How entry, stop loss and TP1–TP3 are derived** once the setup passes

Also: what does "714" refer to? If it encodes parameters (periods of 7 and 14, a 7:14 ratio, a session time), that should be visible in the naming and configuration rather than buried.

Everything up to and including Phase 4 can be built without this answer.

### ~~Q2. Trading Economics subscription~~ — **resolved**

No subscription. Replaced with free sources under ADR-12: ForexFactory as primary, FRED as corroboration. Phase 5 is unblocked and should now be built **early** rather than deferred, because of ADR-15 — the calendar archive only accumulates from the day it ships.

### Q3. Twelve Data plan *(shapes Phase 2)*

The free tier is limited enough (single-digit requests per minute, several hundred per day) that it constrains the fetch schedule. The design in document 02 includes an API budget ledger that adapts either way, but knowing the tier lets Phase 2 set correct default cadences rather than conservative guesses.

---

## 4. Deferred Scope — explicitly not in V1

Recorded so the boundary stays visible: MetaTrader 5 bridge, broker execution, paper trading, AI confidence engine, machine learning, portfolio tracking, trade journal, risk calculator, additional markets, subscription billing, client portal, public REST API, mobile app.

V1 is **signals only**. No order ever reaches a broker. Every one of the above is accommodated by the architecture — documented per item in document 01, §9 — but none is built.
