# Login Protection (core) — Design

- **Date:** 2026-07-18
- **Status:** Approved (design), pending implementation plan
- **Branch:** `phase/1-login-protection`
- **Scope phase:** Phase 1.3 core (PRD §5). 2FA enforcement is split out to **Phase 1.3b** (separate spec/plan).
- **Owners:** `wordpress-development-expert` (PHP: module, storage, hooks, REST/AJAX, WP-CLI, tests), `frontend-expert` (Vue tab). Cross-cutting AJAX↔Vue work briefs both with the contract at the boundary.

## Context

Phase 1.3 hardens the login system against brute force without 2FA in the core cycle (PRD §5). Four cohesive features ship as one `LoginProtection` module with fine-grained toggles (module contract: no master enable). 2FA enforcement — chosen to be built on top of WordPress's official Two-Factor feature plugin — is deferred to Phase 1.3b so each cycle stays focused and independently reviewable.

## Decisions locked (from brainstorming)

1. **2FA → Phase 1.3b**, and it will *enforce on top of WP's Two-Factor plugin* (detect/offer install, enforce per-role enrollment) — no in-house TOTP. Not in this spec.
2. **Storage:** two custom tables (`fanxie_core_login_log`, `fanxie_core_login_bans`) + transients for short tiered lockouts + the module settings option for config/allowlist/slug/policy.
3. **Defaults:** attempt limiting **ON**; hide-login, strong passwords, session timeout **OFF** (opt-in).
4. **IP posture:** resolve client IP from `REMOTE_ADDR` only by default; trust a forwarded header **only** when an explicit trusted-proxy toggle is enabled (XFF spoofing would otherwise evade or frame-ban).
5. **Passwords:** enforce policy on *new/changed* passwords only — never force-reset existing users.
6. **Session timeout:** `auth_cookie_expiration` per-role + a small inactivity heartbeat JS enqueued on **wp-admin** (not the Vue SPA).
7. **Naming (project-wide):** user-facing wp-config override **constants use the `FX_CORE_*` prefix** (e.g. `FX_CORE_LOGIN_SLUG`); the **WP-CLI root command is `fx-core`** (hyphenated), e.g. `wp fx-core login reveal`. Internal bootstrap constants (`FANXIE_WP_CORE_VERSION/PATH/URL`) and the options/table/hook prefixes (`fanxie_wp_core_*`, `fanxie_core_*`, `fanxie_wp_core/`) are unchanged. This convention applies to all future modules (Turnstile, Media, Storage, etc.).

## Architecture

### Module
`src/Modules/LoginProtection/LoginProtection.php` extends `ModuleBase`: `id()='login-protection'`, `name()='Login Protection'`, `register_hooks()`, `get_settings_fields()`, `get_default_config()`. Settings option: `fanxie_wp_core_login_protection_settings`. Runtime split into focused collaborators under `src/Modules/LoginProtection/`:
- `Runtime/AttemptLimiter.php` — failure tracking, tiered lockouts, ban enforcement.
- `Runtime/LoginSlugGuard.php` — hide-wp-login routing.
- `Runtime/PasswordPolicy.php` — strong-password validation.
- `Runtime/SessionTimeout.php` — `auth_cookie_expiration` + heartbeat enqueue.
- `IpResolver.php` — single source of truth for client IP (REMOTE_ADDR-only by default).
- `LoginLogRepository.php` — read/write `fanxie_core_login_log`.
- `BanRepository.php` — read/write `fanxie_core_login_bans`.
- `Schema.php` — `dbDelta` install + version tracking for both tables.
- `AjaxController.php` — sub-actions for the Vue tab.
- `Cli/LoginCommand.php` — `wp fx-core login …`.

### Storage schema

**`{$wpdb->prefix}fanxie_core_login_log`** (history/audit; powers the viewer):
| column | type | notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `event_type` | VARCHAR(32) | `failed_login` \| `lockout` \| `ban_added` \| `ban_removed` \| `slug_changed` \| `blocked_attempt` |
| `ip` | VARCHAR(45) | IPv4/IPv6; indexed |
| `username` | VARCHAR(180) | attempted login; indexed |
| `user_id` | BIGINT UNSIGNED NULL | resolved user if any |
| `context` | LONGTEXT NULL | JSON (tier, duration, reason, …) |
| `created_at` | DATETIME | indexed |

Indexes: `created_at`, `ip`, `username`. Retention: prune > N days (default 30, configurable) via Action Scheduler daily job.

**`{$wpdb->prefix}fanxie_core_login_bans`** (active persistent bans; fast per-attempt lookup):
| column | type | notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `subject_type` | VARCHAR(16) | `ip` \| `username` |
| `subject_value` | VARCHAR(180) | |
| `reason` | VARCHAR(191) NULL | |
| `expires_at` | DATETIME NULL | NULL = indefinite |
| `created_at` | DATETIME | |

Unique index: `(subject_type, subject_value)`. Lookup index covers the auth-time check. Expired rows pruned by the same daily job; expiry also checked at read time.

**Transients:** `fanxie_wp_core_lp_att_{hash}` (failure counter per IP+username), `fanxie_wp_core_lp_lock_{hash}` (active short lockout w/ tier). Keyed by a salted hash of IP and/or username; TTL = the tier's lockout duration.

**Settings option** (`fanxie_wp_core_login_protection_settings`) holds: attempt thresholds/durations, trusted-IP allowlist, trusted-proxy toggle + header name, login slug + enabled flag, password policy config, session-timeout per-role config, log retention days.

`Schema::install()` runs on activation and when a stored `fanxie_wp_core_login_protection_db_version` differs from the module's current version (upgrade-safe `dbDelta`).

## Feature 1 — Attempt limiting (default ON)

- Track failures on `wp_login_failed` (increment counter keyed by IP + username via `IpResolver`), reset on successful `wp_login`.
- Tiers (configurable): 5→15 min, 10→1 h, 20→24 h. On crossing a tier, set the lockout transient and write a `lockout` log row.
- Enforce via a **high-priority `authenticate` filter**: if the IP or username is currently locked (transient) or banned (`BanRepository`), return a `WP_Error` (generic message) and write a `blocked_attempt` row — before credentials are checked.
- **Trusted-IP allowlist** (settings) bypasses limiting entirely.
- **Manual bans:** admin can add/remove bans from the log viewer (writes `fanxie_core_login_bans`, logs `ban_added`/`ban_removed`).
- **IP resolution:** `IpResolver` returns `REMOTE_ADDR` unless `trust_proxy` is on, in which case it reads the configured header (e.g. `X-Forwarded-For`, left-most public) — documented as security-sensitive.

**Acceptance:** N failures within window → lockout of the right tier; locked IP/user is blocked at `authenticate` with a generic error; allowlisted IP never locks; ban blocks auth and is removable; every failure/lockout/block is logged.

## Feature 2 — Hide wp-login.php (default OFF)

- Enabled only when a **valid slug** is set (validated: non-empty, slug-safe, not a reserved path like `wp-admin`/`wp-login`/`admin`, no collision with an existing rewrite/page).
- When enabled: intercept early (`plugins_loaded`/`login_init`), serve the login form only at `/{slug}`; direct hits to `/wp-login.php` return **404**; unauthenticated `/wp-admin` requests **redirect to the slug** (authenticated pass through); handle `wp-login.php?action=` variants (`logout`, `lostpassword`, `rp`/`resetpass`, `register`, `postpass`) so password reset and logout keep working through the slug.
- **Must not break:** `admin-ajax.php`, REST auth, WP-Cron, or interactive-login redirects.
- **Recovery / safety (critical):**
  - **`FX_CORE_LOGIN_SLUG`** wp-config constant overrides the stored slug (locked-out recovery; when defined, the UI shows it as read-only).
  - **`wp fx-core login reveal`** prints the active slug.
  - Email the site admin address on every slug change.
  - Default OFF; enabling requires an explicit slug + a confirmation in the UI.

**Acceptance:** login works only at the slug; `/wp-login.php`→404; `/wp-admin` (unauth)→redirect; each `action=` variant resolves; the constant override and `reveal` command return the correct slug; admin email fires on change.

## Feature 3 — Strong passwords (default OFF)

- Validate on `user_profile_update_errors`, `validate_password_reset`, `registration_errors` (and REST user create/update). Configurable rules: min length (default 12), require mixed case, digit, symbol.
- Clear, specific error messages listing the unmet requirements.
- Applies to **new/changed** passwords only — existing users are not force-reset.

**Acceptance:** a password failing any enabled rule is rejected on set/reset/register with a message naming the unmet rules; a compliant password passes; existing sessions/passwords are untouched.

## Feature 4 — Admin session timeout (default OFF)

- `auth_cookie_expiration` filter shortens cookie lifetime **per role** (defaults: 30 min admins, 2 h other roles; configurable per role).
- A small inactivity **heartbeat JS** enqueued on wp-admin only (not the SPA) logs the user out after the configured idle period (resets on activity; uses WP's heartbeat or a lightweight timer + `wp_logout_url`).

**Acceptance:** cookie lifetime reflects the per-role config; an idle wp-admin session logs out after the configured period; activity resets the timer.

## Admin (Vue tab) — `frontend-expert`

Replaces the `login-protection` `PlaceholderTab` with a real view. Sections (using the `Tooltip`/`HelpText` standard, `Toggle.describedby` where help wires to a switch):
- **Attempt limiting:** enable toggle, tier thresholds/durations, trusted-IP allowlist editor, trusted-proxy toggle + header.
- **Lockout log:** paginated table (event, ip, username, time) with filters (event type, date, ip/user) and per-row **Ban / Unban** actions + a manual "Add ban" control.
- **Hide login:** enable toggle + slug field with validation and a **confirmation modal**; shows current slug, the recovery note (`FX_CORE_LOGIN_SLUG`, `wp fx-core login reveal`), and read-only state when the constant is set.
- **Passwords:** policy builder (length + rule toggles) with a live preview of the requirement message.
- **Sessions:** per-role timeout inputs.

### AJAX contract (all `nonce` + `manage_fanxie_wp_core`)
`fanxie_wp_core_login_protection` router sub-actions:
- `get-config` → full config + current slug source (stored/constant) + db-derived flags.
- `save-config` → validated persist (slug validation returns field errors; invalidates any cached status).
- `get-log` → paginated/filtered rows from `LoginLogRepository`.
- `add-ban` / `remove-ban` → mutate `BanRepository`, log the event.
- `clear-lockout` → clear a live transient lockout for an IP/username (UI convenience).

## WP-CLI — `wp fx-core login …`

- `wp fx-core login reveal` — print the active login slug (recovery).
- `wp fx-core login unlock <ip|username>` — clear lockouts/bans for a subject (recovery). `--dry-run` supported.

Registered via `WP_CLI::add_command( 'fx-core login', … )`. (This establishes the project-wide `fx-core` CLI root; future modules add `wp fx-core log …`, `wp fx-core media …`, etc.)

## Contracts (PHP ↔ TS)

New `assets/admin/src/modules/LoginProtection/types.ts` mirrors: `LoginProtectionConfig` (attempt/slug/password/session/allowlist), `LoginLogRow` (`{ id, event_type, ip, username, user_id, context, created_at }`), `BanRow` (`{ id, subject_type, subject_value, reason, expires_at, created_at }`), and the AJAX envelopes. Shapes are frozen — coordinate any change across both lanes.

## Testing

- **Unit:** tier calculation + reset logic; password validator per rule + combinations + edge cases; slug validation (reserved/collision/format); `IpResolver` (REMOTE_ADDR default, trusted-proxy header parsing, spoof rejection).
- **Integration:** `Schema::install()` creates both tables + upgrade path; attempt→lockout→block round-trip through real `authenticate`; ban lookup blocks auth; slug routing (404 original, serve at slug, each `action=` variant, `/wp-admin` redirect, constant override); `auth_cookie_expiration` per role; AJAX get/save/log/ban with nonce+cap (401/403 on failure); WP-CLI reveal/unlock.
- Coverage ≥80% on `src/Modules/LoginProtection`.

## Out of scope (this spec)

- **2FA enforcement** — Phase 1.3b (enforce on top of WP's Two-Factor plugin).
- Forcing existing users to reset non-compliant passwords.
- Front-end (non-admin) login UI theming beyond serving the form at the slug.

## Follow-ups (docs)

- `CLAUDE.md` §3.1: record the naming convention — user-facing constants `FX_CORE_*`, WP-CLI root `fx-core` — and the two new custom tables.
- `_PRD/prd-fanxie-wp-core-v0.5.md` §5: note that 2FA is being pulled forward (to 1.3b) rather than v2, and that user-config constants/CLI use the `FX_CORE_*` / `fx-core` naming. (Coordinate with the user's in-flight PRD edits.)
- `docs/hooks.md`: document new `fanxie_wp_core/login_protection/*` hooks + the AJAX actions.
- `CHANGELOG.md`: Unreleased entry.
