# Fanxie WP Core — Phase Checklist

Companion tracker for [`prd-fanxie-wp-core-v0.5.md`](./prd-fanxie-wp-core-v0.5.md). Standards defined in [`../CLAUDE.md`](../CLAUDE.md).

**Convention:** `[ ]` open · `[~]` in progress · `[x]` done · `[-]` skipped (document why inline).

**Exit criteria for every phase (unless noted):**
1. Unit + integration tests ≥ 80% line coverage on touched code
2. Plugin Check: **100% pass** (0 errors, 0 warnings)
3. PHPCS (WordPress-Extra), PHPStan lvl 8, ESLint, Prettier, `tsc --noEmit`: all clean
4. Checklist updated, CHANGELOG entry written, phase branch merged

---

## Phase 0 — Foundation & Tooling

*Goal: a runnable, testable, lintable plugin skeleton with zero modules enabled — sets the standard every later phase inherits.*

### 0.1 Repository & PHP scaffold  *(→ wordpress-development-expert)*
- [x] `fanxie-wp-core.php` bootstrap (headers, constants, activation/deactivation, autoload include, `Plugin::boot()`)
- [x] `composer.json` — PSR-4 `FanxieLab\WPCore\` → `src/`, dev deps (PHPUnit, PHPStan, PHPCS + WPCS, Brain Monkey, Mockery)
- [x] `uninstall.php` — honour user opt-in before wiping options/tables
- [x] `readme.txt` skeleton (wp.org format — contributors, tags, requires-at-least, tested-up-to, stable-tag, license, short description, changelog section)
- [x] `src/Plugin.php` — DI container, module registry wiring, bootstraps on `plugins_loaded`
- [x] `src/Modules/ModuleBase.php` abstract + `ModuleRegistry.php`
- [x] `src/Admin/SettingsPage.php` stub (registers menu, renders mount node for Vue app)
- [x] `src/Admin/AjaxRouter.php` — nonce + cap gated dispatcher keyed by `fanxie_wp_core_<action>`
- [x] Custom capability `manage_fanxie_wp_core` mapped to `manage_options` on activation
- [x] Custom hooks doc `docs/hooks.md` seeded

### 0.2 Vue 3 admin SPA scaffold  *(→ frontend-expert)*
- [x] `assets/admin/` workspace with Vite + Vue 3 + TS + Pinia
- [x] `tsconfig.json` strict, path aliases to `src/`
- [x] `api/ajaxClient.ts` — typed wrapper over `admin-ajax.php` with nonce injection and error envelope
- [x] Root `App.vue` with tabbed layout; empty tab per future module
- [x] Shared component primitives: `Toggle`, `TextField`, `Select`, `SaveBar`, `StatusPill`, `Toast` — all WCAG 2.1 AA
- [x] Build outputs to `assets/admin/dist/`; unminified source retained for wp.org review
- [x] Enqueue wired in `SettingsPage.php` — loads **only** on plugin screens

### 0.3 Testing harness  *(→ both agents in parallel)*
- [x] `phpunit.xml.dist` + `tests/bootstrap.php` with `@wordpress/env` integration
- [x] Example `Unit/` + `Integration/` test proving the harness runs
- [x] Vitest + Vue Test Utils + MSW configured; sample component + store tests
- [x] Playwright config + one smoke test (plugin activates, settings page renders)

### 0.4 Quality & CI  *(→ wordpress-development-expert)*
- [x] `phpcs.xml.dist` (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP @ PHP 8.1+, text domain + prefix rules configured)
- [x] `phpstan.neon.dist` level 8 with WP stubs (`szepeviktor/phpstan-wordpress`)
- [x] `eslint.config.js` (flat config) + `prettier.config.js` — see note below on `@wordpress/eslint-plugin`
  - Shipped as ESLint 9 flat config (`eslint.config.js`) instead of `.eslintrc.cjs` since eslintrc is on its way out. `@wordpress/eslint-plugin` is not cleanly flat-config-compatible at its current release, so the equivalent rules (no-console warn, prefer-const, no-floating-promises, no-explicit-any, etc.) are replicated directly in `eslint.config.js`; revisit when it ships a flat entry.
- [x] Root `composer run check` + `npm run check` scripts wire all linters/tests
- [x] GitHub Actions: `ci.yml` running PHP matrix (8.1/8.2/8.3), WP latest + trunk, JS checks, Plugin Check action
- [x] Plugin Check passing at **100%** on an empty skeleton (baseline) — expected scaffold-phase warnings catalogued in [`docs/plugin-check-notes.md`](../docs/plugin-check-notes.md); CI currently ignores warnings and fails on errors only (Phase 7 flips this to 100% strict)

### 0.5 i18n & docs
- [x] `languages/fanxie-wp-core.pot` generated
- [x] `CHANGELOG.md` initialised
- [x] README dev section: local setup, test commands, agent routing pointer

---

## Phase 1 — Security Essentials  *(PRD §3, §4, §5)*

*Goal: zero-risk audit wins first. Each sub-phase is independently shippable.*

### 1.1 Security Headers (PRD §3)  *(→ wordpress-development-expert + frontend-expert)*
- [x] `Modules/SecurityHeaders` module class + default config
- [x] Header emitter on `send_headers`, idempotent (no duplicates)
- [x] HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, Cache-Control toggles
- [x] CSP with **Report-Only** default + learning mode
- [x] REST endpoint `/wp-json/fanxie-wp-core/v1/csp-report` with schema validation + rate limiting
- [x] CSP preset library (WooCommerce + payment gateways, GA/GTM, Meta Pixel)
- [x] Vue tab: per-header toggles, CSP builder, violation log viewer with filters
- [x] Tests: header presence, idempotency, CSP merge logic, preset application

### 1.2 Hardening (PRD §4)  *(→ wordpress-development-expert + frontend-expert)*
- [x] User enumeration block (author scan + REST users endpoint)
- [x] XML-RPC modes: disable / restrict dangerous / IP-allowlist
- [x] Version hiding: generator tag, RSS version, `?ver=`, readme.html/license.txt 404
- [x] Uploads directory: `index.php` drop + `.htaccess` PHP deny + nginx snippet surfacer + live probe
- [x] `DISALLOW_FILE_EDIT` detection + runtime `file_mod_allowed` fallback
- [x] Application Passwords toggle (only shown when none exist)
- [x] Login error obfuscation
- [x] Vue tab: checklist-style UI, each item with status pill + fix/learn-more
- [x] Tests: each toggle round-trips, Apache/nginx branches, REST 401 behaviour

### 1.3 Login Protection (PRD §5)  *(→ wordpress-development-expert + frontend-expert)*
- [x] Failed-attempt tracker (IP + username) with tiered lockouts (echo-suppressed; strictly-higher-tier re-arm — no renewal DoS)
- [x] IP allowlist + trusted-proxy toggle (`REMOTE_ADDR`-only by default)
- [x] Storage: transients (short lockouts) + custom tables `fx_warden_login_log` (history) & `fx_warden_login_bans` (persistent bans)
- [x] Hide `wp-login.php` behind custom slug, `/wp-admin` redirect, `wp-login.php?action=` variants (admin-ajax/REST/cron carve-outs)
- [x] Email owner on slug change; `FX_WARDEN_LOGIN_SLUG` constant + WP-CLI `wp fx-warden login reveal` / `unlock` recovery commands
- [x] Strong password enforcement (length, case, digit, symbol; configurable) on new/changed passwords only
- [x] Role-based session timeout via `auth_cookie_expiration` (shorten-only) + admin idle-logout JS
- [x] Vue tab: attempt config, lockout log table + ban/unban, slug changer with confirmation, password policy builder, per-role timeouts
- [x] Tests: lockout thresholds/escalation, slug routing + carve-outs, password validator, IP resolver, repositories, AJAX, CLI

### 1.3b Login Protection — 2FA enforcement (follow-on)  *(→ wordpress-development-expert + frontend-expert)*
- [ ] Enforce 2FA on top of WordPress's official Two-Factor plugin (detect/offer install, per-role enrollment enforcement + grace window). Pulled forward from v2; separate spec/plan.

### 1.3c Constants renaming: `FANXIE_*` -> `FX_CORE_*`  *(→ wordpress-development-expert)* *(superseded by Phase 7 rename → `FX_WARDEN_*`)*
- [x] Audit every constant against the CLAUDE.md §3.1 convention (user-facing wp-config overrides use `FX_CORE_*`; internal bootstrap constants stay `FANXIE_WP_CORE_*`)
- [x] Rename `FANXIE_WP_CORE_DELETE_ALL_DATA` -> `FX_CORE_DELETE_ALL_DATA` (the only misnamed user-facing constant; `FX_CORE_LOGIN_SLUG` and the `fx-core` CLI root already complied)
- [x] Document both override constants in `docs/hooks.md`
- [-] Options / tables / hooks left on `fanxie_wp_core_*` and `fanxie_core_*` — deliberate per CLAUDE.md §3.1; renaming them would break stored data for no user-visible benefit *(superseded 2026-10-01: all renamed under Phase 7 — see RENAME item)*

---

## Phase 2 — Environment Visibility  *(PRD §7)*

### 2.1 Turnstile (PRD §6)  — **DEFERRED to a future version** *(decided 2026-09-03)*
- [-] Entire module deferred. Not built, not in the admin sidebar, not in `readme.txt`.
  Cloudflare Turnstile is well served by existing free plugins, it is the only
  module that would have forced a runtime third-party script (and a second
  "External services" disclosure) into a plugin whose whole pitch is that it
  phones home for one thing only, and its value is concentrated in form-plugin
  integrations (CF7/WPForms/Gravity/Ninja/Formidable/Woo) that each need their
  own compatibility surface to maintain. PRD §6 is retained as the spec.
  Re-open by restoring the module def in `assets/admin/src/config/modules.ts`
  and the `security` group's `moduleIds`.

### 2.2 Environment Health (PRD §7)  *(→ wordpress-development-expert + frontend-expert)*
- [x] Version checks (WP, PHP, MySQL/MariaDB, SSL, HTTPS) — **date-driven** support matrix, not hardcoded version comparisons (see note below)
- [x] Cron health (overdue events, `DISABLE_WP_CRON` presence, stale `doing_cron` lock, real-cron recommendation block)
- [x] Debug mode scan (`WP_DEBUG`, `WP_DEBUG_DISPLAY`, `WP_DEBUG_LOG` in the web root, `SCRIPT_DEBUG`, PHP `display_errors`, `error_reporting`)
- [x] Inactive plugin + inactive non-default theme detection
- [x] Abandoned plugin check via wp.org API (cached 24h, batched, on by default with a `wporg_scan_enabled` opt-out) — critical at 2y, warning at 1y
- [x] `readme.txt` **External services** disclosure for api.wordpress.org (required for wp.org review)
- [x] Dashboard widget summarising status (renders from cache only — never builds a report or makes a request)
- [x] Vue tab: grouped cards (red/yellow/green), copy-paste fix snippets, all 11 settings exposed, `unknown` as a first-class state
- [x] Tests: matrix edge cases + boundary dates, wp.org API timeout/`is_wp_error`/`not_on_wporg` handling, SSL socket failure, threshold clamping + inverted-pair rule, AJAX surface, widget render

**Threshold validation:** the four numeric settings are clamped server-side to
ranges declared once in `EnvironmentHealth::THRESHOLD_RANGES`, which also feeds
the defaults and publishes `min`/`max` to the admin UI so the browser's bounds
are read from the server rather than duplicated in TypeScript. `save-config` is
a plain AJAX endpoint, so the browser cannot be the validation layer: a bare
`absint` accepted `0` (which breaks the cron and SSL checks silently) and any
ceiling. `abandoned_critical_days < abandoned_warning_days` is resolved by
raising critical to match.

**Support-matrix decision (diverges from PRD §7.2):** the PRD's thresholds
("warning < 8.2, critical < 8.1") were stale by the time the module was built.
`SupportMatrix` instead stores each branch's published *active* and *security*
end dates and derives status by comparing them against now, so verdicts age
correctly on their own. Branches absent from the matrix report `unknown` rather
than a guess, and `SupportMatrix::REVIEWED_ON` records when the tables were last
checked against php.net and endoflife.date.

---

## Phase 3 — Data Hygiene & Audit Trail  *(PRD §8, §9)*

### 3.1 Database Maintenance (PRD §8)  *(→ wordpress-development-expert + frontend-expert)*
- [ ] Revision limit filter + bulk revision purge (per-post, keeps N)
- [ ] Expired transient purge + nuclear option
- [ ] Orphaned meta cleanup (post/user/term/comment)
- [ ] Auto-draft purge (>N days)
- [ ] Trashed post purge (>N days)
- [ ] Spam comment purge (>N days)
- [ ] Action Scheduler-driven schedule (daily/weekly toggle, off-peak window)
- [ ] WP-CLI commands (PRD §8.5) with `--dry-run` on every destructive op
- [ ] Vue tab: counts + sizes table, dry-run preview, "Purge Selected" with confirmation modal
- [ ] Tests: each cleanup function, Action Scheduler registration, CLI commands

### 3.2 Activity Log (PRD §9)  *(→ wordpress-development-expert + frontend-expert)*
- [ ] Custom table `{prefix}fx_warden_activity_log` via `dbDelta`
- [ ] Event recorders: plugin/theme lifecycle, user CRUD, core update, auth events, content deletion, settings changes
- [ ] 90-day retention with Action Scheduler prune
- [ ] WP-CLI `wp fx-warden log list` + `log export --format=csv`
- [ ] Vue log viewer: pagination, filters (event type, user, date range), CSV export button
- [ ] Tests: each recorder fires once per event, retention prune, CSV shape

---

## Phase 4 — Performance: Asset Manager  *(PRD §10)*

*(→ wordpress-development-expert + frontend-expert)*
- [ ] Script defer/async/delay engine with per-handle rules + safelist for jQuery/`wp-*`
- [ ] Interaction-loaded script delay (mouseover/scroll/keydown/touchstart)
- [ ] Conditional unloading rule engine (page type, post type, URL glob)
- [ ] Admin bar discovery tool listing enqueued handles on current page
- [ ] Image dimension injection from attachment metadata
- [ ] Lazy-load enforcement with skip-first-N exclusion
- [ ] Heartbeat controls (frontend/admin/editor + interval)
- [ ] Emoji scripts toggle (default on)
- [ ] Embeds toggle (default on)
- [ ] jQuery Migrate removal (default **off**, warning displayed)
- [ ] Vue tab: rule builder UI, discovery helper, per-feature toggles with impact notes
- [ ] Tests: rule evaluation, enqueue filter outputs, safelist honoured

---

## Phase 5 — Performance: Media Optimizer  *(PRD §11)* — **DEFERRED** *(decided 2026-09-03)*

- [-] Entire phase deferred to a future version. Not built, not in the admin
  sidebar, not in `readme.txt`. Image optimization is the most crowded, most
  commoditised category on wp.org, it is the only planned module that mutates
  user files irreversibly (so it carries the highest support burden per line
  shipped), and its Imagick/GD/AVIF matrix makes it the least predictable thing
  to test across shared hosts. PRD §11 is retained as the spec.

## Phase 6 — Cloud Storage (R2/S3 Offload)  *(PRD §12)* — **DEFERRED** *(decided 2026-09-03)*

- [-] Entire phase deferred to a future version. Not built, not in the admin
  sidebar, not in `readme.txt`. It depended on Media Optimizer (offload was
  specified to run *after* optimization completes), it would have required
  bundling an S3 SDK into a GPL wp.org release, and an offload that goes wrong
  detaches a site from its own media library — the highest-blast-radius feature
  in the PRD. PRD §12 is retained as the spec.

## Phase 7 — wp.org Submission  *(PRD §15 phase 7)*

- [x] **RENAME — "Fanxie WP Core" → "Fanxie Warden"** *(name chosen 2026-09-03; done 2026-10-01 on `phase/7-rename-warden`. `_PRD/` filenames and historical `docs/superpowers/` records intentionally keep the old name.)*
  wordpress.org bans the term "wp" outright in both the plugin name and the
  slug; Plugin Check reports it as `trademarked_term` and it is a hard rejection
  at submission, not a negotiable warning. Cheapest to fix while unreleased — no
  site has stored options under the old prefix, so no data migration is owed.

  **Chosen name:** **Fanxie Warden**, slug `fanxie-warden`. Slug verified free
  against the wp.org plugin API on 2026-09-03 (a slug held by an unpublished
  pending submission would not show up there — reconfirm at submit time).
  Rejected alternatives and why: *Fanxie Core* (accurate but says nothing, and
  after the Phase 5/6 deferrals the plugin is no longer a catch-all "core");
  *Bastion / Bulwark / Fortify / Rampart* (security-only — they undersell
  Environment Health, Database Maintenance and Activity Log); *Sentry*
  (Sentry.io trademark in dev tooling); *Bedrock* (Roots); *Groundwork* (slug
  permanently closed on wp.org since 2013); *Watchtower* (live plugin);
  *Sitewright* (good, but drops brand equity). "Warden" was picked because it
  carries both guard **and** caretaker, which is exactly the shipping scope.

  **Rename surface — public (must change, this is what wp.org polices):**
  - Plugin name + `Plugin Name:` header in the bootstrap file
  - Slug / plugin directory / bootstrap filename → `fanxie-warden.php`
  - Text domain `fanxie-wp-core` → `fanxie-warden` on every `__()` / `esc_html__()` /
    `_n()` / `_x()` call, plus the `Text Domain:` header and `languages/*.pot`
  - `readme.txt` title line and description
  - ~~Repo name~~ — not renamed (see below)

  **Rename surface — internal (optional; decided: CHANGE for readability, since
  nothing has shipped and no migration is owed):**
  - PSR-4 namespace root `FanxieLab\WPCore` → `FanxieLab\Warden` (+ `composer.json`
    autoload map, `phpstan.neon.dist`, `phpunit.xml.dist`, `tests/` namespaces)
  - Bootstrap constants `FANXIE_WP_CORE_{VERSION,PATH,URL}` → `FANXIE_WARDEN_*`
  - Option prefix `fanxie_wp_core_*` → `fanxie_warden_*`
  - Hook prefix `fanxie_wp_core/` → `fanxie_warden/` (+ `docs/hooks.md`)
  - AJAX action prefix `fanxie_wp_core_<action>` → `fanxie_warden_<action>`
  - REST namespace `fanxie-wp-core/v1` → `fanxie-warden/v1`
  - Capability `manage_fanxie_wp_core` → `manage_fanxie_warden`
  - Admin menu slug + asset handles (`fanxie-wp-core-admin`), SPA mount node id,
    and the `window.fanxieWPCore` bootstrap global → `window.fanxieWarden`
  - CI workflows, `phpcs.xml.dist` prefix/text-domain rules, Playwright selectors

  **Originally kept, then renamed too (user decision 2026-10-01; local dev data wiped, no migration):**
  - Custom table prefix `{$wpdb->prefix}fanxie_core_*` → `{$wpdb->prefix}fx_warden_*`
  - WP-CLI root command `fx-core` → `fx-warden`
  - Override constants `FX_CORE_*` → `FX_WARDEN_*`

  **Not renamed (user decision 2026-10-01):** the GitHub repo and local checkout
  folder stay `fanxie-wp-core`; wp-env mounts the plugin under that folder name.
  The release archive must package the plugin as `fanxie-warden/`.
- [x] Verify the rename left no `fanxie-wp-core` / `fanxie_wp_core` / `WPCore` /
  `FANXIE_WP_CORE` / `fx-core` / `FX_CORE` / `fanxie_core` strings outside `_PRD/` and historical docs
- [ ] Full Plugin Check **zero** errors/warnings on complete plugin — **gate runs against the shippable package** (the built archive, extracted as `fanxie-warden/`), not the dev checkout. The `fanxie-wp-core` dev folder name is irrelevant to the gate (decided 2026-10-01). CI `plugin-check` job must be pointed at the package build before the warnings flag flips.
- [ ] Build a distribution archive that excludes `tests/`, `.github/`, `node_modules/`,
  and dev configs — most Plugin Check findings against the dev checkout come from
  test fixtures that never ship (146 of 184 at the end of Phase 2)
- [ ] `readme.txt` polished: short description, long description, FAQ, screenshots, changelog
- [ ] Screenshots captured for each module tab (1544×500+ per wp.org guidance)
- [ ] Tested with latest WP major + trunk
- [ ] Banner + icon assets (`assets/` at repo root for wp.org, not the plugin's `assets/admin/`)
- [ ] `.pot` refreshed; translator credits noted
- [ ] SVN layout dry-run (`trunk/`, `tags/x.y.z/`, `assets/`)
- [ ] Security review pass (second reader) — focus on AJAX/REST surfaces
- [ ] Accessibility audit pass (axe-core + manual keyboard sweep)
- [ ] Submit to wp.org; document review turnaround

---

## Known issues

- [x] **Integration suite is order-fragile.** *(fixed 2026-10-01: four process-wide constants — `FX_WARDEN_LOGIN_SLUG`, `DOING_AJAX`, `WP_ADMIN`, `REST_REQUEST` — were defined by tests without isolation; now run in separate processes or replaced with filters, and a `$_SERVER['REQUEST_URI']` non-restore fixed. Suites pass in default, reverse and multiple random-seed orders.)* `LoginSlugGuardIntegrationTest`
  calls `define( 'FX_WARDEN_LOGIN_SLUG', … )`, which leaks process-wide. Combined
  with `executionOrder="depends,defects"` in `phpunit.xml.dist`, a stale
  `.phpunit.cache` from a previously failed run reorders that test ahead of
  `test_password_reset_link_is_rewritten_and_preserves_its_query`, which then
  fails. Reproduced once at the end of Phase 2; three consecutive runs from a
  cleared cache pass, so it is masked by ordering rather than genuinely fixed.
  It will resurface in CI after any failing run. Re-confirmed 2026-10-01: default order passes (139 tests), but `--order-by=random` seeds 111/222 fail 4 and 6 tests in `LoginSlugGuardIntegrationTest`. Fix by isolating the constant
  (`@runInSeparateProcess`) or by dropping `defects` from the execution order.

## Cross-phase ongoing items

- [ ] Every new user-facing string has text domain `fanxie-warden`
- [ ] Every destructive op has a `--dry-run` and a UI confirmation
- [ ] Every AJAX action: nonce + capability, documented in `docs/hooks.md`
- [ ] Every module: enabled/disabled cost benchmark recorded
- [ ] Every setting has an accessible explanation (ⓘ Tooltip or inline HelpText)
- [ ] CHANGELOG updated per phase
