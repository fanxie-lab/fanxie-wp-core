# Admin Refinements + Hardening Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the pre–Phase 1.3 batch: a top-level "FX Core" menu, a two-tab Security Headers module, an HSTS "Advanced" section, a project-wide tooltip/help standard, and three Hardening fixes (file-editor guard, uploads/`.htaccess` messaging, Application Passwords clarity).

**Architecture:** Changes split across two lanes. PHP (`wordpress-development-expert`): admin menu, `FileEditGuard`, `UploadsProtector`/`StatusInspector`. Vue/TS (`frontend-expert`): tooltip primitives, Security Headers IA, HSTS placement, honest Hardening copy. Contracts between them are the `StatusInspector` snapshot shape mirrored in `Hardening/types.ts`, and the admin bootstrap. Each task ends with a passing test cycle and a commit.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, PHPUnit 12 + Brain Monkey + Mockery (unit) / `WP_UnitTestCase` via `@wordpress/env` (integration), PHPCS (WordPress-Extra), PHPStan level 8. Vue 3 `<script setup>` + TypeScript, Pinia, Vite, Vitest + Vue Test Utils, ESLint + Prettier.

## Global Constraints

- Text domain `fanxie-wp-core` on **every** user-facing string; no variables inside `__()` — use `sprintf` + translator comments.
- Capability gate `Plugin::CAPABILITY` (`manage_fanxie_wp_core`) on admin actions; escape at output (`esc_html`, `esc_attr`, `esc_url`).
- Options/JS contracts are frozen shapes — coordinate any change across both lanes (CLAUDE.md §3.4).
- Accessibility: WCAG 2.1 AA — every interactive element keyboard-reachable, labelled, screen-reader reachable; no hover-only reveals.
- PHP: `declare( strict_types=1 )`, typed properties, no global state.
- Coverage ≥ 80% on touched code; PHPCS + PHPStan L8 + ESLint + Prettier + `vue-tsc --noEmit` all clean.
- Commit style: Conventional Commits, scope = module id where applicable. Every commit message ends with the `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>` trailer.

### Command reference (used throughout)

- PHP unit: `composer test:unit` (all) or `./vendor/bin/phpunit --testsuite=unit --filter <Name>`
- PHP integration (needs `npm run env:start` first): `npm run test:php:integration` or
  `wp-env run tests-cli --env-cwd=wp-content/plugins/fanxie-wp-core ./vendor/bin/phpunit --testsuite=integration --filter <Name>`
- PHP lint: `composer phpcs` and `composer phpstan`
- JS unit: `cd assets/admin && npx vitest run <path>` (single) or `npm run test` (all)
- JS full gate: `cd assets/admin && npm run check` (format + lint + type-check + test)
- JS build (checked-in dist): `cd assets/admin && npm run build`
- Plugin Check: `npm run plugin:check`

---

## Task 1: Fix `FileEditGuard` file-mod contexts (bug E)

WordPress routes both the Theme File Editor and Plugin File Editor through one call —
`wp_is_file_mod_allowed( 'capability_edit_themes' )` (`wp-includes/capabilities.php:611`). The guard
matches `edit_themes`/`edit_plugins` (unprefixed), which core never emits, so it is a silent no-op even
with the toggle on. The existing tests assert the same fictional strings, so they pass while production
is broken.

**Files:**
- Modify: `src/Modules/Hardening/Runtime/FileEditGuard.php:31-36`
- Modify: `tests/Unit/Modules/Hardening/Runtime/FileEditGuardTest.php:34-48`
- Modify: `tests/Integration/Modules/Hardening/FileEditMenuTest.php:48-78`
- Modify (copy only): `assets/admin/src/modules/Hardening/Hardening.vue:668-671`

**Interfaces:**
- Consumes: nothing new.
- Produces: `FileEditGuard::filter_file_mod_allowed( mixed $allowed, string $context ): bool` returns
  `false` for `capability_edit_themes` and `capability_edit_plugins`, passes every other context through
  unchanged (including `capability_update_core`, which must stay allowed so installs/updates aren't blocked).

- [ ] **Step 1: Rewrite the unit test to assert the real contexts**

Replace the two test methods in `tests/Unit/Modules/Hardening/Runtime/FileEditGuardTest.php` (lines 34-48):

```php
	public function test_blocks_the_editor_contexts_wordpress_actually_emits(): void {
		$guard = new FileEditGuard( [ 'file_editing' => [ 'runtime_enforce' => true ] ] );

		// WordPress core gates BOTH editors via `capability_edit_themes`
		// (wp-includes/capabilities.php); `capability_edit_plugins` is defensive.
		$this->assertFalse( $guard->filter_file_mod_allowed( true, 'capability_edit_themes' ) );
		$this->assertFalse( $guard->filter_file_mod_allowed( true, 'capability_edit_plugins' ) );
	}

	public function test_does_not_block_installs_updates_or_unrelated_contexts(): void {
		$guard = new FileEditGuard( [ 'file_editing' => [ 'runtime_enforce' => true ] ] );

		// Must NOT over-block: plugin/theme install/update flow through this one.
		$this->assertTrue( $guard->filter_file_mod_allowed( true, 'capability_update_core' ) );
		// The old, unprefixed strings are fictional — they pass through untouched now.
		$this->assertTrue( $guard->filter_file_mod_allowed( true, 'edit_themes' ) );
		$this->assertFalse( $guard->filter_file_mod_allowed( false, 'automatic_updater' ) );
	}
```

- [ ] **Step 2: Run the unit test — verify it fails**

Run: `./vendor/bin/phpunit --testsuite=unit --filter FileEditGuardTest`
Expected: FAIL — `capability_edit_themes` currently returns `true` (guard doesn't match it).

- [ ] **Step 3: Correct the blocked contexts**

In `src/Modules/Hardening/Runtime/FileEditGuard.php`, replace the `BLOCKED_CONTEXTS` constant (lines 31-36):

```php
	/**
	 * Contexts we lock down. WordPress gates BOTH the theme editor and the
	 * plugin editor through a single `file_mod_allowed` call with the context
	 * `capability_edit_themes` (see wp-includes/capabilities.php, the
	 * edit_files / edit_plugins / edit_themes meta-cap branch).
	 * `capability_edit_plugins` is included defensively in case a plugin or a
	 * future core version gates the plugin editor separately. We deliberately do
	 * NOT block `capability_update_core` so installs/updates keep working — this
	 * mirrors DISALLOW_FILE_EDIT, not the broader DISALLOW_FILE_MODS.
	 *
	 * @var array<int, string>
	 */
	private const BLOCKED_CONTEXTS = [
		'capability_edit_themes',
		'capability_edit_plugins',
	];
```

- [ ] **Step 4: Run the unit test — verify it passes**

Run: `./vendor/bin/phpunit --testsuite=unit --filter FileEditGuardTest`
Expected: PASS.

- [ ] **Step 5: Fix the integration test to use the real contexts**

In `tests/Integration/Modules/Hardening/FileEditMenuTest.php`, change both `foreach` context lists
(lines 55 and 72) from `[ 'edit_themes', 'edit_plugins' ]` to
`[ 'capability_edit_themes', 'capability_edit_plugins' ]`. These are the strings core passes to
`wp_is_file_mod_allowed()`, so this test now proves the editors are actually gated.

- [ ] **Step 6: Run the integration test — verify it passes**

Run: `npm run env:start` (if not running), then
`wp-env run tests-cli --env-cwd=wp-content/plugins/fanxie-wp-core ./vendor/bin/phpunit --testsuite=integration --filter FileEditMenuTest`
Expected: PASS (both `capability_edit_themes` and `capability_edit_plugins` return `false` with the guard on).

- [ ] **Step 7: Tighten the UI copy for the File Editing section**

In `assets/admin/src/modules/Hardening/Hardening.vue`, replace the section hint (lines 668-671):

```html
          <p class="fx-hardening__section-hint">
            Disable the dashboard <strong>Appearance → Theme File Editor</strong>
            and <strong>Plugins → Plugin File Editor</strong> so a compromised
            admin cannot edit PHP directly. Enabling this hides both editors.
          </p>
```

- [ ] **Step 8: Run PHP lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add src/Modules/Hardening/Runtime/FileEditGuard.php \
        tests/Unit/Modules/Hardening/Runtime/FileEditGuardTest.php \
        tests/Integration/Modules/Hardening/FileEditMenuTest.php \
        assets/admin/src/modules/Hardening/Hardening.vue
git commit -m "$(cat <<'EOF'
fix(hardening): gate file editors on the real WP file_mod context

FileEditGuard matched `edit_themes`/`edit_plugins`, but core routes both
editors through `wp_is_file_mod_allowed('capability_edit_themes')`. The
guard (and its tests) used the unprefixed strings core never emits, so it
was a no-op even with runtime enforce on. Match the real contexts; keep
`capability_update_core` allowed so installs/updates still work.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2a: Uploads `.htaccess` write scope + tri-state readme/license probe (bug F, PHP)

Two PHP defects behind the misleading ".htaccess write failed" message: (1) `is_apache_compatible()`
treats `unknown` as Apache, so we attempt writes on servers that don't honor `.htaccess`; (2)
`probe_path_blocked()` collapses a transport failure (`WP_Error`) into "accessible", which in wp-env
(container can't reach its own public URL) produces a false "still accessible" warning even when the file
is actually blocked. Fix: writes only on Apache/LiteSpeed; readme/license probe becomes tri-state
(`true` blocked / `false` accessible / `null` inconclusive).

**Files:**
- Modify: `src/Modules/Hardening/UploadsProtector.php:306-313` (`is_apache_compatible`)
- Modify: `src/Modules/Hardening/StatusInspector.php:64-77,105-123,195-232` (snapshot doc + `probe_path_blocked` return type)
- Modify: `tests/Unit/Modules/Hardening/UploadsProtectorTest.php` (add unknown-skip test)
- Modify: `tests/Unit/Modules/Hardening/StatusInspectorTest.php` (add inconclusive-probe test)

**Interfaces:**
- Consumes: `UploadsProtector::detect_server_type(): string` (unchanged — `apache|nginx|litespeed|iis|unknown`).
- Produces:
  - `UploadsProtector::ensure_protection()` writes the uploads `.htaccess` only when
    `detect_server_type()` is `apache` or `litespeed`.
  - `StatusInspector::snapshot()['readme_blocked']` and `['license_blocked']` are now `bool|null`
    (`null` = probe could not reach the host). Consumed by Task 2b's TS type change.

- [ ] **Step 1: Add a failing test — unknown server skips the `.htaccess` write**

Add to `tests/Unit/Modules/Hardening/UploadsProtectorTest.php` (after `test_ensure_protection_skips_htaccess_on_nginx`, ~line 102):

```php
	public function test_ensure_protection_skips_htaccess_on_unknown_server(): void {
		unset( $_SERVER['SERVER_SOFTWARE'] ); // → detect_server_type() === 'unknown'

		$protector = new UploadsProtector(
			[ 'uploads' => [ 'drop_index' => true, 'block_php_execution' => true ] ]
		);

		$protector->ensure_protection();

		// index.php is server-agnostic and still drops; .htaccess must NOT, because
		// we can't confirm the server honors it.
		$this->assertFileExists( $this->uploads_dir . '/index.php' );
		$this->assertFileDoesNotExist( $this->uploads_dir . '/.htaccess' );
	}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `./vendor/bin/phpunit --testsuite=unit --filter test_ensure_protection_skips_htaccess_on_unknown_server`
Expected: FAIL — `.htaccess` currently written on `unknown`.

- [ ] **Step 3: Narrow the write scope**

In `src/Modules/Hardening/UploadsProtector.php`, replace `is_apache_compatible()` (lines 306-313):

```php
	/**
	 * Whether the current server honours `.htaccess` — Apache proper or
	 * LiteSpeed (same syntax). We no longer treat `unknown` as Apache: writing
	 * a `.htaccess` we can't confirm is honoured produced misleading "write
	 * failed" states. On unknown/nginx/IIS the UI surfaces a server-appropriate
	 * snippet instead (see StatusInspector + the Hardening admin view).
	 */
	private function is_apache_compatible(): bool {
		$type = $this->detect_server_type();
		return 'apache' === $type || 'litespeed' === $type;
	}
```

- [ ] **Step 4: Run it — verify it passes**

Run: `./vendor/bin/phpunit --testsuite=unit --filter UploadsProtectorTest`
Expected: PASS (all methods, including the new one).

- [ ] **Step 5: Add a failing test — inconclusive readme/license probe returns null**

Add to `tests/Unit/Modules/Hardening/StatusInspectorTest.php` (after `test_snapshot_marks_readme_unblocked_when_probe_returns_200`, ~line 154):

```php
	public function test_snapshot_marks_readme_inconclusive_on_transport_error(): void {
		// Container can't reach its own public URL — wp_remote_get returns WP_Error.
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'timeout', 'unreachable' ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );

		$snapshot = $this->make_inspector()->snapshot( true );

		$this->assertNull( $snapshot['readme_blocked'] );
		$this->assertNull( $snapshot['license_blocked'] );
	}
```

- [ ] **Step 6: Run it — verify it fails**

Run: `./vendor/bin/phpunit --testsuite=unit --filter test_snapshot_marks_readme_inconclusive_on_transport_error`
Expected: FAIL — `probe_path_blocked()` currently returns `false` (not `null`) on `WP_Error`.

- [ ] **Step 7: Make the probe tri-state**

In `src/Modules/Hardening/StatusInspector.php`, change `probe_path_blocked()` (lines 205-232) to return `?bool`:

```php
	/**
	 * HTTP-probe a path under the site root. Returns:
	 *   - `true`  when the server refuses it (non-200) → blocked.
	 *   - `false` when it returns 200 → still served.
	 *   - `null`  when the probe can't reach the host (WP_Error) → inconclusive.
	 *
	 * The `null` case matters in container/dev setups (e.g. wp-env) where the
	 * site cannot resolve its own public URL: we must not report "accessible"
	 * (a false warning) when we simply couldn't look.
	 *
	 * @param string $relative Path under the site root (e.g. `readme.html`).
	 */
	private function probe_path_blocked( string $relative ): ?bool {
		if ( ! function_exists( 'home_url' ) ) {
			return null;
		}

		$url = (string) home_url( '/' . ltrim( $relative, '/' ) );

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return 200 !== $status;
	}
```

Then update the `snapshot()` PHPDoc return type (lines 64-77 and the cached-narrowing block at 86-99):
change `readme_blocked: bool` → `readme_blocked: bool|null` and `license_blocked: bool` →
`license_blocked: bool|null` in both docblocks.

- [ ] **Step 8: Run the StatusInspector suite — verify pass**

Run: `./vendor/bin/phpunit --testsuite=unit --filter StatusInspectorTest`
Expected: PASS. (Existing `readme_blocked === true` on 404 and `false` on 200 still hold; new `null` case passes.)

- [ ] **Step 9: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: no errors. (PHPStan sees the widened `?bool` — the snapshot docblocks now match.)

- [ ] **Step 10: Commit**

```bash
git add src/Modules/Hardening/UploadsProtector.php \
        src/Modules/Hardening/StatusInspector.php \
        tests/Unit/Modules/Hardening/UploadsProtectorTest.php \
        tests/Unit/Modules/Hardening/StatusInspectorTest.php
git commit -m "$(cat <<'EOF'
fix(hardening): honest uploads/.htaccess detection + tri-state probe

Only write the uploads .htaccess on Apache/LiteSpeed (never on unknown).
readme/license probe is now tri-state: null = couldn't reach host, so a
dev/container setup that can't resolve its own URL no longer reports a
false "still accessible" warning.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2b: Honest Hardening messaging (bug F, JS)

Replace the hardcoded ".htaccess write failed" copy (readme/license row) with server-aware, probe-aware
wording, and consume the new tri-state probe values.

**Files:**
- Modify: `assets/admin/src/modules/Hardening/types.ts` (`readme_blocked`/`license_blocked` → `boolean | null`)
- Modify: `assets/admin/src/modules/Hardening/Hardening.vue:134-143,523-553`
- Modify/create test: `assets/admin/src/modules/Hardening/__tests__/Hardening.spec.ts`

**Interfaces:**
- Consumes: `ChecksResult.readme_blocked: boolean | null`, `ChecksResult.license_blocked: boolean | null`,
  `ChecksResult.server_type: ServerType` (from Task 2a).
- Produces: `statusBlockReadmeLicense` computed resolves to `'active' | 'warning' | 'inactive'` where
  `warning` means "toggle on but a probe returned `false` (still accessible)"; an inconclusive (`null`)
  probe is treated as `active` (no false alarm) and surfaces neutral copy.

- [ ] **Step 1: Widen the TS contract**

In `assets/admin/src/modules/Hardening/types.ts`, change the two fields on `ChecksResult`:

```ts
  /**
   * Front-door probe for /readme.html. `true` = blocked, `false` = still
   * served, `null` = probe could not reach the host (inconclusive).
   */
  readme_blocked: boolean | null;
  /** Front-door probe for /license.txt. Same tri-state as readme_blocked. */
  license_blocked: boolean | null;
```

- [ ] **Step 2: Add a failing component test for the new copy**

In `assets/admin/src/modules/Hardening/__tests__/Hardening.spec.ts`, add a test that mounts `Hardening`
with a store fixture where `block_readme_license` is on, the probe reports still-accessible
(`readme_blocked: false`) and the server is nginx, then asserts the copy names the server and offers a
snippet — and does NOT say "write failed":

```ts
  it('shows server-aware guidance (not "write failed") when readme is still served on nginx', async () => {
    const wrapper = mountHardening({
      config: baseConfig({ version_hiding: { block_readme_license: true } }),
      checks: baseChecks({ server_type: 'nginx', readme_blocked: false, license_blocked: true }),
    });
    await flushPromises();
    const text = wrapper.text();
    expect(text).not.toContain('write failed');
    expect(text.toLowerCase()).toContain('nginx');
  });
```

> Note: `mountHardening`, `baseConfig`, and `baseChecks` are the existing helpers in this spec file —
> reuse them (extend `baseChecks` defaults with `readme_blocked`/`license_blocked` if not present). If a
> helper does not exist yet, add a minimal factory mirroring the `ChecksResult`/`HardeningConfig` shapes.

- [ ] **Step 3: Run it — verify it fails**

Run: `cd assets/admin && npx vitest run src/modules/Hardening/__tests__/Hardening.spec.ts`
Expected: FAIL — current copy contains ".htaccess write failed".

- [ ] **Step 4: Update the status computed to respect the tri-state**

In `Hardening.vue`, replace `statusBlockReadmeLicense` (lines 134-143):

```ts
const statusBlockReadmeLicense = computed<ChecklistStatus>(() => {
  const enabled = store.config?.version_hiding.block_readme_license ?? false;
  if (!enabled) return 'inactive';
  // Warn only when a probe positively reports the file is STILL served
  // (=== false). `null` (inconclusive — e.g. dev host can't reach itself) is
  // treated as active so we don't cry wolf.
  const readmeServed = store.checks?.readme_blocked === false;
  const licenseServed = store.checks?.license_blocked === false;
  if (readmeServed || licenseServed) return 'warning';
  return 'active';
});
```

- [ ] **Step 5: Replace the warning footer with server-aware copy**

In `Hardening.vue`, replace the readme/license `#footer` block (lines 542-552) with a server-branching note.
Add a computed near the other readme/license logic:

```ts
const readmeServerGuidance = computed<string>(() => {
  const type = store.checks?.server_type ?? 'unknown';
  if (type === 'apache' || type === 'litespeed') {
    return 'The rewrite rule is in place but one or both files still respond. Confirm your host allows .htaccess overrides (AllowOverride) for the site root.';
  }
  if (type === 'nginx' || type === 'iis') {
    return `Your server (${type}) does not use .htaccess. Add a rule to block these files, then run checks again.`;
  }
  return 'One or both files still respond. If your server is nginx/IIS, add the equivalent server-block rule; on Apache, confirm .htaccess overrides are allowed.';
});
```

And the template footer (replacing lines 542-552):

```html
            <template v-if="statusBlockReadmeLicense === 'warning'" #footer>
              <p
                class="fx-hardening__help fx-hardening__help--warn"
                role="note"
              >
                {{ readmeServerGuidance }}
              </p>
              <pre
                v-if="
                  store.checks?.server_type === 'nginx'
                "
                class="fx-hardening__snippet"
              ><code>location ~* /(readme\.html|license\.txt)$ {
    deny all;
}</code></pre>
            </template>
```

- [ ] **Step 6: Run the component test — verify it passes**

Run: `cd assets/admin && npx vitest run src/modules/Hardening/__tests__/Hardening.spec.ts`
Expected: PASS.

- [ ] **Step 7: Full JS gate**

Run: `cd assets/admin && npm run check`
Expected: format + lint + type-check + all tests pass.

- [ ] **Step 8: Rebuild the checked-in bundle**

Run: `cd assets/admin && npm run build`
Expected: `assets/admin/dist/` regenerated with no type errors.

- [ ] **Step 9: Commit**

```bash
git add assets/admin/src/modules/Hardening/types.ts \
        assets/admin/src/modules/Hardening/Hardening.vue \
        assets/admin/src/modules/Hardening/__tests__/Hardening.spec.ts \
        assets/admin/dist
git commit -m "$(cat <<'EOF'
fix(hardening): server-aware readme/license messaging, drop "write failed"

Warn only when a probe positively reports the file is still served; treat
an inconclusive probe as fine. Copy now branches by detected server type
and offers the right remediation instead of asserting a write failure.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3a: Application Passwords holders in the snapshot (G, PHP)

`count_application_passwords()` sums `_application_passwords` across every user (correct for a site-wide
gate) but the UI can't say whose they are. Add holder details so the copy can name them.

**Files:**
- Modify: `src/Modules/Hardening/StatusInspector.php:64-77,105-123,234-316`
- Create: `tests/Integration/Modules/Hardening/ApplicationPasswordsStatusTest.php`
- Modify: `tests/Unit/Modules/Hardening/StatusInspectorTest.php:120-137,212-225` (shape list + sentinel)

**Interfaces:**
- Consumes: nothing new.
- Produces: `StatusInspector::snapshot()['application_passwords_users']` is a
  `list<array{user_login: string, count: int}>` (holders with ≥1 AP). `['application_passwords_count']`
  equals the sum of those counts. Consumed by Task 3b's TS type + copy.

- [ ] **Step 1: Add the failing integration test**

Create `tests/Integration/Modules/Hardening/ApplicationPasswordsStatusTest.php`:

```php
<?php
/**
 * Integration test — StatusInspector reports Application Password holders.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\Hardening;

use FanxieLab\WPCore\Modules\Hardening\StatusInspector;
use FanxieLab\WPCore\Modules\Hardening\UploadsProtector;
use WP_Application_Passwords;
use WP_UnitTestCase;

/**
 * Verifies the snapshot enumerates which users hold Application Passwords, so
 * the admin UI can name them instead of showing a dead-end "revoke them" note.
 */
final class ApplicationPasswordsStatusTest extends WP_UnitTestCase {

	public function test_snapshot_lists_holders_and_matches_total(): void {
		$editor = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_login' => 'app_pw_editor',
			]
		);

		WP_Application_Passwords::create_new_application_password( $editor, [ 'name' => 'CLI one' ] );
		WP_Application_Passwords::create_new_application_password( $editor, [ 'name' => 'CLI two' ] );

		$inspector = new StatusInspector( new UploadsProtector( [] ) );
		$snapshot  = $inspector->snapshot( true );

		$this->assertSame( 2, $snapshot['application_passwords_count'] );
		$this->assertContains(
			[
				'user_login' => 'app_pw_editor',
				'count'      => 2,
			],
			$snapshot['application_passwords_users']
		);
	}

	public function test_snapshot_reports_empty_holders_when_none_exist(): void {
		$inspector = new StatusInspector( new UploadsProtector( [] ) );
		$snapshot  = $inspector->snapshot( true );

		$this->assertSame( 0, $snapshot['application_passwords_count'] );
		$this->assertSame( [], $snapshot['application_passwords_users'] );
	}
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `wp-env run tests-cli --env-cwd=wp-content/plugins/fanxie-wp-core ./vendor/bin/phpunit --testsuite=integration --filter ApplicationPasswordsStatusTest`
Expected: FAIL — `application_passwords_users` key does not exist yet.

- [ ] **Step 3: Add the holders method and derive the count from it**

In `src/Modules/Hardening/StatusInspector.php`, replace `count_application_passwords()` (lines 234-287)
with a holders resolver, and keep a thin count wrapper for readability:

```php
	/**
	 * Enumerate Application Password holders across all users.
	 *
	 * @return list<array{user_login: string, count: int}> Users with ≥1 AP.
	 */
	private function application_password_holders(): array {
		if ( ! class_exists( \WP_Application_Passwords::class ) ) {
			return [];
		}

		/**
		 * WordPress database handle.
		 *
		 * @var \wpdb|null $wpdb
		 */
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, meta_value FROM %i WHERE meta_key = %s',
				$wpdb->usermeta,
				\WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$holders = [];
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->meta_value ) ) {
				continue;
			}
			$decoded = maybe_unserialize( (string) $row->meta_value );
			if ( ! is_array( $decoded ) || 0 === count( $decoded ) ) {
				continue;
			}

			$user  = get_userdata( (int) $row->user_id );
			$login = ( $user instanceof \WP_User ) ? (string) $user->user_login : (string) $row->user_id;

			$holders[] = [
				'user_login' => $login,
				'count'      => count( $decoded ),
			];
		}

		return $holders;
	}

	/**
	 * Total Application Passwords across all users (sum of holder counts).
	 */
	private function count_application_passwords( array $holders ): int {
		$total = 0;
		foreach ( $holders as $holder ) {
			$total += (int) ( $holder['count'] ?? 0 );
		}
		return $total;
	}
```

- [ ] **Step 4: Wire the holders into the snapshot**

In `snapshot()` (lines 105-118), compute holders once and add both fields:

```php
		$ap_holders = $this->application_password_holders();

		$snapshot = [
			'server_type'                 => $this->uploads->detect_server_type(),
			'x_powered_by_present'        => $this->x_powered_by_present(),
			'disallow_file_edit_defined'  => defined( 'DISALLOW_FILE_EDIT' ),
			'disallow_file_edit_value'    => defined( 'DISALLOW_FILE_EDIT' ) ? (bool) constant( 'DISALLOW_FILE_EDIT' ) : false,
			'uploads_dir_listable'        => $this->uploads->probe_directory_listable(),
			'uploads_php_executable'      => $this->uploads->probe_php_execution(),
			'uploads_htaccess_exists'     => $this->uploads->htaccess_exists(),
			'uploads_index_exists'        => $this->uploads->index_exists(),
			'readme_blocked'              => $this->probe_path_blocked( 'readme.html' ),
			'license_blocked'             => $this->probe_path_blocked( 'license.txt' ),
			'application_passwords_count' => $this->count_application_passwords( $ap_holders ),
			'application_passwords_users' => $ap_holders,
			'probed_at'                   => time(),
		];
```

Add `'application_passwords_users'` to the `is_valid_shape()` key list (lines 295-309) and to both
`snapshot()` return-type docblocks (lines 64-77 and 86-99):
`application_passwords_users: list<array{user_login: string, count: int}>`.

- [ ] **Step 5: Run the integration test — verify it passes**

Run: `wp-env run tests-cli --env-cwd=wp-content/plugins/fanxie-wp-core ./vendor/bin/phpunit --testsuite=integration --filter ApplicationPasswordsStatusTest`
Expected: PASS.

- [ ] **Step 6: Update the unit test shape + sentinel**

In `tests/Unit/Modules/Hardening/StatusInspectorTest.php`:
- Add `'application_passwords_users'` to the key list in `test_snapshot_returns_stable_shape` (lines 120-134).
- Add `'application_passwords_users' => [],` to the seeded sentinel in `test_force_bypasses_cache`
  (lines 212-225) so the sentinel passes `is_valid_shape()` and the cached-read path is still exercised.

- [ ] **Step 7: Run the unit suite — verify it passes**

Run: `./vendor/bin/phpunit --testsuite=unit --filter StatusInspectorTest`
Expected: PASS.

- [ ] **Step 8: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add src/Modules/Hardening/StatusInspector.php \
        tests/Integration/Modules/Hardening/ApplicationPasswordsStatusTest.php \
        tests/Unit/Modules/Hardening/StatusInspectorTest.php
git commit -m "$(cat <<'EOF'
feat(hardening): enumerate Application Password holders in status snapshot

Adds application_passwords_users (user_login + count) so the admin UI can
name who holds site-wide APs, and derives the total from the same scan.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3b: Application Passwords site-wide copy (G, JS)

**Files:**
- Modify: `assets/admin/src/modules/Hardening/types.ts` (add `application_passwords_users`)
- Modify: `assets/admin/src/modules/Hardening/Hardening.vue:199-206,771-776`
- Modify: `assets/admin/src/modules/Hardening/__tests__/Hardening.spec.ts`

**Interfaces:**
- Consumes: `ChecksResult.application_passwords_users: { user_login: string; count: number }[]`.
- Produces: `applicationPasswordsSummary` computed string naming holder(s); the AP note no longer tells
  the current user to "revoke them" when they can't.

- [ ] **Step 1: Add the TS field**

In `types.ts`, add to `ChecksResult` (next to `application_passwords_count`):

```ts
  /** Users holding ≥1 Application Password, site-wide. */
  application_passwords_users: { user_login: string; count: number }[];
```

- [ ] **Step 2: Add a failing test for the site-wide copy**

In `Hardening.spec.ts`, add:

```ts
  it('names Application Password holders and states the scope is site-wide', async () => {
    const wrapper = mountHardening({
      config: baseConfig(),
      checks: baseChecks({
        application_passwords_count: 2,
        application_passwords_users: [{ user_login: 'app_pw_editor', count: 2 }],
      }),
    });
    await flushPromises();
    const text = wrapper.text();
    expect(text).toContain('app_pw_editor');
    expect(text.toLowerCase()).toContain('site-wide');
    expect(text).not.toContain('Revoke them before disabling');
  });
```

Extend `baseChecks` defaults to include `application_passwords_users: []`.

- [ ] **Step 3: Run it — verify it fails**

Run: `cd assets/admin && npx vitest run src/modules/Hardening/__tests__/Hardening.spec.ts`
Expected: FAIL.

- [ ] **Step 4: Add the summary computed**

In `Hardening.vue`, after `applicationPasswordsCount` (line 206):

```ts
const applicationPasswordsSummary = computed<string>(() => {
  const users = store.checks?.application_passwords_users ?? [];
  const count = applicationPasswordsCount.value;
  const noun = count === 1 ? 'credential' : 'credentials';
  if (users.length === 0) {
    return `${count} ${noun} in use site-wide.`;
  }
  const who = users.map((u) => `${u.user_login} (${u.count})`).join(', ');
  const userNoun = users.length === 1 ? 'user' : 'users';
  return `In use by ${count} ${noun} across ${users.length} ${userNoun}: ${who}. These are site-wide, not just your account.`;
});
```

- [ ] **Step 5: Replace the AP note markup**

In `Hardening.vue`, replace the `v-else` note (lines 771-776):

```html
        <p v-else class="fx-hardening__ap-note" role="note">
          {{ applicationPasswordsSummary }}
          Manage them per user under
          <strong>Users → Profile → Application Passwords</strong> before
          disabling this feature.
        </p>
```

- [ ] **Step 6: Run the test — verify it passes**

Run: `cd assets/admin && npx vitest run src/modules/Hardening/__tests__/Hardening.spec.ts`
Expected: PASS.

- [ ] **Step 7: Full JS gate + build**

Run: `cd assets/admin && npm run check && npm run build`
Expected: all green; `dist/` rebuilt.

- [ ] **Step 8: Commit**

```bash
git add assets/admin/src/modules/Hardening/types.ts \
        assets/admin/src/modules/Hardening/Hardening.vue \
        assets/admin/src/modules/Hardening/__tests__/Hardening.spec.ts \
        assets/admin/dist
git commit -m "$(cat <<'EOF'
fix(hardening): site-wide-aware Application Passwords copy

Name the holder(s) and state the count is site-wide; drop the dead-end
"revoke them" imperative the current user often can't act on.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Top-level "FX Core" admin menu (A)

**Files:**
- Modify: `src/Admin/SettingsPage.php:42-47,106-119`
- Create: `tests/Unit/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Plugin::CAPABILITY`, `SettingsPage::MENU_SLUG` (unchanged), `SettingsPage::render()`.
- Produces: `register_menu()` calls `add_menu_page()` (top-level) then `add_submenu_page()` relabeling the
  first row "Settings"; both keep slug `fanxie-wp-core`. `$this->hook_suffix` is still populated from the
  `add_menu_page()` return, so `enqueue_assets()` scoping is unchanged.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Admin/SettingsPageTest.php`:

```php
<?php
/**
 * Unit tests for SettingsPage menu registration.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Admin
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Admin\SettingsPage;
use FanxieLab\WPCore\Modules\ModuleRegistry;
use FanxieLab\WPCore\Plugin;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the plugin registers a TOP-LEVEL "FX Core" menu (not a Settings
 * submenu) with a relabeled first submenu row, keeping the slug stable.
 */
final class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_register_menu_adds_top_level_fx_core_menu(): void {
		$page = new SettingsPage( Mockery::mock( ModuleRegistry::class ) );

		Functions\expect( 'add_menu_page' )
			->once()
			->with(
				'Fanxie WP Core',
				'FX Core',
				Plugin::CAPABILITY,
				SettingsPage::MENU_SLUG,
				Mockery::type( 'array' ),
				Mockery::type( 'string' ), // data: URI icon
				Mockery::any()
			)
			->andReturn( 'toplevel_page_fanxie-wp-core' );

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				SettingsPage::MENU_SLUG,
				'Fanxie WP Core',
				'Settings',
				Plugin::CAPABILITY,
				SettingsPage::MENU_SLUG,
				Mockery::type( 'array' )
			)
			->andReturn( 'fanxie-wp-core_page' );

		$page->register_menu();
	}
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `./vendor/bin/phpunit --testsuite=unit --filter SettingsPageTest`
Expected: FAIL — `add_options_page` is called, not `add_menu_page`.

- [ ] **Step 3: Rewrite `register_menu()`**

In `src/Admin/SettingsPage.php`, replace `register_menu()` (lines 106-119). Add a `MENU_ICON_SVG`
constant near the other class constants (after line 62):

```php
	/**
	 * Monochrome shield used as the top-level menu icon. Emitted as a base64
	 * `data:` URI so WordPress can recolor it via CSS mask to match the admin
	 * color scheme (a colored glyph would not survive that masking).
	 *
	 * @var string
	 */
	private const MENU_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 1 3 3.6v5.2c0 4.2 2.9 7 7 9.2 4.1-2.2 7-5 7-9.2V3.6L10 1Zm0 2.1 5 1.9v3.8c0 3.2-2.1 5.5-5 7.2-2.9-1.7-5-4-5-7.2V5l5-1.9Z"/></svg>';
```

Then:

```php
	/**
	 * Register a top-level **FX Core** admin menu hosting the Vue SPA.
	 *
	 * The first submenu row is relabeled "Settings" (WordPress otherwise
	 * duplicates the top-level title). Slug is unchanged, so the settings screen
	 * lives at `admin.php?page=fanxie-wp-core`.
	 */
	public function register_menu(): void {
		$hook = add_menu_page(
			esc_html__( 'Fanxie WP Core', 'fanxie-wp-core' ),
			esc_html__( 'FX Core', 'fanxie-wp-core' ),
			Plugin::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render' ],
			'data:image/svg+xml;base64,' . base64_encode( self::MENU_ICON_SVG ),
			58.9
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Fanxie WP Core', 'fanxie-wp-core' ),
			esc_html__( 'Settings', 'fanxie-wp-core' ),
			Plugin::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render' ]
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}
```

Also update the docblock at lines 42-47 (`add_options_page()` → `add_menu_page()`).

- [ ] **Step 4: Run it — verify it passes**

Run: `./vendor/bin/phpunit --testsuite=unit --filter SettingsPageTest`
Expected: PASS.

- [ ] **Step 5: Guard against stray legacy URL references**

Run: `grep -rn "options-general.php?page=fanxie-wp-core\|options-general.php?page=fanxie" src assets/admin/src`
Expected: no matches. (If any surface, switch them to `admin.php?page=fanxie-wp-core`.)

- [ ] **Step 6: Lint + static analysis**

Run: `composer phpcs && composer phpstan`
Expected: no errors.

- [ ] **Step 7: Manual smoke (integration environment)**

Run: `npm run env:start`, open `wp-admin`, confirm a top-level **FX Core** menu with an icon, and that the
settings screen loads at `admin.php?page=fanxie-wp-core` with the SPA assets enqueued only there.

- [ ] **Step 8: Commit**

```bash
git add src/Admin/SettingsPage.php tests/Unit/Admin/SettingsPageTest.php
git commit -m "$(cat <<'EOF'
feat(admin): promote settings to a top-level "FX Core" menu

Switch add_options_page → add_menu_page with a monochrome shield icon and
a relabeled "Settings" submenu row. Slug unchanged; enqueue scoping still
keys off the returned hook suffix.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `Tooltip` primitive (D1)

Reusable, accessible ⓘ tooltip. The first half of the project-wide help standard.

**Files:**
- Create: `assets/admin/src/components/Tooltip.vue`
- Modify: `assets/admin/src/components/index.ts`
- Create: `assets/admin/src/components/__tests__/Tooltip.spec.ts`

**Interfaces:**
- Produces: `Tooltip` component. Props: `text: string` (required), `label?: string` (accessible name for
  the trigger, default `"More information"`). Renders a `<button type="button">` ⓘ trigger with
  `aria-describedby` pointing at a tooltip node shown on hover **and** focus, hidden on blur / `Escape`.

- [ ] **Step 1: Write the failing test**

Create `assets/admin/src/components/__tests__/Tooltip.spec.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Tooltip from '../Tooltip.vue';

describe('Tooltip', () => {
  it('exposes an accessible trigger describing the tooltip text', () => {
    const wrapper = mount(Tooltip, { props: { text: 'Explains the setting.' } });
    const button = wrapper.get('button');
    expect(button.attributes('aria-label')).toBe('More information');
    const describedBy = button.attributes('aria-describedby');
    expect(describedBy).toBeTruthy();
    const bubble = wrapper.get(`#${describedBy}`);
    expect(bubble.text()).toContain('Explains the setting.');
  });

  it('reveals on focus and hides on Escape', async () => {
    const wrapper = mount(Tooltip, { props: { text: 'Hi' } });
    const button = wrapper.get('button');
    await button.trigger('focus');
    expect(wrapper.get('[role="tooltip"]').isVisible()).toBe(true);
    await button.trigger('keydown', { key: 'Escape' });
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
  });
});
```

- [ ] **Step 2: Run it — verify it fails**

Run: `cd assets/admin && npx vitest run src/components/__tests__/Tooltip.spec.ts`
Expected: FAIL — component does not exist.

- [ ] **Step 3: Implement `Tooltip.vue`**

Create `assets/admin/src/components/Tooltip.vue`:

```vue
<script setup lang="ts">
import { computed, ref, useId } from 'vue';

/**
 * Accessible information tooltip (WCAG 2.1 AA).
 *
 * A keyboard-focusable ⓘ button describes a short text bubble via
 * `aria-describedby`. Shows on hover and focus; hides on blur / Escape. The
 * bubble content is always in the DOM for screen readers when open, and never
 * hover-only. This is the shared help primitive for simple settings across all
 * modules (see HelpText for inline explanations of complex/risky settings).
 */

interface Props {
  /** Tooltip body text. */
  text: string;
  /** Accessible name for the trigger button. */
  label?: string;
}

const props = withDefaults(defineProps<Props>(), {
  label: 'More information',
});

const open = ref(false);
const bubbleId = `fx-tip-${useId()}`;
const describedBy = computed(() => (open.value ? bubbleId : undefined));

function show(): void {
  open.value = true;
}
function hide(): void {
  open.value = false;
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') hide();
}
</script>

<template>
  <span class="fx-tip">
    <button
      type="button"
      class="fx-tip__trigger"
      :aria-label="props.label"
      :aria-describedby="describedBy"
      :aria-expanded="open"
      @mouseenter="show"
      @mouseleave="hide"
      @focus="show"
      @blur="hide"
      @keydown="onKeydown"
    >
      <svg
        class="fx-tip__icon"
        viewBox="0 0 16 16"
        aria-hidden="true"
        focusable="false"
      >
        <circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="1.4" />
        <circle cx="8" cy="4.6" r="0.95" fill="currentColor" />
        <path d="M8 7v5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
      </svg>
    </button>
    <span
      v-if="open"
      :id="bubbleId"
      role="tooltip"
      class="fx-tip__bubble"
    >
      {{ props.text }}
    </span>
  </span>
</template>

<style scoped>
.fx-tip {
  position: relative;
  display: inline-flex;
  vertical-align: middle;
}

.fx-tip__trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.05rem;
  height: 1.05rem;
  padding: 0;
  border: none;
  background: transparent;
  color: var(--fx-color-text-muted);
  cursor: help;
  border-radius: 50%;
}

.fx-tip__trigger:hover,
.fx-tip__trigger:focus-visible {
  color: var(--fx-color-primary-strong);
}

.fx-tip__trigger:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
}

.fx-tip__icon {
  width: 100%;
  height: 100%;
}

.fx-tip__bubble {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
  z-index: 50;
  width: max-content;
  max-width: 18rem;
  padding: var(--fx-space-2) var(--fx-space-3);
  background: var(--fx-color-text);
  color: var(--fx-color-surface);
  border-radius: var(--fx-radius-md);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  box-shadow: var(--fx-shadow-md);
  white-space: normal;
}
</style>
```

- [ ] **Step 4: Export from the barrel**

In `assets/admin/src/components/index.ts`, add after the `Toast` export (line 9):

```ts
export { default as Tooltip } from './Tooltip.vue';
```

- [ ] **Step 5: Run it — verify it passes**

Run: `cd assets/admin && npx vitest run src/components/__tests__/Tooltip.spec.ts`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add assets/admin/src/components/Tooltip.vue \
        assets/admin/src/components/index.ts \
        assets/admin/src/components/__tests__/Tooltip.spec.ts
git commit -m "$(cat <<'EOF'
feat(admin): add accessible Tooltip primitive

Keyboard-focusable ⓘ trigger with aria-describedby, hover+focus reveal,
Escape to dismiss. Shared help primitive for simple settings.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: `HelpText` primitive (D2)

Inline helper text for complex/risky settings — always visible, wired via `aria-describedby`.

**Files:**
- Create: `assets/admin/src/components/HelpText.vue`
- Modify: `assets/admin/src/components/index.ts`
- Create: `assets/admin/src/components/__tests__/HelpText.spec.ts`

**Interfaces:**
- Produces: `HelpText` component. Props: `id?: string`, `tone?: 'muted' | 'warn'` (default `'muted'`).
  Renders `<p role="note">` with the default slot; when `id` is set, callers reference it from a control's
  `aria-describedby`.

- [ ] **Step 1: Write the failing test**

Create `assets/admin/src/components/__tests__/HelpText.spec.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import HelpText from '../HelpText.vue';

describe('HelpText', () => {
  it('renders a note with the provided id and slot content', () => {
    const wrapper = mount(HelpText, {
      props: { id: 'cache-help' },
      slots: { default: 'Controls admin caching.' },
    });
    const note = wrapper.get('[role="note"]');
    expect(note.attributes('id')).toBe('cache-help');
    expect(note.text()).toContain('Controls admin caching.');
  });

  it('applies the warn tone modifier', () => {
    const wrapper = mount(HelpText, {
      props: { tone: 'warn' },
      slots: { default: 'Risky.' },
    });
    expect(wrapper.get('[role="note"]').classes()).toContain('fx-help--warn');
  });
});
```

- [ ] **Step 2: Run it — verify it fails**

Run: `cd assets/admin && npx vitest run src/components/__tests__/HelpText.spec.ts`
Expected: FAIL — component does not exist.

- [ ] **Step 3: Implement `HelpText.vue`**

Create `assets/admin/src/components/HelpText.vue`:

```vue
<script setup lang="ts">
/**
 * Inline helper text for complex or risky settings — always visible, unlike
 * Tooltip. Give it an `id` and reference it from the control's
 * `aria-describedby` so screen readers announce it with the field.
 */
interface Props {
  id?: string;
  tone?: 'muted' | 'warn';
}

withDefaults(defineProps<Props>(), { id: undefined, tone: 'muted' });
</script>

<template>
  <p
    :id="id"
    role="note"
    class="fx-help"
    :class="{ 'fx-help--warn': tone === 'warn' }"
  >
    <slot />
  </p>
</template>

<style scoped>
.fx-help {
  margin: 0;
  color: var(--fx-color-text-muted);
  font-size: var(--fx-font-size-sm);
  line-height: var(--fx-line-height-snug);
  max-width: 62ch;
}

.fx-help--warn {
  color: var(--fx-color-warn);
}

.fx-help :deep(code) {
  background: var(--fx-color-elevated);
  padding: 0 var(--fx-space-1);
  border-radius: var(--fx-radius-sm);
  font-family: var(--fx-font-mono);
  font-size: 0.9em;
}
</style>
```

- [ ] **Step 4: Export from the barrel**

In `assets/admin/src/components/index.ts`, add:

```ts
export { default as HelpText } from './HelpText.vue';
```

- [ ] **Step 5: Run it — verify it passes**

Run: `cd assets/admin && npx vitest run src/components/__tests__/HelpText.spec.ts`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add assets/admin/src/components/HelpText.vue \
        assets/admin/src/components/index.ts \
        assets/admin/src/components/__tests__/HelpText.spec.ts
git commit -m "$(cat <<'EOF'
feat(admin): add HelpText primitive for inline setting explanations

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Security Headers two-tab IA, fold Violations into CSP (B)

**Files:**
- Modify: `assets/admin/src/router/index.ts:57-91,108-124`
- Modify: `assets/admin/src/modules/SecurityHeaders/SecurityHeaders.vue:29-41`
- Modify: `assets/admin/src/modules/SecurityHeaders/views/CspView.vue` (append violations section)
- Modify: `assets/admin/src/modules/SecurityHeaders/__tests__/SecurityHeaders.spec.ts`

**Interfaces:**
- Consumes: `ViolationsView.vue` (kept as-is, imported as a child), the SecurityHeaders store.
- Produces: two tabs — `Response Headers` (`security-headers.headers`) and `Content Security Policy`
  (`security-headers.csp`). Route `security-headers.violations` is removed; a redirect from its old path
  to `security-headers.csp` preserves bookmarks. `CspView` renders `<ViolationsView />` inside a
  collapsible "Violation log" section.

- [ ] **Step 1: Update the tab list test first**

In `assets/admin/src/modules/SecurityHeaders/__tests__/SecurityHeaders.spec.ts`, update the tab
assertions to expect exactly two tabs with labels `Response Headers` and `Content Security Policy`
(remove any `Violations` tab expectation). If the spec asserts three `role="tab"` elements, change it to
two and update the label list.

- [ ] **Step 2: Run it — verify it fails**

Run: `cd assets/admin && npx vitest run src/modules/SecurityHeaders/__tests__/SecurityHeaders.spec.ts`
Expected: FAIL — three tabs currently render.

- [ ] **Step 3: Reduce the tab list**

In `SecurityHeaders.vue`, replace `TABS` (lines 29-41):

```ts
const TABS: readonly Tab[] = [
  {
    id: 'headers',
    label: 'Response Headers',
    routeName: 'security-headers.headers',
  },
  {
    id: 'csp',
    label: 'Content Security Policy',
    routeName: 'security-headers.csp',
  },
] as const;
```

Also narrow the `TabId` type (line 21) to `'headers' | 'csp'` and update the `tabButtons` ref initializer
(lines 53-57) to drop the `violations` key.

- [ ] **Step 4: Drop the violations route, add a redirect**

In `assets/admin/src/router/index.ts`, in `liveModuleRoutes['security-headers'].children` (lines 63-81),
remove the `violations` child. Then add a top-level redirect after the `moduleRoutes` spread (near line
110):

```ts
  {
    // Back-compat: the violations log now lives inside the CSP tab.
    path: '/security-headers/violations',
    redirect: { name: 'security-headers.csp' },
  },
```

- [ ] **Step 5: Fold the violation log into CspView**

In `CspView.vue`, import the existing view and render it in a collapsible section at the bottom of the
template (inside the root element):

```vue
import ViolationsView from './ViolationsView.vue';
```

```html
    <details class="fx-csp__violations">
      <summary class="fx-csp__violations-summary">Violation log</summary>
      <ViolationsView />
    </details>
```

Add minimal styling so the `<summary>` reads as an affordance:

```css
.fx-csp__violations {
  border-top: 1px solid var(--fx-color-border);
  padding-top: var(--fx-space-4);
}
.fx-csp__violations-summary {
  cursor: pointer;
  font-family: var(--fx-font-heading);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-text);
  padding: var(--fx-space-1) 0;
}
.fx-csp__violations-summary:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
  border-radius: var(--fx-radius-sm);
}
```

- [ ] **Step 6: Run the SecurityHeaders spec — verify it passes**

Run: `cd assets/admin && npx vitest run src/modules/SecurityHeaders/__tests__/SecurityHeaders.spec.ts`
Expected: PASS.

- [ ] **Step 7: Full JS gate**

Run: `cd assets/admin && npm run check`
Expected: all green (type-check confirms the narrowed `TabId` and the removed route are consistent).

- [ ] **Step 8: Commit**

```bash
git add assets/admin/src/router/index.ts \
        assets/admin/src/modules/SecurityHeaders/SecurityHeaders.vue \
        assets/admin/src/modules/SecurityHeaders/views/CspView.vue \
        assets/admin/src/modules/SecurityHeaders/__tests__/SecurityHeaders.spec.ts
git commit -m "$(cat <<'EOF'
refactor(security-headers): two-tab IA, fold violations into CSP tab

Response Headers + Content Security Policy. The violation log renders in a
collapsible section inside the CSP tab; the old violations route redirects
there for bookmark compatibility.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: HSTS "Advanced" section + Cache-Control HelpText + header tooltips (C + D-apply-SH)

**Files:**
- Modify: `assets/admin/src/modules/SecurityHeaders/views/HeadersView.vue`
- Modify: `assets/admin/src/modules/SecurityHeaders/views/__tests__/HeadersView.spec.ts`

**Interfaces:**
- Consumes: `Tooltip`, `HelpText` from `@/components`; the SecurityHeaders config store.
- Produces: HSTS section rendered last, inside a collapsed `<details class="fx-headers-view__advanced">`;
  Cache-Control gains a full `HelpText`; each header section title gets a `Tooltip`.

- [ ] **Step 1: Add a failing test for HSTS placement + Cache-Control help**

In `HeadersView.spec.ts`, add:

```ts
  it('places HSTS inside a collapsed Advanced section', () => {
    const wrapper = mountHeadersView();
    const advanced = wrapper.get('details.fx-headers-view__advanced');
    expect(advanced.attributes('open')).toBeUndefined(); // collapsed by default
    expect(advanced.text()).toContain('HTTP Strict Transport Security');
  });

  it('explains Cache-Control inline', () => {
    const wrapper = mountHeadersView();
    expect(wrapper.text().toLowerCase()).toContain('proxies');
  });
```

> Reuse the file's existing mount helper; if none exists, mount `HeadersView` with the store seeded via
> the module's test setup (mirror `SecurityHeaders.spec.ts`).

- [ ] **Step 2: Run it — verify it fails**

Run: `cd assets/admin && npx vitest run src/modules/SecurityHeaders/views/__tests__/HeadersView.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Import the primitives**

In `HeadersView.vue`, extend the components import (line 3):

```ts
import { Toggle, TextField, Select, SaveBar, Tooltip, HelpText } from '@/components';
```

- [ ] **Step 4: Move HSTS into an Advanced `<details>` at the bottom**

Cut the entire HSTS `<section>` (lines 50-86) and re-insert it just before the `<SaveBar>` (line 190),
wrapped:

```html
    <details class="fx-headers-view__advanced">
      <summary class="fx-headers-view__advanced-summary">
        Advanced — HSTS (can break sites)
      </summary>
      <!-- (the existing HSTS <section> markup, unchanged, goes here) -->
    </details>
```

Add styling:

```css
.fx-headers-view__advanced {
  border: 1px solid var(--fx-color-border);
  border-radius: var(--fx-radius-lg);
  background: var(--fx-color-surface);
  box-shadow: var(--fx-shadow-sm);
  padding: var(--fx-space-4) var(--fx-space-5);
}
.fx-headers-view__advanced-summary {
  cursor: pointer;
  font-family: var(--fx-font-heading);
  font-weight: var(--fx-font-weight-medium);
  color: var(--fx-color-warn);
}
.fx-headers-view__advanced-summary:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--fx-color-primary-soft);
  border-radius: var(--fx-radius-sm);
}
.fx-headers-view__advanced[open] .fx-headers-view__advanced-summary {
  margin-bottom: var(--fx-space-3);
}
```

- [ ] **Step 5: Give Cache-Control a full inline explanation**

In the Cache-Control section (was lines 170-188), replace the `<p class="...section-hint">` with a
`HelpText` and add the accessible wiring on the toggle:

```html
      <HelpText id="fx-cache-help">
        Sends a strict <code>Cache-Control: no-store</code> on wp-admin and
        logged-in responses so proxies, CDNs, and browsers never cache private
        or per-user admin pages. Frontend/anonymous caching is untouched. Leave
        on unless a plugin manages admin caching itself — wrong values here can
        serve stale dashboards or leak one user's page to another.
      </HelpText>
      <Toggle
        v-model="config.headers.cache_control.enabled"
        label="Enable admin Cache-Control"
        aria-describedby="fx-cache-help"
      />
```

- [ ] **Step 6: Add a Tooltip to each header section title**

For each non-HSTS section header (X-Frame-Options, X-Content-Type-Options, Referrer-Policy,
Permissions-Policy), add a `Tooltip` immediately after the `<h3>` using this copy table:

| Section | Tooltip `text` |
|---|---|
| X-Frame-Options | "Stops other sites from embedding yours in a frame (clickjacking). SAMEORIGIN allows your own site to frame itself." |
| X-Content-Type-Options | "Sends `nosniff` so browsers don't guess a file's type — blocks tricks that run an upload as script." |
| Referrer-Policy | "Limits how much of the current URL is sent when users click outbound links." |
| Permissions-Policy | "Turns off browser features (camera, mic, geolocation…) your site doesn't use." |

Pattern (repeat per section, swapping the text):

```html
      <header class="fx-headers-view__section-header">
        <div class="fx-headers-view__title-row">
          <h3 id="fx-headers-xfo" class="fx-headers-view__section-title">
            X-Frame-Options
          </h3>
          <Tooltip text="Stops other sites from embedding yours in a frame (clickjacking). SAMEORIGIN allows your own site to frame itself." />
        </div>
        ...
```

Add the small layout helper:

```css
.fx-headers-view__title-row {
  display: flex;
  align-items: center;
  gap: var(--fx-space-2);
}
```

- [ ] **Step 7: Run the tests — verify they pass**

Run: `cd assets/admin && npx vitest run src/modules/SecurityHeaders/views/__tests__/HeadersView.spec.ts`
Expected: PASS.

- [ ] **Step 8: Full JS gate + build**

Run: `cd assets/admin && npm run check && npm run build`
Expected: all green; `dist/` rebuilt.

- [ ] **Step 9: Commit**

```bash
git add assets/admin/src/modules/SecurityHeaders/views/HeadersView.vue \
        assets/admin/src/modules/SecurityHeaders/views/__tests__/HeadersView.spec.ts \
        assets/admin/dist
git commit -m "$(cat <<'EOF'
feat(security-headers): HSTS Advanced section, Cache-Control help, tooltips

Move HSTS into a collapsed "Advanced" block, give Cache-Control a full
inline explanation, and add ⓘ tooltips to each header section.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Apply the help standard across Hardening (D-apply-H)

Give every Hardening setting a short "why" tooltip via a new `tooltip` prop on `ChecklistItem`.

**Files:**
- Modify: `assets/admin/src/modules/Hardening/components/ChecklistItem.vue`
- Modify: `assets/admin/src/modules/Hardening/components/__tests__/ChecklistItem.spec.ts`
- Modify: `assets/admin/src/modules/Hardening/Hardening.vue` (pass `tooltip` per row)

**Interfaces:**
- Consumes: `Tooltip` from `@/components`.
- Produces: `ChecklistItem` accepts optional `tooltip?: string`; when set, a `Tooltip` renders next to the
  item label.

- [ ] **Step 1: Add a failing test on ChecklistItem**

In `ChecklistItem.spec.ts`, add:

```ts
  it('renders a tooltip trigger when the tooltip prop is set', () => {
    const wrapper = mount(ChecklistItem, {
      props: { label: 'Block author archive', status: 'active', tooltip: 'Why this matters.' },
    });
    expect(wrapper.find('button[aria-label="More information"]').exists()).toBe(true);
  });
```

(Import `ChecklistItem` and `mount` at the top if not already present.)

- [ ] **Step 2: Run it — verify it fails**

Run: `cd assets/admin && npx vitest run src/modules/Hardening/components/__tests__/ChecklistItem.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Add the `tooltip` prop to ChecklistItem**

Read `ChecklistItem.vue`. Add `tooltip?: string` to its `Props` interface (default `undefined`), import
`Tooltip` from `@/components`, and render it next to the label — e.g. immediately after the label text
element:

```html
<span class="fx-checklist-item__label">{{ label }}</span>
<Tooltip v-if="tooltip" :text="tooltip" />
```

Wrap the label + tooltip in a flex row if needed (`display: flex; align-items: center; gap: var(--fx-space-2)`).

- [ ] **Step 4: Run it — verify it passes**

Run: `cd assets/admin && npx vitest run src/modules/Hardening/components/__tests__/ChecklistItem.spec.ts`
Expected: PASS.

- [ ] **Step 5: Pass `tooltip` on each Hardening row**

In `Hardening.vue`, add a `tooltip="…"` attribute to each `<ChecklistItem>` using this copy table:

| Row (label) | `tooltip` |
|---|---|
| Block author archive (?author=N) | "Author URLs like /?author=1 redirect to /author/username, leaking valid usernames to attackers. This 404s them for logged-out visitors." |
| Block REST /wp/v2/users … | "The REST users endpoint lists every account's slug. Blocking anonymous access hides that list." |
| XML-RPC mode | "xmlrpc.php enables remote publishing but is a common brute-force and pingback-DDoS vector. Disable it unless a legacy client needs it." |
| Remove X-Powered-By header | "Hides the PHP version so scanners can't match your stack to known CVEs." |
| Remove WordPress generator tag | "Removes the `<meta name=generator>` WordPress version hint from your page source." |
| Remove generator from RSS feeds | "Feeds also expose the WP version in a `<generator>` element — this strips it." |
| Strip ?ver= query from scripts and styles | "The ?ver= on asset URLs reveals WP/plugin versions. Stripping it hides them but also weakens cache-busting across upgrades." |
| Block access to readme.html and license.txt | "Both files state the exact WordPress version. Blocking them removes an easy version fingerprint." |
| Obfuscate login errors | "Generic 'invalid username or password' stops attackers learning which half was right." |
| Runtime-enforce file editing lockdown | "Disables the dashboard theme/plugin code editors. The DISALLOW_FILE_EDIT constant is stronger — use it when you can edit wp-config.php." |
| Disable Application Passwords | "App Passwords authenticate REST/XML-RPC clients. Turn off if nothing external connects to this site." |

- [ ] **Step 6: Full JS gate + build**

Run: `cd assets/admin && npm run check && npm run build`
Expected: all green; `dist/` rebuilt.

- [ ] **Step 7: Commit**

```bash
git add assets/admin/src/modules/Hardening/components/ChecklistItem.vue \
        assets/admin/src/modules/Hardening/components/__tests__/ChecklistItem.spec.ts \
        assets/admin/src/modules/Hardening/Hardening.vue \
        assets/admin/dist
git commit -m "$(cat <<'EOF'
feat(hardening): add per-setting help tooltips via ChecklistItem

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Document the standard + checklist + CHANGELOG

**Files:**
- Modify: `CLAUDE.md` (§3.4)
- Modify: `_PRD/checklist-fanxie-wp-core.md` (cross-phase ongoing items)
- Modify: `CHANGELOG.md` (Unreleased)

**Interfaces:** none (docs only).

- [ ] **Step 1: Record the help standard in CLAUDE.md**

In `CLAUDE.md` §3.4 (Vue 3 Admin SPA), add a bullet under the accessibility guidance:

```markdown
- **Setting help is a standard, not a one-off.** Every setting exposes an
  explanation: simple toggles via the `Tooltip` primitive (accessible ⓘ), and
  complex/risky settings via inline `HelpText`. All current and future modules
  follow this — new modules wire it in as they are built.
```

- [ ] **Step 2: Add the cross-phase checklist item**

In `_PRD/checklist-fanxie-wp-core.md`, under "Cross-phase ongoing items", add:

```markdown
- [ ] Every setting has an accessible explanation (Tooltip or inline HelpText)
```

- [ ] **Step 3: Write the CHANGELOG entry**

In `CHANGELOG.md`, under `## [Unreleased]`, add entries:

```markdown
### Changed
- Admin settings now live under a top-level **FX Core** menu (was Settings →
  Fanxie WP Core).
- Security Headers consolidated to two tabs — Response Headers and Content
  Security Policy — with the CSP violation log folded into the CSP tab. HSTS
  moved into a collapsed "Advanced" section.
- Every admin setting now carries an accessible explanation (ⓘ tooltip or
  inline help); Cache-Control (admin) gained a full description.

### Fixed
- Hardening file-editor lockdown now actually hides the Theme/Plugin File
  Editors: the runtime guard matched `edit_themes`/`edit_plugins` but WordPress
  gates both editors via `capability_edit_themes`.
- Hardening uploads/readme protection no longer reports a misleading
  ".htaccess write failed": writes are attempted only on Apache/LiteSpeed, the
  readme/license probe distinguishes "inconclusive" from "still served", and
  messaging is server-aware.
- Application Passwords status is now site-wide-aware and names the holder(s)
  instead of showing a dead-end "revoke them" note.
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md _PRD/checklist-fanxie-wp-core.md CHANGELOG.md
git commit -m "$(cat <<'EOF'
docs: record help standard, checklist item, and changelog for the batch

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Final verification (whole batch)

- [ ] **Step 1: PHP — full unit + integration + lint + static analysis**

Run:
```bash
composer test:unit
composer phpcs && composer phpstan
npm run env:start && npm run test:php:integration
```
Expected: all green.

- [ ] **Step 2: JS — full gate + production build**

Run: `cd assets/admin && npm run check && npm run build`
Expected: format + lint + type-check + tests pass; `dist/` rebuilt and committed.

- [ ] **Step 3: Plugin Check**

Run: `npm run plugin:check`
Expected: no new errors (warnings still ignored per the Phase 0–6 concession).

- [ ] **Step 4: Manual smoke**

`npm run env:start`, then confirm in wp-admin: top-level **FX Core** menu with icon; Security Headers
shows two tabs with the violation log inside CSP and HSTS under a collapsed Advanced block; tooltips
appear and are keyboard-reachable; Hardening file-editor toggle hides both editors; no ".htaccess write
failed" copy; Application Passwords note names holders.

- [ ] **Step 5: Open the PR (only when the user asks)**

Do not push or open a PR until the user requests it. When they do, target `main` from
`phase/1-admin-refinements`.
```

## Self-review notes (addressed inline in the plan)

- **Spec coverage:** A→Task 4, B→Task 7, C→Task 8, D→Tasks 5/6 (primitives) + 8/9 (application) + 10 (standard), E→Task 1, F→Tasks 2a/2b, G→Tasks 3a/3b. Contract-change table items all mapped. The `application_passwords_users` type flows PHP (3a) → TS (3b). The readme/license probe widening (discovered during code review) is folded into F.
- **Sequencing:** matches the spec (E→F→G→A→D→B→C), with tooltip primitives (5/6) landing before their Security Headers/Hardening applications (8/9).
- **Types:** `readme_blocked`/`license_blocked` widened to `bool|null` in both PHP docblocks and the TS `ChecksResult`; `application_passwords_users` shape identical across PHP snapshot, `is_valid_shape`, and TS.
