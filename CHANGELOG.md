# Changelog

All notable changes to Fanxie WP Core are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
