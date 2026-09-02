<?php
/**
 * Unit tests for the Login Protection client IP resolver.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\LoginProtection;

use FanxieLab\WPCore\Modules\LoginProtection\IpResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see IpResolver}.
 */
final class IpResolverTest extends TestCase {

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tearDown();
	}

	public function test_default_uses_remote_addr_only(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.1'; // Must be ignored.
		$this->assertSame( '198.51.100.7', ( new IpResolver( false, 'HTTP_X_FORWARDED_FOR' ) )->resolve() );
	}

	public function test_trusts_proxy_header_when_enabled(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.4, 10.0.0.5';
		$this->assertSame( '203.0.113.4', ( new IpResolver( true, 'HTTP_X_FORWARDED_FOR' ) )->resolve() );
	}

	public function test_rejects_invalid_or_private_forwarded_and_falls_back(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 10.0.0.1'; // Invalid + private, so fall back.
		$this->assertSame( '198.51.100.9', ( new IpResolver( true, 'HTTP_X_FORWARDED_FOR' ) )->resolve() );
	}
}
