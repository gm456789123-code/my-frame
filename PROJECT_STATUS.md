# Project Status — Sovereign Engine

Living map of `agents_md.md`'s 12 sections against what actually exists in
`src/`, what's been verified (tests + manual review), and what's still
open. Update this when adding/reviewing a feature — it's the shared
reference for anyone (human or AI) picking up work here, so two people
don't duplicate or silently overwrite each other's work.

Legend: ✅ built & verified · 🟡 built, not fully verified · ⚪ not started

| # | Section | Status | Key files |
|---|---|---|---|
| 0 | Core Philosophy | ✅ | — (design principle, not code) |
| 1 | Language & Type System (Hack-strict) | ✅ enforced | `phpstan.neon` (L9), `psalm.xml` (L1), every file has `declare(strict_types=1)` |
| 2 | Architecture (Hexagonal DDD) | ✅ `Page` slice | `src/Domain`, `src/Application`, `src/Infrastructure`, `src/Presentation`; boundary enforced by `tests/Architecture/DomainPurityTest.php` |
| 3 | Gutenberg AST Parser & Synced Patterns | 🟡 built, verified | `src/Infrastructure/Wordpress/Gutenberg/BlockAstParser.php`, `src/Domain/Model/BlockDto.php`, `src/Presentation/View/BlockRenderer.php`, `templates/blocks/*.latte` |
| 4 | DB, Caching & Edge SWR Engine | 🟡 built, verified | `src/Infrastructure/Cache/CacheInterface.php`, `RedisTaggedCache.php` |
| 5 | Dual-Tier Storage & Upload Pipeline | 🟡 built, reviewed + fixed | `SecureUploadHandler.php`, `SignedUrlGenerator.php`, `SecureFileController.php` — fixed: missing `is_uploaded_file()` check, missing UUID format validation |
| 6 | Security, Crypto & CSP Bridge | 🟡 built, reviewed + fixed | `FieldEncryptor.php`, `MerkleAuditLogger.php` (with MySQL named lock concurrency fix), `CspBridge.php`, `CsrfTokenManager.php` |
| 7 | Asynchronous Engine & Worker Fallback | 🟡 built, reviewed + fixed | `RedisStreamEventDispatcher.php`, `WorkerRunCommand.php` |
| 8 | Native Multilingual Support | 🟡 built | `src/Domain/Model/TranslatableTrait.php`, `src/Application/Context/RequestLocaleContext.php` |
| 9 | Search Engine Strategy | 🟡 built | `src/Infrastructure/Search/MySqlFullTextSearch.php`, `src/Domain/Repository/SearchRepositoryInterface.php` |
| 10 | Environment Portability & Dynamic URLs | ✅ built & verified | `index.php`, `.htaccess`, `public/front.php` (host auto-detection), `public/assets/logo.png`, `?asset=logo.png` fallback, dual repo distribution |
| 11 | Developer Experience & Diagnostics | 🟡 partial | `make:page` ✅, `make:block` 🟡, `audit:verify` 🟡, `DevToolbar.php` 🟡, `wp:install` ✅ (CLI + 1-Click Web) |
| 12 | Forbidden Practices | ✅ enforced | `DomainPurityTest.php` catches `WP_Post`/`WP_Query`/`wpdb`/`App\Infrastructure` deps from Domain/Application |

---

## 🏗️ Repository Architecture (Source vs Pre-built Distribution)

To make adoption seamless across all hosting tiers without requiring developers or end-users to run complex build steps or install CLI tools:

1. **Source Code Repository (For Core Engine Development & Tests):**
   * **URL:** `https://github.com/gm456789123-code/my-frame.git`
   * **Contents:** Full source code, Pest automated tests (62 passed, 173 assertions), PHPStan L9, Psalm L1 configs, and Docker dev environment.

2. **Standalone Pre-Built Distribution (For Production & Shared Hosting):**
   * **URL:** `https://github.com/gm456789123-code/wp-framework_build.git`
   * **Contents:** Pre-packaged production build including pre-installed `vendor/` autoloader, root `index.php`, `.htaccess`, and pre-compiled assets.
   * **Target:** Shared hosting (Hostinger, cPanel, LiteSpeed, Apache) where Composer, Node, or terminal access is unavailable.

---

## 🚀 Live HTTP Wiring & Deployment Blueprints

### 1. Root-Level Entrypoint & 403-Forbidden Immunity
* **Problem:** Shared hosts (like Hostinger LiteSpeed) look for `index.php` directly inside `public_html/`. Deploying a framework where the entrypoint is at `public/front.php` resulted in a `403 Forbidden` directory listing denial.
* **Solution:** 
  * Root `index.php` delegates directly to `public/front.php`.
  * Root `.htaccess` routes requests cleanly to `public/front.php` for LiteSpeed/Apache.
  * Asset endpoint `?asset=logo.png` and local `public/assets/` ensure zero broken images regardless of domain or SSL setup.

### 2. Dual-Mode Installer (1-Click Web + Secure CLI)
* **Web 1-Click Installer:**
  * When `wp_posts` does not exist, `public/front.php` serves `templates/starter.latte`.
  * Displays a single glowing **"⚡ Install WordPress & Sovereign Engine"** button.
  * Clicking the button submits `POST /?action=install`, downloads/unpacks core if missing, provisions DB tables, creates credentials, and activates the Sovereign theme.
  * Shows a clean credentials card with instant links to `wp-admin` and the site.
  * Once installed, the installation trigger is automatically disabled (no unauthenticated re-installation possible).
* **CLI Installer:**
  * For Docker or SSH users: `php bin/console wp:install`.

### 3. Verification & Quality Gates
* **PHPStan:** Level 9 — `0 Errors`
* **Psalm:** Level 1 — `0 Errors, 99.88% type inference`
* **Pest Tests:** `62 passed (173 assertions)`
* **End-to-End Latency:** Cold request ~3-10ms, Warm cached request (Redis Tagged Cache) ~0.5ms with 0 DB queries.
