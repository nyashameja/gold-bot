# Gold Bot — Database Design

Document 02 of 05 · Status: **Awaiting approval** · MySQL 8.0 / MariaDB 10.6+ · InnoDB · utf8mb4

This is the schema specification. DDL is written in Phase 1 as numbered migrations, after approval.

---

## 1. Conventions

- **Primary keys** `BIGINT UNSIGNED AUTO_INCREMENT` named `id` on every table.
- **Public identifiers** `uuid BINARY(16)` with a unique index, on user-facing aggregates only — `users`, `signals`, `api_tokens`. Never on time-series tables (ADR-10). Stored as `BINARY(16)` rather than `CHAR(36)`: 16 bytes against 36, on an index that is read constantly.
- **Prices** `DECIMAL(14,5)` universally (ADR-11). Never `FLOAT` or `DOUBLE`.
- **Timestamps** stored UTC. Column suffix `_at`. Display timezone is a user preference, applied at render only.
- **Candle times** `DATETIME(0)` not `TIMESTAMP` — `TIMESTAMP` carries an implicit server-timezone conversion and a 2038 limit, neither of which belongs on market data.
- **Fixed-but-extensible sets** (timeframes, event impacts, signal states) are lookup tables, not `ENUM`. Adding M5 should be an insert, not a schema migration.
- **Soft deletes** `deleted_at` on `users`, `strategies`, `instruments` only. Market data and events are never soft-deleted; they are pruned by the cleanup task.
- **Foreign keys** explicit, with `ON DELETE` chosen per relationship: `CASCADE` for owned children, `SET NULL` for optional references, `RESTRICT` for lookups.

## 2. Schema Map

```
IDENTITY          users · roles · permissions · role_permissions · user_roles
                  sessions · password_resets · login_attempts · api_tokens · audit_logs

REFERENCE         instruments · timeframes · market_sessions · settings

MARKET DATA       candles · candle_indicators · price_snapshots
                  market_structure_points · market_levels · ingest_watermarks

CALENDAR          economic_events · event_categories

STRATEGY          strategies · strategy_configs · strategy_runs
                  signals · signal_events · signal_targets · signal_scores

DELIVERY          telegram_chats · telegram_templates · telegram_messages

OPERATIONS        api_providers · api_usage_log · scheduled_tasks · task_runs
                  health_checks · system_logs · performance_snapshots
```

---

## 3. Identity & Access

**`users`** — `id`, `uuid`, `email` (unique), `password_hash` (Argon2id), `name`, `is_active`, `timezone`, `last_login_at`, `last_login_ip`, `failed_login_count`, `locked_until`, `created_at`, `updated_at`, `deleted_at`.

**`roles`** — `id`, `slug` (unique), `name`, `description`, `is_system`. Seeded: `administrator`, `analyst`, `viewer`. `is_system` prevents deletion of roles the application depends on.

**`permissions`** — `id`, `slug` (unique), `name`, `group`. Seeded per capability: `signals.view`, `signals.cancel`, `strategies.edit`, `settings.edit`, `users.manage`, `telegram.send`, `health.view`.

**`role_permissions`** — composite PK `(role_id, permission_id)`.

**`user_roles`** — composite PK `(user_id, role_id)`. Many-to-many from the start; collapsing to a single role later is trivial, expanding from one is a migration.

**`sessions`** — `id`, `session_id` (unique), `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity_at`, `expires_at`. Database-backed so sessions survive a PHP restart and an administrator can revoke one.

**`password_resets`** — `id`, `user_id`, `token_hash`, `expires_at`, `used_at`. The token is stored hashed: a leaked table must not grant account takeover.

**`login_attempts`** — `id`, `email`, `ip_address`, `succeeded`, `attempted_at`. Index `(email, attempted_at)` and `(ip_address, attempted_at)` for throttling.

**`api_tokens`** — `id`, `uuid`, `user_id`, `name`, `token_hash`, `abilities` (JSON), `last_used_at`, `expires_at`, `revoked_at`. V1 uses these for internal automation; V2's public API reuses them unchanged.

**`audit_logs`** — `id`, `user_id` (nullable — cron acts without a user), `action`, `subject_type`, `subject_id`, `before` (JSON), `after` (JSON), `ip_address`, `user_agent`, `created_at`. Append-only; no update or delete path exists in code. Index `(subject_type, subject_id)` and `(user_id, created_at)`.

---

## 4. Reference Data

**`instruments`** — `id`, `symbol` (unique, `XAU/USD`), `provider_symbol`, `name`, `asset_class`, `price_precision`, `pip_size`, `contract_size`, `is_active`, `deleted_at`. Present from day one so multi-instrument in V2 requires no restructuring — every market table already carries `instrument_id`.

**`timeframes`** — `id`, `code` (unique: `M5`, `M15`, `H1`, `H4`, `D1`), `minutes`, `provider_interval`, `sort_order`, `is_active`. `minutes` lets candle boundaries be computed arithmetically rather than with a per-timeframe branch. M5 ships as an inactive row, ready for V2.

**`market_sessions`** — `id`, `code` (`SYDNEY`, `TOKYO`, `LONDON`, `NEW_YORK`), `name`, `open_time`, `close_time`, `timezone` (IANA), `is_active`.

Sessions are stored as local times with an IANA timezone rather than fixed UTC hours, because London and New York observe DST on different dates. Hardcoding UTC offsets means the session labels — and any session-based filter — go silently wrong for several weeks each year, in a way nobody notices until the performance breakdown looks strange.

**`settings`** — `id`, `key` (unique), `value`, `type` (`string|int|float|bool|json`), `group`, `label`, `description`, `is_secret`, `updated_by`, `updated_at`. Typed so reads cast correctly instead of every caller re-parsing. `is_secret` masks values in the UI and redacts them from audit entries.

---

## 5. Market Data

### `candles` — the highest-volume table

`id`, `instrument_id`, `timeframe_id`, `open_time`, `close_time`, `open`, `high`, `low`, `close`, `volume`, `is_closed`, `source`, `created_at`, `updated_at`.

- **`UNIQUE (instrument_id, timeframe_id, open_time)`** — the linchpin. It makes ingest idempotent via `ON DUPLICATE KEY UPDATE`, so a retried or overlapping fetch cannot duplicate a bar. Without it, every ingest bug becomes a data-integrity bug.
- Index `(instrument_id, timeframe_id, open_time DESC)` serves the dominant query: "last N closed candles".
- `is_closed` gates indicator and strategy processing (ADR-14).
- `source` records the provider, so a future second source is distinguishable.

Volume is modest — 15-minute XAU/USD is roughly 25,000 bars per year — so this stays comfortably small for years. The index design matters not for size but because the strategy engine issues these range scans on every run.

### `candle_indicators`

`id`, `candle_id` (unique FK, `ON DELETE CASCADE`), `instrument_id`, `timeframe_id`, `open_time`, plus typed columns: `ema_50`, `ema_200`, `rsi_14`, `atr_14`, `macd`, `macd_signal`, `macd_histogram`, `bb_upper`, `bb_middle`, `bb_lower`, `volume_sma_20`, `computed_at`.

Wide rather than long, per ADR-13: the indicator set is small and stable, and both the dashboard and the strategy engine read many indicators for one candle at once — one row read instead of a pivot.

`instrument_id`, `timeframe_id` and `open_time` are denormalised from `candles` to let the strategy engine load an indicator window without a join. A deliberate, bounded denormalisation: these three values are immutable for the life of a candle, so the usual objection — divergence between copies — cannot arise.

### `price_snapshots`

`id`, `instrument_id`, `price`, `bid`, `ask`, `spread`, `day_high`, `day_low`, `change_absolute`, `change_percent`, `captured_at`, `provider_time`.

Powers the live price widget and signal lifecycle tracking. Both `captured_at` and `provider_time` are stored so the dashboard can display genuine data age rather than the age of our own write. Pruned by the cleanup task on a rolling window.

### `market_structure_points`

`id`, `instrument_id`, `timeframe_id`, `candle_id`, `type` (`SWING_HIGH`, `SWING_LOW`, `BOS`, `CHOCH`), `price`, `direction`, `strength`, `detected_at`, `invalidated_at`.

Swing points and break-of-structure / change-of-character events, computed once and reused by every strategy and by the Live Market chart overlays.

### `market_levels`

`id`, `instrument_id`, `timeframe_id`, `type` (`SUPPORT`, `RESISTANCE`, `SUPPLY_ZONE`, `DEMAND_ZONE`, `DAILY_HIGH`, `DAILY_LOW`, `WEEKLY_HIGH`, `WEEKLY_LOW`), `price_from`, `price_to`, `strength`, `touch_count`, `is_active`, `formed_at`, `invalidated_at`.

Zones need two prices; single levels store the same value in both, which keeps one table and one query path instead of two near-identical tables.

### `ingest_watermarks`

`id`, `instrument_id`, `timeframe_id`, `stage` (`INGEST`, `INDICATORS`, `STRUCTURE`, `LEVELS`, `STRATEGY`), `last_open_time`, `last_candle_id`, `updated_at`. Unique `(instrument_id, timeframe_id, stage)`.

This small table is what makes "only analyse new candles" structural rather than aspirational. Each stage advances its own watermark independently, so a failure in structure detection does not force indicators to recompute, and any single stage can be safely replayed by rewinding one row.

---

## 6. Economic Calendar

**`event_categories`** — `id`, `code` (`CPI`, `PPI`, `NFP`, `GDP`, `RETAIL_SALES`, `INTEREST_RATE`, `FED_SPEECH`), `name`, `default_impact`, `blackout_minutes_before`, `blackout_minutes_after`.

Blackout windows live on the category, so "no signals within 30 minutes of NFP" is configuration rather than code — and can differ per event type, which it should: a rate decision warrants a wider window than retail sales.

**`economic_events`** — `id`, `provider_event_id`, `category_id`, `country`, `currency`, `title`, `impact` (`LOW|MEDIUM|HIGH|HOLIDAY`), `scheduled_at`, `time_is_approximate`, `actual`, `forecast`, `previous`, `revised_from`, `unit`, `source`, `first_seen_at`, `last_seen_at`, `created_at`, `updated_at`.

`UNIQUE (source, provider_event_id)` for idempotent upsert — events are revised as actuals publish, and re-importing must update rather than duplicate. Index `(scheduled_at, impact)` serves both the calendar page and the blackout filter, which is the hottest read: every strategy run asks "is there a high-impact event near now?"

Four columns exist specifically because of the free-source decision (ADR-12, ADR-15, ADR-16):

- **`impact` includes `HOLIDAY`.** ForexFactory emits it as a fourth impact level, and it is genuinely useful rather than noise: a bank holiday means thin liquidity, which is a legitimate reason to suppress signals even though no data is released.
- **`provider_event_id` is synthetic** for sources that supply no identifier — a deterministic hash of `(source, currency, normalised_title, scheduled_at)` computed in the adapter (ADR-16). The column and its constraint are unchanged; only the adapter knows the difference.
- **`time_is_approximate`** flags events published as "Day 1" or "Tentative" rather than at a fixed time — common for Fed speeches and some rate decisions. A blackout window around an approximate time should be widened, not applied as though the minute were known.
- **`first_seen_at` / `last_seen_at`** support the reconciliation described in ADR-16: an unreleased event that stops appearing in the feed has been rescheduled or cancelled, and should be retired rather than left to suppress signals forever.

The `source` column earns its place now that two providers write here concurrently — ForexFactory and FRED can both describe the same release, and the blackout filter deduplicates on `(currency, scheduled_at, category_id)` while retaining both rows for provenance.

---

## 7. Strategy & Signals

**`strategies`** — `id`, `code` (unique: `714`, `BREAKOUT`, `EMA_CROSS`, `LIQUIDITY_SWEEP`), `name`, `description`, `class_name`, `is_enabled`, `sort_order`, `deleted_at`. `class_name` maps a row to its PHP implementation, so registering a strategy is a container binding plus a row.

**`strategy_configs`** — `id`, `strategy_id`, `version`, `config` (JSON), `is_active`, `created_by`, `created_at`, `activated_at`.

**Immutable** (ADR-06). Editing writes a new version; only the active pointer moves. Unique `(strategy_id, version)`. The JSON holds every tunable parameter — pillar weights, thresholds, indicator periods, RR ratios, session and news filters — so tuning never requires a deploy.

**`strategy_runs`** — `id`, `strategy_id`, `strategy_config_id`, `instrument_id`, `timeframe_id`, `candle_id`, `evaluated_at`, `score`, `passed`, `rejection_reason`, `features` (JSON), `duration_ms`.

Written on **every** evaluation, not only when a signal fires. Three reasons: it answers "why did nothing fire today?" — the most common operational question; it makes threshold tuning empirical, since you can see the distribution of near-misses; and its `features` column accumulates the labelled dataset any future ML work needs (document 01, §9). This table cannot be backfilled after the fact, which is why it belongs in V1.

Retention is bounded by the cleanup task.

**`signals`** — `id`, `uuid`, `strategy_id`, `strategy_config_id`, `instrument_id`, `timeframe_id`, `direction` (`BUY|SELL`), `state`, `score`, `entry_price`, `stop_loss`, `risk_reward`, `session_code`, `market_regime`, `generated_at`, `activated_at`, `closed_at`, `expires_at`, `close_reason`, `realised_r`, `strategy_run_id`.

`strategy_config_id` is a hard FK: every signal is permanently attributable to the exact configuration that produced it. `state` is the read projection; `signal_events` is the history (ADR-05). `realised_r` (outcome in R multiples) is the currency of all performance reporting — percentages mislead when position sizes differ.

**`signal_targets`** — `id`, `signal_id`, `level` (1–3), `price`, `close_percent`, `hit_at`, `hit_price`. Separate table because the number of targets is configurable; `tp1`/`tp2`/`tp3` columns would cap it at three forever.

**`signal_events`** — `id`, `signal_id`, `event_type` (`GENERATED`, `SENT`, `ENTRY_ACTIVATED`, `TP1_HIT`, `TP2_HIT`, `TP3_HIT`, `MOVED_TO_BREAKEVEN`, `STOP_LOSS_HIT`, `CANCELLED`, `EXPIRED`), `price_at_event`, `notes`, `triggered_by` (`SYSTEM|USER`), `user_id`, `occurred_at`.

Append-only, and the source of truth for the signal's history. Index `(signal_id, occurred_at)`.

**`signal_scores`** — `id`, `signal_id`, `pillar` (`TREND`, `STRUCTURE`, `PULLBACK`, `CONFIRMATION`, `RISK`), `raw_score`, `weight`, `weighted_score`, `passed`, `detail` (JSON).

The per-pillar breakdown behind the score out of 100. This is what makes the 714 page explain a signal instead of merely asserting one — and what lets you discover that, say, the Confirmation pillar is contributing nothing.

---

## 8. Telegram Delivery

**`telegram_chats`** — `id`, `chat_id`, `type`, `title`, `is_active`, `receives_signals`, `receives_alerts`, `receives_summaries`, `created_at`. Separate subscription flags so system alerts can go to an operations chat while signals go to a subscriber channel.

**`telegram_templates`** — `id`, `code` (unique, one per message type), `name`, `body`, `parse_mode`, `is_active`, `updated_by`, `updated_at`. Message wording changes far more often than message logic; templates keep copy edits out of deployments.

**`telegram_messages`** — the outbox (ADR-07).

`id`, `chat_id`, `template_code`, `idempotency_key` (unique), `payload` (JSON), `rendered_text`, `priority`, `status` (`PENDING|SENDING|SENT|FAILED|DEAD`), `attempts`, `max_attempts`, `last_error`, `provider_message_id`, `available_at`, `sent_at`, `created_at`, `signal_id`.

- **`idempotency_key` unique** is the guarantee that matters: derived deterministically from what the message is about (e.g. `signal:{uuid}:TP1`), it makes duplicate sends impossible even if the producer runs twice.
- `available_at` implements exponential backoff without a separate scheduler — the drainer selects where `status = 'PENDING' AND available_at <= NOW()`.
- `priority` gives system alerts their own lane (document 01, §11).
- `DEAD` is terminal after `max_attempts`, surfaced on the health page rather than retried forever.

Index `(status, available_at, priority)`.

---

## 9. Operations

**`api_providers`** — `id`, `code` (`TWELVE_DATA`, `TRADING_ECONOMICS`, `TELEGRAM`), `name`, `base_url`, `daily_limit`, `per_minute_limit`, `is_active`.

**`api_usage_log`** — `id`, `provider_id`, `endpoint`, `method`, `http_status`, `succeeded`, `response_time_ms`, `error_message`, `credits_used`, `requested_at`.

Powers the API Usage page and the budget gate (document 01, §5). Index `(provider_id, requested_at)` for rolling-window counts. Pruned to a retention window; daily aggregates are rolled into `performance_snapshots` before pruning so long-term trends survive.

**`scheduled_tasks`** — `id`, `code` (unique), `name`, `handler_class`, `cadence_expression`, `is_enabled`, `timeout_seconds`, `max_runtime_warning_seconds`, `last_run_at`, `last_success_at`, `next_due_at`, `consecutive_failures`.

The schedule as data (ADR-08). `last_success_at` against `cadence_expression` is what detects a silently dead task — the failure mode that produces no errors.

**`task_runs`** — `id`, `task_id`, `started_at`, `finished_at`, `duration_ms`, `status` (`RUNNING|SUCCESS|FAILED|SKIPPED_LOCKED|SKIPPED_BUDGET`), `items_processed`, `output`, `error_message`.

The distinct skip statuses matter operationally: a task skipped because it was already running is healthy, one skipped for exhausted API budget is a warning, and a failure is an error. Collapsing them into "didn't run" discards exactly the information needed to respond.

**`health_checks`** — `id`, `component`, `status` (`OK|WARNING|CRITICAL`), `message`, `metrics` (JSON), `checked_at`, `duration_ms`. Latest row per component drives the System Health page; history shows whether a problem is new or chronic.

**`system_logs`** — `id`, `level` (PSR-3), `channel`, `event`, `message`, `context` (JSON), `user_id`, `ip_address`, `created_at`. The UI-surfaced subset; full-fidelity logs go to rotated files. Index `(level, created_at)` and `(channel, created_at)`.

**`performance_snapshots`** — `id`, `period_type` (`DAILY|WEEKLY|MONTHLY`), `period_start`, `period_end`, `strategy_id`, `instrument_id`, `session_code`, `total_signals`, `wins`, `losses`, `breakeven`, `win_rate`, `profit_factor`, `average_rr`, `total_r`, `max_drawdown_r`, `max_consecutive_wins`, `max_consecutive_losses`, `computed_at`.

Precomputed rollups rather than aggregating raw signals on page load. The dimension columns are nullable, so one table serves both the overall figures and the per-strategy, per-session and per-weekday breakdowns the brief asks for. Recomputed nightly and on demand after a signal closes — the Performance page then reads rows instead of scanning.

---

## 10. Retention

The cleanup task enforces these, all configurable in `settings`:

| Data | Default retention | Reason |
|---|---|---|
| `candles` | Indefinite | The asset. Backtesting needs full history. |
| `candle_indicators` | Indefinite | Recomputable, but cheap to keep and expensive to rebuild. |
| `price_snapshots` | 30 days | High-frequency, low long-term value. |
| `strategy_runs` | 180 days | Balances ML value against volume. |
| `api_usage_log` | 90 days | Aggregated into snapshots before pruning. |
| `task_runs` | 90 days | Operational forensics window. |
| `system_logs` | 90 days | Files retain longer. |
| `signals`, `signal_events` | Indefinite | Permanent performance record. |
| `economic_events` | **Indefinite — never pruned** | The upstream feed is a rolling window (ADR-15). This table is the only archive that will ever exist, and it cannot be rebuilt. |
| `audit_logs` | Indefinite | Audit trails are not pruned. |
| `telegram_messages` | 90 days for `SENT` | `DEAD` retained for investigation. |
