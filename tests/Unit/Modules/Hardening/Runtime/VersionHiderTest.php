<?php
/**
 * Unit tests for VersionHider.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\Hardening\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\Hardening\Runtime\RootHtaccessWriter;
use FanxieLab\Warden\Modules\Hardening\Runtime\VersionHider;
use PHPUnit\Framework\TestCase;

/**
 * Covers asset URL rewriting + generator emptying.
 */
final class VersionHiderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'remove_query_arg' )->alias(
			static function ( $key, $url ) {
				$parsed = parse_url( (string) $url );
				if ( ! isset( $parsed['query'] ) ) {
					return (string) $url;
				}
				parse_str( (string) $parsed['query'], $q );
				unset( $q[ $key ] );
				$parsed['query'] = http_build_query( $q );
				$rebuilt         = ( $parsed['scheme'] ?? '' ) . '://' . ( $parsed['host'] ?? '' ) . ( $parsed['path'] ?? '' );
				if ( '' !== ( $parsed['query'] ?? '' ) ) {
					$rebuilt .= '?' . $parsed['query'];
				}
				return $rebuilt;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_generator_returns_empty_string(): void {
		$hider = new VersionHider( [] );
		$this->assertSame( '', $hider->empty_generator() );
	}

	public function test_strip_version_query_removes_ver_parameter(): void {
		$hider = new VersionHider( [] );

		$this->assertSame(
			'https://example.test/style.css',
			$hider->strip_version_query( 'https://example.test/style.css?ver=5.8' )
		);
	}

	public function test_strip_version_query_preserves_other_query_params(): void {
		$hider = new VersionHider( [] );

		$result = $hider->strip_version_query( 'https://example.test/style.css?v2=1&ver=5.8' );
		$this->assertStringContainsString( 'v2=1', (string) $result );
		$this->assertStringNotContainsString( 'ver=5.8', (string) $result );
	}

	public function test_strip_version_query_returns_empty_for_non_string(): void {
		$hider = new VersionHider( [] );
		$this->assertSame( '', $hider->strip_version_query( 42 ) );
	}

	public function test_strip_version_query_is_noop_when_no_ver_present(): void {
		$hider = new VersionHider( [] );
		$this->assertSame(
			'https://example.test/style.css',
			$hider->strip_version_query( 'https://example.test/style.css' )
		);
	}

	public function test_ensure_readme_license_block_writes_markers(): void {
		$tmp    = sys_get_temp_dir() . '/fanxie-htaccess-' . uniqid( '', true );
		$writer = new RootHtaccessWriter( $tmp );

		try {
			$hider = new VersionHider(
				[ 'version_hiding' => [ 'block_readme_license' => true ] ],
				$writer
			);

			$this->assertTrue( $hider->ensure_readme_license_block() );
			$this->assertTrue( $writer->block_present() );

			$contents = (string) file_get_contents( $tmp );
			$this->assertStringContainsString( 'readme.html', $contents );
			$this->assertStringContainsString( 'license.txt', $contents );
		} finally {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}

	public function test_ensure_readme_license_block_removes_block_when_toggle_off(): void {
		$tmp = sys_get_temp_dir() . '/fanxie-htaccess-' . uniqid( '', true );
		file_put_contents( $tmp, "# BEGIN Fanxie Warden\nrule\n# END Fanxie Warden\n" );

		try {
			$writer = new RootHtaccessWriter( $tmp );
			$hider  = new VersionHider(
				[ 'version_hiding' => [ 'block_readme_license' => false ] ],
				$writer
			);

			$this->assertTrue( $hider->ensure_readme_license_block() );
			$this->assertFalse( $writer->block_present() );
		} finally {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}

	public function test_maybe_block_readme_license_short_circuits_on_readme(): void {
		$_SERVER['REQUEST_URI'] = '/readme.html';

		Functions\when( 'wp_parse_url' )->alias( static fn ( $url ) => parse_url( (string) $url, PHP_URL_PATH ) );
		Functions\when( 'status_header' )->justReturn( true );
		Functions\when( 'nocache_headers' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$hider = new VersionHider( [] );

		try {
			$hider->maybe_block_readme_license();
			$this->fail( 'Expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		} finally {
			unset( $_SERVER['REQUEST_URI'] );
		}
	}
}
