# Changelog

All notable changes to Fanxie WP Core are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- Renamed the uninstall opt-in constant `FANXIE_WP_CORE_DELETE_ALL_DATA` ->
  `FX_CORE_DELETE_ALL_DATA`, bringing it in line with the `FX_CORE_*` convention
  for wp-config override constants. It was the last user-facing constant still on
  the old prefix. Both wp-config override constants are now listed in
  `docs/hooks.md`. Pre-release rename with no deprecation shim: the plugin has
  never been tagged, so no site can have the old constant defined.

### Added
- Environment Health module, PHP side (PRD §7): read-only reporting on software
  versions (WordPress, PHP, MySQL/MariaDB, TLS certificate expiry, HTTPS), cron
  health (`DISABLE_WP_CRON`, a wedged `doing_cron` lock, overdue events), debug
  exposure (`WP_DEBUG`, `WP_DEBUG_DISPLAY`, a debug log inside the web root,
  `SCRIPT_DEBUG`, PHP `display_errors` / `error_reporting`), and plugin/theme
  hygiene (inactive plugins, unused non-default themes, abandoned plugins).
  Ships a dashboard widget, four AJAX sub-actions
  (`environment-health/get-report`, `refresh`, `get-config`, `save-config`), and
  a twice-daily `fanxie_wp_core_environment_health_scan` event.
- Environment Health support matrix is expressed as **published end-of-support
  dates per branch**, not as version comparisons. PRD §7.2 specified thresholds
  as literals ("critical below 8.1"), which were already stale when the module
  was written; deriving status from dates means "PHP 8.2 is EOL" becomes true on
  the right day with no code change, and a branch missing from the matrix
  reports `unknown` rather than a guess. `SupportMatrix::REVIEWED_ON` records
  when the tables were last verified against php.net and endoflife.date.
- Environment Health talks to **api.wordpress.org** to detect abandoned plugins.
  On by default, with a `wporg_scan_enabled` opt-out that also discards every
  cached result, 24-hour per-plugin caching, and batching so a site with dozens
  of plugins never fires dozens of blocking requests at once — the scan runs on
  a schedule or on an explicit refresh, never during a page render. Disclosed in
  a new `readme.txt` **External services** section naming what is sent (plugin
  slugs only), when, and linking wordpress.org's privacy policy.
- Environment Health thresholds are range-checked server-side. The four numeric
  settings previously used a bare `absint`, which accepted `0` and any ceiling —
  and `absint('')` / `absint('garbage')` both land on `0`, the value that breaks
  each check silently rather than loudly (`cron_overdue_minutes = 0` alarms
  permanently; `ssl_expiry_warning_days = 0` never warns at all). Each is now
  clamped to a documented range declared once in
  `EnvironmentHealth::THRESHOLD_RANGES` (SSL 1–365, cron 1–1440, abandoned
  warning/critical 30–3650), which also supplies the defaults and publishes
  `min`/`max` to the admin UI. Out-of-range input clamps to the nearest limit
  rather than reverting to the default; non-numeric input falls back to the
  default. `abandoned_critical_days` below `abandoned_warning_days` — coherent
  to each field's own bounds check, but a config in which nothing is ever
  flagged critical — is resolved by raising critical to match. The rules apply
  on read as well as write, so options stored before they existed self-heal.
- Environment Health TLS check degrades gracefully: a short connect timeout, a
  12-hour cache, and an explicit `unknown` status ("couldn't check — this host
  may block outbound connections") when the socket fails, rather than a false
  "certificate missing" alarm.
- Environment Health admin tab (Vue): status cards grouped by check family, each
  row carrying a pill, the observed value, and an expandable detail with
  copy-paste remediation snippets. All 11 settings are exposed — four check-group
  gates, the wordpress.org scan, the TLS probe, the dashboard widget, and four
  numeric thresholds with client-side bounds read from the server's published
  `min`/`max`. `unknown` is a first-class state (dashed neutral border, "Couldn't
  check"), visually distinct from both healthy and critical, for hosts that block
  the outbound TLS probe.
- Environment Health admin tab is split into `Checks` and `Settings` sub-tabs
  (deep-linkable, following the SecurityHeaders parent/children route pattern).
  The page header, "last checked" timestamp, Re-run control, and the
  OK/warning/critical counts stay pinned in the parent shell, so the health
  verdict remains visible while settings are being changed. Each tab lazy-loads
  as its own chunk. Leaving the Settings tab with unsaved edits now prompts
  through the shared `ConfirmDialog` — behind a tab the SaveBar is no longer
  always on screen, so dirty state could otherwise be lost silently; discarding
  resets the store rather than leaving the edits to reappear or be saved later.
- Shared `CodeSnippet` component promoted out of Environment Health and adopted by
  Hardening, which fixes a pre-existing accessibility bug: Hardening's copy button
  confirmed only via a silent icon swap, with no `aria-live` announcement.
- Shared `TextField` gained real `min`/`max`/`step`/`inputmode` props and a `blur`
  emit. These had to be props rather than fall-through attributes: `TextField`
  does not set `inheritAttrs: false`, so bare `min`/`max` would have landed on the
  wrapper element and enforced nothing — the same trap CLAUDE.md §3.4 documents
  for `Toggle`'s `describedby`.
- Login Protection module (PRD §5): brute-force attempt limiting with tiered
  lockouts (default on; per-IP by default with an opt-in username dimension,
  transient-backed, echo-suppressed so a
  locked subject can't renew its own lockout), a trusted-IP allowlist and
  `REMOTE_ADDR`-only IP resolution (opt-in reverse-proxy header), hide-`wp-login`
  behind a custom slug (`/wp-admin` redirect, `action=` variants, admin-ajax/
  REST/cron carve-outs, `FX_CORE_LOGIN_SLUG` override + admin email on change),
  strong-password enforcement on new/changed passwords, and role-based session
  timeout (`auth_cookie_expiration`, shorten-only, + an admin idle-logout script).
  Two custom tables (`fanxie_core_login_log`, `fanxie_core_login_bans`, `dbDelta`
  version-tracked), a daily retention prune, a Vue admin tab (attempt config,
  lockout log with ban/unban, slug changer, password-policy builder, per-role
  timeouts), and WP-CLI recovery commands `wp fx-core login reveal` / `unlock`.
- Naming convention: user-facing wp-config override constants use the `FX_CORE_*`
  prefix and the WP-CLI root command is `fx-core` (project-wide).
- Hardening module (PRD §4): information-leakage and attack-surface
  reduction across user enumeration (`?author=N`, REST `/users`),
  XML-RPC (disable / restrict methods / IP allowlist with `X-Pingback`
  stripping), version hiding (generator meta, feed generators, asset
  `?ver=`, `readme.html` / `license.txt` 404), uploads protection
  (idempotent `index.php` + Apache `.htaccess` drop plus nginx snippet
  surfacer and live HTTP probes), login error obfuscation,
  `DISALLOW_FILE_EDIT` detection with runtime `file_mod_allowed`
  fallback, and Application Password disabling. Four new hooks
  documented in `docs/hooks.md`.
- Hardening `RootHtaccessWriter`: idempotent root `.htaccess` block
  writer (between `# BEGIN Fanxie WP Core` / `# END Fanxie WP Core`
  markers) enforcing `Require all denied` on `readme.html` and
  `license.txt` at the Apache layer. The previous PHP-only
  `template_redirect` block never ran because Apache serves static
  files without entering PHP.
- Hardening AJAX surface: two new sub-actions for the uploads status
  row in the Vue admin — `hardening/drop-upload-guard` (re-writes
  `uploads/index.php` or `uploads/.htaccess`) and
  `hardening/remove-upload-guard` (deletes them).
- `StatusInspector` snapshot: `readme_blocked` and `license_blocked`
  booleans derived from live HTTP probes of `home_url('/readme.html')`
  and `home_url('/license.txt')`, plus a new
  `fanxie_wp_core/hardening/root_htaccess_path` filter.

### Fixed
- Hardening `StatusInspector` — `x_powered_by_present` now fires an
  HTTP probe against the frontend (`home_url('/')`) and inspects the
  response headers case-insensitively, rather than reading
  `headers_list()` during an admin-ajax request (which has no bearing
  on what the public pipeline emits).
- Hardening `UploadsProtector::probe_php_execution()` — the null-return
  path is now reserved for "couldn't even write the canary", so a
  working probe against a genuinely blocked URL no longer leaves the
  status row stuck on "inconclusive"; the canary is always deleted in
  a `finally` block.
- Hardening `XmlRpcGate` `disabled` mode — in addition to
  `xmlrpc_enabled => false`, we now empty the method table at
  `PHP_INT_MAX` and 403 via `xmlrpc_call` before any handler runs.
  `restrict_methods` / `restrict_ips` filters were also bumped to
  `PHP_INT_MAX` so plugins re-adding `pingback.*` after our filter
  can't sneak past.
- Hardening `VersionHider` — `readme.html` / `license.txt` block now
  runs on `init` priority 1 as a PHP fallback, while the primary path
  is the new `.htaccess` snippet (Apache serves these static files
  without entering PHP, so `template_redirect` never fired).
- Hardening `AjaxController` — `save-config` now invalidates the
  `StatusInspector` transient after `update_config()` (mirroring
  `apply-fix`) so the next `get-config` re-probes against the new
  settings.
- Hardening file-editor lockdown now actually hides the Theme and Plugin
  File Editors. The runtime guard matched `edit_themes` / `edit_plugins`,
  but WordPress gates both editors through
  `wp_is_file_mod_allowed( 'capability_edit_themes' )`; it now matches the
  real contexts and still leaves plugin/theme installs and updates alone.
- Hardening uploads/readme protection no longer reports a misleading
  ".htaccess write failed": the uploads `.htaccess` is written only on
  Apache/LiteSpeed, the `readme.html` / `license.txt` probe is now
  tri-state (blocked / served / inconclusive — so a dev host that cannot
  reach its own URL no longer shows a false "still accessible" warning),
  and the messaging is server-aware.
- Hardening Application Passwords status is now site-wide-aware and names
  the holder(s) instead of a dead-end "revoke them" note. The disable
  toggle stays visible and reversible even when app passwords exist, with
  a warning that disabling does not delete them and hides the profile
  revoke UI.

### Security
- Security Headers: HSTS and its `includeSubDomains` flag are now **off**
  by default with an inline warning explaining the lock-in risk. The
  prior defaults could brick a site that wasn't fully on HTTPS.

### Changed
- Admin settings now live under a top-level **FX Core** menu (was
  *Settings → Fanxie WP Core*).
- Security Headers consolidated to two tabs — Response Headers and Content
  Security Policy — with the CSP violation log folded into the CSP tab, and
  HSTS moved into a collapsed "Advanced" section.
- Every admin setting now carries an accessible explanation — a keyboard-
  focusable ⓘ tooltip for simple toggles, inline help text for complex or
  risky ones (Cache-Control gained a full description). Shared `Tooltip` /
  `HelpText` primitives establish this as the standard across all modules.
- Security Headers admin polish: smooth scroll between sub-tabs, refresh
  button on the CSP violations log, HSTS controls re-laid out so
  `includeSubDomains` sits below `max-age`, and the duplicate page
  title/description above each module is removed in favour of the
  module's own icon header.
- PHPStan 1.12 → 2.x (level 8); stricter defaults applied to `src/`.
- Vitest 2 → 3 (frontend test harness).
- PHPUnit 10 → 12 (annotation-free attribute-style test metadata, stricter defaults).

### Added
- Plugin foundation: PSR-4 autoloaded PHP under `FanxieLab\WPCore`, module
  registry + base class, admin settings page under **Settings → Fanxie WP
  Core**, nonce- and capability-gated AJAX router with a `ping` smoke
  sub-action.
- Vue 3 + TypeScript + Pinia admin SPA, self-hosted Inter + Lexend fonts,
  sidebar IA grouping ten future modules into Security, Performance, and
  Maintenance, Lucide icons per module.
- Local dev environment via `@wordpress/env` (WordPress latest, PHP 8.3,
  WooCommerce + Plugin Check preloaded).
- Vite HMR bridge: hot-file marker makes the WP admin pull `/src/main.ts`
  directly from `localhost:5173` while the dev server is running.
- Quality gates: PHPCS (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP),
  PHPStan level 8 with WP stubs, ESLint 9 flat config + Prettier, GitHub
  Actions CI with PHP matrix and Plugin Check.
- Testing harness: PHPUnit (unit + integration modes), Vitest + Vue Test
  Utils + MSW, Playwright smoke test.

### Known
- Vue SPA strings are not yet wired through WordPress's i18n mechanism;
  to be addressed before 1.0 public release.
- `languages/fanxie-wp-core.pot` must be regenerated via
  `wp i18n make-pot` on every release.
- Plugin Check currently ignores warnings during Phase 0–6; flipped to
  strict at Phase 7.

## [0.1.0-dev] — 2026-04-17

Initial scaffold (see the Unreleased section).
