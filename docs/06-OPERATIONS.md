# Gold Bot — Installation, Deployment & Operations

Document 06 of 06 · Phase 10

Three parts: getting it running the first time, getting a new version onto a
running system, and what to do when something breaks. Written to be followed
by someone who did not build it.

---

# Part 1 · Installation

Follow this section alone. If a step needs knowledge that is not on this page,
that is a defect in this page — say so rather than working around it.

## 1.1 What the host must provide

| Requirement | Minimum | Check it with |
|---|---|---|
| PHP | 8.3 | `php -v` |
| PHP extensions | `pdo_mysql`, `curl`, `mbstring`, `json`, `openssl` | `php -m` |
| MySQL / MariaDB | MySQL 8.0 or MariaDB 10.6 | `mysql --version` |
| Composer | 2.x | `composer --version` |
| Cron | One entry, every minute | cPanel → Cron Jobs |
| Disk | 2 GB to start; candle history grows slowly | `df -h` |

`mysqldump` and `gunzip` must be on `PATH` for backups. Almost every cPanel
host provides both; confirm with `which mysqldump gunzip`.

Node is **not** required. `public/assets/` is built and committed precisely
because cPanel has no Node runtime.

## 1.2 Create the database

In cPanel → MySQL Databases, create a database and a user, and grant that user
all privileges on it. Note all three values — you need them in the next step.

## 1.3 Get the code

Clone **above** the document root, never inside it:

```bash
cd ~
git clone <repository-url> gold-bot
cd gold-bot
composer install --no-dev --optimize-autoloader
```

The kernel, `paragon/php-core`, is **inside this repository** at
`packages/php-core` (ADR-02) and is wired up by that `composer install`. It
needs no separate clone, no credentials and no Packagist access. You can see it
worked:

```bash
ls -l vendor/paragon/php-core     # a symlink to ../../packages/php-core
```

**If you cannot run Composer on the host** — no SSH, no cPanel Terminal — see
§2.4 before uploading anything. Uploading a `vendor/` built elsewhere is the
one deployment method the kernel package breaks, and it breaks silently.

## 1.4 Configure

```bash
cp .env.example .env
php cron/run.php key:generate
```

Then edit `.env`. The values that must be set before anything works:

```ini
APP_ENV=production
APP_DEBUG=false            # never true on a public host — it prints stack traces
APP_URL=https://your-domain.example

DB_HOST=127.0.0.1
DB_DATABASE=cpaneluser_goldbot
DB_USERNAME=cpaneluser_goldbot
DB_PASSWORD=…

TWELVE_DATA_API_KEY=…      # market data
FRED_API_KEY=…             # economic calendar corroboration (free, instant)
TELEGRAM_BOT_TOKEN=…       # from @BotFather
```

Lock the file down — it holds every credential the system has:

```bash
chmod 600 .env
```

## 1.5 Install the schema

```bash
php cron/run.php install
```

This runs the migrations and seeds reference data: roles, permissions,
instruments, timeframes, sessions, settings, the scheduled tasks and the
Telegram templates. It is idempotent — running it twice applies nothing the
second time, which is the property that makes it safe to re-run when you are
unsure whether it completed.

## 1.6 Create the first administrator

```bash
php cron/run.php user:create you@example.com "Your Name" administrator
```

The password is prompted for, never passed as an argument — arguments end up in
shell history and in `ps`.

## 1.7 Point the web server at `public/`

In cPanel → Domains, set the document root to `~/gold-bot/public`.

**Nothing above `public/` may be web-reachable.** Confirm it, do not assume it:

```bash
curl -sI https://your-domain.example/.env      # expect 404
curl -sI https://your-domain.example/composer.json   # expect 404
```

If either returns 200, the document root is wrong. Stop and fix it before
continuing — `.env` contains every credential.

## 1.8 Add the cron entry

**One** entry, every minute (ADR-08). The scheduler decides what is due; adding
one cron line per task is how a schedule drifts out of sync with the code.

```
* * * * * /usr/local/bin/php /home/USER/gold-bot/cron/run.php schedule >/dev/null 2>&1
```

Use the absolute PHP path your host provides — cPanel's default `php` is
sometimes an older build than the one the web server uses.

## 1.9 Verify the installation

```bash
php cron/run.php check
```

Every line must pass. Then, after waiting two minutes for the cron to run
twice:

```bash
php cron/run.php health:check
```

`scheduler` must not say *overdue*. If it does, the cron entry is not firing —
check the path to PHP and the path to `run.php`.

Finally, sign in and confirm the Overview page renders with a price.

### Installation is complete when

- [ ] `php cron/run.php check` passes every line
- [ ] `curl -sI https://your-domain/.env` returns 404
- [ ] `php cron/run.php health:check` reports the scheduler as OK
- [ ] You can sign in and the Overview shows a gold price with a fresh age
- [ ] `php cron/run.php backup:run` writes a file, and `ls -l` shows mode `-rw-------`

---

# Part 2 · Deployment

## 2.1 Releasing a new version

```bash
cd ~/gold-bot
php cron/run.php backup:run          # before anything else
git pull --ff-only
composer install --no-dev --optimize-autoloader
php cron/run.php migrate
php cron/run.php check
```

Back up **first**. A migration that goes wrong on a system with last night's
backup costs a day; on a system with no backup it costs everything.

Migrations are additive by convention. There is no `down()` — a rollback path
that has never been executed is a hypothesis, and on a live database an
untested `down()` is more dangerous than the forward fix.

## 2.2 If a release goes wrong

```bash
git reset --hard <previous-commit>
composer install --no-dev --optimize-autoloader
```

Code reverts cleanly. **The database does not** — if the release included a
migration, reverting the code leaves a schema the old code does not expect.
That is what the pre-release backup is for; see §3.6.

## 2.3 Rebuilding front-end assets

Only needed if you changed anything in `resources/`. Do it on a machine with
Node and commit the result — cPanel cannot run this:

```bash
npm install
npm run build      # writes public/assets/{css,js}
git add public/assets && git commit
```

## 2.4 The kernel package, and the one way to break it

`paragon/php-core` is required from a Composer **path** repository pointing at
`packages/php-core` inside this repo (ADR-02). Composer satisfies it by
creating a symlink:

```
vendor/paragon/php-core -> ../../packages/php-core
```

`git pull` + `composer install` on the host — the procedure in §1.3 and §2.1 —
handles this correctly and needs nothing extra. **Deploying by uploading files
does not.** Extracting a ZIP through cPanel's File Manager, or copying with an
FTP client, does not preserve symlinks: most will either skip the link or
replace it with a small text file. Either way `vendor/paragon/php-core` stops
being the kernel, and the site returns a 500 with `Class "Paragon\Core\…" not
found` in `storage/logs/`. It is a confusing failure precisely because the code
is all present on disk.

If you must deploy without running Composer on the host, tell Composer to copy
the package rather than link it. In `composer.json`:

```json
"options": { "symlink": false }
```

Then `composer update paragon/php-core --no-dev --optimize-autoloader` on the
machine you build on, and upload `vendor/` as an ordinary directory. This is
verified to work; the trade is that the kernel is now duplicated on disk, so
after any change to `packages/php-core` you must re-run that command or you
will be running the old kernel with no indication that you are.

**Prefer running Composer on the host.** It is one command, and it removes this
entire class of problem.

When the kernel later moves to its own private repository — the end state
ADR-02 describes, see `packages/php-core/README.md` — this section stops
applying: `paragon/php-core` becomes an ordinary versioned dependency with no
symlink involved.

---

# Part 3 · Operations runbook

## 3.1 The daily glance

Open **System Health**. It computes its checks live rather than replaying
stored ones, deliberately: if the scheduler has stopped then so has the health
cron, and a page that only replayed stored results would show the last cheerful
green row it managed to write before everything died.

From the CLI:

```bash
php cron/run.php health:check      # exits non-zero if anything is degraded
```

That exit code is useful on its own — a plain cPanel cron emails you when a
command exits non-zero, which gives alerting with no extra machinery:

```
*/15 * * * * /usr/local/bin/php /home/USER/gold-bot/cron/run.php health:check
```

## 3.2 The alerts you will receive

Telegram alerts fire on **state changes**, not while a condition holds. A
component critical overnight sends one message, not ninety-six. Recovery is
announced too, so you are never left wondering whether to check by hand.

| Alert | What it means | First thing to do |
|---|---|---|
| Scheduler CRITICAL | A task has not succeeded in three times its cadence | Is the cron entry still there? `php cron/run.php schedule` by hand |
| API providers CRITICAL | Quota nearly gone, or ten failures in an hour | Open **API Usage**; check the projection column |
| Telegram CRITICAL | A message is dead-lettered, or the queue has stopped moving | Open **Telegram**; retry the dead message |
| Storage CRITICAL | Under 5% free, or a directory is unwritable | `df -h`; check `storage/` permissions |
| Price feed CRITICAL | No quote for 30 minutes | Usually the provider; check **API Usage** for failures |

## 3.3 "No signals are being generated"

Work down this list; each step rules out one cause.

1. **Is the strategy enabled?** `php cron/run.php strategy:list`. The 714
   strategy ships **disabled** on purpose — its configuration is a documented
   placeholder, not the real method (docs/00 Q1).
2. **Is the engine enabled?** Settings → `signals.enabled`.
3. **Is a news blackout active?** Open **Economic Calendar**; an active window
   is banner-flagged.
4. **Is the score threshold reachable?** Open **714 Method** and look at the
   score distribution. A threshold with no bars near it is not selective, it is
   unreachable.
5. **What did the engine actually decide?** `strategy_runs` records a
   `rejection_reason` for every evaluation. This answers the question directly
   rather than by elimination.

## 3.4 "The dashboard shows old prices"

Every figure on the dashboard carries its own age, judged against the cadence
that should refresh it. A stale one means the cron that fills it has stopped —
the dashboard itself never calls a provider.

```bash
php cron/run.php task market.price     # run the import by hand
php cron/run.php health:check
```

If a manual run works but the scheduled one does not, the cron entry is the
problem, not the code.

## 3.5 Taking and checking backups

Backups run nightly at the `system.backup` task's cadence. Seven are kept — a
**count**, not an age, because under an age policy an account that stopped
backing up would quietly delete its way to nothing.

```bash
php cron/run.php backup:list
php cron/run.php backup:run        # take one now
```

Backups are written `0600`. Nothing else on a shared host should be able to
read them; a dump contains every password hash and the whole audit trail.

## 3.6 Restoring — the procedure that has actually been run

This was executed during Phase 10 against a real dump. It is not a sketch.

**Restore into a scratch database first, always.** `backup:restore` requires an
explicit target and has no default, because a command that would overwrite the
live database when called carelessly is an accident waiting to be typed.

```bash
# 1. Create an empty scratch database (cPanel → MySQL Databases)

# 2. Restore into it
php cron/run.php backup:restore storage/backups/goldbot-YYYYMMDD-HHMMSS.sql.gz goldbot_scratch

# 3. Verify it — count rows against what you expect
mysql -u USER -p goldbot_scratch -e "
  SELECT 'signals', COUNT(*) FROM signals
  UNION ALL SELECT 'candles', COUNT(*) FROM candles
  UNION ALL SELECT 'users', COUNT(*) FROM users;"

# 4. Prove the application runs against it: point DB_DATABASE at the scratch
#    database in a COPY of .env and run
php cron/run.php check
php cron/run.php migrate:status
```

Only once the scratch copy checks out should you consider pointing production
at it. A restore that has been verified only by "the command exited zero" is
not a verified restore — the Phase 10 verification counted rows and then signed
in and browsed every page.

## 3.7 Retention

The cleanup task prunes on its own (docs/02 §10). What it **never** prunes,
and why:

- `candles`, `candle_indicators` — the asset; backtesting needs full history
- `signals`, `signal_events` — the permanent performance record
- `audit_logs` — an audit trail that expires is not one
- `economic_events` — **unrecoverable if deleted.** The upstream feed is a
  rolling window (ADR-15); this table is the only archive that will ever exist
- `telegram_messages` with status `DEAD` — the evidence of a delivery problem

## 3.8 Useful commands

```bash
php cron/run.php check                  # configuration and connectivity
php cron/run.php health:check           # component health, non-zero if degraded
php cron/run.php schedule               # run everything due now
php cron/run.php task <code>            # run one task now
php cron/run.php migrate:status         # what has and has not been applied
php cron/run.php strategy:list          # strategies, versions, thresholds
php cron/run.php performance:show       # metrics from the stored rollups
php cron/run.php performance:rebuild    # recompute every rollup from signals
php cron/run.php backup:run             # take a backup now
php cron/run.php backup:list
php cron/run.php backup:restore <file> <target-db>
php cron/run.php backtest:run <strategy> [--from=…] [--to=…] [--min-score=N] [--news]
php cron/run.php backtest:sweep <strategy> [--range=50:90:5]
php cron/run.php backtest:list
php cron/run.php user:create <email> "<Name>" [role]
```

## 3.10 Choosing the 714 threshold

Do not pick it by intuition. That is what the backtester is for (ADR-04), and
a number chosen without one becomes the foundation every later tuning decision
is layered on.

```bash
php cron/run.php backtest:sweep 714 --range=50:90:5
```

Read the output with three things in mind:

1. **Rows marked `*` have fewer than 30 closed trades.** They are not
   measurements. A 100% win rate over four trades tells you nothing, and it
   will look like the best row in the table.
2. **The recommendation is ranked by expectancy, not net R.** Net R rewards
   whichever threshold took the most trades, which measures activity as much
   as edge. Expectancy is R per signal — what one more trade is worth, which
   is the actual decision.
3. **No recommendation is a real answer.** If the sweep declines, the period
   does not support choosing a threshold. Get more history rather than picking
   the least-bad row.

Then apply it as a new config version, which leaves every past signal
attributed to the rules that actually produced it (ADR-06):

```bash
php cron/run.php strategy:config 714 tuned-config.json
```

**On the news filter.** `--news` is refused over any period before the calendar
archive begins, and the error names the date. That is deliberate: the upstream
feed is a rolling window (ADR-15), so running the filter over unarchived
history would apply nothing at all — and the result would look like evidence
that the news filter costs nothing, a conclusion drawn from its absence.

## 3.9 Where the logs are

- `storage/logs/app-YYYY-MM-DD.log` — full fidelity, rotated daily, pruned by
  the cleanup task
- `system_logs` table — the UI-surfaced subset, shown on **System Health**
- `task_runs` table — every scheduled run with its status and output
- `audit_logs` table — who changed what, append-only

Secrets are redacted from the first two. `password`, `token`, `api_key`,
`secret` and `bot_token` never reach a log line.
