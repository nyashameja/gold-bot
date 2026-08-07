# paragon/php-core

The framework-free PHP 8.3 kernel behind Paragon's applications: a container, a
router, a PDO wrapper, a template renderer, and the small set of infrastructure
ports that everything else is written against.

It exists because Gold Bot and NexusDesk are the same shape of application —
PHP on cPanel, no Node, no Laravel — and were on course to grow two copies of
the same container that would drift apart within a year. See ADR-02 in Gold
Bot's `docs/00-DECISIONS-AND-NAMING.md`.

## What is in it

| Namespace | What it is |
|---|---|
| `Paragon\Core` | `Application`, `Container`, `Config`, `Env`, `Database`, `ErrorHandler`, `Router`, `Route`, `Request`, `Response`, `JsonResponse`, `RedirectResponse`, `HttpException`, `View`, `Controller`, `MiddlewareInterface` |
| `Paragon\Core\Clock` | `ClockInterface`, `SystemClock`, `FrozenClock` |
| `Paragon\Core\Cache` | `CacheInterface`, `FileCache`, `ApcuCache` |
| `Paragon\Core\Lock` | `LockInterface`, `MySqlNamedLock` |
| `Paragon\Core\Logging` | `LoggerInterface`, `LogLevel`, `FileLogger` |
| `Paragon\Core\Session` | `DatabaseSessionHandler` |
| `Paragon\Core\Http` | `HttpClient`, `HttpResponse` |
| `Paragon\Core\Support` | `Uuid`, `Encryption`, `Csrf` |
| *(global)* | `app()`, `config()`, `base_path()`, `storage_path()`, `e()`, `array_get()`, `str_snake()`, `str_studly()` |

## What is deliberately not in it

Anything that knows what the application *is*. The line was drawn at knowledge
of a schema or a domain concept:

- **`ApiBudget`** stayed in Gold Bot. It reads `api_providers` and writes
  `api_usage_log` — it is a service about a specific pair of tables, not a
  kernel component, however much its name sounds like plumbing.
- **`Controller`'s knowledge of a user.** The kernel's `Controller` takes a
  `View` and nothing else. Gold Bot's subclass adds `AuthService` and puts the
  signed-in user into every render. A kernel that knows what a user is has
  stopped being a kernel.
- **`SecurityHeaders` and the rest of the middleware.** `MiddlewareInterface`
  is kernel — it is the router's contract. The implementations are not:
  Gold Bot's CSP names TradingView.
- **Migrations, repositories, domain, strategies, tasks.** All Gold Bot.

## Conventions it does impose

The kernel is small but not opinion-free. `Application::create($basePath)`
expects, relative to that base path:

- `config/` — one PHP file per section, each returning an array; `Config` loads
  them all and keys them by filename.
- `config/services.php` — returns `static function (Container, Config, Application): void`
  and registers every binding. This is the only file that names a concrete
  implementation.
- `.env` — optional. Absent is legitimate (cPanel and CI inject variables
  directly); a *missing required value* still fails loudly at first use through
  `Env::require()`.
- `storage/` — writable, for logs and the file cache.

## Testing

The kernel's tests live in `tests/` and run two ways. Day to day they run as
part of the consuming application's Unit suite, because the package is consumed
from a path repository and a kernel change has to break the build it landed in.
Standalone:

```
composer install
vendor/bin/phpunit
```

## How it is consumed today, and how to split it out

ADR-02 calls for a private VCS repository with Gold Bot pinning a version. What
is committed today is the step before that: the package lives at
`packages/php-core` inside the Gold Bot repository and is consumed through a
Composer **path** repository. Every import, namespace and autoload rule is
already what it will be in the split repository, so the migration is mechanical
and needs no code change:

```bash
# 1. Split the package's history out, preserving its commits.
git subtree split --prefix=packages/php-core -b php-core-split

# 2. Push that branch to the new private repository.
git push git@github.com:<owner>/php-core.git php-core-split:main

# 3. In Gold Bot's composer.json, swap the path repository for a vcs one
#    and pin a version instead of @dev:
#      {"type": "vcs", "url": "git@github.com:<owner>/php-core.git"}
#      "paragon/php-core": "^1.0"

# 4. Tag a release in the new repository, then:
composer update paragon/php-core

# 5. Remove packages/php-core from Gold Bot, and drop the second <directory>
#    from the Unit testsuite in phpunit.xml.
```

Until step 3, the pinning that ADR-02 is *for* — a kernel change never breaking
Gold Bot unannounced — does not yet apply: a change here takes effect
immediately. That is a deliberate trade for the extraction phase, not the end
state.
