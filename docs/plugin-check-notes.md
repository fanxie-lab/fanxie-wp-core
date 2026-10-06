# Plugin Check — scaffold baseline notes

Tracker of **expected** Plugin Check output for the current skeleton (Phase 0.4). Each entry lists the expected finding, its severity, why we tolerate it at this stage, and which later phase closes it.

The CI workflow (`.github/workflows/ci.yml`) currently allows warnings through and fails only on errors. Before the first public wp.org release (Phase 7) this flips — both errors and warnings must be zero.

Authoritative rule reference: <https://github.com/WordPress/plugin-check>.

---

## Warnings we expect (and accept) at the scaffold stage

### 1. `Stable tag` may drift from release version

- **Finding:** `readme.txt` has `Stable tag: 0.1.0-dev`. Plugin Check may flag this as non-release-ready.
- **Why tolerated now:** the plugin is unreleased; the `-dev` suffix deliberately marks the pre-1.0 scaffold.
- **Resolved in:** Phase 0.5 (docs + CHANGELOG init will bump as versions cut) and hard-locked in Phase 7 (wp.org submission).

### 2. Missing wp.org banner / icon assets

- **Finding:** No `banner-1544x500.png`, `banner-772x250.png`, `icon-256x256.png`, or `icon-128x128.png` at the **repo-root** `/assets/` path. Plugin Check's asset sniffs may note absence.
- **Why tolerated now:** design assets are a Phase 7 deliverable. The `assets/admin/` directory is the Vue SPA workspace, **not** the wp.org asset directory. wp.org assets will live alongside it (e.g. `/assets/banner-*.png`) or in the SVN `/assets/` branch when published.
- **Resolved in:** Phase 7 (wp.org submission).

### 3. Missing screenshots

- **Finding:** `readme.txt` references screenshots 1–4 as "(placeholder)", and no `screenshot-*.png` files exist.
- **Why tolerated now:** UI is not yet built — every module ships its own Vue tab in Phases 1–6.
- **Resolved in:** Phase 7, after every module's settings UI exists.

### 4. `Tested up to` version

- **Finding:** `Tested up to: 6.7` in both `readme.txt` and the plugin header. If WP ships a newer minor during development, Plugin Check warns about staleness.
- **Why tolerated now:** we bump this with each release, not each sprint.
- **Resolved in:** ongoing (bumped at every `tag` / stable release).

### 5. Empty plugin functionality

- **Finding:** Plugin Check may notice that no modules are registered (registry is empty until Phase 1) and that the admin page shows a mount node with no tabs.
- **Why tolerated now:** this is the foundation phase — modules land progressively.
- **Resolved in:** Phases 1–6 (module rollout).

### 6. `PluginCheck.Security.DirectDB.UnescapedDBParameter` in Database Maintenance (10 warnings)

- **Finding:** Plugin Check flags 10 `$wpdb->get_col()` / `get_var()` / `get_results()` calls in `src/Modules/DatabaseMaintenance/Cleanup/` because the SQL passed to `prepare()` (or the query itself) is built by interpolation:
  - `TrashedPostsTask.php` lines 75, 87, 97 (shared `$from` / `$not_in` fragments)
  - `AutoDraftsTask.php` line 66 (`$not_in` placeholder list)
  - `OrphanedMetaTask.php` lines 115, 124, 138, 166 (`from_clause()` and the meta/parent table identifiers from the fixed `specs()` map)
  - `SpamCommentsTask.php` line 61 (`$not_in` placeholder list)
  - `AllTransientsTask.php` line 61 (pre-built `$sql` with `%s` placeholders)
- **Why tolerated now:** every interpolated piece is an identifier (core `$wpdb->*` table names, column names from a hard-coded map) or a string of `%d` / `%s` placeholders built from `count()` of the exclude list. No user input reaches the SQL text; all values go through `$wpdb->prepare()` arguments. The sniff cannot see through the helper methods, so it reports a possible unescaped parameter. The same patterns are already annotated for PHPCS (`WordPress.DB.PreparedSQL.*`).
- **Resolved in:** Phase 7. Preferred fix is `%i` identifier placeholders (WordPress 6.2+ `prepare()`) for table and column names; where the fragment is a shared `FROM` / `NOT IN` clause, restructure so each query is a single literal string handed to `prepare()` (or build the placeholder list inline in the call). Line numbers drift; re-run Plugin Check to relocate them.

---

## Findings that must stay at zero from day one

These are **not** expected. If Plugin Check flags any of the following, treat it as a bug, not a tolerated warning.

- **Text domain mismatches.** Every `__()`, `_e()`, `esc_html__()`, `esc_attr__()`, `_n()`, `_x()` call in PHP **must** carry `'fanxie-warden'`. Scan confirmed clean at Phase 0.4 baseline (see verification below).
- **Direct database calls without `$wpdb->prepare()`.** Current usage in `uninstall.php` is verified — both `$wpdb->get_col()` calls use `prepare()`; the `DROP TABLE` call operates on internally-sourced table names filtered by our own prefix, documented inline.
- **Unescaped output.** `SettingsPage::render()` contains one `phpcs:ignore` for `wp_get_inline_script_tag()` — the ignore is justified in the inline comment (the helper is the documented-correct method).
- **Missing nonce / capability checks on AJAX or REST handlers.** `AjaxRouter::dispatch()` verifies both, per the contract.
- **Hardcoded URLs.** None present — `FANXIE_WARDEN_URL`, `admin_url()`, `rest_url()`, etc. are used consistently.
- **`eval`, `create_function`, `extract`, or dynamic includes.** None present.

---

## Verification performed at Phase 0.4

| Check | Command | Result |
|---|---|---|
| Every PHP source parses | `php -l` on every file in `src/`, `fanxie-warden.php`, `uninstall.php` | Pass |
| Every translation call has text domain | grep for `__(`, `_e(`, `esc_*__(`, `_n(`, `_x(` across all PHP | Pass — every hit includes `'fanxie-warden'` |
| JSON configs parse | `node -e "JSON.parse(...)"` on `composer.json`, `package.json` | Pass |
| YAML workflow parses | `node -e "require('yaml').parse(...)"` when `yaml` dep available, otherwise manual review | Pass |

---

## When warnings flip to errors

Per `CLAUDE.md` §2, "Plugin Check passes with **100%** (zero errors, zero warnings) before any release is cut." The CI flag `wp-plugin-check-ignore-warnings: true` is therefore a **temporary scaffold-phase concession**. Track this flip in the Phase 7 checklist item "Full Plugin Check **zero** errors/warnings on complete plugin".
