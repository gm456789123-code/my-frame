# AGENTS.md v5.5 - The Production Sovereign Engine

# TARGET: Enterprise / ISO 27001 & OWASP ASVS L3 Ready / Zero WP Bloat

# PURPOSE: High-Performance Autonomous WordPress Engine for Production

# COMPATIBILITY: WordPress 6.5+ (Headless Admin) | PHP 8.5+ | FrankenPHP & PHP-FPM

# ================================================================================
0. CORE PHILOSOPHY

* WordPress is an unmanaged headless data store and admin dashboard only.

* Domain logic must remain 100% pure PHP with zero WordPress coupling.

* Every state mutation is immutable, soft-deleted, and cryptographically verified.

* User requests are never blocked by logging, cache sync, or audits (Async by default).

* Zero runtime overhead: Native Block Bindings, Zero-JS/Micro-JS Frontend, Sub-millisecond cache hits.

* Resilience first: Fall back gracefully if daemons or Redis are offline.

* Ergonomics for non-tech users: Self-explanatory visual diagnostics for marketing and admin teams.

================================================================================

1. LANGUAGE & TYPE SYSTEM (HACK STRICT)
   ================================================================================

* Runtime: PHP 8.5+ strictly typed.

* File Header: declare(strict_types=1); on every single PHP file without exception.

* Class Modifiers: final by default, readonly for all Entities, DTOs, and Value Objects.

* Type Safety:

  * Strict avoidance of `mixed`, untyped arrays, `object`, `global`, `extract()`, `eval()`, and `unserialize()`.

  * Safe functions only: Use `thecodingmachine/safe` for all standard library calls.

  * Scalar types must be wrapped in Domain Value Objects:

    * PostId, UserId, TenantId, Email, DocumentUuid, ClassificationLevel, Locale ('th'|'en').

* Statuses & Categories: Backed String Enums exclusively.

* Static Analysis Target: PHPStan Level 9 + Psalm Level 1 with 0 errors and 0 suppressions.

# ================================================================================
2. ARCHITECTURE (HEXAGONAL DDD & SEPARATION)

Directory Structure:
src/
├── Domain/                       # Pure Business Rules (Zero External Dependencies)
│   ├── Model/                    # Final readonly domain entities
│   ├── ValueObject/              # Immutable typed primitives (PostId, Locale, SeoData)
│   ├── Event/                    # Pure Domain Events (PostCreated, PostDeleted)
│   └── Repository/               # Pure interfaces (PostRepositoryInterface)
├── Application/                  # Orchestration Layer
│   ├── UseCase/                  # Command & Query handlers (Input DTO -> Output DTO)
│   └── Security/                 # ABAC / RBAC Policy enforcement
├── Infrastructure/               # External World Adapters
│   ├── Wordpress/                # \$wpdb queries, hooks, Block Bindings integration ONLY
│   ├── Storage/                  # Dual-tier storage adapters (Public S3 vs Private Vault)
│   ├── Cache/                    # Redis Relay, SWR Engine, Cache-Tags
│   ├── Async/                    # Redis Streams producer & consumer daemons
│   └── Security/                 # Sodium Field Encryption, Hash-Chained Audit Logger
└── Presentation/                 # Delivery Mechanism
├── Http/                     # Controllers, Custom Middlewares, API routes
├── View/                     # Latte 3.x templates and layout presenters
└── Cli/                      # Console commands (bin/console)

Architectural Guardrails:

* Domain layer must NEVER import `wpdb`, `WP_Post`, `WP_Query`, or any procedural WP functions.

* Enforced via automated Pest Architecture tests during build pipelines.

# ================================================================================ 3. GUTENBERG AST PARSER & SYNCED PATTERNS (NO ACF)

* Core Rule: Never call `do_blocks()` on frontend templates.

* Content Ingestion Pipeline:

  * Parse raw `post_content` using custom `BlockAstParser`.

  * Maps core blocks (headings, paragraphs, columns, media) into clean typed `BlockDto` arrays.

  * Render pure Latte 3.x templates (`templates/blocks/*.latte`) with Tailwind 4 classes.

* Zero WP CSS injection: Disable `wp-block-library.css` and all inline Gutenberg CSS.

* Eager Pre-Resolution for Synced Patterns (Reusable Blocks):

  * Pass 1: Scan block AST for all reusable references `<!-- wp:block {"ref":123} -->`.

  * Single Query Batch: `SELECT ID, post_content FROM wp_posts WHERE ID IN (...)`.

  * Injects pre-resolved pattern trees into memory before template rendering to guarantee zero N+1 queries.

# ================================================================================ 4. DATABASE, CACHING & EDGE SWR ENGINE

* Performance Budget:

  * Warm Request (Cache Hit): Exactly 0 database queries (served via SWR Edge / Redis).

  * Cold Request (Cache Miss): Strictly <= 3 targeted queries.

  * TTFB: < 90ms (PHP-FPM) / < 20ms (FrankenPHP Worker Mode).

* Cache Layers:

  * L1: OPCache + JIT + Preload.

  * L2: Redis Relay (In-Memory PHP extension) with Tagged Keys (`{tenant}:{entity}:{id}`).

  * L3: Cloudflare Edge Cache-Tags with Stale-While-Revalidate (SWR).

* Decoupled CSRF Nonce Hydration:

  * Forms do NOT embed static inline nonces in cached HTML.

  * Client scripts fetch dynamic tokens via asynchronous call (`GET /api/v1/auth/csrf-token`) on user interaction.

* Draft & Preview Hydration:

  * Requests with `?preview=true` authenticated via WordPress Preview Nonces bypass L2/L3 caches completely.

  * Repositories hydrate directly from draft records or `auto-draft` revisions.

* Data Retention:

  * HARD DELETE IS FORBIDDEN. All mutations use Soft Delete: `deleted_at`, `deleted_by`.

  * Every repository read automatically appends `WHERE deleted_at IS NULL`.

  * Schema managed via declarative migration files (Phinx/Atlas). No `dbDelta()`.

# ================================================================================ 5. DUAL-TIER STORAGE & UPLOAD PIPELINE

* Tier A: Public Assets (Featured images, banners, public media):

  * Stored in public storage/S3.

  * Auto-converted to WebP/AVIF.

  * Served directly by CDN/Nginx with long cache headers.

* Tier B: Sensitive Vault (Citizen documents, PDFs, payment slips, private attachments):

  * Stored strictly outside web root (`/var/data/vault_private/`).

  * Delivered exclusively via PHP Controller through HMAC-SHA256 Signed URLs.

  * Signed URL format: `/secure-file/{uuid}?sig={hash}&exp={timestamp}` (Max TTL: 10 minutes).

* Validation:

  * Direct access to `$_FILES` is prohibited. Must pass through `SecureUploadHandler`.

  * Strict Magic Bytes check via `finfo(FILEINFO_MIME_TYPE)`. File extensions are untrusted.

  * Vault files renamed to UUID v7 immediately; original filenames stored as encrypted metadata.

# ================================================================================ 6. SECURITY, CRYPTOGRAPHY & MARKETING CSP BRIDGE

* Field-Level Encryption at Rest (NIST SP 800-53 / ISO 27001):

  * All PII (CitizenId, Phone, Private Notes) encrypted before saving to MySQL.

  * Cipher: XChaCha20-Poly1305 via `sodium_crypto_secretbox`.

  * Encrypted format: `enc:v1:{base64_ciphertext_and_nonce}`.

* Tamper-Proof Audit Trail (NIST AU-9):

  * Table `wp_audit_log` implements Merkle Hash Chaining: `current_hash = HMAC_SHA256(prev_hash + user_id + action + object_id + payload_json + created_at, secret_key)`

  * Any row deletion or manual DB alteration breaks the chain immediately.

* Marketing CSP Bridge & Visual Diagnostics:

  * Dynamic cryptographically random nonces injected into whitelisted analytics scripts (GTM, GA4).

  * Diagnostic Observer: When a third-party script (Pixel, TikTok, Hotjar) is blocked by CSP:

    * If user is logged in as Admin/Editor or in Preview mode: Display an in-browser diagnostic modal explaining the blocked domain, with a single-click copy button and direct link to the Admin CSP Whitelist settings.

    * If public visitor: Violations logged quietly to `/api/v1/telemetry/csp-reports`.

# ================================================================================ 7. ASYNCHRONOUS ENGINE & WORKER FALLBACK

* Domain Events: Life-cycle operations emit pure events to Redis Streams (`stream:domain_events`).

* Resilient Circuit Breaker (Sync Fallback):

  * If Redis connection fails OR CLI worker heartbeat is older than 60 seconds:

  * Auto-switch to Synchronous Transactional Execution immediately.

  * Guarantees 0% data loss for Audit Logs on budget VPS or unmanaged hosting.

* Background Daemon: `bin/console worker:run` processes audit logs, search indexing, and webhook delivery.

# ================================================================================ 8. NATIVE MULTILINGUAL SUPPORT (TH/EN)

* Banned: WPML, Polylang, and TranslatePress plugins.

* Architecture:

  * Entities implement `TranslatableTrait` with explicit `Locale` Value Object (`th` or `en`).

  * Translations linked via shared `translation_group_id` (UUID v7).

  * Repository automatically applies locale filtering via `RequestLocaleContext`.

# ================================================================================ 9. SEARCH ENGINE STRATEGY

* Banned: Native WordPress search queries (`?s=keyword` / `LIKE '%...%'`).

* Architecture:

  * Default (Single Node): MySQL Full-Text Search in Boolean mode (`MATCH...AGAINST`).

  * Enterprise Scale: Seamless adapter swap to `Typesense` or `Meilisearch` via Docker.

# ================================================================================ 10. ENVIRONMENT PORTABILITY & DYNAMIC URLS

* Banned: Hardcoded domain strings in `wp_options` or stored Gutenberg blocks.

* Implementation:

  * Base URLs resolved dynamically at bootstrap from `$_ENV['APP_URL']`.

  * Media links processed by `BlockAstParser` use relative storage paths (`/storage/...`), with runtime origin prepending.

# ================================================================================ 11. DEVELOPER EXPERIENCE (DX) & DIAGNOSTICS

* Scaffolding CLI Commands:

  * `bin/console make:page {Name}` -> Generates Entity, DTO, Repository, Latte View, Route.

  * `bin/console make:block {Name}` -> Scaffolds native Gutenberg block binding + Latte component.

  * `bin/console audit:verify` -> Validates cryptographic hash chain integrity.

  * `bin/console db:migrate` -> Runs versioned database migrations.

* In-Browser Diagnostics:

  * Local Dev: Spatie Ignition error handler with deep links to VS Code / Cursor.

  * Floating DevToolbar: Injected strictly in `APP_ENV=local` to show Query Counter (alert > 3), Memory, Cache Hit/Miss, and TTFB.

# ================================================================================ 12. FORBIDDEN PRACTICES (INSTANT REJECTION)

* NEVER run WP_Query, get_posts(), or get_field() inside loops.

* NEVER access \$\_FILES directly; use SecureUploadHandler.

* NEVER call do_blocks(); all block parsing must pass through BlockAstParser.

* NEVER use Hard Delete (DELETE FROM); always soft delete with deleted_at and deleted_by.

* NEVER call global functions inside src/Domain/ or src/Application/.

* NEVER serve sensitive vault documents through direct static URLs.

* NEVER