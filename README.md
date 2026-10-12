# wp-framework — Sovereign Engine

A headless, Hexagonal-DDD PHP engine on top of WordPress. WordPress is used
strictly as a data store and admin dashboard (`wp-admin` + the Gutenberg
editor); the public-facing site is rendered entirely by this codebase, never
by a WordPress theme (see `agents_md.md` for the full target spec).

This repository currently implements **Milestone 1: Foundation** — the
Hexagonal DDD skeleton, tooling, and one vertical slice (`Page`) proving the
architecture end-to-end, verified against a real dockerized WordPress +
MySQL stack. Later milestones (Gutenberg AST parsing, Redis/SWR caching,
dual-tier storage, field encryption + audit log, async engine, i18n, search,
DX tooling) are not implemented yet — `wp-admin` currently serves the
default WordPress theme, since the frontend bypass is section 3's work.

## Architecture

```
src/
├── Domain/           Pure business rules. Never imports wpdb/WP_*/WordPress functions.
├── Application/       Use cases (Command/Query handlers, Input/Output DTOs).
├── Infrastructure/    Adapters — WordPress ($wpdb-equivalent via PDO), events, storage, cache.
└── Presentation/       CLI commands, Latte views, HTTP controllers (later milestones).
```

The Domain/Application purity rule is enforced automatically by
`tests/Architecture/DomainPurityTest.php` (Pest + pest-plugin-arch, plus a
source scan for WordPress globals that the arch plugin can't otherwise see
— it only matches dependencies that resolve to an actual project file).

Domain events (`PageCreated`, `PageDeleted`) are dispatched through
`DomainEventDispatcherInterface` (a Domain-owned port). The only adapter
today is `SynchronousEventDispatcher`, an in-process listener registry —
section 7's Redis Streams producer will wrap it as a synchronous fallback
for when Redis/the worker is unavailable.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `openssl` extensions
- Docker (for local MySQL/Redis/WordPress/Nginx) — or point `.env` at your own MySQL

## Setup

```bash
cp .env.example .env

# Local infrastructure: MySQL, Redis, WordPress (PHP-FPM), Nginx in front of
# it (the wordpress:*-fpm image only speaks FastCGI, it has no web server of
# its own), and a PHP CLI container (app) for running bin/console & friends.
docker compose up -d

# Install PHP dependencies (via the bundled Composer container)
docker compose --profile tools run --rm composer install

# Install WordPress headlessly so wp_posts exists before migrating
docker compose --profile tools run --rm wpcli core install \
    --url=http://localhost:8080 --title="Sovereign Engine" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com

# Run migrations (adds deleted_at/deleted_by/locale to wp_posts)
docker compose exec app php bin/console db:migrate
```

The site is then reachable at http://localhost:8080 (wp-admin included).

If you prefer running PHP natively instead of in Docker, install Composer
locally and point the `DB_*` vars in `.env` at your own MySQL instance; the
`app`/`composer`/`wpcli`/`nginx` Compose services are conveniences, not
requirements.

### Why `mysql`'s `command` overrides `sql_mode`

WordPress's own schema (e.g. `wp_posts.post_date` defaulting to
`'0000-00-00 00:00:00'`) is incompatible with MySQL 8's default strict
`sql_mode` (`NO_ZERO_DATE`/`STRICT_TRANS_TABLES`). `wp core install` itself
works fine because it only ever `INSERT`s real dates, but any later `ALTER
TABLE wp_posts` (including the migration below) re-validates every column's
default against the current `sql_mode` and fails on `post_date`, even though
the migration never touches that column. `docker-compose.yml` starts MySQL
with `--sql-mode=NO_ENGINE_SUBSTITUTION` to avoid this — the same fix most
WordPress hosts apply.

### Why Composer pins `config.platform.php`

Resolving dependencies with whatever PHP happens to run Composer (the
`composer:2` image currently ships PHP 8.5, newer than our PHP 8.3 runtime
images) can lock in packages that don't support our actual deploy target.
`composer.json` pins `config.platform.php` to `8.3.0` so dependency
resolution always targets the runtime we actually deploy to, regardless of
which PHP happens to execute `composer` itself.

## Verification

```bash
docker compose exec app php vendor/bin/phpstan analyse   # level 9, must be 0 errors
docker compose exec app php vendor/bin/psalm             # level 1, must be 0 errors
docker compose exec app php vendor/bin/pest               # includes the architecture guardrail
```

(Or run the same `vendor/bin/...` commands directly on the host if you set
up PHP/Composer natively instead of via Docker.)

`tests/Infrastructure/WpPageRepositoryTest.php` needs a reachable, migrated
MySQL — it skips itself with a clear message instead of failing when that's
not available (e.g. running `pest` directly without Docker up).

## Using the Page vertical slice

```bash
docker compose exec app php bin/console page:create "Hello World" "<p>First page</p>" --locale=th
docker compose exec app php bin/console page:show <id>
docker compose exec app php bin/console page:delete <id> --deleted-by=1   # soft delete only
```

Scaffold a new page type (Entity, Repository interface/impl, Input DTO, Latte view):

```bash
docker compose exec app php bin/console make:page Event
```

## License

MIT — see [LICENSE](LICENSE).
