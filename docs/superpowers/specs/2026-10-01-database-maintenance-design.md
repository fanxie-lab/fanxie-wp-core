# Database Maintenance — Design

- **Date:** 2026-10-01
- **Status:** Approved (design), pending implementation plan
- **Branch:** `phase/3-data-hygiene`
- **Scope phase:** Phase 3.1 (PRD §8). Activity Log (Phase 3.2, PRD §9) gets its own spec/plan.
- **Owners:** `wordpress-development-expert` (PHP: module, cleanup tasks, runner, cron, AJAX, WP-CLI, tests), `frontend-expert` (Vue module). The AJAX contract below is the boundary between the two lanes.

## Context

Client sites accumulate database bloat: unlimited revisions, expired transients, orphaned meta, stale auto-drafts, old trash and spam. PRD §8 specifies six cleanups, an admin table with counts/sizes, an optional schedule, and WP-CLI commands. Success means a mid-size client site gets cleaned without losing anything it should keep, nothing is deleted without a preview and an explicit confirmation, and Phase 3 exit criteria (checks, coverage, Plugin Check) pass.

## Decisions locked (from brainstorming)

1. **Scheduling uses WP-Cron, not Action Scheduler** (diverges from PRD §8.3/§9.3). Matches the three existing modules, adds no bundled dependency; Action Scheduler was justified by Media Optimizer bulk jobs, which are deferred. Applies to Activity Log retention too. Recorded in CLAUDE.md.
2. **Revision limit is OFF by default**, with **20** pre-filled (PRD said ON at 10). If `WP_POST_REVISIONS` is defined in wp-config it wins and the setting renders locked, mirroring how `FX_WARDEN_LOGIN_SLUG` locks the login slug.
3. **"Delete ALL transients" is CLI-only** (`wp fx-warden db transients --all`). The admin tab only offers expired transients; the schedule never deletes valid transients.
4. **Purges run as time-budgeted steps** that the client (or CLI/cron) re-invokes until done. One engine for all three entry points; no hidden background work; an abandoned browser tab just leaves a partial, harmless purge.
5. **PRD code samples are illustrative.** Deletions go through WP APIs (hooks fire, object cache stays coherent), batched; the PRD's unbatched loops and `post_modified`-based trash age are not used verbatim.
6. **Naming:** module id `database-maintenance`; CLI `wp fx-warden db …` (PRD's `wp fanxie db …` predates the rename).

## Architecture

All PHP under `src/Modules/DatabaseMaintenance/` (`FanxieLab\Warden\Modules\DatabaseMaintenance`).

| Unit | Responsibility |
|---|---|
| `DatabaseMaintenance.php` | `ModuleBase` implementation: `id()='database-maintenance'`, `name()='Database Maintenance'`, settings fields + defaults, registers the `wp_revisions_to_keep` filter (only when `revision_limit_enabled` and `WP_POST_REVISIONS` is not defined), wires `ScheduledCleanup`, `AjaxController`, `Cli/DbCommand`. |
| `Settings.php` | Value object built from the stored option; owns clamping/validation of every field. Single source for task parameters. |
| `Cleanup/CleanupTask.php` | Interface: `id(): string`, `label(): string`, `count(): int`, `estimate_bytes(): int`, `sample( int $n ): array`, `purge_batch( int $limit ): BatchResult`. |
| `Cleanup/RevisionsTask.php` | Excess revisions beyond newest N per parent. |
| `Cleanup/ExpiredTransientsTask.php` | Expired `_transient_*` / `_site_transient_*` rows. |
| `Cleanup/AllTransientsTask.php` | Every transient row. **CLI-only** — never registered with the AJAX controller or the schedule. |
| `Cleanup/OrphanedMetaTask.php` | Parameterised by meta type (`post`, `user`, `term`, `comment`) → four instances. |
| `Cleanup/AutoDraftsTask.php` | `auto-draft` posts older than N days. |
| `Cleanup/TrashedPostsTask.php` | Trashed posts older than N days. |
| `Cleanup/SpamCommentsTask.php` | Spam comments older than N days. |
| `Cleanup/TaskFactory.php` | Builds tasks from a `Settings` snapshot (CLI flags may override per run); exposes the admin set, the scheduled set, and the CLI set. |
| `Cleanup/CleanupRunner.php` | `run( CleanupTask $task, ?float $budget_seconds, int $batch_size = 500 ): RunResult` — loops `purge_batch()` until the task reports nothing left or the budget is spent. `null` budget = unlimited (CLI). Owns the per-task lock. |
| `Cleanup/RunResult.php`, `Cleanup/BatchResult.php` | Readonly value objects: `deleted`, `failed`, `remaining`, `done`, `busy` (run) / `deleted`, `failed`, `exhausted` (batch). |
| `ScheduledCleanup.php` | Owns cron event `fanxie_warden_database_maintenance_run` and continuation event `fanxie_warden_database_maintenance_continue`. |
| `AjaxController.php` | Registers sub-actions on the shared `AjaxRouter`. |
| `Cli/DbCommand.php` | `wp fx-warden db …`. |

### Task identifiers

`revisions`, `expired-transients`, `all-transients` (CLI only), `orphaned-postmeta`, `orphaned-usermeta`, `orphaned-termmeta`, `orphaned-commentmeta`, `auto-drafts`, `trashed-posts`, `spam-comments`.

## Settings

Option `fanxie_warden_database_maintenance_settings`:

| Key | Default | Range / values | Notes |
|---|---|---|---|
| `revision_limit_enabled` | `false` | bool | Caps future revisions via `wp_revisions_to_keep`. Ignored (and UI locked) when `WP_POST_REVISIONS` is defined. |
| `revisions_keep` | `20` | 0–50 | Used by both the future-revision cap and the purge's "keep newest N per post". |
| `auto_draft_days` | `7` | 1–365 | |
| `trash_days` | `30` | 1–365 | |
| `spam_days` | `15` | 1–365 | |
| `schedule_enabled` | `false` | bool | |
| `schedule_frequency` | `weekly` | `daily` \| `weekly` | |
| `schedule_hour` | `3` | 0–23 | Site timezone (`wp_timezone()`). |
| `schedule_tasks` | see below | map task id → bool | Defaults ON: `expired-transients`, all four `orphaned-*`, `auto-drafts`, `spam-comments`. Defaults OFF: `revisions`, `trashed-posts` (they delete user-authored content). `all-transients` is not a valid key. |

Values are clamped on save **and** again when `Settings` is constructed, so a hand-edited option can't push a task out of range. Unknown keys are dropped.

## Cleanup semantics

All age thresholds compare against GMT columns/timestamps. Batches default to 500 rows.

- **Revisions** — `SELECT post_parent … WHERE post_type='revision' AND post_parent > 0 GROUP BY post_parent HAVING COUNT(*) > %d`; per parent, select revision IDs ordered `post_date DESC, ID DESC` with `LIMIT 18446744073709551615 OFFSET N` (no window functions — WP's MySQL floor doesn't guarantee them); delete with `wp_delete_post_revision()`. `count()` = Σ(per-parent count − N).
- **Expired transients** — `count()` / `estimate_bytes()` via a prepared query joining value rows to `_transient_timeout_*` / `_site_transient_timeout_*` rows with timeout `< time()`. Purge calls core `delete_expired_transients( true )` (single statement; reports `exhausted=true`). When `wp_using_ext_object_cache()` is true, the task reports 0 and the status response carries `object_cache: true` so the UI can explain.
- **All transients (CLI only)** — deletes every `_transient_%` / `_site_transient_%` option row in batches of `option_name`s via `delete_option()` / `delete_site_option()` on the un-prefixed key.
- **Orphaned meta** — batch of `meta_id`s via `LEFT JOIN` parent table `WHERE parent.ID IS NULL LIMIT %d`, deleted with `delete_metadata_by_mid( $type, $mid )`. No multi-table raw `DELETE`.
- **Auto-drafts** — `post_status='auto-draft' AND post_date_gmt < cutoff`; `wp_delete_post( $id, true )`.
- **Trashed posts** — age from `_wp_trash_meta_time` (when the post was trashed), falling back to `post_modified_gmt` when the meta is absent; `wp_delete_post( $id, true )`. Trashed attachments lose their files, matching core's own Empty Trash.
- **Spam comments** — `comment_approved='spam' AND comment_date_gmt < cutoff`; `wp_delete_comment( $id, true )`.
- **Sizes** — `estimate_bytes()` sums `LENGTH()` of the rows' principal columns (post: content/title/excerpt; meta: key+value; option: name+value; comment: content+author fields). Displayed as approximate ("≈"). Not used for any decision.
- **`sample( $n )`** — up to 10 human-readable rows: post title + parent/ID + date, transient name, meta key + parent ID, comment author + excerpt + date.

## Execution model

- `CleanupRunner::run()` acquires a lock transient `fanxie_warden_db_lock_{task_id}` (TTL 60 s, refreshed after every batch). If the lock is held, it returns `RunResult` with `busy=true` and does nothing.
- A row whose WP delete function returns false/`null` is counted as `failed` and its ID is skipped for the rest of the run (and excluded from subsequent `purge_batch()` calls within the same run via an exclusion list), so the loop always terminates.
- **Admin:** `purge-step` runs one task with a 5-second budget and returns `{ deleted, failed, remaining, done, busy }`. The client re-calls until `done`.
- **Cron:** the scheduled event runs each enabled task in order with a 20-second total budget. If unfinished, it schedules `fanxie_warden_database_maintenance_continue` as a single event +5 minutes carrying the remaining task ids; continuation repeats until all are done. Scheduling/rescheduling happens on settings save (clear + schedule next occurrence of `schedule_hour` in site timezone); disabling clears both events.
- **CLI:** unlimited budget, `WP_CLI\Utils\make_progress_bar`.

## AJAX contract

Registered on the shared router (`fanxie_warden` action, `fanxie_warden_admin` nonce, `manage_fanxie_warden` capability — both checks, always).

| Sub-action | Request | Response `data` |
|---|---|---|
| `database-maintenance/get-status` | — | `{ items: StatusItem[], total_bytes: number, object_cache: boolean }` where `StatusItem = { id, label, count, bytes }` for the admin task set |
| `database-maintenance/preview` | `task` | `{ id, count, bytes, sample: SampleRow[] }`, `SampleRow = { label: string, detail: string, date: string \| null }` |
| `database-maintenance/purge-step` | `task` | `{ id, deleted, failed, remaining, done, busy }` |
| `database-maintenance/get-config` | — | `{ settings: DbSettings, revisions_constant: number \| boolean \| null, next_run: string \| null }` (`next_run` ISO-8601 in site timezone) |
| `database-maintenance/save-config` | `settings` (JSON) | same as `get-config` after save; field errors as `{ errors: Record<string,string> }` with `success:false` |

Unknown `task` ids (including `all-transients`) → `success:false`, HTTP 400. TS mirrors live in `assets/admin/src/modules/DatabaseMaintenance/types.ts`; shapes are frozen across both lanes.

## Admin (Vue) — `frontend-expert`

Replaces the Database Maintenance placeholder with a real module, two tabs (same pattern as Environment Health):

- **Cleanup tab** — table: checkbox · item · count · ≈ size · **Preview** (expands the row with up to 10 sample rows). Rows with count 0 show "—" and are not selectable. Footer: total recoverable (≈), **Purge Selected**, **Purge All** (= every admin task with count > 0). Purge opens the shared `ConfirmDialog` listing each item with its count. During a run: per-row progress bar, overall progress, **Cancel** (stops after the in-flight step), `aria-live="polite"` announcements per completed item; `busy` responses retry after 2 s (max 5 tries, then a per-row error). On finish: toast with deleted/failed totals, then re-fetch status. Object-cache note shown on the transients row when `object_cache` is true.
- **Settings tab** — revision limit toggle + keep-N number (locked with explanation when `revisions_constant !== null`); three age fields (same number-field pattern as Environment Health thresholds); schedule toggle, frequency select, hour select, per-task toggles, and "Next run: …". Every setting has a `Tooltip` or `HelpText` per the CLAUDE.md standard; `HelpText` wired to `Toggle` via `describedby`. Uses the shared `SaveBar`.
- Pinia store `stores/databaseMaintenance.ts`; purge loop lives in a composable (`usePurgeRun`) so it is unit-testable apart from the view.

## WP-CLI — `wp fx-warden db …`

| Command | Behaviour |
|---|---|
| `status [--format=table\|json\|csv]` | Counts and ≈sizes for every admin task. |
| `clean --all` | Runs every admin task. (Never `all-transients`.) |
| `revisions [--keep=<n>]` | Override `revisions_keep` for this run. |
| `transients [--all]` | Expired only by default; `--all` deletes every transient. |
| `orphans [--type=post\|user\|term\|comment]` | All four by default. |
| `trash [--days=<n>]` / `spam [--days=<n>]` / `autodrafts [--days=<n>]` | Override day thresholds. |

Every destructive command accepts `--dry-run` (prints count + sample, deletes nothing) and prompts via `WP_CLI::confirm()` unless `--yes`. Flags are validated with the same ranges as `Settings`. Exit non-zero with `WP_CLI::error()` on invalid input; summary line reports deleted/failed.

## Uninstall

`uninstall.php` follows the existing data-deletion rules (gated by `FX_WARDEN_DELETE_ALL_DATA` like every other module) and removes the settings option, both cron events, and any `fanxie_warden_db_lock_*` transients. No tables are created by this module.

## Testing

- **Unit (Brain Monkey):** `Settings` clamping and defaults; `CleanupRunner` budget/batching/failed-row exclusion/busy lock; `ScheduledCleanup` next-run computation (timezone, hour, daily/weekly) and continuation decision; `DbCommand` argument parsing/validation and dry-run path; `TaskFactory` sets (admin set excludes `all-transients`).
- **Integration (real WP):** one class per task using factories — e.g. post with 30 revisions → keeps newest N exactly; other posts' revisions untouched; expired vs valid transients; orphaned vs attached meta for each type; auto-draft/trash/spam at boundary ages (N−1 vs N+1 days); trash age from `_wp_trash_meta_time` with fallback. `count()`/`sample()` agree with what `purge_batch()` removes. Revision filter with/without `WP_POST_REVISIONS` (separate process for the constant). Cron scheduling on save, clearing on disable, continuation scheduling. AJAX sub-actions: happy paths, unknown task 400, nonce failure, capability failure.
- **Vitest + Vue Test Utils + MSW:** Cleanup tab render/selection/confirm/progress/cancel/busy-retry; Settings tab locking, validation, save; `usePurgeRun` composable; store.
- **Playwright smoke:** open Database Maintenance, preview a row, purge it, see the success toast.
- Coverage ≥80% on `src/Modules/DatabaseMaintenance`; `npm run check`, `composer test`, `npm run test:php:integration` green before 3.1 is ticked.

## Out of scope (this spec)

- Activity Log (Phase 3.2 — separate spec).
- Table optimisation (`OPTIMIZE TABLE`), WooCommerce/third-party table cleanup, multisite network-wide runs.
- Exact on-disk size accounting (estimates only).
- Undo/restore of purged data.

## Follow-ups (docs)

- CLAUDE.md: record the WP-Cron decision (PRD §8.3/§9.3 divergence) and the revision-limit default divergence.
- `docs/hooks.md`: cron event names and AJAX sub-actions.
- Checklist 3.1: replace "Action Scheduler-driven schedule" with WP-Cron wording; CLI command names → `wp fx-warden db …`.
- `readme.txt`: module description + FAQ on revision limit vs `WP_POST_REVISIONS`.
