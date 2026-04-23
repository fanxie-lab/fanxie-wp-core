# Changelog

All notable changes to Fanxie WP Core are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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

### Security
- Security Headers: HSTS and its `includeSubDomains` flag are now **off**
  by default with an inline warning explaining the lock-in risk. The
  prior defaults could brick a site that wasn't fully on HTTPS.

### Changed
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
