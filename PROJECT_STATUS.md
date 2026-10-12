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
| 3 | Gutenberg AST Parser & Synced Patterns | 🟡 built by Gemini, partially reviewed | `src/Infrastructure/Wordpress/Gutenberg/BlockAstParser.php`, `src/Domain/Model/BlockDto.php`, `src/Presentation/View/BlockRenderer.php`, `templates/blocks/*.latte` |
| 4 | DB, Caching & Edge SWR Engine | 🟡 built by Gemini, **not reviewed** | `src/Infrastructure/Cache/CacheInterface.php`, `RedisTaggedCache.php` |
| 5 | Dual-Tier Storage & Upload Pipeline | 🟡 built by Gemini, reviewed + fixed | `SecureUploadHandler.php`, `SignedUrlGenerator.php`, `SecureFileController.php` — fixed: missing `is_uploaded_file()` check, missing UUID format validation before path-traversal-prone filesystem use |
| 6 | Security, Crypto & CSP Bridge | 🟡 built by Gemini, reviewed + fixed | `FieldEncryptor.php` (reviewed, OK), `MerkleAuditLogger.php` (reviewed, **fixed a real race condition** — see below), `CspBridge.php` (**not reviewed**), `CsrfTokenManager.php` (reviewed, OK) |
| 7 | Asynchronous Engine & Worker Fallback | 🟡 built by Gemini, reviewed + fixed | `RedisStreamEventDispatcher.php` (reviewed, OK), `WorkerRunCommand.php` (fixed: PHPStan error, eager Redis connection) |
| 8 | Native Multilingual Support | 🟡 built by Gemini, **not reviewed** | `src/Domain/Model/TranslatableTrait.php`, `src/Application/Context/RequestLocaleContext.php` |
| 9 | Search Engine Strategy | 🟡 built by Gemini, **not reviewed** | `src/Infrastructure/Search/MySqlFullTextSearch.php`, `src/Domain/Repository/SearchRepositoryInterface.php` |
| 10 | Environment Portability & Dynamic URLs | ⚪ not started | — |
| 11 | Developer Experience & Diagnostics | 🟡 partial | `make:page` ✅ (Milestone 1), `make:block` 🟡 (Gemini, not reviewed), `audit:verify` 🟡 (Gemini, not reviewed), `DevToolbar.php` **not reviewed** |
| 12 | Forbidden Practices | ✅ enforced | `DomainPurityTest.php` catches `WP_Post`/`WP_Query`/`wpdb`/`App\Infrastructure` deps from Domain/Application |

## Reviewed-and-fixed in this pass (2026-10-12/13)

- **`MerkleAuditLogger::log()`** had a real race condition: read-then-insert
  with no lock meant two concurrent writers could fork the hash chain.
  Fixed with a MySQL named lock (`GET_LOCK`/`RELEASE_LOCK`), skipped on
  non-MySQL drivers (the existing test uses in-memory SQLite). Verified with
  a real two-connection test proving the lock actually serializes access.
- **`SecureFileController`/`SignedUrlGenerator`**: neither validated that
  the `uuid` parameter was actually UUID-shaped before using it in a
  filesystem path — the only thing stopping path traversal was the HMAC
  signature, which is fragile as a sole defense. Both now construct
  `UuidV7` (which validates format) before touching the filesystem.
- **`SecureUploadHandler`** used `copy()` instead of `is_uploaded_file()` +
  `move_uploaded_file()`, meaning it never actually verified the "upload"
  came from a real HTTP POST. Added a `testMode` flag (defaults to
  production-safe behavior; tests opt into `copy()` explicitly) since
  `is_uploaded_file()` only recognizes real uploads within a live PHP SAPI
  request.
- **`Bootstrap::redis()`** opened a new TCP connection (with a blocking 1s
  timeout) on every call, and was being resolved eagerly for every
  `bin/console` invocation regardless of command. Now cached and resolved
  lazily via `Bootstrap::redisFactory(): Closure`, matching the
  `LazyPageRepository`/`LazyAuditLogger` pattern already established.
- **`WorkerRunCommand`**: fixed a PHPStan "cannot cast mixed to int" error
  and switched to constructor-injecting the Redis factory closure instead
  of an already-resolved (or eagerly-attempted) connection.

## Known gaps not yet addressed

- Sections 4, 8, 9, the CSP bridge, `make:block`/`audit:verify`/`DevToolbar`
  have not been read/reviewed yet — tests passing is not the same as
  reviewed, especially for anything security- or correctness-sensitive.
- No live HTTP entrypoint wires `PageController`/`SecureFileController`/
  `CsrfController` into an actual request — `src/Presentation/Http` exists
  but nothing routes real requests to it yet (`wp-admin` still serves the
  default WordPress theme).
- `agents_md.md`'s cipher description ("XChaCha20-Poly1305 via
  `sodium_crypto_secretbox`") is internally inconsistent — `sodium_crypto_secretbox`
  is actually XSalsa20-Poly1305, a different (also secure) construction.
  `FieldEncryptor` implements what the spec's code reference says, not what
  its prose says. Worth a decision on which one is actually required before
  this is called done.
