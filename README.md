# wp-framework — Sovereign Engine

A headless, Hexagonal-DDD PHP engine on top of WordPress. WordPress is used
strictly as a data store and admin dashboard (`wp-admin` + the Gutenberg
editor); the public-facing site is rendered entirely by this codebase, never
by a WordPress theme (see `agents_md.md` for the full target spec).

This repository currently implements **Milestone 1: Foundation** — the
Hexagonal DDD skeleton, tooling, and one vertical slice (`Page`) proving the
architecture end-to-end. Later milestones (Gutenberg AST parsing, Redis/SWR
caching, dual-tier storage, field encryption + audit log, async engine,
i18n, search, DX tooling) are not implemented yet.

## Architecture

```
src/
├── Domain/           Pure business rules. Never imports wpdb/WP_*/WordPress functions.
├── Application/       Use cases (Command/Query handlers, Input/Output DTOs).
├── Infrastructure/    Adapters — WordPress ($wpdb-equivalent via PDO), storage, cache.
└── Presentation/       CLI commands, Latte views, HTTP controllers (later milestones).
```

The Domain/Application purity rule is enforced automatically by
`tests/Architecture/DomainPurityTest.php` (Pest + pest-plugin-arch, plus a
source scan for WordPress globals that the arch plugin can't otherwise see).

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `openssl` extensions
- Docker (for local MySQL/Redis/WordPress) — or point `.env` at your own MySQL

## Setup

```bash
cp .env.example .env

# Local infrastructure (MySQL, Redis, WordPress, a PHP CLI container)
docker compose up -d

# Install PHP dependencies (via the bundled Composer container)
docker compose --profile tools run --rm composer install

# Install WordPress headlessly so wp_posts exists before migrating
docker compose --profile tools run --rm wpcli core install \
    --url=http://localhost:8080 --title="Sovereign Engine" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com

# Run migrations (adds deleted_at/deleted_by/locale to wp_posts)
docker compose run --rm app php bin/console db:migrate
```

If you prefer running PHP natively instead of in Docker, install Composer
locally and point the `DB_*` vars in `.env` at your own MySQL instance; the
`app`/`composer`/`wpcli` Compose services are conveniences, not requirements.

## Verification

```bash
composer install
composer stan    # PHPStan level 9, must be 0 errors
composer psalm   # Psalm level 1, must be 0 errors
composer test    # Pest, including the architecture guardrail
```

Scaffold a new page type:

```bash
bin/console make:page Event
```

## License

MIT — see [LICENSE](LICENSE).
