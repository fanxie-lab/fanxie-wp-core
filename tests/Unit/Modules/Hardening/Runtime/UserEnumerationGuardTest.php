<?php
/**
 * Unit tests for UserEnumerationGuard.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\Hardening\Runtime\UserEnumerationGuard;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Tests the REST + redirect_canonical filters.
 */
final class UserEnumerationGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );

		// Shim the minimal WP_REST_Request surface we need. Only define the
		// class once per test run — Brain Monkey resets functions, not classes.
		if ( ! class_exists( WP_REST_Request::class, false ) ) {
			$src = 'class WP_REST_Request { private string $method; private string $route;'
				. ' public function __construct( string $method = "GET", string $route = "" ) { $this->method = $method; $this->route = $route; }'
				. ' public function get_method(): string { return $this->method; }'
				. ' public function get_route(): string { return $this->route; } }';
			eval( $src );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_GET['author'] );
		parent::tearDown();
	}

	public function test_redirect_canonical_blocks_author_param_for_guests(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$guard = new UserEnumerationGuard( [ 'user_enumeration' => [ 'block_author_archive' => true ] ] );

		$_GET['author'] = '1';
		$this->assertFalse( $guard->filter_redirect_canonical( 'https://example.test/author/foo/', 'https://example.test/?author=1' ) );
	}

	public function test_redirect_canonical_ignored_for_logged_in_users(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$guard = new UserEnumerationGuard( [ 'user_enumeration' => [ 'block_author_archive' => true ] ] );

		$_GET['author'] = '1';
		$this->assertSame(
			'https://example.test/author/foo/',
			$guard->filter_redirect_canonical( 'https://example.test/author/foo/', 'https://example.test/?author=1' )
		);
	}

	public function test_redirect_canonical_ignored_without_author_param(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$guard = new UserEnumerationGuard( [ 'user_enumeration' => [ 'block_author_archive' => true ] ] );

		unset( $_GET['author'] );
		$this->assertSame(
			'https://example.test/',
			$guard->filter_redirect_canonical( 'https://example.test/', 'https://example.test/' )
		);
	}

	public function test_rest_request_401s_unauthenticated_users_endpoint(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$guard = new UserEnumerationGuard( [ 'user_enumeration' => [ 'block_rest_users_endpoint' => true ] ] );

		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$result  = $guard->filter_rest_request( null, [], $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_cannot_view', $result->get_error_code() );
	}

	public function test_rest_request_passes_for_logged_in_users(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$guard   = new UserEnumerationGuard( [ 'user_enumeration' => [ 'block_rest_users_endpoint' => true ] ] );
		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );

		$this->assertNull( $guard->filter_rest_request( null, [], $request ) );
	}

	public function test_rest_request_passes_for_non_users_routes(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$guard   = new UserEnumerationGuard( [ 'user_enumeration' => [ 'block_rest_users_endpoint' => true ] ] );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );

		$this->assertNull( $guard->filter_rest_request( null, [], $request ) );
	}

	public function test_rest_request_matches_singular_user_route(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$guard   = new UserEnumerationGuard( [ 'user_enumeration' => [ 'block_rest_users_endpoint' => true ] ] );
		$request = new WP_REST_Request( 'GET', '/wp/v2/users/42' );

		$this->assertInstanceOf( WP_Error::class, $guard->filter_rest_request( null, [], $request ) );
	}
}
