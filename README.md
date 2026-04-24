# Fanxie WP Core

> WordPress plugin consolidating security, performance, and maintenance modules by Fanxie Lab.

[![CI](https://github.com/fanxie-lab/fanxie-wp-core/actions/workflows/ci.yml/badge.svg)](https://github.com/fanxie-lab/fanxie-wp-core/actions/workflows/ci.yml)

## About

Most production WordPress sites stack 5–6 overlapping plugins for security headers, hardening, login protection, bot mitigation, maintenance, and asset management — each with its own UI, options table, and footprint. Fanxie WP Core replaces that stack with a single opinionated plugin whose modules share one bootstrap, one options prefix, and one admin surface, and cost nothing at runtime when disabled. Primary audience: Fanxie Lab client deployments and WordPress operators who prefer an integrated, auditable toolkit over a plugin sprawl.

Full product scope lives in [`_PRD/prd-fanxie-wp-core-v0.5.md`](./_PRD/prd-fanxie-wp-core-v0.5.md). User-facing copy ships in [`readme.txt`](./readme.txt) (wp.org format) — this README is the developer/contributor entry point.

## Requirements

- PHP **8.1+**
- WordPress **6.4+**
- Node **20 LTS+** (with npm 10+)
- Docker (for `@wordpress/env`) — Docker Desktop or OrbStack

Full environment walkthrough: [`docs/local-dev.md`](./docs/local-dev.md).

## Local development

Four one-liners get you a working plugin in a containerised WP install:

```bash
npm install
(cd assets/admin && npm install)
npm run env:start
(cd assets/admin && npm run dev)   # Vite HMR, optional
```

Then visit <http://localhost:8888/wp-admin/options-general.php?page=fanxie-wp-core>.

Default wp-env credentials: **admin** / **password**. (Do not use these outside local dev.)

## Testing

| Command | What it runs |
|---|---|
| `composer run check` | PHPCS (WordPress-Extra + WordPress-Docs) + PHPStan level 8 |
| `composer run test:unit` | PHPUnit unit suite (Brain Monkey, no WordPress runtime) |
| `composer run test:integration` | PHPUnit integration suite (against `@wordpress/env` tests-cli) |
| `cd assets/admin && npm run check` | Prettier + ESLint + `tsc --noEmit` + Vitest |
| `npm run test:e2e` | Playwright smoke tests |
| `npm run env:cli -- plugin check fanxie-wp-core` | WordPress Plugin Check (wp.org compliance) |

All of the above must be green before a phase is considered complete — see the exit criteria at the top of [`_PRD/checklist-fanxie-wp-core.md`](./_PRD/checklist-fanxie-wp-core.md).

## Architecture

PHP runtime follows strict WordPress + PSR-4 conventions under the `FanxieLab\WPCore` namespace, with every module extending `ModuleBase` and costing zero when disabled. The admin UI is a Vue 3 + TypeScript + Pinia SPA loaded only on plugin screens and talking to PHP via nonce-gated `admin-ajax.php` actions. See [`CLAUDE.md`](./CLAUDE.md) §3 (PHP conventions), §3.4 (Vue SPA), and the public hooks contract in [`docs/hooks.md`](./docs/hooks.md).

## Contributing

- **CI gates must stay green.** PHPCS, PHPStan level 8, ESLint, Prettier, `tsc --noEmit`, Vitest, PHPUnit, and Plugin Check all run on every PR and block merge on failure.
- **Agent routing.** PHP work (plugin runtime, modules, AJAX/REST, tests, CI) is owned by the `wordpress-development-expert` agent; JS/TS/CSS work (Vue SPA, components, Vite, styling, accessibility) is owned by `frontend-expert`. Cross-cutting tasks dispatch both in parallel with the boundary contract briefed to each. See [`CLAUDE.md`](./CLAUDE.md) §2 for the full routing rules.

## License

GPL-2.0-or-later — same as WordPress core. See the `License` line in [`readme.txt`](./readme.txt) and the plugin header in [`fanxie-wp-core.php`](./fanxie-wp-core.php).
