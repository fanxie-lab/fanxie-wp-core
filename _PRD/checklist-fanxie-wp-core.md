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
- [ ] `phpunit.xml.dist` + `tests/bootstrap.php` with `@wordpress/env` integration
- [ ] Example `Unit/` + `Integration/` test proving the harness runs
- [ ] Vitest + Vue Test Utils + MSW configured; sample component + store tests
- [ ] Playwright config + one smoke test (plugin activates, settings page renders)

### 0.4 Quality & CI  *(→ wordpress-development-expert)*
- [x] `phpcs.xml.dist` (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP @ PHP 8.1+, text domain + prefix rules configured)
- [x] `phpstan.neon.dist` level 8 with WP stubs (`szepeviktor/phpstan-wordpress`)
- [x] `eslint.config.js` (flat config) + `prettier.config.js` — see note below on `@wordpress/eslint-plugin`
  - Shipped as ESLint 9 flat config (`eslint.config.js`) instead of `.eslintrc.cjs` since eslintrc is on its way out. `@wordpress/eslint-plugin` is not cleanly flat-config-compatible at its current release, so the equivalent rules (no-console warn, prefer-const, no-floating-promises, no-explicit-any, etc.) are replicated directly in `eslint.config.js`; revisit when it ships a flat entry.
- [x] Root `composer run check` + `npm run check` scripts wire all linters/tests
- [x] GitHub Actions: `ci.yml` running PHP matrix (8.1/8.2/8.3), WP latest + trunk, JS checks, Plugin Check action
- [x] Plugin Check passing at **100%** on an empty skeleton (baseline) — expected scaffold-phase warnings catalogued in [`docs/plugin-check-notes.md`](../docs/plugin-check-notes.md); CI currently ignores warnings and fails on errors only (Phase 7 flips this to 100% strict)

### 0.5 i18n & docs
- [ ] `languages/fanxie-wp-core.pot` generated
- [ ] `CHANGELOG.md` initialised
- [ ] README dev section: local setup, test commands, agent routing pointer

---

## Phase 1 — Security Essentials  *(PRD §3, §4, §5)*

*Goal: zero-risk audit wins first. Each sub-phase is independently shippable.*

### 1.1 Security Headers (PRD §3)  *(→ wordpress-development-expert + frontend-expert)*
- [ ] `Modules/SecurityHeaders` module class + default config
- [ ] Header emitter on `send_headers`, idempotent (no duplicates)
- [ ] HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, Cache-Control toggles
- [ ] CSP with **Report-Only** default + learning mode
- [ ] REST endpoint `/wp-json/fanxie-wp-core/v1/csp-report` with schema validation + rate limiting
- [ ] CSP preset library (WooCommerce + payment gateways, GA/GTM, Meta Pixel)
- [ ] Vue tab: per-header toggles, CSP builder, violation log viewer with filters
- [ ] Tests: header presence, idempotency, CSP merge logic, preset application

### 1.2 Hardening (PRD §4)  *(→ wordpress-development-expert + frontend-expert)*
- [ ] User enumeration block (author scan + REST users endpoint)
- [ ] XML-RPC modes: disable / restrict dangerous / IP-allowlist
- [ ] Version hiding: generator tag, RSS version, `?ver=`, readme.html/license.txt 404
- [ ] Uploads directory: `index.php` drop + `.htaccess` PHP deny + nginx snippet surfacer + live probe
- [ ] `DISALLOW_FILE_EDIT` detection + runtime `file_mod_allowed` fallback
- [ ] Application Passwords toggle (only shown when none exist)
- [ ] Login error obfuscation
- [ ] Vue tab: checklist-style UI, each item with status pill + fix/learn-more
- [ ] Tests: each toggle round-trips, Apache/nginx branches, REST 401 behaviour

### 1.3 Login Protection (PRD §5)  *(→ wordpress-development-expert + frontend-expert)*
- [ ] Failed-attempt tracker (IP + username) with tiered lockouts
- [ ] IP allowlist
- [ ] Transient + options storage layering
- [ ] Hide `wp-login.php` behind custom slug, `/wp-admin` redirect, `wp-login.php?action=` variants
- [ ] Email owner on slug change; WP-CLI `wp fanxie login reveal` recovery command
- [ ] Strong password enforcement (length, case, digit, symbol; configurable)
- [ ] Role-based session timeout via `auth_cookie_expiration` + heartbeat JS
- [ ] Vue tab: lockout log table, slug changer with confirmation, password policy builder
- [ ] Tests: lockout thresholds, slug routing, password validator edge cases

---

## Phase 2 — Bot Protection & Environment Visibility  *(PRD §6, §7)*

### 2.1 Turnstile (PRD §6)  *(→ wordpress-development-expert + frontend-expert)*
- [ ] Config: site key + secret key via option **or** `FANXIE_TURNSTILE_*` constants
- [ ] Verifier service with fail-open/closed toggle, timeout, WP_Error handling
- [ ] Native form integrations: login, register, lost-password, comments
- [ ] WooCommerce integrations (guarded by `class_exists( 'WooCommerce' )`)
- [ ] Form plugin integrations: CF7, WPForms, Gravity, Ninja, Formidable — all guarded, auto-detected
- [ ] Cache-bypass headers on protected pages; guidance banner when caching plugins detected
- [ ] Success/failure stats (7-day rolling transient)
- [ ] Vue tab: key entry (redacts if defined by constants), per-form toggles, test-mode switch, stats chart
- [ ] Tests: verifier mock, each integration renders + validates, fail-open vs fail-closed

### 2.2 Environment Health (PRD §7)  *(→ wordpress-development-expert + frontend-expert)*
- [ ] Version checks (WP, PHP, MySQL/MariaDB, SSL, HTTPS) with hardcoded support matrix
- [ ] Cron health (overdue events, `DISABLE_WP_CRON` presence, real-cron recommendation block)
- [ ] Debug mode scan (`WP_DEBUG`, `WP_DEBUG_DISPLAY`, `SCRIPT_DEBUG`, PHP `display_errors`)
- [ ] Inactive plugin + inactive non-default theme detection
- [ ] Abandoned plugin check via wp.org API (cached 24h) — critical at 2y, warning at 1y
- [ ] Dashboard widget summarising status
- [ ] Vue tab: grouped cards (red/yellow/green), copy-paste fix snippets
- [ ] Tests: matrix edge cases, wp.org API timeout/error handling, snapshot of widget render

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
- [ ] Custom table `{prefix}fanxie_activity_log` via `dbDelta`
- [ ] Event recorders: plugin/theme lifecycle, user CRUD, core update, auth events, content deletion, settings changes
- [ ] 90-day retention with Action Scheduler prune
- [ ] WP-CLI `wp fanxie log list` + `log export --format=csv`
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

## Phase 5 — Performance: Media Optimizer  *(PRD §11)*

*(→ wordpress-development-expert + frontend-expert)*
- [ ] Imagick detection + GD fallback path with quality warning
- [ ] On-upload compression with backup of original
- [ ] WebP + AVIF generation, metadata stored in postmeta
- [ ] `<img>` → `<picture>` rewriter with `srcset` preservation
- [ ] Action Scheduler bulk processor (max 3 concurrent jobs)
- [ ] WP-CLI `wp fanxie media optimize` + `--dry-run`
- [ ] Media library column: status + savings %
- [ ] Media detail modal: sizes, formats, re-optimise action
- [ ] Vue tab: global quality sliders, bulk progress bar, skip rules (large/small/mime)
- [ ] Tests: encoder selection, format availability detection, picture rewriter fidelity

---

## Phase 6 — Cloud Storage (R2/S3 Offload)  *(PRD §12)*

*(→ wordpress-development-expert + frontend-expert)*
- [ ] Config UI + `wp-config.php` constant override (bucket, endpoint, key, secret, CDN domain)
- [ ] Pluggable adapter (`CloudStorageAdapter` interface) — R2 reference impl, S3 compatible
- [ ] On-upload offload after optimization completes
- [ ] Attachment URL + content filter rewriter
- [ ] Local file retention policy (keep / grace period / immediate delete)
- [ ] WP-CLI `wp fanxie storage offload --dry-run`
- [ ] Migration helper (previously-uploaded media catch-up)
- [ ] Vue tab: connection test, bulk progress, retention selector
- [ ] Tests: adapter contract, URL rewriter, retry/backoff on failure, dry-run integrity

---

## Phase 7 — wp.org Submission  *(PRD §15 phase 7)*

- [ ] Full Plugin Check **zero** errors/warnings on complete plugin
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

## Cross-phase ongoing items

- [ ] Every new user-facing string has text domain `fanxie-wp-core`
- [ ] Every destructive op has a `--dry-run` and a UI confirmation
- [ ] Every AJAX action: nonce + capability, documented in `docs/hooks.md`
- [ ] Every module: enabled/disabled cost benchmark recorded
- [ ] CHANGELOG updated per phase
