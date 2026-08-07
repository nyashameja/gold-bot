# Gold Bot — Security Review

Document 05 of 06 · Phase 10 · Reviewed against the code at the Phase 10 commit

This is a review, not a checklist. Each control below was **exercised**, not
read: where a row says a request was rejected, that request was actually sent
and the response recorded. Controls that are only inspected are marked as such,
and the reason is given.

---

## 1. Findings

Three findings. All three are resolved in this phase; none was left open.

### F-01 · Backup dumps were world-readable — **resolved**

**Severity:** high on shared hosting, medium on a dedicated host.

`mysqldump` output was written with the process umask, landing at `0644`. A
database dump is the single most sensitive file this application produces: it
contains every Argon2id hash, every live session row, the whole audit trail and
the encrypted-token column. On a cPanel box where accounts share a filesystem,
a readable dump is a credential-store disclosure that requires no exploit at
all — only a neighbour with `ls`.

**Fixed:** `BackupService::create()` now `chmod`s the completed dump to `0600`,
and the backup directory is created `0750`. Verified by inspection of the file
mode after a real backup run.

### F-02 · Restore target was sanitised rather than validated — **resolved**

**Severity:** low. Reachable only from the CLI, by an operator who already has
shell access and therefore already has the database password.

`countTables()` interpolated the target database name into a SQL string having
merely stripped single quotes. Removing dangerous characters is a losing game:
it requires enumerating everything that could hurt, forever, and being right
every time.

**Fixed:** both `restore()` and `countTables()` now validate the name against
`^[A-Za-z0-9_]{1,64}$` and refuse anything else. Deciding what is *allowed* is
the only version of this that stays correct.

### F-03 · Health alerting could have become a self-silencing control — **resolved by design**

**Severity:** medium, and easy to miss because nothing fails.

An alerting implementation that fires while a condition holds — rather than
when it changes — sends one message per check interval. At the five-minute
cadence configured here, a component critical overnight produces about 96
messages. The operator mutes the channel, and from then on believes they would
be told about the next outage. That is strictly worse than never having built
alerting, because it manufactures false confidence.

**Fixed:** `HealthMonitor` alerts on **transitions only**, keys each alert by
`health:{component}:{status}:{minute}` so a retried run cannot double-send, and
announces recovery as well as failure. Covered by
`HealthMonitorTest::test_a_persistently_broken_component_alerts_only_on_the_change`.

---

## 2. Controls verified by exercise

| # | Control | How it was tested | Result |
|---|---|---|---|
| 1 | Authentication gate | Unauthenticated `GET` to `/`, `/signals`, `/settings`, `/users`, `/api/overview`, `/api/health` | All `302` to `/login`. No page or JSON endpoint answers unauthenticated. |
| 2 | Authorisation (RBAC) | Logged in as an `analyst`, requested every page | `403` on `/users`, `/settings`, `/audit`; `200` on the rest. |
| 3 | Authorisation is not UI-only | `POST` directly to a gated route without the permission | Refused by middleware **and** again in the controller (docs/01 §10). |
| 4 | CSRF | `POST /settings` with `_token=forged` | `419`, and the target setting was **unchanged** afterwards — verified in the database, not merely from the response code. |
| 5 | Account lockout | 12 consecutive wrong passwords, then the **correct** one | Locked after 5. The correct password was refused while locked: *"Too many failed attempts. Try again in 15 minutes."* This is the real control; the 60/minute rate limiter is a speed bump and is documented as such. |
| 6 | Secrets are not web-reachable | Requested `/.env`, `/../.env`, `/storage/backups/`, `/../storage/logs/app.log` | All `404`. Only `public/` is inside the document root; `.env`, `storage/` and `vendor/` sit above it. |
| 7 | Directory listing | `Options -Indexes` plus a `FilesMatch "^\."` deny | Dotfiles and listings refused. |
| 8 | Security headers | Inspected a live response | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` and a CSP all present. HSTS is sent only over HTTPS — advertising it on plain HTTP is meaningless and can lock out a misconfigured local setup. |
| 9 | CSP has no script escape hatch | Read the header | `script-src 'self' https://s3.tradingview.com https://www.tradingview-widget.com` — no `'unsafe-inline'`, no `'unsafe-eval'`. This is why Alpine's CSP build is used; relaxing the policy to suit a UI library would undo the protection it exists to provide. |
| 10 | Backup credential handling | Read the process construction; confirmed no `--password` | The password is passed through `MYSQL_PWD` in the child environment. Command-line arguments are visible in `ps` to every account on a shared host, so `--password=` would publish the database password to the neighbours nightly. |

---

## 3. Controls verified by inspection

These are structural properties, verified by reading every occurrence rather
than by sending a request. The greps are reproducible and are given so the
review can be repeated rather than trusted.

**SQL injection.** Every query uses prepared statements. Grepping `app/` for
string interpolation inside SQL returns only whitelisted fragments — table and
column names that are literals in the same file, never values from a request:

- `MySqlPerformanceRepository::DIMENSIONS` — a fixed map; the caller may name a
  key, never a column. An unknown key throws
  (`DashboardReadTest::test_an_unknown_dimension_is_refused`).
- `MySqlSignalRepository::filterScope()` — column names are literals; only
  values are bound.
- `CleanupTask` — table and column names come from a constant array in the file.

**Output escaping.** Every `<?= ?>` in `resources/views/` passes through `e()`.
The exceptions are `$content` (already-rendered markup), `$this->partial(...)`,
`$csrf->field()`, and the SVG path constants in the sidebar and empty-state
partials — all application-authored, none derived from input. The renderer does
not auto-escape by design (docs/01 §10); `e()` is short precisely so it is
never skipped for brevity.

**Dangerous functions.** `eval`, `assert`, `system`, `passthru` and
`create_function` do not appear. What does:

- `extract()` in `View::capture()` — inside a closure with `EXTR_SKIP`, over
  keys the application controls.
- `unserialize()` in `FileCache` — with `['allowed_classes' => false]`, so
  object injection is not possible.
- `shell_exec('stty')` in the CLI password prompt — a literal string.
- `proc_open()` in `BackupService` — every interpolated value is
  `escapeshellarg`'d and the binary is `escapeshellcmd`'d (see F-02).

**Secret redaction.** `FileLogger` and `MySqlAuditRepository` both redact keys
matching `password`, `token`, `api_key`, `secret`, `bot_token`. A secret
setting's before/after values are replaced with a mask before being audited —
the audit log is readable by more people than the secret is.

**Password storage.** Argon2id via `password_hash`, with `password_needs_rehash`
checked on every successful login so parameters can be raised without a
migration. No hash is returned by any service or written to any log.

**Session security.** Database-backed, so a session can be revoked and survives
a PHP restart. Cookies are `httponly`, `samesite=Lax`, and `secure` by config
default. Deactivating a user destroys their sessions — otherwise a deactivated
user with a live cookie is still a logged-in user.

**Transport.** `.htaccess` forces HTTPS, honouring `X-Forwarded-Proto` so it
behaves correctly behind a proxy.

---

## 4. Accepted risks

Recorded rather than resolved. Each is a deliberate decision with its reasoning,
so a future reviewer can disagree with the judgement rather than rediscover the
question.

**A-01 · `style-src` allows `'unsafe-inline'`.** Required by Alpine's `x-show`
and by the inline `style="width: …%"` on the progress bars. The XSS risk from
injected *styles* is far lower than from scripts, and `script-src` grants no
such allowance. Removing it would mean a nonce per response and a build step
that rewrites every inline style — cost out of proportion to the exposure.

**A-02 · TradingView is a third-party script with full page context.** It is
the only external script the browser loads, and it is in the brief. It is
loaded lazily, degrades to a message when unreachable, and the page is fully
usable without it. Anyone unwilling to accept a third-party script on an
authenticated page should remove the widget; nothing else depends on it.

**A-03 · No two-factor authentication.** Out of scope for V1 and not in the
brief. Mitigated by Argon2id, lockout after five attempts, and database-backed
sessions that an administrator can revoke. Worth revisiting before the platform
is exposed to users outside the organisation.

**A-04 · Rate limiting is per-process cache, not distributed.** On a single
cPanel host this is the same thing. It would need moving to the database or to
Redis before running behind more than one application server.

---

## 5. Not yet verifiable

Stated plainly because a review that quietly omits its blind spots is worth
less than no review.

**Provider TLS and response handling have never been exercised against a live
endpoint.** The build environment cannot reach Twelve Data, ForexFactory, FRED
or `api.telegram.org`. Every provider mapping is fixture-based, shaped from
documented formats rather than observed traffic. Before sign-off:

1. Re-record all four providers' fixtures from live responses.
2. Confirm certificate verification is on and that an invalid certificate is
   rejected — `HttpClient` sets it, but a setting that has never been tested
   against a real failure is a hypothesis.
3. Confirm the bot token is accepted and that `getMe` returns the expected bot.

---

## 6. Re-running this review

```bash
# SQL interpolation — expect only whitelisted fragments
grep -rn '\$[a-zA-Z_]*"' --include=*.php app/ | grep -iE "SELECT|INSERT|UPDATE|DELETE|WHERE"

# Unescaped view output — expect nothing
grep -rnoE '<\?=\s*\$[a-zA-Z_>\[\]-]+\s*\?>' resources/views/ \
  | grep -vE '\$content|\$this->partial|\$csrf|\$icon'

# Dangerous functions
grep -rnE '\b(eval|assert|system|passthru|shell_exec|popen|create_function|unserialize|extract)\s*\(' \
  --include=*.php app/ cron/ public/

# Backup file mode — expect 0600
php cron/run.php backup:run && ls -l storage/backups/*.sql.gz
```
