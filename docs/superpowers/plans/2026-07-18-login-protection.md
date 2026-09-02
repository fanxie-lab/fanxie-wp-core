# Login Protection (core) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Phase 1.3 core `LoginProtection` module — brute-force attempt limiting (on by default), hide-wp-login, strong-password enforcement, and per-role session timeout — with a Vue admin tab, WP-CLI recovery commands, and two custom tables.

**Architecture:** A `LoginProtection` module (extends `ModuleBase`, fine-grained toggles, no master enable) wires focused runtime collaborators. Two `dbDelta` tables (`fanxie_core_login_log`, `fanxie_core_login_bans`) mirror the existing `ViolationRepository` pattern; short tiered lockouts use transients. The Vue tab mirrors the `Hardening.vue` pattern and the `Tooltip`/`HelpText` help standard. 2FA is out of scope (Phase 1.3b).

**Tech Stack:** PHP 8.1+, WP 6.4+, PHPUnit 12 + Brain Monkey/Mockery (unit) / `WP_UnitTestCase` (integration), PHPCS (WordPress-Extra), PHPStan L8. Vue 3 `<script setup>` + TS + Pinia, Vitest + Vue Test Utils, ESLint + Prettier.

## Global Constraints

- Text domain `fanxie-wp-core` on every PHP user-facing string; no variables inside `__()` (use `sprintf` + translator comments). The Vue SPA is intentionally NOT i18n-wired yet (deferred to pre-1.0) — match the existing plain-string pattern; do not add i18n wrappers in `.vue`/`.ts`.
- All AJAX handlers register through `AjaxRouter::register()` — nonce (`fanxie_wp_core_admin`) + `manage_fanxie_wp_core` are enforced centrally by the dispatcher; handlers receive the decoded payload and return `array` (success) or `WP_Error` (failure).
- `$wpdb` custom queries use `prepare()`; table names are private-property/const interpolations with the same `phpcs:ignore` pragmas as `ViolationRepository`.
- Options prefix `fanxie_wp_core_*`; custom tables `{$wpdb->prefix}fanxie_core_*`; hooks `fanxie_wp_core/login_protection/*`; capability `manage_fanxie_wp_core` (`Plugin::CAPABILITY`).
- **Naming (project-wide, new this phase):** user-facing wp-config override constants use the `FX_CORE_*` prefix (this module: `FX_CORE_LOGIN_SLUG`); the WP-CLI root command is `fx-core` (`wp fx-core login …`). Internal bootstrap constants (`FANXIE_WP_CORE_*`) and option/table/hook prefixes are unchanged.
- **IP posture:** client IP comes from `IpResolver` — `REMOTE_ADDR` only unless the `attempts.trust_proxy` toggle is on, then the configured header (left-most public). Never trust a forwarded header by default.
- PHP: `declare( strict_types=1 )`, typed properties, no global state. PHPStan L8 + PHPCS clean.
- Coverage ≥80% on `src/Modules/LoginProtection`. `dist/` is gitignored — `npm run build` verifies only, never committed.
- Commit style: Conventional Commits, scope `login-protection`. Every commit message ends with:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- Only `git add` the exact files a task changes — the working tree carries unrelated uncommitted user edits (`_PRD/…md`, `UserEnumerationGuard.php`, untracked `docs/design-system.html`) that must stay untouched.

### Command reference
- PHP unit: `composer test:unit` or `./vendor/bin/phpunit --testsuite=unit --filter <Name>`
- PHP integration (needs `npm run env:start`): `wp-env run tests-cli --env-cwd=wp-content/plugins/fanxie-wp-core ./vendor/bin/phpunit --testsuite=integration --filter <Name>`
- PHP lint: `composer phpcs` · `composer phpstan`
- JS: `cd assets/admin && npx vitest run <path>` · full gate `cd assets/admin && npm run check` · build-verify `npm run build`
- WP-CLI in env: `wp-env run cli wp <args>`

### Reference patterns (read before implementing the matching task)
- Custom table + dbDelta + query/prune: `src/Modules/SecurityHeaders/ViolationRepository.php`.
- Module wiring + schema/sanitizers: `src/Modules/Hardening/Hardening.php`; base contract `src/Modules/ModuleBase.php`.
- AJAX registration + dispatch: `src/Admin/AjaxRouter.php`; a controller: `src/Modules/Hardening/AjaxController.php`.
- Activation table install: `src/Plugin.php::activate()` (calls `( new ViolationRepository() )->install()`).
- Vue module shape: `assets/admin/src/modules/Hardening/` (root view, store, types, components) + `router/index.ts` `liveModuleRoutes` + shared primitives (`Toggle` incl. `describedby`, `Tooltip`, `HelpText`, `StatusPill`, `SaveBar`, `Select`, `TextField`).

---

## Task 1: Module skeleton + registration

Create the `LoginProtection` module class (config + schema), register it in the container, and confirm config round-trips. `register_hooks()` starts minimal and grows in later tasks.

**Files:**
- Create: `src/Modules/LoginProtection/LoginProtection.php`
- Modify: `src/Plugin.php:14-18` (imports), `:201-218` (register_services)
- Create test: `tests/Unit/Modules/LoginProtection/LoginProtectionTest.php`

**Interfaces:**
- Produces: `LoginProtection extends ModuleBase` with `MODULE_ID='login-protection'`, constructor `__construct(private readonly AjaxRouter $ajax_router)`, `id()`, `name()`, `get_default_config()`, `get_settings_fields()`, `register_hooks()`. Default config keys consumed by later tasks: `attempts` (enabled, tiers, allowlist, trust_proxy, proxy_header, log_retention_days), `hide_login` (enabled, slug), `passwords` (enforce, min_length, require_mixed_case, require_number, require_symbol), `sessions` (enabled, timeouts). Registered in `Plugin::register_services()` as `$this->services[LoginProtection::class]`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );
namespace FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\LoginProtection\LoginProtection;
use Mockery;
use PHPUnit\Framework\TestCase;

final class LoginProtectionTest extends TestCase {
	protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when( '__' )->returnArg( 1 ); }
	protected function tearDown(): void { Monkey\tearDown(); Mockery::close(); parent::tearDown(); }

	private function module(): LoginProtection {
		return new LoginProtection( Mockery::mock( AjaxRouter::class ) );
	}

	public function test_identity(): void {
		$m = $this->module();
		$this->assertSame( 'login-protection', $m->id() );
		$this->assertSame( 'Login Protection', $m->name() );
	}

	public function test_default_config_shape(): void {
		$c = $this->module()->get_default_config();
		$this->assertTrue( $c['attempts']['enabled'] );          // attempt limiting ON by default
		$this->assertFalse( $c['hide_login']['enabled'] );        // hide-login OFF
		$this->assertFalse( $c['passwords']['enforce'] );         // passwords OFF
		$this->assertFalse( $c['sessions']['enabled'] );          // sessions OFF
		$this->assertSame( [ 5, 10, 20 ], array_column( $c['attempts']['tiers'], 'threshold' ) );
	}

	public function test_config_sanitises_and_round_trips(): void {
		$stored = [];
		Functions\when( 'get_option' )->alias( fn ( $k, $d = false ) => $stored[ $k ] ?? $d );
		Functions\when( 'update_option' )->alias( function ( $k, $v ) use ( &$stored ) { $stored[ $k ] = $v; return true; } );
		Functions\when( 'sanitize_key' )->alias( fn ( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ) );
		Functions\when( 'sanitize_text_field' )->alias( fn ( $v ) => trim( (string) $v ) );
		Functions\when( 'absint' )->alias( fn ( $v ) => abs( (int) $v ) );

		$m = $this->module();
		$m->update_config( [ 'hide_login' => [ 'enabled' => true, 'slug' => 'My Portal!' ] ] );
		$c = $m->get_config();
		$this->assertTrue( $c['hide_login']['enabled'] );
		$this->assertSame( 'my-portal', $c['hide_login']['slug'] ); // slug sanitizer_callback
	}
}
```

- [ ] **Step 2: Run — verify it fails** — `./vendor/bin/phpunit --testsuite=unit --filter LoginProtectionTest` → FAIL (class missing).

- [ ] **Step 3: Implement `LoginProtection.php`**

```php
<?php
/**
 * Login Protection module entry point.
 *
 * @package FanxieLab\WPCore\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\LoginProtection;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\ModuleBase;

defined( 'ABSPATH' ) || exit;

/**
 * Module #3 — Login Protection (PRD §5).
 *
 * Fine-grained toggles: attempt limiting (default on), hide-login, strong
 * passwords, session timeout (all default off). Runtime concerns live in
 * focused `Runtime/*` classes; this class is the wiring + config layer.
 */
final class LoginProtection extends ModuleBase {

	public const MODULE_ID = 'login-protection';

	/**
	 * @param AjaxRouter $ajax_router Shared AJAX router.
	 */
	public function __construct( private readonly AjaxRouter $ajax_router ) {}

	public function id(): string {
		return self::MODULE_ID;
	}

	public function name(): string {
		return __( 'Login Protection', 'fanxie-wp-core' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_default_config(): array {
		return [
			'attempts'   => [
				'enabled'      => true,
				'trust_proxy'  => false,
				'proxy_header' => 'HTTP_X_FORWARDED_FOR',
				'allowlist'    => [],
				'tiers'        => [
					[ 'threshold' => 5, 'lockout_minutes' => 15 ],
					[ 'threshold' => 10, 'lockout_minutes' => 60 ],
					[ 'threshold' => 20, 'lockout_minutes' => 1440 ],
				],
				'log_retention_days' => 30,
			],
			'hide_login' => [
				'enabled' => false,
				'slug'    => '',
			],
			'passwords'  => [
				'enforce'            => false,
				'min_length'         => 12,
				'require_mixed_case' => true,
				'require_number'     => true,
				'require_symbol'     => true,
			],
			'sessions'   => [
				'enabled'  => false,
				'timeouts' => [
					'administrator' => 30,
					'default'       => 120,
				],
			],
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return [
			[ 'id' => 'attempts.enabled', 'label' => __( 'Limit login attempts', 'fanxie-wp-core' ), 'type' => 'toggle', 'default' => true, 'sanitizer' => 'bool' ],
			[ 'id' => 'attempts.trust_proxy', 'label' => __( 'Trust reverse-proxy header for client IP', 'fanxie-wp-core' ), 'type' => 'toggle', 'default' => false, 'sanitizer' => 'bool' ],
			[ 'id' => 'attempts.proxy_header', 'label' => __( 'Proxy IP header', 'fanxie-wp-core' ), 'type' => 'text', 'default' => 'HTTP_X_FORWARDED_FOR', 'sanitizer' => 'text' ],
			[ 'id' => 'attempts.allowlist', 'label' => __( 'Trusted IP allowlist', 'fanxie-wp-core' ), 'type' => 'textarea', 'default' => [], 'sanitizer_callback' => [ $this, 'sanitize_ip_list' ] ],
			[ 'id' => 'attempts.tiers', 'label' => __( 'Lockout tiers', 'fanxie-wp-core' ), 'type' => 'textarea', 'default' => [], 'sanitizer_callback' => [ $this, 'sanitize_tiers' ] ],
			[ 'id' => 'attempts.log_retention_days', 'label' => __( 'Log retention (days)', 'fanxie-wp-core' ), 'type' => 'number', 'default' => 30, 'sanitizer' => 'absint' ],

			[ 'id' => 'hide_login.enabled', 'label' => __( 'Hide wp-login.php', 'fanxie-wp-core' ), 'type' => 'toggle', 'default' => false, 'sanitizer' => 'bool' ],
			[ 'id' => 'hide_login.slug', 'label' => __( 'Custom login slug', 'fanxie-wp-core' ), 'type' => 'text', 'default' => '', 'sanitizer_callback' => [ $this, 'sanitize_slug' ] ],

			[ 'id' => 'passwords.enforce', 'label' => __( 'Force strong passwords', 'fanxie-wp-core' ), 'type' => 'toggle', 'default' => false, 'sanitizer' => 'bool' ],
			[ 'id' => 'passwords.min_length', 'label' => __( 'Minimum length', 'fanxie-wp-core' ), 'type' => 'number', 'default' => 12, 'sanitizer' => 'absint' ],
			[ 'id' => 'passwords.require_mixed_case', 'label' => __( 'Require mixed case', 'fanxie-wp-core' ), 'type' => 'toggle', 'default' => true, 'sanitizer' => 'bool' ],
			[ 'id' => 'passwords.require_number', 'label' => __( 'Require a number', 'fanxie-wp-core' ), 'type' => 'toggle', 'default' => true, 'sanitizer' => 'bool' ],
			[ 'id' => 'passwords.require_symbol', 'label' => __( 'Require a symbol', 'fanxie-wp-core' ), 'type' => 'toggle', 'default' => true, 'sanitizer' => 'bool' ],

			[ 'id' => 'sessions.enabled', 'label' => __( 'Enforce session timeout', 'fanxie-wp-core' ), 'type' => 'toggle', 'default' => false, 'sanitizer' => 'bool' ],
			[ 'id' => 'sessions.timeouts', 'label' => __( 'Per-role timeouts (minutes)', 'fanxie-wp-core' ), 'type' => 'textarea', 'default' => [], 'sanitizer_callback' => [ $this, 'sanitize_timeouts' ] ],
		];
	}

	/**
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	public function sanitize_ip_list( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', trim( $value ) ) ?: [];
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$clean = [];
		foreach ( $value as $ip ) {
			$ip = is_string( $ip ) ? trim( $ip ) : '';
			if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) && ! in_array( $ip, $clean, true ) ) {
				$clean[] = $ip;
			}
			if ( count( $clean ) >= 100 ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return array<int, array{threshold: int, lockout_minutes: int}>
	 */
	public function sanitize_tiers( mixed $value ): array {
		if ( ! is_array( $value ) || [] === $value ) {
			return $this->get_default_config()['attempts']['tiers'];
		}
		$clean = [];
		foreach ( $value as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$t = absint( $tier['threshold'] ?? 0 );
			$m = absint( $tier['lockout_minutes'] ?? 0 );
			if ( $t > 0 && $m > 0 ) {
				$clean[] = [ 'threshold' => $t, 'lockout_minutes' => $m ];
			}
		}
		if ( [] === $clean ) {
			return $this->get_default_config()['attempts']['tiers'];
		}
		usort( $clean, static fn ( $a, $b ) => $a['threshold'] <=> $b['threshold'] );
		return $clean;
	}

	/**
	 * @param mixed $value Raw value.
	 */
	public function sanitize_slug( mixed $value ): string {
		$slug = sanitize_title( (string) $value );
		$reserved = [ 'wp-admin', 'wp-login', 'admin', 'login', 'wp-content', 'wp-includes', 'wp-json' ];
		return in_array( $slug, $reserved, true ) ? '' : $slug;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return array<string, int>
	 */
	public function sanitize_timeouts( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return $this->get_default_config()['sessions']['timeouts'];
		}
		$clean = [];
		foreach ( $value as $role => $minutes ) {
			$role = sanitize_key( (string) $role );
			$min  = absint( $minutes );
			if ( '' !== $role && $min > 0 ) {
				$clean[ $role ] = $min;
			}
		}
		return [] === $clean ? $this->get_default_config()['sessions']['timeouts'] : $clean;
	}

	/**
	 * Wire the module. Collaborators are added in later tasks.
	 */
	public function register_hooks(): void {
		// Populated by Tasks 3-10.
	}
}
```

> Note: `sanitize_title`/`sanitize_key`/`absint` are stubbed in the unit test; in production they are WP core. If PHPStan flags `absint` inside `get_default_config()` slug/tier defaults, none are called there — defaults are literals.

- [ ] **Step 4: Register in the container**

In `src/Plugin.php`, add the import (after line 15):
```php
use FanxieLab\WPCore\Modules\LoginProtection\LoginProtection;
```
In `register_services()` (after the Hardening block, ~line 209):
```php
		$login_protection = new LoginProtection( $ajax_router );
		$registry->register( $login_protection );
```
And in the `$this->services[...]` assignments:
```php
		$this->services[ LoginProtection::class ] = $login_protection;
```

- [ ] **Step 5: Run — verify pass** — `./vendor/bin/phpunit --testsuite=unit --filter LoginProtectionTest` → PASS.

- [ ] **Step 6: Lint** — `composer phpcs && composer phpstan` → clean.

- [ ] **Step 7: Commit**
```bash
git add src/Modules/LoginProtection/LoginProtection.php src/Plugin.php tests/Unit/Modules/LoginProtection/LoginProtectionTest.php
git commit -m "$(cat <<'EOF'
feat(login-protection): module skeleton + config schema

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Custom tables — `LoginLogRepository` + `BanRepository`

Two `dbDelta` tables mirroring `ViolationRepository`, plus wiring `install()` into activation.

**Files:**
- Create: `src/Modules/LoginProtection/LoginLogRepository.php`, `src/Modules/LoginProtection/BanRepository.php`
- Modify: `src/Plugin.php::activate()` (call both `install()`)
- Create test: `tests/Integration/Modules/LoginProtection/RepositoriesTest.php`

**Interfaces:**
- Produces:
  - `LoginLogRepository`: `install(): void`, `record(string $event_type, string $ip, string $username, ?int $user_id, array $context): void`, `query(array $filters, int $page, int $per_page): array{rows: list<array>, total: int}`, `prune(int $days): int`, `table_name(): string`. Const `TABLE_BASENAME='fanxie_core_login_log'`, `SCHEMA_VERSION_OPTION='fanxie_wp_core_login_protection_log_version'`.
  - `BanRepository`: `install(): void`, `add(string $subject_type, string $subject_value, ?string $reason, ?int $ttl_minutes): void`, `remove(string $subject_type, string $subject_value): int`, `is_banned(string $subject_type, string $subject_value): bool`, `query(int $page, int $per_page): array`, `prune_expired(): int`, `table_name(): string`. Const `TABLE_BASENAME='fanxie_core_login_bans'`, `SCHEMA_VERSION_OPTION='fanxie_wp_core_login_protection_bans_version'`.

- [ ] **Step 1: Write the failing integration test**

```php
<?php
declare( strict_types=1 );
namespace FanxieLab\WPCore\Tests\Integration\Modules\LoginProtection;

use FanxieLab\WPCore\Modules\LoginProtection\BanRepository;
use FanxieLab\WPCore\Modules\LoginProtection\LoginLogRepository;
use WP_UnitTestCase;

final class RepositoriesTest extends WP_UnitTestCase {
	public function test_log_install_record_query_prune(): void {
		$repo = new LoginLogRepository();
		$repo->install();
		global $wpdb;
		$this->assertSame( $repo->table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $repo->table_name() ) ) );

		$repo->record( 'failed_login', '203.0.113.5', 'admin', null, [ 'tier' => 1 ] );
		$out = $repo->query( [], 1, 25 );
		$this->assertSame( 1, $out['total'] );
		$this->assertSame( 'failed_login', $out['rows'][0]['event_type'] );
		$this->assertSame( '203.0.113.5', $out['rows'][0]['ip'] );
	}

	public function test_ban_add_lookup_remove_and_expiry(): void {
		$repo = new BanRepository();
		$repo->install();

		$repo->add( 'ip', '203.0.113.9', 'manual', null ); // indefinite
		$this->assertTrue( $repo->is_banned( 'ip', '203.0.113.9' ) );
		$this->assertFalse( $repo->is_banned( 'ip', '203.0.113.10' ) );

		$this->assertSame( 1, $repo->remove( 'ip', '203.0.113.9' ) );
		$this->assertFalse( $repo->is_banned( 'ip', '203.0.113.9' ) );

		// Expired ban is not "banned" and is pruned.
		$repo->add( 'username', 'bob', 'auto', -1 ); // already-expired (ttl in the past)
		$this->assertFalse( $repo->is_banned( 'username', 'bob' ) );
		$this->assertGreaterThanOrEqual( 1, $repo->prune_expired() );
	}
}
```

- [ ] **Step 2: Run — verify it fails** — `wp-env run tests-cli --env-cwd=wp-content/plugins/fanxie-wp-core ./vendor/bin/phpunit --testsuite=integration --filter RepositoriesTest` (after `npm run env:start`) → FAIL (classes missing).

- [ ] **Step 3: Implement `LoginLogRepository.php`** — mirror `ViolationRepository`'s `install()`/`query()`/`prune()` structure (same `require_once ABSPATH.'wp-admin/includes/upgrade.php'`, `get_charset_collate()`, version-gated `dbDelta`, the `phpcs:disable WordPress.DB.*` pragmas around every direct query, and `$wpdb->prepare()` for values with the table interpolated from the private property).

```php
<?php
declare( strict_types=1 );
namespace FanxieLab\WPCore\Modules\LoginProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Data layer for `{$wpdb->prefix}fanxie_core_login_log` — the login event
 * history that powers the admin log viewer. Mirrors ViolationRepository.
 */
final class LoginLogRepository {

	public const SCHEMA_VERSION_OPTION = 'fanxie_wp_core_login_protection_log_version';
	public const SCHEMA_VERSION        = '1';
	public const TABLE_BASENAME        = 'fanxie_core_login_log';

	private ?string $table = null;

	public function table_name(): string {
		if ( null === $this->table ) {
			global $wpdb;
			$prefix      = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
			$this->table = $prefix . self::TABLE_BASENAME;
		}
		return $this->table;
	}

	public function install(): void {
		global $wpdb;
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_VERSION_OPTION, '' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $this->table_name();
		$cc    = $wpdb->get_charset_collate();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(32) NOT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			username VARCHAR(180) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED DEFAULT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY ip (ip),
			KEY username (username),
			KEY created_at (created_at)
		) {$cc};";
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * @param array<string, mixed> $context Extra JSON-encoded detail.
	 */
	public function record( string $event_type, string $ip, string $username, ?int $user_id, array $context = [] ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table_name(),
			[
				'event_type' => substr( $event_type, 0, 32 ),
				'ip'         => substr( $ip, 0, 45 ),
				'username'   => substr( $username, 0, 180 ),
				'user_id'    => $user_id,
				'context'    => [] === $context ? null : (string) wp_json_encode( $context ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @param array<string, mixed> $filters Keys: event_type, ip, username, since, until.
	 * @return array{rows: list<array<string, mixed>>, total: int}
	 */
	public function query( array $filters, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;
		$table    = $this->table_name();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = [];
		$params = [];
		foreach ( [ 'event_type' => 'event_type', 'ip' => 'ip', 'username' => 'username' ] as $key => $col ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$where[]  = "{$col} = %s";
				$params[] = sanitize_text_field( (string) $filters[ $key ] );
			}
		}
		if ( isset( $filters['since'] ) && '' !== (string) $filters['since'] ) {
			$where[] = 'created_at >= %s';
			$params[] = (string) $filters['since'];
		}
		if ( isset( $filters['until'] ) && '' !== (string) $filters['until'] ) {
			$where[] = 'created_at <= %s';
			$params[] = (string) $filters['until'];
		}
		$where_sql = [] === $where ? '' : 'WHERE ' . implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		$rows_sql  = "SELECT id, event_type, ip, username, user_id, context, created_at FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( [] === $params ) {
			$total = (int) $wpdb->get_var( $count_sql );
			$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, $per_page, $offset ), ARRAY_A );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...array_merge( $params, [ $per_page, $offset ] ) ), ARRAY_A );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [ 'rows' => is_array( $rows ) ? $rows : [], 'total' => $total ];
	}

	public function prune( int $older_than_days ): int {
		global $wpdb;
		$table = $this->table_name();
		$days  = max( 1, $older_than_days );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )", $days ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
```

- [ ] **Step 4: Implement `BanRepository.php`** (same conventions):

```php
<?php
declare( strict_types=1 );
namespace FanxieLab\WPCore\Modules\LoginProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Data layer for `{$wpdb->prefix}fanxie_core_login_bans` — active persistent
 * bans, looked up on every gated login attempt.
 */
final class BanRepository {

	public const SCHEMA_VERSION_OPTION = 'fanxie_wp_core_login_protection_bans_version';
	public const SCHEMA_VERSION        = '1';
	public const TABLE_BASENAME        = 'fanxie_core_login_bans';

	private ?string $table = null;

	public function table_name(): string {
		if ( null === $this->table ) {
			global $wpdb;
			$prefix      = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
			$this->table = $prefix . self::TABLE_BASENAME;
		}
		return $this->table;
	}

	public function install(): void {
		global $wpdb;
		if ( self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_VERSION_OPTION, '' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $this->table_name();
		$cc    = $wpdb->get_charset_collate();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subject_type VARCHAR(16) NOT NULL,
			subject_value VARCHAR(180) NOT NULL,
			reason VARCHAR(191) DEFAULT NULL,
			expires_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subject (subject_type, subject_value)
		) {$cc};";
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta( $sql );
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	public function add( string $subject_type, string $subject_value, ?string $reason = null, ?int $ttl_minutes = null ): void {
		global $wpdb;
		$expires = null === $ttl_minutes ? null : gmdate( 'Y-m-d H:i:s', time() + ( $ttl_minutes * 60 ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is private.
				"INSERT INTO {$this->table_name()} (subject_type, subject_value, reason, expires_at, created_at)
				 VALUES (%s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)",
				substr( $subject_type, 0, 16 ),
				substr( $subject_value, 0, 180 ),
				null === $reason ? null : substr( $reason, 0, 191 ),
				$expires,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function remove( string $subject_type, string $subject_value ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $this->table_name(), [ 'subject_type' => $subject_type, 'subject_value' => $subject_value ], [ '%s', '%s' ] );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	public function is_banned( string $subject_type, string $subject_value ): bool {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is private.
				"SELECT id FROM {$table} WHERE subject_type = %s AND subject_value = %s AND ( expires_at IS NULL OR expires_at > %s ) LIMIT 1",
				$subject_type,
				$subject_value,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $id && '' !== $id;
	}

	/**
	 * @return array{rows: list<array<string, mixed>>, total: int}
	 */
	public function query( int $page = 1, int $per_page = 25 ): array {
		global $wpdb;
		$table    = $this->table_name();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, subject_type, subject_value, reason, expires_at, created_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return [ 'rows' => is_array( $rows ) ? $rows : [], 'total' => $total ];
	}

	public function prune_expired(): int {
		global $wpdb;
		$table = $this->table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at <= %s", gmdate( 'Y-m-d H:i:s' ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
```

- [ ] **Step 5: Wire activation** — in `src/Plugin.php::activate()`, after the `ViolationRepository` install (line 111):
```php
		( new \FanxieLab\WPCore\Modules\LoginProtection\LoginLogRepository() )->install();
		( new \FanxieLab\WPCore\Modules\LoginProtection\BanRepository() )->install();
```

- [ ] **Step 6: Run — verify pass** — the integration filter from Step 2 → PASS (both tests).

- [ ] **Step 7: Lint** — `composer phpcs && composer phpstan` → clean.

- [ ] **Step 8: Commit**
```bash
git add src/Modules/LoginProtection/LoginLogRepository.php src/Modules/LoginProtection/BanRepository.php src/Plugin.php tests/Integration/Modules/LoginProtection/RepositoriesTest.php
git commit -m "$(cat <<'EOF'
feat(login-protection): login_log + login_bans custom tables

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `IpResolver`

**Files:** Create `src/Modules/LoginProtection/IpResolver.php`; test `tests/Unit/Modules/LoginProtection/IpResolverTest.php`.

**Interfaces:** `IpResolver::__construct(bool $trust_proxy, string $proxy_header)`; `resolve(): string` — returns `REMOTE_ADDR` unless `trust_proxy`, then the left-most **valid public** IP from the configured `$_SERVER` header, falling back to `REMOTE_ADDR`. Returns `''` when nothing valid.

- [ ] **Step 1: Failing test**
```php
<?php
declare( strict_types=1 );
namespace FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection;

use FanxieLab\WPCore\Modules\LoginProtection\IpResolver;
use PHPUnit\Framework\TestCase;

final class IpResolverTest extends TestCase {
	protected function tearDown(): void { unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] ); parent::tearDown(); }

	public function test_default_uses_remote_addr_only(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1'; // must be ignored
		$this->assertSame( '198.51.100.7', ( new IpResolver( false, 'HTTP_X_FORWARDED_FOR' ) )->resolve() );
	}

	public function test_trusts_proxy_header_when_enabled(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.4, 10.0.0.5';
		$this->assertSame( '203.0.113.4', ( new IpResolver( true, 'HTTP_X_FORWARDED_FOR' )->resolve() ) );
	}

	public function test_rejects_invalid_or_private_forwarded_and_falls_back(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 10.0.0.1'; // invalid + private → fall back
		$this->assertSame( '198.51.100.9', ( new IpResolver( true, 'HTTP_X_FORWARDED_FOR' ) )->resolve() );
	}
}
```

- [ ] **Step 2: Run — fail.** `--filter IpResolverTest`.

- [ ] **Step 3: Implement**
```php
<?php
declare( strict_types=1 );
namespace FanxieLab\WPCore\Modules\LoginProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the client IP for rate-limiting. REMOTE_ADDR only unless a trusted
 * proxy header is explicitly configured — forwarded headers are attacker-
 * controlled and would otherwise allow lockout evasion or victim framing.
 */
final class IpResolver {

	public function __construct( private readonly bool $trust_proxy, private readonly string $proxy_header ) {}

	public function resolve(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated via FILTER_VALIDATE_IP below.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$remote = false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

		if ( ! $this->trust_proxy || '' === $this->proxy_header || ! isset( $_SERVER[ $this->proxy_header ] ) ) {
			return $remote;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated per-candidate below.
		$forwarded = (string) $_SERVER[ $this->proxy_header ];
		foreach ( explode( ',', $forwarded ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $candidate;
			}
		}
		return $remote;
	}
}
```

- [ ] **Step 4: Run — pass.** **Step 5: Lint.** **Step 6: Commit** (`feat(login-protection): client IP resolver (REMOTE_ADDR-only default)`).

---

## Task 4: `AttemptLimiter`

Failure tracking, tiered lockouts (transients), ban enforcement at `authenticate`, allowlist bypass, logging.

**Files:** Create `src/Modules/LoginProtection/Runtime/AttemptLimiter.php`; unit test `tests/Unit/Modules/LoginProtection/Runtime/AttemptLimiterTest.php`; integration `tests/Integration/Modules/LoginProtection/AttemptLimiterIntegrationTest.php`.

**Interfaces:** `__construct(array $config, IpResolver $ip, LoginLogRepository $log, BanRepository $bans)`. `register_hooks()` — when `attempts.enabled`: `add_filter('authenticate', [$this,'gate'], 30, 2)` (after WP's own auth), `add_action('wp_login_failed', [$this,'on_failed'])`, `add_action('wp_login', [$this,'on_success'], 10, 2)`. `gate($user, $username): mixed` returns a `WP_Error` when the IP or username is currently locked or banned (and logs `blocked_attempt`), else `$user`. `on_failed($username)` increments the IP+username counters, and on crossing a tier sets the lockout transient + logs `lockout`. Pure helper `tier_for(int $failures): ?array` (the tier whose `threshold` is the highest ≤ failures).

- [ ] **Step 1: Failing unit test** (pure tier logic + allowlist), Brain Monkey stubs for transients:
```php
<?php
declare( strict_types=1 );
namespace FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\LoginProtection\IpResolver;
use FanxieLab\WPCore\Modules\LoginProtection\Runtime\AttemptLimiter;
use Mockery;
use PHPUnit\Framework\TestCase;

final class AttemptLimiterTest extends TestCase {
	protected function setUp(): void { parent::setUp(); Monkey\setUp(); Functions\when( '__' )->returnArg( 1 ); }
	protected function tearDown(): void { Monkey\tearDown(); Mockery::close(); parent::tearDown(); }

	private function config(): array {
		return [
			'enabled' => true, 'trust_proxy' => false, 'proxy_header' => '', 'allowlist' => [ '198.51.100.50' ],
			'tiers' => [ [ 'threshold' => 3, 'lockout_minutes' => 15 ], [ 'threshold' => 6, 'lockout_minutes' => 60 ] ],
			'log_retention_days' => 30,
		];
	}

	public function test_tier_for_returns_highest_matching_tier(): void {
		$ip = Mockery::mock( IpResolver::class ); $ip->allows( 'resolve' )->andReturn( '203.0.113.1' );
		$limiter = new AttemptLimiter( $this->config(), $ip, Mockery::mock( 'LogStub' ), Mockery::mock( 'BanStub' ) );
		$this->assertNull( $limiter->tier_for( 2 ) );
		$this->assertSame( 15, $limiter->tier_for( 3 )['lockout_minutes'] );
		$this->assertSame( 15, $limiter->tier_for( 5 )['lockout_minutes'] );
		$this->assertSame( 60, $limiter->tier_for( 9 )['lockout_minutes'] );
	}

	public function test_allowlisted_ip_is_never_gated(): void {
		$ip = Mockery::mock( IpResolver::class ); $ip->allows( 'resolve' )->andReturn( '198.51.100.50' );
		$bans = Mockery::mock( 'BanStub' ); $bans->shouldNotReceive( 'is_banned' );
		Functions\when( 'get_transient' )->justReturn( false );
		$limiter = new AttemptLimiter( $this->config(), $ip, Mockery::mock( 'LogStub' ), $bans );
		$user = new \stdClass();
		$this->assertSame( $user, $limiter->gate( $user, 'admin' ) );
	}
}
```
> Use real `LoginLogRepository`/`BanRepository` type-hints; the `LogStub`/`BanStub` mocks above stand in for those in unit context — adjust the constructor hints to the real classes and `Mockery::mock( LoginLogRepository::class )` etc.

- [ ] **Step 2: Run — fail.**

- [ ] **Step 3: Implement `AttemptLimiter.php`**
```php
<?php
declare( strict_types=1 );
namespace FanxieLab\WPCore\Modules\LoginProtection\Runtime;

use FanxieLab\WPCore\Modules\LoginProtection\BanRepository;
use FanxieLab\WPCore\Modules\LoginProtection\IpResolver;
use FanxieLab\WPCore\Modules\LoginProtection\LoginLogRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Brute-force limiter: counts failures per IP + username in transients, applies
 * tiered lockouts, and blocks locked/banned subjects at `authenticate`.
 */
final class AttemptLimiter {

	private const PREFIX = 'fanxie_wp_core_lp_';

	/**
	 * @param array<string, mixed> $config `attempts` sub-config.
	 */
	public function __construct(
		private readonly array $config,
		private readonly IpResolver $ip,
		private readonly LoginLogRepository $log,
		private readonly BanRepository $bans,
	) {}

	public function register_hooks(): void {
		if ( empty( $this->config['enabled'] ) ) {
			return;
		}
		add_filter( 'authenticate', [ $this, 'gate' ], 30, 2 );
		add_action( 'wp_login_failed', [ $this, 'on_failed' ] );
		add_action( 'wp_login', [ $this, 'on_success' ], 10, 2 );
	}

	/**
	 * @param mixed $user     Auth result so far.
	 * @param mixed $username Attempted login.
	 * @return mixed WP_Error when blocked, else the incoming $user.
	 */
	public function gate( mixed $user, mixed $username ): mixed {
		$ip = $this->ip->resolve();
		if ( $this->is_allowlisted( $ip ) ) {
			return $user;
		}
		$username = is_string( $username ) ? $username : '';

		$locked = ( '' !== $ip && false !== get_transient( $this->lock_key( 'ip', $ip ) ) )
			|| ( '' !== $username && false !== get_transient( $this->lock_key( 'user', $username ) ) )
			|| ( '' !== $ip && $this->bans->is_banned( 'ip', $ip ) )
			|| ( '' !== $username && $this->bans->is_banned( 'username', $username ) );

		if ( ! $locked ) {
			return $user;
		}
		$this->log->record( 'blocked_attempt', $ip, $username, null, [] );
		return new WP_Error( 'fanxie_login_locked', __( 'Too many failed attempts. Try again later.', 'fanxie-wp-core' ) );
	}

	public function on_failed( mixed $username ): void {
		$ip       = $this->ip->resolve();
		$username = is_string( $username ) ? $username : '';
		if ( $this->is_allowlisted( $ip ) ) {
			return;
		}
		$this->log->record( 'failed_login', $ip, $username, null, [] );
		$this->bump( 'ip', $ip );
		$this->bump( 'user', $username );
	}

	public function on_success( mixed $user_login, mixed $user = null ): void {
		$ip = $this->ip->resolve();
		if ( '' !== $ip ) {
			delete_transient( $this->count_key( 'ip', $ip ) );
		}
		if ( is_string( $user_login ) && '' !== $user_login ) {
			delete_transient( $this->count_key( 'user', $user_login ) );
		}
	}

	/**
	 * @return array{threshold: int, lockout_minutes: int}|null
	 */
	public function tier_for( int $failures ): ?array {
		$match = null;
		foreach ( $this->tiers() as $tier ) {
			if ( $failures >= (int) $tier['threshold'] ) {
				$match = $tier;
			}
		}
		return $match;
	}

	private function bump( string $type, string $value ): void {
		if ( '' === $value ) {
			return;
		}
		$key   = $this->count_key( $type, $value );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, DAY_IN_SECONDS );
		$tier = $this->tier_for( $count );
		if ( null !== $tier ) {
			set_transient( $this->lock_key( $type, $value ), $count, (int) $tier['lockout_minutes'] * MINUTE_IN_SECONDS );
			$this->log->record( 'lockout', 'ip' === $type ? $value : '', 'user' === $type ? $value : '', null, [ 'tier' => $tier ] );
		}
	}

	private function is_allowlisted( string $ip ): bool {
		return '' !== $ip && in_array( $ip, (array) ( $this->config['allowlist'] ?? [] ), true );
	}

	/**
	 * @return array<int, array{threshold: int, lockout_minutes: int}>
	 */
	private function tiers(): array {
		$tiers = is_array( $this->config['tiers'] ?? null ) ? $this->config['tiers'] : [];
		return $tiers;
	}

	private function count_key( string $type, string $value ): string {
		return self::PREFIX . 'cnt_' . $type . '_' . md5( $value );
	}

	private function lock_key( string $type, string $value ): string {
		return self::PREFIX . 'lock_' . $type . '_' . md5( $value );
	}
}
```

- [ ] **Step 4: Run unit — pass.**

- [ ] **Step 5: Integration test** — real WP: register hooks with `enabled`, threshold 2 / short lockout; call `wp_authenticate` (or trigger `wp_login_failed`) N times → assert `authenticate` returns a `WP_Error` once locked and a `lockout` row was written; assert allowlisted IP never locks. Run under wp-env, verify pass.

- [ ] **Step 6: Lint. Step 7: Commit** (`feat(login-protection): tiered brute-force attempt limiter`).

---

## Task 5: `LoginSlugGuard` (hide wp-login)

**Files:** Create `src/Modules/LoginProtection/Runtime/LoginSlugGuard.php`; integration `tests/Integration/Modules/LoginProtection/LoginSlugGuardTest.php`; unit for slug resolution.

**Interfaces:** `__construct(array $config)`. `effective_slug(): string` — `FX_CORE_LOGIN_SLUG` constant (sanitized) wins over `hide_login.slug`. `is_active(): bool` — `hide_login.enabled` && `'' !== effective_slug()`. `register_hooks()` (when active): intercept on `plugins_loaded` (priority 1) to detect the request path; serve `wp-login.php` only at `/{slug}`; direct `wp-login.php` hits (not via slug, not an allowed `action`) → 404; `admin_init`: redirect unauthenticated `/wp-admin` to the slug; `wp_login_url`/`site_url`/`network_site_url` filters rewrite generated login URLs to the slug; `login_url` action variants (`logout`, `lostpassword`, `rp`, `resetpass`, `register`, `postpass`) preserved. Email the admin on slug change is handled in `AjaxController` (Task 8), not here.

- [ ] **Step 1: Unit test for slug resolution + activation** (constant override, reserved rejection). **Step 2: fail. Step 3: implement `effective_slug()`/`is_active()` + the routing hooks** (follow the well-known "hide login" technique: compare `$_SERVER['REQUEST_URI']` trailing segment against the slug on `plugins_loaded`; `require ABSPATH.'wp-login.php'` when matched; otherwise for a raw `wp-login.php` request without an allowed `action`, `status_header(404)` + load the theme 404 template and `exit`). **Step 4: pass.**

- [ ] **Step 5: Integration test** — set a slug, register hooks; assert: a request to the slug resolves the login form; a raw `wp-login.php` GET is 404'd; `?action=logout`/`lostpassword`/`rp` still resolve; `wp_login_url()` returns the slug URL; unauthenticated `/wp-admin` redirects to the slug; the `FX_CORE_LOGIN_SLUG` constant overrides the stored slug. **Step 6: lint. Step 7: commit** (`feat(login-protection): hide wp-login behind a custom slug`).

> Implementer note: this feature is the highest-risk. Keep `admin-ajax.php`, REST (`/wp-json`), and cron (`wp-cron.php`) reachable — only intercept the literal `wp-login.php` entry point and `/wp-admin` HTML requests. Add a defensive short-circuit if `defined('DOING_AJAX')`, `defined('DOING_CRON')`, or `REST_REQUEST`.

---

## Task 6: `PasswordPolicy`

**Files:** Create `src/Modules/LoginProtection/Runtime/PasswordPolicy.php`; unit `tests/Unit/Modules/LoginProtection/Runtime/PasswordPolicyTest.php`.

**Interfaces:** `__construct(array $config)`. `register_hooks()` (when `passwords.enforce`): `add_action('user_profile_update_errors', [$this,'validate_profile'], 10, 3)`, `add_filter('registration_errors', [$this,'validate_registration'], 10, 3)`, `add_action('validate_password_reset', [$this,'validate_reset'], 10, 2)`. Pure `check(string $password): list<string>` returns the list of unmet-requirement messages (empty = valid). Each hook pulls the candidate password from the request, runs `check()`, and adds a `WP_Error` per failure.

- [ ] **Step 1: Failing unit test** — `check()` for each rule + combinations (too short, no upper, no lower, no digit, no symbol, all-pass); config toggles disable individual rules. **Step 2: fail. Step 3: implement.** `check()`:
```php
public function check( string $password ): array {
	$errors = [];
	if ( strlen( $password ) < (int) $this->config['min_length'] ) {
		$errors[] = sprintf( /* translators: %d: minimum length */ __( 'Password must be at least %d characters.', 'fanxie-wp-core' ), (int) $this->config['min_length'] );
	}
	if ( ! empty( $this->config['require_mixed_case'] ) && ( ! preg_match( '/[a-z]/', $password ) || ! preg_match( '/[A-Z]/', $password ) ) ) {
		$errors[] = __( 'Password must include both uppercase and lowercase letters.', 'fanxie-wp-core' );
	}
	if ( ! empty( $this->config['require_number'] ) && ! preg_match( '/\d/', $password ) ) {
		$errors[] = __( 'Password must include at least one number.', 'fanxie-wp-core' );
	}
	if ( ! empty( $this->config['require_symbol'] ) && ! preg_match( '/[^A-Za-z0-9]/', $password ) ) {
		$errors[] = __( 'Password must include at least one symbol.', 'fanxie-wp-core' );
	}
	return $errors;
}
```
The three hook methods read the submitted password (`$_POST['pass1']` for profile/reset, unslashed + not sanitized since it's a raw secret — add the phpcs nonce/sanitize pragmas WP core uses) and append each `check()` message to the passed `WP_Error`/errors object. **Step 4: pass. Step 5 integration** (optional): a profile update with a weak password yields errors. **Step 6 lint. Step 7 commit** (`feat(login-protection): strong-password policy enforcement`).

---

## Task 7: `SessionTimeout`

**Files:** Create `src/Modules/LoginProtection/Runtime/SessionTimeout.php` + a tiny admin script `assets/admin/src/loginProtection/idle-logout.ts` **or** a plain `assets/login-protection/idle-logout.js` (no build) enqueued on wp-admin; unit `SessionTimeoutTest.php`.

**Interfaces:** `__construct(array $config)`. `register_hooks()` (when `sessions.enabled`): `add_filter('auth_cookie_expiration', [$this,'cookie_lifetime'], 10, 3)`; `add_action('admin_enqueue_scripts', [$this,'enqueue_idle_script'])`. `cookie_lifetime(int $length, int $user_id, bool $remember): int` — return the per-role minutes×60 for the user's primary role (fallback to `timeouts.default`), never exceeding the incoming `$length` only when a role match applies (shorten, don't extend). Idle script logs out via `wp_logout_url()` after the configured idle minutes with activity reset.

- [ ] **Step 1: Failing unit test** — `cookie_lifetime` returns 30×60 for an administrator, default for others (stub `get_userdata`/roles via Brain Monkey). **Step 2: fail. Step 3: implement.** For the idle script, ship a small dependency-free JS file under `assets/` (not the Vite SPA) and enqueue with `wp_enqueue_script` + `wp_localize_script` for the timeout + logout URL. **Step 4: pass. Step 5 integration** (`auth_cookie_expiration` per role). **Step 6 lint. Step 7 commit** (`feat(login-protection): role-based session timeout`).

---

## Task 8: `AjaxController` + retention cron

**Files:** Create `src/Modules/LoginProtection/AjaxController.php`; integration `tests/Integration/Modules/LoginProtection/AjaxControllerTest.php`.

**Interfaces:** `__construct(LoginProtection $module, LoginLogRepository $log, BanRepository $bans)`; `register(AjaxRouter $router)` registers sub-actions: `login_protection/get-config`, `save-config`, `get-log`, `add-ban`, `remove-ban`, `clear-lockout`. Each returns `array`/`WP_Error`. `save-config` validates the slug (rejects reserved/empty when hide-login enabled → field error), persists via `$module->update_config()`, emails the admin when the slug changed, and returns the fresh config + `slug_source` (`stored`|`constant`). Also schedule a daily `fanxie_wp_core_login_protection_prune` event (log `prune()` + bans `prune_expired()`).

- [ ] Steps mirror `Hardening/AjaxController` + its test: **failing test** (get-config returns shape; save-config persists + validates slug; add/remove-ban round-trips; nonce/cap enforced by the router — assert a handler returns a `WP_Error` on invalid slug) → **fail** → **implement** → **pass** → **lint** → **commit** (`feat(login-protection): admin AJAX surface + retention prune`).

---

## Task 9: WP-CLI — `wp fx-core login …`

**Files:** Create `src/Modules/LoginProtection/Cli/LoginCommand.php`; register in `LoginProtection::register_hooks()` under `if ( defined( 'WP_CLI' ) && WP_CLI )`; unit test the pure logic.

**Interfaces:** `WP_CLI::add_command( 'fx-core login', LoginCommand::class )`. Subcommands:
- `reveal` → prints the effective login slug (constant or stored) and its source.
- `unlock <subject>` → clears transient lockouts + removes bans for an IP or username; supports `--dry-run` (report what would be cleared without deleting).

- [ ] **Steps:** failing unit test for `unlock` logic (dry-run vs apply via injected `BanRepository`) → fail → implement (`reveal`/`unlock` methods calling `LoginSlugGuard::effective_slug()` and `BanRepository`) → pass → verify in env: `wp-env run cli wp fx-core login reveal` prints a slug → lint → commit (`feat(login-protection): wp fx-core login reveal/unlock CLI`).

---

## Task 10: Wire `register_hooks()`

Assemble all collaborators in `LoginProtection::register_hooks()`.

**Files:** Modify `src/Modules/LoginProtection/LoginProtection.php`; integration `tests/Integration/Modules/LoginProtection/BootTest.php`.

- [ ] **Step 1: Failing integration test** — boot the module with default config; assert the `authenticate` filter is attached (attempt limiter on by default) and the AJAX sub-actions are registered on the router; assert hide-login/password/session hooks are NOT attached at defaults (off). **Step 2: fail. Step 3: implement:**
```php
public function register_hooks(): void {
	$config = $this->get_config();
	$ip     = new IpResolver( (bool) $config['attempts']['trust_proxy'], (string) $config['attempts']['proxy_header'] );
	$log    = new LoginLogRepository();
	$bans   = new BanRepository();

	( new AjaxController( $this, $log, $bans ) )->register( $this->ajax_router );

	( new AttemptLimiter( $config['attempts'], $ip, $log, $bans ) )->register_hooks();
	( new LoginSlugGuard( $config['hide_login'] ) )->register_hooks();
	( new PasswordPolicy( $config['passwords'] ) )->register_hooks();
	( new SessionTimeout( $config['sessions'] ) )->register_hooks();

	add_action( 'fanxie_wp_core_login_protection_prune', function () use ( $log, $bans, $config ): void {
		$log->prune( (int) $config['attempts']['log_retention_days'] );
		$bans->prune_expired();
	} );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'fx-core login', new Cli\LoginCommand( $config, $log, $bans ) );
	}
}
```
(Add the corresponding `use` imports.) **Step 4: pass. Step 5 lint. Step 6 commit** (`feat(login-protection): wire runtime collaborators`).

---

## Task 11: Vue tab — scaffold (route, store, types, root view)

**Files:** Create `assets/admin/src/modules/LoginProtection/{LoginProtection.vue,types.ts,stores/loginProtection.ts}`; modify `assets/admin/src/router/index.ts` (`liveModuleRoutes['login-protection']`). Tests: store spec.

**Interfaces:** TS `LoginProtectionConfig`, `LoginLogRow`, `BanRow` mirroring the PHP shapes (Task 1 config + Task 2 rows). Pinia store `useLoginProtectionStore` with `load()`, `save()`, `fetchLog(page, filters)`, `addBan()`, `removeBan()`, `clearLockout()` over the AJAX client (sub-actions `login_protection/*`). Route: single top-level view (no sub-tabs), following `Hardening`'s `liveModuleRoutes` entry.

- [ ] **Steps:** add the route (mirror the `hardening` entry in `router/index.ts`); write the TS types; write the store (mirror `Hardening/stores/hardening.ts`) with a failing store spec (load/save round-trip via MSW/mocked ajaxClient) → fail → implement → pass; scaffold `LoginProtection.vue` root (icon header `KeyRound`, `StatusPill`, section shells + `SaveBar`) mirroring `Hardening.vue`. `npm run check` → commit (`feat(login-protection): admin tab scaffold — route, store, types`).

---

## Task 12: Vue tab — attempt limiting + lockout log

**Files:** `LoginProtection.vue` (attempt section), new `components/LockoutLog.vue`; component test.

- [ ] Build the **attempt-limiting** section (enable `Toggle` with `describedby`→`HelpText`, tier inputs, allowlist `textarea`, trust-proxy toggle + header field, `Tooltip`s) and the **`LockoutLog`** table (paginated, filters by event/ip/username/date, per-row **Ban**/**Unban**, manual add-ban). Follow `ViolationsView.vue` for the table/pagination pattern and `Hardening.vue` for section styling. Failing component test (renders rows from a fixture; emits ban/unban) → implement → `npm run check` → commit (`feat(login-protection): attempt-limiting UI + lockout log`).

---

## Task 13: Vue tab — hide-login, passwords, sessions

**Files:** `LoginProtection.vue` (three more sections); component test.

- [ ] **Hide-login:** enable toggle + slug `TextField` with a confirmation modal, current-slug + recovery note (`FX_CORE_LOGIN_SLUG`, `wp fx-core login reveal`), read-only when `slug_source === 'constant'`. **Passwords:** policy builder (min-length number + rule toggles) with a live requirement-message preview. **Sessions:** per-role timeout inputs. All using the help standard. Failing test (slug field disabled when constant-sourced; password preview reflects toggles) → implement → `npm run check` → commit (`feat(login-protection): hide-login, password, session UI`).

---

## Task 14: Docs

**Files:** `CLAUDE.md`, `docs/hooks.md`, `CHANGELOG.md`, `_PRD/checklist-fanxie-wp-core.md`, `_PRD/prd-fanxie-wp-core-v0.5.md`.

- [ ] **CLAUDE.md §3.1:** record the naming convention (user-facing constants `FX_CORE_*`; WP-CLI root `fx-core`) and the two new custom tables (`fanxie_core_login_log`, `fanxie_core_login_bans`).
- [ ] **docs/hooks.md:** document `fanxie_wp_core/login_protection/*` hooks + the `login_protection/*` AJAX sub-actions.
- [ ] **checklist:** tick the Phase 1.3 items delivered here; add a 1.3b line for 2FA.
- [ ] **PRD §5:** note (per CLAUDE.md §7) that 2FA is pulled forward to 1.3b (enforce on WP's Two-Factor plugin) rather than v2, and the `FX_CORE_*`/`fx-core` naming — coordinating with the user's in-flight PRD edits (do not clobber them).
- [ ] **CHANGELOG:** Unreleased entry for the module. Commit (`docs(login-protection): naming convention, hooks, changelog, checklist`).

---

## Final verification (whole branch)

- [ ] `composer test:unit` · `composer phpcs` · `composer phpstan` all green.
- [ ] `npm run env:start` then run each new integration file individually (the combined run is flaky — issue #1) and confirm 0 failures.
- [ ] `cd assets/admin && npm run check && npm run build` green.
- [ ] `npm run plugin:check` — no new errors.
- [ ] Manual smoke: enable attempt limiting, force lockouts, see the log + ban/unban; set a login slug (confirm `/wp-login.php`→404, slug works, `wp fx-core login reveal` prints it); enable a password rule and try a weak password; set a short admin session timeout.
- [ ] Open the PR only when the user asks.

## Self-review notes (addressed inline)

- **Spec coverage:** Storage (Task 2) · attempt limiting (4) · hide-login (5) · passwords (6) · sessions (7) · admin AJAX + retention (8) · CLI (9) · wiring (10) · Vue tab (11–13) · docs incl. naming/divergences (14). IP posture → Task 3 + config. 2FA correctly absent (1.3b).
- **Types:** `LoginProtectionConfig`/`LoginLogRow`/`BanRow` shapes are defined once (Task 1 config + Task 2 tables) and mirrored in TS (Task 11); the AJAX sub-action names are identical across `AjaxController` (8) and the store (11): `login_protection/get-config|save-config|get-log|add-ban|remove-ban|clear-lockout`.
- **Naming:** `FX_CORE_LOGIN_SLUG` + `wp fx-core login` used consistently in Tasks 5, 9, 13, 14.
