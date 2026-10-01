<?php
/**
 * Unit tests for UploadsProtector.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\Hardening;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\Hardening\UploadsProtector;
use PHPUnit\Framework\TestCase;

/**
 * Covers file-writing paths against a temp directory.
 */
final class UploadsProtectorTest extends TestCase {

	private string $uploads_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'trailingslashit' )->alias( static fn ( $v ) => rtrim( (string) $v, '/' ) . '/' );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'wp_unslash' )->alias( static fn ( $v ) => is_string( $v ) ? stripslashes( $v ) : $v );

		$this->uploads_dir = sys_get_temp_dir() . '/fanxie-uploads-' . uniqid( '', true );
		mkdir( $this->uploads_dir, 0777, true );

		Functions\when( 'wp_upload_dir' )->alias(
			fn () => [
				'basedir' => $this->uploads_dir,
				'baseurl' => 'https://example.test/wp-content/uploads',
			]
		);
		Filters\expectApplied( 'fanxie_warden/hardening/uploads_dir' )
			->zeroOrMoreTimes()
			->andReturnFirstArg();
	}

	protected function tearDown(): void {
		// Clean up any files we wrote.
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

	public function test_ensure_protection_drops_index_and_htaccess_on_apache(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4 (Ubuntu)';

		$protector = new UploadsProtector(
			[
				'uploads' => [
					'drop_index'          => true,
					'block_php_execution' => true,
				],
			]
		);

		$result = $protector->ensure_protection();

		$this->assertTrue( $result['uploads_index'] );
		$this->assertTrue( $result['uploads_htaccess'] );
		$this->assertFileExists( $this->uploads_dir . '/index.php' );
		$this->assertFileExists( $this->uploads_dir . '/.htaccess' );
	}

	public function test_ensure_protection_skips_htaccess_on_nginx(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

		$protector = new UploadsProtector(
			[
				'uploads' => [
					'drop_index'          => true,
					'block_php_execution' => true,
				],
			]
		);

		$protector->ensure_protection();

		$this->assertFileExists( $this->uploads_dir . '/index.php' );
		$this->assertFileDoesNotExist( $this->uploads_dir . '/.htaccess' );
	}

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

	public function test_ensure_protection_is_idempotent(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache';

		$protector = new UploadsProtector(
			[ 'uploads' => [ 'drop_index' => true, 'block_php_execution' => true ] ]
		);

		$first  = $protector->ensure_protection();
		$second = $protector->ensure_protection();

		$this->assertSame( $first, $second );
	}

	public function test_ensure_protection_respects_disabled_toggles(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache';

		$protector = new UploadsProtector(
			[ 'uploads' => [ 'drop_index' => false, 'block_php_execution' => false ] ]
		);

		$protector->ensure_protection();

		$this->assertFileDoesNotExist( $this->uploads_dir . '/index.php' );
		$this->assertFileDoesNotExist( $this->uploads_dir . '/.htaccess' );
	}

	public function test_detect_server_type_matches_known_strings(): void {
		$protector = new UploadsProtector();

		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
		$this->assertSame( 'apache', $protector->detect_server_type() );

		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
		$this->assertSame( 'nginx', $protector->detect_server_type() );

		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		$this->assertSame( 'litespeed', $protector->detect_server_type() );

		$_SERVER['SERVER_SOFTWARE'] = 'Microsoft-IIS/10';
		$this->assertSame( 'iis', $protector->detect_server_type() );

		unset( $_SERVER['SERVER_SOFTWARE'] );
		$this->assertSame( 'unknown', $protector->detect_server_type() );
	}

	public function test_apply_fix_writes_requested_target(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache';

		$protector = new UploadsProtector();

		$this->assertTrue( $protector->apply_fix( 'uploads_index' ) );
		$this->assertFileExists( $this->uploads_dir . '/index.php' );

		$this->assertTrue( $protector->apply_fix( 'uploads_htaccess' ) );
		$this->assertFileExists( $this->uploads_dir . '/.htaccess' );

		$this->assertFalse( $protector->apply_fix( 'bogus_target' ) );
	}

	public function test_apply_fix_skips_htaccess_on_non_apache_server(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

		$protector = new UploadsProtector();

		// `.htaccess` must NOT be written where the server won't honour it —
		// otherwise htaccess_exists() would report a no-op file as "protected".
		// This mirrors ensure_protection(), which also gates on is_apache_compatible().
		$this->assertFalse( $protector->apply_fix( 'uploads_htaccess' ) );
		$this->assertFileDoesNotExist( $this->uploads_dir . '/.htaccess' );

		// `index.php` is server-agnostic (blocks directory listing everywhere),
		// so it still drops on nginx.
		$this->assertTrue( $protector->apply_fix( 'uploads_index' ) );
		$this->assertFileExists( $this->uploads_dir . '/index.php' );
	}

	public function test_nginx_snippet_returns_deterministic_string(): void {
		$protector = new UploadsProtector();
		$snippet   = $protector->nginx_snippet();

		$this->assertStringContainsString( 'location ~*', $snippet );
		$this->assertStringContainsString( 'deny all', $snippet );
	}

	public function test_remove_fix_deletes_requested_file(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache';
		$protector                  = new UploadsProtector();
		$protector->apply_fix( 'uploads_index' );
		$this->assertFileExists( $this->uploads_dir . '/index.php' );

		$this->assertTrue( $protector->remove_fix( 'uploads_index' ) );
		$this->assertFileDoesNotExist( $this->uploads_dir . '/index.php' );
	}

	public function test_remove_fix_succeeds_when_file_already_absent(): void {
		$protector = new UploadsProtector();

		$this->assertTrue( $protector->remove_fix( 'uploads_htaccess' ) );
	}

	public function test_remove_fix_rejects_unknown_target(): void {
		$protector = new UploadsProtector();

		$this->assertFalse( $protector->remove_fix( 'bogus_target' ) );
	}

	public function test_probe_php_execution_returns_false_when_server_blocks(): void {
		Functions\when( 'wp_generate_password' )->alias( static fn ( int $l = 12 ) => substr( str_repeat( 'x', $l ), 0, $l ) );
		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 403 ], 'body' => '' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $r ) => is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '' );

		$protector = new UploadsProtector();
		$this->assertFalse( $protector->probe_php_execution() );
	}

	public function test_probe_php_execution_returns_true_when_marker_echoes(): void {
		Functions\when( 'wp_generate_password' )->alias( static fn ( int $l = 12 ) => substr( str_repeat( 'x', $l ), 0, $l ) );
		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 200 ], 'body' => 'CANARY_HIT' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $r ) => is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '' );

		$protector = new UploadsProtector();
		$this->assertTrue( $protector->probe_php_execution() );
	}

	public function test_probe_php_execution_returns_false_on_wp_error(): void {
		// Previously returned null on WP_Error; now returns false (optimistic).
		Functions\when( 'wp_generate_password' )->alias( static fn ( int $l = 12 ) => substr( str_repeat( 'x', $l ), 0, $l ) );
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'timeout', 'nope' ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 0 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$protector = new UploadsProtector();
		$this->assertFalse( $protector->probe_php_execution() );
	}

	public function test_index_and_htaccess_existence_probes(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache';
		$protector                  = new UploadsProtector();

		$this->assertFalse( $protector->index_exists() );
		$this->assertFalse( $protector->htaccess_exists() );

		touch( $this->uploads_dir . '/index.php' );
		touch( $this->uploads_dir . '/.htaccess' );

		$this->assertTrue( $protector->index_exists() );
		$this->assertTrue( $protector->htaccess_exists() );
	}
}
