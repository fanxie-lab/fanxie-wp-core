# CLAUDE.md — Fanxie Warden

Guidance for Claude Code when working in this repository. The source of truth for *what* to build is [`_PRD/prd-fanxie-wp-core-v0.5.md`](./_PRD/prd-fanxie-wp-core-v0.5.md). This file defines *how* we build it.

Progress lives in [`_PRD/checklist-fanxie-wp-core.md`](./_PRD/checklist-fanxie-wp-core.md) — always consult and update it when finishing work.

---

## 1. Identity & Positioning

- **Plugin name:** Fanxie Warden
- **Slug / text domain:** `fanxie-warden`
- **PHP namespace root:** `FanxieLab\Warden`
- **Author:** Fanxie Lab
- **Destination:** WordPress.org public release (day-one wp.org compliance)
- **Quality bar:** Plugin Check passes with **100%** (zero errors, zero warnings) before any release is cut.
  - *Scaffold-phase concession:* during Phase 0–4 the CI job `plugin-check` (see `.github/workflows/ci.yml`) is configured with `wp-plugin-check-ignore-warnings: true` so expected scaffolding-era warnings (missing banner/icon, placeholder screenshots, `Stable tag: 0.1.0-dev`) do not block PRs. Phase 7 (wp.org submission) flips this flag to `false`; warnings become hard-fail. Expected warnings are catalogued in [`docs/plugin-check-notes.md`](./docs/plugin-check-notes.md).

---

## 2. Agent Routing — hard rules

The user has set explicit agent ownership. When the scope is clear, delegate; do not write the code yourself.

| Domain | Agent |
|---|---|
| PHP (plugin runtime, modules, REST/AJAX endpoints, WP-CLI, tests, build/CI, wp.org compliance) | `wordpress-development-expert` |
| JS/TS/CSS (Vue 3 admin SPA, components, Vite build, styling, accessibility) | `frontend-expert` |

- Cross-cutting tasks (e.g., wiring a new AJAX endpoint to a Vue view) → dispatch **both in parallel**, brief each with the contract at the boundary (endpoint shape, payload, nonce strategy).
- The main agent owns: PRD interpretation, phase planning, checklist maintenance, CLAUDE.md updates, cross-agent coordination, and final acceptance review.
- Never delegate understanding — if a spec is ambiguous, resolve it with the user before spawning an agent.

---

## 3. Architecture — non-negotiables

### 3.1 PHP

- **Target:** PHP 8.1+, WordPress 6.4+ (per PRD §2.3). Use modern language features (typed properties, readonly, enums, `match`, named args).
- **PSR-4 autoloading** via Composer. Directory → namespace mapping:
  - `src/` → `FanxieLab\Warden\`
  - `src/Modules/SecurityHeaders/` → `FanxieLab\Warden\Modules\SecurityHeaders\`
  - `tests/` → `FanxieLab\Warden\Tests\`
- Composer dependencies are committed to `vendor/` on tagged releases (wp.org requirement); runtime deps must be GPL-compatible.
- **Module contract:** every module extends `FanxieLab\Warden\Modules\ModuleBase` and implements `id()`, `name()`, `register_hooks()`, `get_settings_fields()`, `get_default_config()`. There is no module-level `is_enabled()` flag — each module exposes fine-grained toggles through its own settings, and runtime emitters consult those settings to decide whether to do any work.
- **No global state.** Use DI through the core `Plugin` container. No singletons except the plugin bootstrap.
- **Options:** one prefix — `fanxie_warden_*`. One namespaced option per module (`fanxie_warden_<module_id>_settings`) to keep `wp_options` tidy.
- **Custom tables:** prefix `{$wpdb->prefix}fx_warden_` (e.g., `wp_fx_warden_csp_violations`, `wp_fx_warden_login_log`, `wp_fx_warden_login_bans`, `wp_fx_warden_activity_log`). Install via `dbDelta`, version-tracked (one `fanxie_warden_<...>_version` option per table).
- **Hooks API:** prefix custom hooks `fanxie_warden/` (e.g., `fanxie_warden/module/registered`). Documented in `docs/hooks.md`. (Exception: WP-Cron event names are flat, e.g. `fanxie_warden_login_protection_prune`.)
- **Capabilities:** gate admin actions behind a dedicated cap `manage_fanxie_warden` (mapped to `manage_options` by default, overridable via filter).
- **User-facing constants & CLI naming:** wp-config **override constants** use the `FX_WARDEN_*` prefix (e.g. `FX_WARDEN_LOGIN_SLUG`, `FX_WARDEN_DELETE_ALL_DATA`); the **WP-CLI root command is `fx-warden`** (e.g. `wp fx-warden login reveal`). Internal bootstrap constants are `FANXIE_WARDEN_*` (VERSION/PATH/URL). Applies to all modules.
- **Dev checkout folder stays `fanxie-wp-core`** (repo and local directory are not renamed, by user decision 2026-10-01). wp-env therefore mounts the plugin at `wp-content/plugins/fanxie-wp-core`; dev scripts and `.wp-env.json` use that path. The shipping slug is still `fanxie-warden` — the release build is responsible for packaging under that folder name.

### 3.2 Security

- Every input sanitized with the narrowest matching function (`sanitize_text_field`, `absint`, `sanitize_key`, `wp_kses` with explicit allowlist, etc.).
- Every output escaped at the point of output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`). Never trust stored data.
- All AJAX / REST endpoints: nonce check (`check_ajax_referer` / `permission_callback`) **and** capability check. Both, always.
- `$wpdb` queries use `prepare()`. Raw SQL only where the PRD explicitly shows it (bulk cleanup in Database Maintenance) and only against internal tables.
- No `eval`, no `create_function`, no `extract`, no dynamic `include` of user-influenced paths.
- No remote calls without user opt-in, per PRD §2.4.

### 3.3 i18n

- Text domain `fanxie-warden` on **every** user-facing string. Use `__`, `esc_html__`, `esc_attr__`, `_n`, `_x` appropriately.
- No variables inside `__()`. Use `sprintf` with translator comments:
  ```php
  /* translators: %s: module name */
  esc_html__( 'Module %s enabled.', 'fanxie-warden' );
  ```
- `.pot` file generated via `wp i18n make-pot`. Regenerate before every release.

### 3.4 Vue 3 Admin SPA

- **Stack:** Vue 3 + `<script setup>` + Composition API, **TypeScript**, Pinia for state, Vite for build. Tailwind optional per module; if used, scoped with a prefix to avoid collisions with WP admin styles.
- **Structure:**
  ```
  assets/admin/
  ├── src/
  │   ├── main.ts                 # entry, mounts per-module apps
  │   ├── api/                    # AJAX client (nonce-aware wrapper)
  │   ├── stores/                 # Pinia stores (one per module)
  │   ├── components/             # shared UI primitives
  │   ├── modules/
  │   │   ├── SecurityHeaders/
  │   │   ├── Hardening/
  │   │   └── ...
  │   └── types/                  # shared TS types (mirror PHP contracts)
  ├── vite.config.ts
  ├── package.json
  └── tsconfig.json
  ```
- **Build output:** `assets/admin/dist/` — checked in for wp.org (with unminified source in `assets/admin/src/`, satisfying PRD §2.4 "no minified code without unminified source").
- **Enqueue:** admin assets load **only on the plugin's settings screens** (screen ID check). Never on the frontend, never globally.
- **No CDN dependencies at runtime.** No exceptions — every asset the admin SPA loads ships with the plugin.
- **Bridging to PHP:** hydrate initial state via `wp_add_inline_script( 'fanxie-warden-admin', 'window.fanxieWarden = ' . wp_json_encode( $bootstrap ), 'before' )`. Do not echo JSON into the DOM.
- **AJAX over REST for the admin UI**, per user direction. Each action = one `admin-ajax.php` action registered as `fanxie_warden_<action>`. REST routes reserved for external integrations (the CSP report endpoint, future webhooks).
- **Accessibility:** WCAG 2.1 AA. Every interactive element keyboard-reachable, labelled, and screen-reader tested. Settings forms use native labels, not placeholder-as-label.
- **Setting help is a standard, not a one-off.** Every setting exposes an explanation: simple toggles via the accessible `Tooltip` primitive (keyboard-focusable ⓘ), complex or risky settings via inline `HelpText`. To wire `HelpText` to a `Toggle` for screen readers, pass the help element's id through the Toggle's `describedby` prop (a raw `aria-describedby` on `<Toggle>` falls through to its wrapper `<div>`, not the switch). All current and future modules follow this pattern.

---

## 4. Testing

### 4.1 PHP

- **PHPUnit** (WordPress test suite via `wp-env` or `@wordpress/env` for CI parity).
- **Coverage target:** ≥80% line coverage on `src/` before any phase is marked done. Unit tests for pure logic; integration tests for hooks, options, custom tables, AJAX handlers.
- **Fixtures:** factory pattern, no shared mutable state between tests. Database rolled back between tests.
- **Mocking:** Brain Monkey for WP function mocks in unit tests; real WP for integration tests.

### 4.2 JavaScript / TypeScript

- **Vitest** for unit tests of composables, stores, and pure utilities.
- **Vue Test Utils** for component tests. One test file per component covering render, user interaction, and emitted events.
- **MSW** (Mock Service Worker) for stubbing `admin-ajax.php` responses in component tests.
- **Type-check in CI:** `tsc --noEmit` must pass with zero errors.

### 4.3 End-to-End (light)

- **Playwright** scripts for the critical admin flows (enable a module, save settings, see success toast) running against `@wordpress/env`. Smoke-level only — not a substitute for unit/integration tests.

### 4.4 Linting & formatting

| Tool | Scope | Enforced in CI |
|---|---|---|
| PHPCS (WordPress-Extra ruleset) | `*.php` | yes |
| PHPStan level 8 | `src/` | yes |
| Plugin Check | full plugin | yes — **must pass 100%** |
| ESLint + `@wordpress/eslint-plugin` | `assets/admin/src/**/*.{ts,vue}` | yes |
| Prettier | JS/TS/Vue/CSS | yes |
| `tsc --noEmit` | admin SPA | yes |

CI runs on every PR and blocks merge on failure.

---

## 5. Repository layout (target)

```
fanxie-warden/
├── fanxie-warden.php           # bootstrap only — version, constants, activation, require autoloader
├── readme.txt                    # wp.org format
├── uninstall.php                 # full cleanup when requested
├── composer.json
├── composer.lock
├── phpunit.xml.dist
├── phpcs.xml.dist
├── phpstan.neon.dist
├── package.json                  # workspace root (scripts proxy to assets/admin)
├── CLAUDE.md                     # this file
├── _PRD/                         # product requirements + checklist
├── src/                          # PHP, PSR-4 → FanxieLab\Warden
│   ├── Plugin.php
│   ├── Modules/
│   │   ├── ModuleBase.php
│   │   ├── ModuleRegistry.php
│   │   ├── SecurityHeaders/
│   │   ├── Hardening/
│   │   └── ...
│   ├── Admin/
│   │   ├── SettingsPage.php
│   │   └── AjaxRouter.php
│   ├── Cli/
│   └── Support/                  # shared utilities, value objects
├── assets/
│   └── admin/                    # Vue 3 SPA (see §3.4)
├── languages/                    # .pot, .po, .mo
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── bootstrap.php
└── vendor/                        # committed on tagged releases
```

---

## 6. Git & release hygiene

- Branch per phase (`phase/0-foundation`, `phase/1-security-headers`, …). Feature branches off the phase branch.
- Conventional Commits (`feat:`, `fix:`, `chore:`, `test:`, `docs:`, `refactor:`, `perf:`, `ci:`). Scope = module id when applicable: `feat(security-headers): add HSTS toggle`.
- `readme.txt` `Stable tag` and the `Version:` header in `fanxie-warden.php` always match the current tag.
- A phase is "done" only when: checklist items ticked, tests green, Plugin Check 100%, PHPStan clean, checklist updated, CHANGELOG entry written.

---

## 7. Working style

- Read the PRD section before touching a module. PRD > inference.
- Edit existing files — do not create new docs unless explicitly asked.
- Keep the checklist current; tick items as completion happens, not in batches.
- When a decision diverges from the PRD, write it down in the relevant module's section in this file (not in code comments).
- Before declaring a phase complete, run: `npm run check` (phpcs + phpstan via `composer run check`, then prettier + eslint + vue-tsc + vitest), `composer test` (PHPUnit unit suite), `npm run test:php:integration` (integration suite in wp-env), and Plugin Check against the shippable package. All green, or the phase isn't done. All green, or the phase isn't done.

---

## 8. Module decisions — PRD divergences

### Database Maintenance (PRD §8) — decided 2026-10-01, spec `docs/superpowers/specs/2026-10-01-database-maintenance-design.md`

- **WP-Cron, not Action Scheduler** (PRD §8.3, §9.3). Matches existing modules and avoids bundling a dependency; Action Scheduler was justified by Media Optimizer, now deferred. Also applies to Activity Log retention.
- **Revision limit OFF by default, 20 pre-filled** (PRD: ON at 10). One `revisions_keep` value drives both the future-revision cap and the purge. `WP_POST_REVISIONS` in wp-config wins and locks the setting.
- **"Delete all transients" is CLI-only** (`wp fx-warden db transients --all`); the admin UI and schedule only ever purge expired transients.
- **Trash age uses `_wp_trash_meta_time`** (when trashed), not PRD's `post_modified`; deletions go through WP APIs in time-budgeted batches rather than the PRD's unbatched loops.
