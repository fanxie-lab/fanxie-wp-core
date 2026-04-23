<?php
/**
 * Integration tests for UploadsProtector against the real filesystem.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\Hardening;

use FanxieLab\WPCore\Modules\Hardening\UploadsProtector;
use WP_UnitTestCase;

/**
 * Round-trips the writes against the real uploads dir exposed by WP.
 */
final class UploadsProtectorIntegrationTest extends WP_UnitTestCase {

	private string $uploads_basedir = '';

	protected function set_up(): void {
		parent::set_up();

		$info                  = wp_upload_dir( null, false );
		$this->uploads_basedir = (string) $info['basedir'];

		// Start clean.
		foreach ( [ 'index.php', '.htaccess' ] as $file ) {
			$path = $this->uploads_basedir . '/' . $file;
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
	}

	protected function tear_down(): void {
		foreach ( [ 'index.php', '.htaccess' ] as $file ) {
			$path = $this->uploads_basedir . '/' . $file;
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		unset( $_SERVER['SERVER_SOFTWARE'] );
		parent::tear_down();
	}

	public function test_ensure_protection_writes_files_on_apache(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';

		$protector = new UploadsProtector(
			[
				'uploads' => [
					'drop_index'          => true,
					'block_php_execution' => true,
				],
			]
		);

		$protector->ensure_protection();

		$this->assertFileExists( $this->uploads_basedir . '/index.php' );
		$this->assertFileExists( $this->uploads_basedir . '/.htaccess' );
		$this->assertTrue( $protector->index_exists() );
		$this->assertTrue( $protector->htaccess_exists() );
	}

	public function test_apply_fix_writes_only_requested_target(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';

		$protector = new UploadsProtector();

		$protector->apply_fix( 'uploads_index' );

		$this->assertFileExists( $this->uploads_basedir . '/index.php' );
		$this->assertFileDoesNotExist( $this->uploads_basedir . '/.htaccess' );
	}

	public function test_ensure_protection_returns_shape_for_status_surface(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';

		$protector = new UploadsProtector(
			[ 'uploads' => [ 'drop_index' => true, 'block_php_execution' => true ] ]
		);

		$result = $protector->ensure_protection();

		$this->assertArrayHasKey( 'uploads_index', $result );
		$this->assertArrayHasKey( 'uploads_htaccess', $result );
		$this->assertTrue( $result['uploads_index'] );
		$this->assertTrue( $result['uploads_htaccess'] );
	}
}
