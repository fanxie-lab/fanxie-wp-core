<?php
/**
 * Unit tests for the Hardening AjaxController.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\Hardening;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\Hardening\AjaxController;
use FanxieLab\Warden\Modules\Hardening\Hardening;
use FanxieLab\Warden\Modules\Hardening\Runtime\RootHtaccessWriter;
use FanxieLab\Warden\Modules\Hardening\StatusInspector;
use FanxieLab\Warden\Modules\Hardening\UploadsProtector;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Covers the four AJAX handlers.
 */
final class AjaxControllerTest extends TestCase {

	/**
	 * In-memory option storage used by the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $options_store = [];

	/**
	 * In-memory transient storage used by the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	/**
	 * Uploads temp dir so we can write / delete protection files.
	 */
	private string $uploads_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'wp_unslash' )->alias( static fn ( $v ) => is_string( $v ) ? stripslashes( $v ) : $v );
		Functions\when( 'sanitize_textarea_field' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $v ) {
				if ( ! is_string( $v ) ) {
					return '';
				}
				return (string) ( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ) ?? '' );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) abs( (int) $v ) );
		Functions\when( 'trailingslashit' )->alias( static fn ( $v ) => rtrim( (string) $v, '/' ) . '/' );

		$this->options_store = [];
		$this->transients    = [];
		Functions\when( 'get_option' )->alias(
			fn ( $key, $default_value = false ) => array_key_exists( $key, $this->options_store ) ? $this->options_store[ $key ] : $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options_store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn ( $key ) => array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);

		$this->uploads_dir = sys_get_temp_dir() . '/fanxie-ajax-' . uniqid( '', true );
		mkdir( $this->uploads_dir, 0777, true );

		Functions\when( 'wp_upload_dir' )->alias(
			fn () => [
				'basedir' => $this->uploads_dir,
				'baseurl' => 'https://example.test/wp-content/uploads',
			]
		);
		Functions\when( 'home_url' )->alias( static fn ( $path = '/' ) => 'https://example.test' . (string) $path );
		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 404 ], 'body' => '', 'headers' => [] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $r ) => is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '' );
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static fn ( $r ) => is_array( $r ) && isset( $r['headers'] ) && is_array( $r['headers'] ) ? $r['headers'] : []
		);
		Functions\when( 'wp_generate_password' )->alias( static fn ( int $l = 12 ) => substr( str_repeat( 'x', $l ), 0, $l ) );
		// We never exercise the application-passwords count path in these
		// tests (no `WP_Application_Passwords` class is loaded), so the
		// `maybe_unserialize` stub just returns the input unchanged.
		Functions\when( 'maybe_unserialize' )->returnArg( 1 );

		Filters\expectApplied( 'fanxie_warden/hardening/uploads_dir' )
			->zeroOrMoreTimes()
			->andReturnFirstArg();

		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
	}

	protected function tearDown(): void {
		if ( '' !== $this->htaccess_path && file_exists( $this->htaccess_path ) ) {
			unlink( $this->htaccess_path );
		}
		if ( is_dir( $this->uploads_dir ) ) {
			foreach ( glob( $this->uploads_dir . '/*' ) ?: [] as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			foreach ( glob( $this->uploads_dir . '/.*' ) ?: [] as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			rmdir( $this->uploads_dir );
		}
		unset( $_SERVER['SERVER_SOFTWARE'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Path to the sandboxed `.htaccess` used for RootHtaccessWriter round-trips.
	 */
	private string $htaccess_path = '';

	/**
	 * @return array{0:Hardening,1:AjaxController}
	 */
	private function make_controller(): array {
		$module              = new Hardening( new AjaxRouter() );
		$uploads             = $module->uploads_protector();
		$inspector           = $module->status_inspector();
		$this->htaccess_path = $this->uploads_dir . '/.test-htaccess';
		$writer              = new RootHtaccessWriter( $this->htaccess_path );
		$controller          = new AjaxController( $module, $inspector, $uploads, $writer );
		return [ $module, $controller ];
	}

	public function test_get_config_returns_full_envelope(): void {
		[ , $controller ] = $this->make_controller();

		$result = $controller->handle_get_config();

		$this->assertArrayHasKey( 'settings', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'checks', $result );
		$this->assertArrayHasKey( 'active', $result['status'] );
		$this->assertArrayHasKey( 'summary', $result['status'] );
		$this->assertArrayHasKey( 'warnings', $result['status'] );
	}

	public function test_save_config_requires_settings_array(): void {
		[ , $controller ] = $this->make_controller();

		$err = $controller->handle_save_config( [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_settings', $err->get_error_code() );

		$err = $controller->handle_save_config( [ 'settings' => 'nope' ] );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_save_config_persists_and_invalidates_cache(): void {
		[ , $controller ] = $this->make_controller();

		// Seed the transient to prove invalidation happens.
		$this->transients[ StatusInspector::CACHE_KEY ] = [ 'stale' => true ];

		$result = $controller->handle_save_config(
			[
				'settings' => [
					'uploads' => [ 'drop_index' => false ],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['settings']['uploads']['drop_index'] );
		// After invalidation + re-probe, the transient should exist again but
		// with a fresh shape (not the stale marker we planted).
		$this->assertArrayNotHasKey( 'stale', (array) ( $this->transients[ StatusInspector::CACHE_KEY ] ?? [] ) );
	}

	public function test_run_checks_bypasses_cache(): void {
		[ , $controller ] = $this->make_controller();

		$controller->handle_get_config(); // prime the cache.
		$this->transients[ StatusInspector::CACHE_KEY ]['server_type'] = 'STALE';

		$result = $controller->handle_run_checks();
		$this->assertNotSame( 'STALE', $result['checks']['server_type'] );
	}

	public function test_apply_fix_writes_uploads_index(): void {
		[ , $controller ] = $this->make_controller();

		$result = $controller->handle_apply_fix( [ 'target' => 'uploads_index' ] );

		$this->assertIsArray( $result );
		$this->assertFileExists( $this->uploads_dir . '/index.php' );
	}

	public function test_apply_fix_requires_target(): void {
		[ , $controller ] = $this->make_controller();

		$err = $controller->handle_apply_fix( [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'missing_target', $err->get_error_code() );
	}

	public function test_apply_fix_rejects_unknown_target(): void {
		[ , $controller ] = $this->make_controller();

		$err = $controller->handle_apply_fix( [ 'target' => 'evil_payload' ] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_target', $err->get_error_code() );
	}

	public function test_apply_fix_writes_readme_license_block(): void {
		[ , $controller ] = $this->make_controller();

		$result = $controller->handle_apply_fix( [ 'target' => 'readme_license_block' ] );

		$this->assertIsArray( $result );
		$this->assertFileExists( $this->htaccess_path );
		$contents = (string) file_get_contents( $this->htaccess_path );
		$this->assertStringContainsString( '# BEGIN Fanxie Warden', $contents );
		$this->assertStringContainsString( 'readme.html', $contents );
		$this->assertStringContainsString( 'license.txt', $contents );
	}

	public function test_save_config_installs_readme_license_block_when_enabled(): void {
		[ , $controller ] = $this->make_controller();

		$controller->handle_save_config(
			[
				'settings' => [
					'version_hiding' => [ 'block_readme_license' => true ],
				],
			]
		);

		$this->assertFileExists( $this->htaccess_path );
		$this->assertStringContainsString(
			'# BEGIN Fanxie Warden',
			(string) file_get_contents( $this->htaccess_path )
		);
	}

	public function test_save_config_removes_readme_license_block_when_disabled(): void {
		[ , $controller ] = $this->make_controller();

		// Prime the block so we can verify it's stripped.
		file_put_contents(
			$this->htaccess_path,
			"# BEGIN Fanxie Warden\n<Files \"readme.html\">\n</Files>\n# END Fanxie Warden\n"
		);

		$controller->handle_save_config(
			[
				'settings' => [
					'version_hiding' => [ 'block_readme_license' => false ],
				],
			]
		);

		if ( file_exists( $this->htaccess_path ) ) {
			$this->assertStringNotContainsString(
				'# BEGIN Fanxie Warden',
				(string) file_get_contents( $this->htaccess_path )
			);
		}
	}

	public function test_drop_upload_guard_writes_requested_target(): void {
		[ , $controller ] = $this->make_controller();

		$result = $controller->handle_drop_upload_guard( [ 'target' => 'uploads_index' ] );

		$this->assertIsArray( $result );
		$this->assertFileExists( $this->uploads_dir . '/index.php' );
	}

	public function test_drop_upload_guard_rejects_bad_target(): void {
		[ , $controller ] = $this->make_controller();

		$err = $controller->handle_drop_upload_guard( [ 'target' => 'foo' ] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_target', $err->get_error_code() );
	}

	public function test_drop_upload_guard_rejects_missing_target(): void {
		[ , $controller ] = $this->make_controller();

		$err = $controller->handle_drop_upload_guard( [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'missing_target', $err->get_error_code() );
	}

	public function test_remove_upload_guard_deletes_file(): void {
		[ , $controller ] = $this->make_controller();

		file_put_contents( $this->uploads_dir . '/index.php', '<?php // seeded' );
		$this->assertFileExists( $this->uploads_dir . '/index.php' );

		$controller->handle_remove_upload_guard( [ 'target' => 'uploads_index' ] );

		$this->assertFileDoesNotExist( $this->uploads_dir . '/index.php' );
	}

	public function test_envelope_includes_new_readme_license_keys(): void {
		[ , $controller ] = $this->make_controller();

		$envelope = $controller->handle_get_config();

		$this->assertArrayHasKey( 'readme_blocked', $envelope['checks'] );
		$this->assertArrayHasKey( 'license_blocked', $envelope['checks'] );
	}

	public function test_status_summary_reports_inactive_when_all_off(): void {
		[ $module, $controller ] = $this->make_controller();

		$module->update_config(
			[
				'user_enumeration'      => [
					'block_author_archive'      => false,
					'block_rest_users_endpoint' => false,
				],
				'xmlrpc'                => [ 'mode' => 'off' ],
				'version_hiding'        => [
					'remove_powered_by'    => false,
					'remove_wp_generator'  => false,
					'remove_rss_generator' => false,
					'strip_version_query'  => false,
					'block_readme_license' => false,
				],
				'uploads'               => [
					'drop_index'          => false,
					'block_php_execution' => false,
				],
				'login'                 => [ 'obfuscate_errors' => false ],
				'file_editing'          => [ 'runtime_enforce' => false ],
				'application_passwords' => [ 'disable' => false ],
			]
		);

		$status = $controller->handle_get_config()['status'];
		$this->assertFalse( $status['active'] );
		$this->assertSame( 'Inactive', $status['summary'] );
	}

	public function test_status_summary_counts_active_toggles(): void {
		[ , $controller ] = $this->make_controller();

		// Defaults: 11 booleans + xmlrpc mode ≠ off = 12 safeguards.
		$status = $controller->handle_get_config()['status'];

		$this->assertTrue( $status['active'] );
		$this->assertStringContainsString( 'safeguards active', $status['summary'] );
	}
}
