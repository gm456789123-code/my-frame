# HANDOFF — Readiness Fix Plan

Living work log for the production-readiness + SEO fixes. **If you're an
agent/human picking this up: read this first, continue from the first
unchecked item, tick items as you finish them, and keep the "Notes" section
current.** Full audit rationale: see "Audit findings" at the bottom.

## How to verify (Windows dev box, no Docker)

```powershell
php -c .phpconf/php.ini vendor/bin/pest
php -c .phpconf/php.ini vendor/bin/phpstan analyse --memory-limit=1G
```

`.phpconf/php.ini` is gitignored; it must load: openssl, mbstring, pdo_mysql,
mysqli, curl, sodium, zip, pdo_sqlite, sqlite3, fileinfo.
MySQL-backed tests skip without Docker — that's expected locally.

## P0 — Security (blockers)

- [x] C1 `.htaccess`: deny dotfiles, `vendor/ src/ var/ db/ tests/ docker/ bin/ scripts/ templates/ .phpconf/`, `*.md`, `composer.*`, `phinx.php`, `*.neon`, `*.xml`, `*.lock`, `*.phar`; only real static files under `public/`/`assets/`/WP dirs pass through
- [x] C2 Web installer: refuse when already installed; only accept POST (see 2026-10-17 note — dropped the `.env`/`SETUP_TOKEN` gate in favor of standard WP-style browser-entered DB credentials)
- [x] C3 Fix `generateWpConfig()` arity in `public/front.php` (pass DB config array from env)
- [x] C4 Escape values written into `wp-config.php` (var_export)
- [x] C5 `WpPageRepository::find()` filter `post_status='publish'` + `post_type IN ('page','post')`; same for `findLatestPublishedId()`
- [x] C6 Distinguish "not installed" (starter page) from "DB error" (503 maintenance, no install button)
- [x] C7 Hide PHP version + wp-admin link unless `APP_ENV=local`
- [x] Add `public/` to `phpstan.neon` paths so front controller is analysed

## P1 — Routing + SEO core

- [x] Lightweight router in `public/front.php` (`src/Presentation/Http/Router.php`): `/`, `/{slug}`, `/robots.txt`, `/sitemap.xml`, `/secure-file/{uuid}`, `/api/v1/auth/csrf-token`; unknown path → real 404 (layout + `noindex`)
- [x] `PageRepositoryInterface::findBySlug()` + Thai-safe slugify (keep Thai chars, rawurlencode in URLs)
- [x] Homepage honours `page_on_front` / `show_on_front` from `wp_options`
- [x] `Page` gets `slug`, `excerpt`, `modifiedAt`; build `SeoData` per page (description from excerpt / first paragraph, canonical = siteUrl + path)
- [x] `layout.latte`: meta description, canonical, robots, OG + Twitter, JSON-LD (WebSite + WebPage/Article), favicon
- [x] `robots.txt` (with Sitemap line) + `sitemap.xml` (published pages, lastmod)
- [x] `ETag` / `Last-Modified` on page responses

## P2 — Wiring + performance

- [x] Security headers middleware: CSP via `CspBridge` (nonce), HSTS (https only), X-Content-Type-Options, X-Frame-Options/frame-ancestors, Referrer-Policy, Permissions-Policy
- [x] Wire `?preview=true` (must verify WP preview nonce/auth — don't just trust the param)
- [x] Replace `cdn.tailwindcss.com` with a prebuilt static CSS file
- [x] Logo → optimized WebP/SVG (<20 KB), real `alt`, width/height
- [x] Image block: add `loading="lazy"`, `decoding="async"`, keep width/height
- [x] Heading block: demote content H1 → H2 (layout owns the single H1)
- [x] Self-host fonts or use system stack

## P3

- [x] Locale routing (`/en/...`) via `RequestLocaleContext` + `hreflang`
- [x] Search page via `MySqlFullTextSearch`
- [x] Upgrade `thecodingmachine/safe` so Psalm runs on PHP 8.4+
- [x] Strict static analysis: Psalm Level 1 (0 errors) & PHPStan Level 9 (0 errors)
- [x] Plugin buffer & conflict shield (`themes/sovereign/functions.php` + `tests/Infrastructure/PluginBufferTest.php`)
- [ ] Run MySQL test suite via docker-compose (environment pending)
- [x] Update `PROJECT_STATUS.md` to reflect real state

## Notes (append as you go)

- 2026-10-15: plan created. P0 Security blockers (C1-C7) completed and verified.
- 2026-10-15: Added `tests/Security/ComprehensivePenetrationTest.php` covering Path Traversal, Magic Byte spoofing, AEAD ciphertext tampering, Merkle chain breakage, SQLi immunity, XSS auto-escaping, and timing attacks.
- 2026-10-15: Added `tests/Security/ExternalOutsiderPenetrationTest.php` covering Web Cache Poisoning, Host Header Injection, malformed parameter DoS, Clickjacking prevention (X-Frame-Options), MIME sniffing protection, and Apache .htaccess sensitive file denial.
- 2026-10-15: P1 completed: Implemented `Router.php` routing `/`, `/{slug}`, `/robots.txt`, `/sitemap.xml`, `/secure-file/{uuid}`, `/api/v1/auth/csrf-token`. Added `findFrontPageId()` honoring `wp_options` `page_on_front`. Extended `Page` entity with `slug`, `excerpt`, `modifiedAt`, and `buildSeoData()`. Upgraded `layout.latte` with Open Graph, Twitter card, canonical, JSON-LD, and robots meta tags. Added HTTP caching `ETag` and `Last-Modified` with 304 Not Modified support.
- 2026-10-15: P2 completed:
  - CspBridge enhanced with nonce generation, strict CSP directives, HSTS on HTTPS, and removal of unsafe-eval/cdn.tailwindcss.com.
  - Authorized preview mode wired in `Router.php` and repositories: unauthenticated ?preview=true requests receive 403 Forbidden; authenticated drafts receive noindex robots tag.
  - Replaced runtime JIT `cdn.tailwindcss.com` (350 KB JS) with lightweight static prebuilt `sovereign.css` (19 KB minified), wired across `front.php`, `layout.latte`, `starter.latte`, and `themes/sovereign/index.php`.
  - Optimized 1.47 MB PNG logo to 7.4 KB WebP (99.5% reduction) and 31.6 KB PNG with fixed width/height and descriptive alt tags.
  - Added `loading="lazy"` and `decoding="async"` to content images in `templates/blocks/image.latte`.
  - Demoted content headings to minimum H2 in `templates/blocks/heading.latte` to protect layout H1.
  - Added zero-latency system font stack fallback.
- 2026-10-15: P3 completed:
  - Upgraded `thecodingmachine/safe` to `^3.0` (v3.4.0) resolving all PHP 8.4 deprecations.
  - Added Psalm Redis stub in `stubs/Redis.stub.php` and cleaned all strict type errors across `Router.php`, `WpPageRepository.php`, `Env.php`, and `RedisTaggedCache.php`.
  - Enforced plugin buffer shielding in `themes/sovereign/functions.php` (XML-RPC disable, pingback removal, unauthenticated REST user enumeration blocking, query soft-delete shielding, theme auto-update collision blocking, and front-end heartbeat deregulation).
  - Test suite: 148 passed (371 assertions, 8 skipped MySQL), PHPStan Level 9: 0 errors across 78 files, Psalm Level 1: 0 errors (99.95% type inference).
- 2026-10-17: Fixed `.env` never being loaded by the web entrypoint — `Env::loadFile()` existed but nothing called it from `public/front.php` (only `bin/console` had its own crude duplicate parser). Added `Env::loadFile($projectRoot . '/.env')` to `front.php`; `bin/console` now calls the same shared loader instead of duplicating the logic.
- 2026-10-17: Reworked the installer gate after feedback that real WP hosting never ships a `.env` and beginners shouldn't have to create/edit one. Replaced the `SETUP_TOKEN` requirement with WordPress's own model: install state is now detected purely from whether `wp-config.php` exists on disk (`SmartBootstrapper::detectEnvironment()['configExists']`), never from env vars. `public/front.php` now `require_once`s `wp-config.php` when present so `WpdbConnection` picks up its `DB_*` constants directly (previously dead code — the file was generated but never loaded back in). The install form collects DB host/name/user/password in the browser (like `wp-admin/setup-config.php`) only when no `wp-config.php` exists yet; if config exists but tables are missing (interrupted install), the form resumes without re-asking for credentials. "Already installed" is still gated on `wp_posts` existing, same as `is_blog_installed()`.
- 2026-10-17: Security audit round against automated-scanner-style attacks (WPScan/dirbuster/nikto patterns) and the installer rewrite above. Found and fixed:
  - **Auth bypass (HIGH)**: `Router::isAuthorizedPreview()` trusted a `wordpress_logged_in_*` cookie by its name/shape alone (unsigned expiry timestamp, no HMAC check) — any unauthenticated request could forge this cookie and read draft/private/scheduled page content. Removed the cookie-trust path entirely; preview access now requires only the already-correct signed `preview_token`/`_wpnonce` (HMAC via `APP_KEY`). `RouterTest.php`'s matching test was itself asserting the vulnerable behavior as "working as intended" — flipped to assert 403, added a new test for the valid signed-token path.
  - **Info disclosure (HIGH)**: `MySqlFullTextSearch::search()` was the only repository query missing `post_status = 'publish' AND post_type IN ('page','post')` — public `/search` and `/api/v1/search` could surface draft content to anonymous users. Added the filter; added `tests/Infrastructure/MySqlFullTextSearchTest.php` (MySQL-skip pattern) to lock it in.
  - **`.htaccess` nested-path gap**: the sensitive-extension denylist (`^[^/]+\.(...)$`) only matched files directly in the project root — `wp-content/debug.log`, nested `.sql`/`.bak` backups, and `readme.html` (classic WPScan version-fingerprint target) all fell through un-blocked. Removed the root-only anchor so the rule applies at any depth, added `html`/`swp`/`swo`/`~`/`.orig` to the denied set, and added a dedicated rule blocking PHP execution (including double-extension disguises like `shell.php.jpg`) under `wp-content/uploads/` — the standard WP RCE-escalation path if any upload flaw ever lands a file there. Extended `ExternalOutsiderPenetrationTest.php` with the new scanner-favorite paths plus a "legit uploads still serve" counter-test.
  - **Fixed as a follow-up**: the installer CSRF gap noted above is closed. Added `src/Infrastructure/Security/InstallCsrfGuard.php` (double-submit cookie pattern — a random token set as both an httponly `SameSite=Strict` cookie and a hidden form field whenever the install page renders; the POST handler in `public/front.php` rejects the request with 403 unless the two match via `hash_equals`). No server-side session needed (none exists pre-install) and nothing the admin creates by hand, consistent with the earlier decision to drop `.env`/`SETUP_TOKEN`. Unit tested directly (`tests/Infrastructure/InstallCsrfGuardTest.php`, 7 cases) since the class takes cookie/posted-field arrays as parameters rather than reading superglobals itself.
- 2026-10-17: Deeper manual audit (the kind of grep-the-public-source pass a skilled bug-bounty researcher would do, not automated-scanner patterns) found and fixed two more real issues:
  - **CRITICAL — hardcoded fallback `APP_KEY` shipped in public source**: `public/front.php`, `src/Presentation/Cli/LazyAuditLogger.php`, and `src/Presentation/Http/Router.php` all had `Env::string('APP_KEY', '<literal default>')`. Since this is an open-source repo, that literal is public — anyone could forge signed `/secure-file/{uuid}` download URLs (`SignedUrlGenerator`) for the sensitive vault, or recompute valid-looking HMACs for a tampered Merkle audit-log entry so `bin/console audit:verify` falsely reports "chain intact" (confirmed `verifyChain()` is wired to that real CLI command, not dead code). Worse: until this session's earlier `.env`-loading fix, `public/front.php` never read `.env` at all, so essentially every deployment ran on the public default by construction. Fixed by adding `Env::appKey()` (`src/Infrastructure/Env.php`) with **no hardcoded fallback** — it reads an `APP_KEY` constant from `wp-config.php` first (now generated fresh per install by `SmartBootstrapper::generateWpConfig()`, same as the WordPress salts), falling back to `.env`'s `APP_KEY` for local/Docker dev only, and finally `''`. All three call sites now fail closed on `''` instead of signing with a known key: `front.php` only constructs `CsrfController`/`SecureFileController` when a real key exists (otherwise `Router`'s existing `null`-service 503 path kicks in). Locked in with `tests/Infrastructure/SmartBootstrapperTest.php`'s new "generates a fresh random APP_KEY per install" case.
  - **MEDIUM-HIGH — truncated HMAC accepted for preview tokens**: `Router::isAuthorizedPreview()` accepted either the full 256-bit HMAC *or* just its first 10 hex characters (40 bits) as a valid `preview_token`/`_wpnonce`. Nothing in the codebase ever generated the shortened form — pure unnecessary attack surface with no rate limiting anywhere to slow a brute-force attempt against the 40-bit variant. Removed the truncated-prefix acceptance; full HMAC comparison only.
  - **Noted, not fixed (infra-dependent, lower confidence)**: `provisionDatabase()`'s admin email defaults to `'admin@' . parse_url($siteUrl, PHP_URL_HOST)`, and `$siteUrl` falls back to the request's `Host` header when `APP_URL` isn't configured. On a server with a misconfigured catch-all/default vhost, a forged `Host` header on the first install request could make the newly created admin account's email point at a domain the attacker controls, enabling account takeover via WordPress's standard lost-password flow. Requires a specific server misconfiguration in addition to the app's own behavior, so flagged for awareness rather than fixed immediately.
  - **Investigated and ruled out**: considered whether `UuidV7`'s leaked 48-bit millisecond timestamp (vault file identifiers) narrows brute-force search space. It doesn't — each millisecond bucket still has ~74 bits of true randomness, so knowing the upload time window doesn't reduce the random portion's effective entropy. Not a real finding.
- 2026-10-17: Closed the two accepted-but-open risks from the installer trust-model discussion (DoS/rate-limiting was explicitly out of scope for the earlier reviews; revisited on request):
  - Added `src/Infrastructure/Security/RateLimiter.php` — fixed-window, file-based (no Redis dependency, since Redis is optional/fail-silent elsewhere in this app and a rate limiter must not inherit that fail-open behavior; it fails *closed* — refuses — if its storage directory is unwritable). `flock()`-serialized read-modify-write per key, same concurrency concern `MerkleAuditLogger` solves with MySQL's `GET_LOCK`. 5 unit tests (`tests/Infrastructure/RateLimiterTest.php`).
  - `public/front.php`'s `?action=install` POST handler now: (1) rate-limits by IP (5 attempts / 10 min) before any other work — the actual mitigation for a "bump script" racing a human to be first through the installer, since the CSRF token alone doesn't stop a single attacker replaying their own valid token rapidly; (2) acquires a non-blocking `flock()` (`var/install.lock`) around the whole install critical section, so a burst of concurrent requests can't race each other into corrupting `wp-config.php` or calling `wp_install()` concurrently — a losing request gets an immediate 409 instead of hanging behind a WordPress-core download that can take up to 180s.
  - `Router::dispatch()` now rate-limits `/search` and `/api/v1/search` (30 req/min per IP) the same way — nothing previously limited how many MySQL FULLTEXT queries an anonymous caller could trigger. `Router` takes an optional `?RateLimiter` (null-safe, same pattern as `?SecureFileController`); wired from `front.php`.
  - Explicitly **not** solved (can't be, without reintroducing the `.env`/`SETUP_TOKEN` friction already rejected): a sufficiently fast single attacker who is simply first to the install URL still wins, same as stock WordPress/Softaculous. Rate limiting stops the *repeated-attempt* pattern a bump script relies on; it doesn't change who wins a single clean race. Documented as an accepted trade-off, not a gap anyone missed.
  - Test suite: 180 passed (456 assertions, 10 skipped MySQL), PHPStan Level 9: 0 errors (80 files), Psalm Level 1: 0 errors.
- 2026-10-17: Added Yoast SEO plugin compatibility. The public front controller deliberately never boots WordPress (see `public/front.php`'s own top comment), so SEO plugins' `wp_head`/`the_content` hooks never fire on public pages -- a site owner using Yoast would see zero effect from it on their actual rendered pages. Fixed by reading Yoast's own `wp_postmeta` directly instead of loading WP:
  - `Page` (`src/Domain/Model/Page.php`) gained `?string $seoTitleOverride`/`$seoDescriptionOverride`/`$seoOgImageOverride`, used by `buildSeoData()` ahead of the auto-generated title/excerpt-derived description if set. Canonical URL and hreflangs deliberately still come from this app's own routing, not Yoast's, since Yoast's canonical assumes stock WP permalinks that don't match this app's slug-based routes.
  - `WpPageRepository` (`find()`, `findForPreview()`, `findBySlug()`) now share a `hydratePage()` helper (also removed ~60 lines of triplicated row-mapping code) that calls a new `fetchSeoPluginOverrides()` reading `_yoast_wpseo_title`/`_yoast_wpseo_metadesc`/`_yoast_wpseo_opengraph-image` from `wp_postmeta` for the current post id — one extra indexed query per page, gracefully returns nulls (auto-generate as before) if Yoast isn't installed or `wp_postmeta` is missing.
  - RankMath/AIOSEO support can follow the identical shape later (their own meta key names in the same `IN (...)` list) — scoped to Yoast only for this pass per explicit choice.
  - Tests: `tests/Infrastructure/WpPageRepositoryTest.php` gained two MySQL-skip cases (Yoast meta present → overrides used; absent → falls back to auto-generated SEO, confirmed via `php -l` + full suite since Docker/MySQL is still unavailable in this dev environment).
- 2026-10-17 (still open): caching-plugin compatibility not yet addressed. Preliminary read: classic page-cache plugins (WP Super Cache, W3TC, WP Rocket) hook into WP's render path, which this app never loads for public pages — so they're inert on our fast path already (nothing to "not conflict" with on the rendering side). The real risk is `.htaccess`: several of these plugins auto-insert their own rule blocks (e.g. `# BEGIN W3TC Page Cache`) into the root `.htaccess`, which could land before/interleave with the rules added this session. Needs verification once a real caching plugin is installed against this `.htaccess` to confirm ordering/markers don't collide.

## Audit findings (summary)

Core domain/crypto code is solid and tested. The public HTTP entrypoint is
not: `.htaccess` exposes `.env`; the web installer is unauthenticated, never
disables itself and calls `generateWpConfig()` with wrong arity; `?page_id=`
serves drafts/revisions; any exception shows the install button. Several
blueprint features exist as classes but aren't routed (secure-file, csrf
endpoint, CSP, preview, i18n, search). SEO: no pretty URLs, every unknown
path returns 200 with the latest page (duplicate content/soft-404), no meta
description/canonical/OG/JSON-LD/hreflang/sitemap/robots, Tailwind CDN +
1.47 MB PNG logo hurt Core Web Vitals.
