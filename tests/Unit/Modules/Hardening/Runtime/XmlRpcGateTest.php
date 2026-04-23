<?php
/**
 * Unit tests for XmlRpcGate.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\Hardening\Runtime\XmlRpcGate;
use PHPUnit\Framework\TestCase;

/**
 * Tests the three XML-RPC enforcement modes.
 */
final class XmlRpcGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $v ) => is_string( $v ) ? trim( $v ) : '' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_hard_block_calls_wp_die_with_403(): void {
		Functions\when( 'esc_html__' )->returnArg( 1 );
		$status_code = 0;
		Functions\when( 'status_header' )->alias(
			static function ( $code ) use ( &$status_code ): void {
				$status_code = (int) $code;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( $msg, $title, $args ): void {
				throw new \RuntimeException( 'wp_die: ' . (int) ( $args['response'] ?? 0 ) );
			}
		);

		$gate = new XmlRpcGate( [ 'xmlrpc' => [ 'mode' => 'disabled' ] ] );

		try {
			$gate->hard_block();
			$this->fail( 'Expected wp_die to fire.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 403, $status_code );
			$this->assertStringContainsString( '403', $e->getMessage() );
		}
	}

	public function test_strip_pingback_header_removes_header(): void {
		$gate = new XmlRpcGate( [ 'xmlrpc' => [ 'mode' => 'disabled' ] ] );

		$headers = $gate->strip_pingback_header(
			[
				'X-Pingback'   => 'https://example.test/xmlrpc.php',
				'Content-Type' => 'text/html',
			]
		);

		$this->assertArrayNotHasKey( 'X-Pingback', $headers );
		$this->assertArrayHasKey( 'Content-Type', $headers );
	}

	public function test_filter_methods_drops_multicall_and_pingbacks(): void {
		$gate = new XmlRpcGate( [ 'xmlrpc' => [ 'mode' => 'restrict_methods' ] ] );

		$filtered = $gate->filter_methods(
			[
				'system.multicall'    => 'x',
				'pingback.ping'       => 'x',
				'pingback.extensions' => 'x',
				'wp.getPosts'         => 'x',
				'system.listMethods'  => 'x',
			]
		);

		$this->assertArrayNotHasKey( 'system.multicall', $filtered );
		$this->assertArrayNotHasKey( 'pingback.ping', $filtered );
		$this->assertArrayNotHasKey( 'pingback.extensions', $filtered );
		$this->assertArrayHasKey( 'wp.getPosts', $filtered );
		$this->assertArrayHasKey( 'system.listMethods', $filtered );
	}

	public function test_filter_methods_for_ip_returns_methods_when_ip_allowed(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$gate = new XmlRpcGate(
			[
				'xmlrpc' => [
					'mode'        => 'restrict_ips',
					'allowed_ips' => [ '10.0.0.1', '10.0.0.2' ],
				],
			]
		);

		$methods = $gate->filter_methods_for_ip( [ 'wp.getPosts' => 'x' ] );
		$this->assertArrayHasKey( 'wp.getPosts', $methods );
	}

	public function test_filter_methods_for_ip_returns_empty_when_ip_missing(): void {
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$gate = new XmlRpcGate(
			[
				'xmlrpc' => [
					'mode'        => 'restrict_ips',
					'allowed_ips' => [ '10.0.0.1' ],
				],
			]
		);

		$this->assertSame( [], $gate->filter_methods_for_ip( [ 'wp.getPosts' => 'x' ] ) );
	}

	public function test_allowed_ips_drops_invalid_entries_during_runtime_resolution(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$gate = new XmlRpcGate(
			[
				'xmlrpc' => [
					'mode'        => 'restrict_ips',
					'allowed_ips' => [ '10.0.0.1', 'not-an-ip', 42 ],
				],
			]
		);

		$this->assertArrayHasKey( 'wp.getPosts', $gate->filter_methods_for_ip( [ 'wp.getPosts' => 'x' ] ) );
	}
}
