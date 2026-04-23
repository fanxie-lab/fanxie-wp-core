<?php
/**
 * Integration tests for the readme/license 404 behaviour.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\Hardening;

use FanxieLab\WPCore\Modules\Hardening\Runtime\RootHtaccessWriter;
use FanxieLab\WPCore\Modules\Hardening\Runtime\VersionHider;
use WP_UnitTestCase;

/**
 * Verifies the runtime fallback for /readme.html + /license.txt (hooked on
 * `init` priority 1) and the root `.htaccess` writer round-trip.
 */
final class ReadmeLicense404Test extends WP_UnitTestCase {

	protected function set_up(): void {
		parent::set_up();

		$hider = new VersionHider(
			[
				'version_hiding' => [
					'remove_powered_by'    => true,
					'remove_wp_generator'  => true,
					'remove_rss_generator' => true,
					'strip_version_query'  => true,
					'block_readme_license' => true,
				],
			]
		);
		$hider->register_hooks();
	}

	public function test_readme_request_results_in_404_status(): void {
		$_SERVER['REQUEST_URI'] = '/readme.html';

		// The hander calls wp_die() — intercept the default handler so the test
		// process survives.
		$handler = static function ( $m, $t, $args ) {
			throw new \RuntimeException( 'wp_die: ' . (int) ( $args['response'] ?? 0 ) );
		};
		add_filter( 'wp_die_handler', static fn () => $handler, 100 );

		try {
			$hider = new VersionHider(
				[ 'version_hiding' => [ 'block_readme_license' => true ] ]
			);
			$hider->maybe_block_readme_license();
			$this->fail( 'Expected wp_die() to be called.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '404', $e->getMessage() );
		} finally {
			unset( $_SERVER['REQUEST_URI'] );
		}
	}

	public function test_unrelated_request_is_not_blocked(): void {
		$_SERVER['REQUEST_URI'] = '/about/';

		$hider = new VersionHider(
			[ 'version_hiding' => [ 'block_readme_license' => true ] ]
		);

		// Should return without calling wp_die().
		$hider->maybe_block_readme_license();
		$this->addToAssertionCount( 1 );

		unset( $_SERVER['REQUEST_URI'] );
	}

	public function test_strip_version_query_end_to_end(): void {
		$hider = new VersionHider( [] );

		$this->assertSame(
			'https://example.test/style.css',
			$hider->strip_version_query( 'https://example.test/style.css?ver=1' )
		);
	}

	public function test_ensure_readme_license_block_writes_root_htaccess_snippet(): void {
		$tmp    = sys_get_temp_dir() . '/fanxie-rt-htaccess-' . uniqid( '', true );
		$writer = new RootHtaccessWriter( $tmp );

		try {
			$hider = new VersionHider(
				[ 'version_hiding' => [ 'block_readme_license' => true ] ],
				$writer
			);

			$this->assertTrue( $hider->ensure_readme_license_block() );
			$this->assertFileExists( $tmp );

			$contents = (string) file_get_contents( $tmp );
			$this->assertStringContainsString( '# BEGIN Fanxie WP Core', $contents );
			$this->assertStringContainsString( 'readme.html', $contents );
			$this->assertStringContainsString( 'license.txt', $contents );
		} finally {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}
}
