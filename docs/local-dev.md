# Local Development Environment

This project uses [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (wp-env) to provide a fully containerised WordPress environment. Your checkout is mounted into the running container as an active plugin, so edits to source files are reflected immediately.

---

## 1. Prerequisites

| Tool | Version | Notes |
|---|---|---|
| Docker | Running daemon | [Docker Desktop](https://www.docker.com/products/docker-desktop/) or [OrbStack](https://orbstack.dev/) (recommended on macOS — lighter than Docker Desktop). |
| Node.js | 20 LTS or newer | Check with `node --version`. |
| npm | 10+ | Ships with Node 20. |

Nothing else is required on the host. PHP, MySQL, WP-CLI, Composer, and PHPUnit all run inside the containers.

---

## 2. First-time setup

From the repo root:

```bash
npm install
npm run env:start
```

`env:start` will:

1. Pull the required Docker images (first run only — takes a few minutes).
2. Boot two WordPress sites — `development` and `tests` — each on its own MySQL instance.
3. Install WordPress, activate **Fanxie Warden** (this repo), **WooCommerce**, and **Plugin Check**.
4. Install the Twenty Twenty-Four and Twenty Twenty-Three themes.

> **Expected first-boot warning:** until the plugin bootstrap file (`fanxie-warden.php`) exists, WordPress will either fail to activate the plugin or list it as "Invalid Plugin" on the Plugins screen. This is expected during Phase 0.0 and resolves itself once Phase 0.1 lands.

---

## 3. URLs & credentials

| Env | URL | Admin URL | User | Password |
|---|---|---|---|---|
| `development` | http://localhost:8888 | http://localhost:8888/wp-admin | `admin` | `password` |
| `tests` | http://localhost:8889 | http://localhost:8889/wp-admin | `admin` | `password` |

Both are the wp-env defaults. Do not use these credentials outside local dev.

---

## 4. Daily workflow

| Task | Command |
|---|---|
| Start containers | `npm run env:start` |
| Stop containers (keep state) | `npm run env:stop` |
| Restart | `npm run env:restart` |
| Tail logs (dev) | `npm run env:logs` |
| Tail logs (tests) | `npm run env:logs:tests` |
| WP-CLI (dev) | `npm run env:cli -- <subcommand>` — e.g. `npm run env:cli -- plugin list` |
| WP-CLI (tests) | `npm run env:cli:tests -- <subcommand>` |
| MySQL shell | `npm run db:cli` |
| Run Plugin Check | `npm run plugin:check` |
| Reset everything | `npm run env:destroy && npm run env:start` |

### Running PHPUnit

PHPUnit executes **inside the tests container** so it uses the WordPress test bootstrap provided by wp-env:

```bash
npm run test:php
npm run test:php:coverage
```

> **Phase note:** PHPUnit config (`phpunit.xml.dist`) and the `vendor/` directory do not exist yet — they land in Phase 0 proper along with `composer.json`. Until then, `npm run test:php` will fail with "phpunit not found", which is expected.

### Running the Vue admin SPA

Handled separately inside `assets/admin/` (Phase 0.4). That workspace has its own `package.json`, Vite dev server, and Vitest runner.

---

## 5. Port conflicts & other overrides

If `8888`, `8889`, `33306`, or `33307` are already in use on your machine, copy the override template:

```bash
cp .wp-env.override.json.example .wp-env.override.json
# edit to taste, then:
npm run env:restart
```

`.wp-env.override.json` is gitignored — safe for per-developer tweaks. It merges over
`.wp-env.json`, so only include the keys you want to change; `port` (development) and
`testsPort` (tests) are usually enough.

Note: wp-env validates the file strictly and rejects unknown keys — JSON has no comment
syntax and a `_comment` key fails with `"_comment" is not a configuration option`.

---

## 6. Troubleshooting

| Symptom | Fix |
|---|---|
| `Error: Cannot connect to the Docker daemon` | Start Docker Desktop / OrbStack. |
| `port is already allocated` | Use `.wp-env.override.json` to change the port (see §5). |
| Weird state — plugin won't activate, DB stuck, migrations wrong | `npm run env:destroy && npm run env:start` (destroys containers + volumes; uploads are lost). |
| Only the DB needs resetting | `npm run env:clean` or the scoped `env:clean:dev` / `env:clean:tests`. |
| WordPress says "Invalid Plugin" for the `fanxie-wp-core` plugin folder | Expected until Phase 0.1 adds the plugin bootstrap file. |
| `wp-env` hangs on "Starting WordPress..." | Stop it (Ctrl-C), then `docker ps -a` and remove stale containers, or `npm run env:destroy`. |
| PHPUnit: `phpunit: not found` | Expected until Composer setup lands (Phase 0). |

---

## 7. Quality gate — Plugin Check

Every release must score **100% on Plugin Check** (zero errors, zero warnings). See [`CLAUDE.md`](../CLAUDE.md) §4 for the full quality bar.

Run it any time with:

```bash
npm run plugin:check
```

---

## 8. Further reading

- [`CLAUDE.md`](../CLAUDE.md) — engineering standards, architecture rules, repo layout.
- [`_PRD/prd-fanxie-wp-core-v0.5.md`](../_PRD/prd-fanxie-wp-core-v0.5.md) — product requirements.
- [`_PRD/checklist-fanxie-wp-core.md`](../_PRD/checklist-fanxie-wp-core.md) — phase tracker.
- [`@wordpress/env` docs](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
