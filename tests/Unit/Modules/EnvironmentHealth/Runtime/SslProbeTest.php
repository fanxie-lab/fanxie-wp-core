<?php
/**
 * Unit tests for the TLS certificate probe.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\EnvironmentHealth\Runtime\SslProbe;
use PHPUnit\Framework\TestCase;

/**
 * The socket itself is not exercised here — the behaviour that matters is what
 * happens around it: that a cold cache with probing disallowed does nothing at
 * all, that a failed connection degrades to a reason rather than an alarm, and
 * that a cached reading is reused.
 *
 * The failure path is driven for real by pointing the probe at a port nothing
 * listens on, so the `stream_socket_client()` error branch is genuinely taken
 * rather than mocked away.
 */
final class SslProbeTest extends TestCase {

	/**
	 * Transient store backing the Brain Monkey stubs.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = [];

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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_cold_cache_returns_null_when_probing_is_not_permitted(): void {
		$probe = new SslProbe();

		$this->assertNull(
			$probe->snapshot( 'example.test', 443, false ),
			'A page render must never pay for a socket.'
		);
		$this->assertSame( [], $this->transients, 'Nothing is written when nothing is probed.' );
	}

	public function test_a_cached_reading_is_reused_without_probing(): void {
		$cached = [
			'result'     => SslProbe::RESULT_READ,
			'expires_at' => 1893456000,
			'starts_at'  => 1861920000,
			'issuer'     => "Let's Encrypt",
			'message'    => '',
			'probed_at'  => 1756800000,
		];

		$this->transients[ SslProbe::CACHE_KEY ] = $cached;

		$this->assertSame( $cached, ( new SslProbe() )->snapshot( 'example.test', 443, false ) );
	}

	public function test_an_unreachable_host_degrades_to_a_reason_not_a_false_alarm(): void {
		// Port 1 on the loopback interface: reliably refused, never a certificate.
		$snapshot = ( new SslProbe() )->snapshot( '127.0.0.1', 1, true );

		$this->assertIsArray( $snapshot );
		$this->assertSame( SslProbe::RESULT_UNREACHABLE, $snapshot['result'] );
		$this->assertNull( $snapshot['expires_at'], 'No expiry is invented when nothing was read.' );
		$this->assertNotSame( '', $snapshot['message'], 'The operator is told why we could not look.' );
		$this->assertGreaterThan( 0, $snapshot['probed_at'] );
	}

	public function test_a_failed_probe_is_still_cached_so_it_is_not_retried_every_request(): void {
		$probe = new SslProbe();
		$probe->snapshot( '127.0.0.1', 1, true );

		$this->assertArrayHasKey( SslProbe::CACHE_KEY, $this->transients );
		$this->assertSame(
			SslProbe::RESULT_UNREACHABLE,
			$probe->snapshot( '127.0.0.1', 1, false )['result']
		);
	}

	public function test_an_empty_host_is_reported_as_unsupported(): void {
		$snapshot = ( new SslProbe() )->snapshot( '', 443, true );

		$this->assertIsArray( $snapshot );
		$this->assertSame( SslProbe::RESULT_UNSUPPORTED, $snapshot['result'] );
		$this->assertSame( 'no_host', $snapshot['message'] );
	}

	public function test_invalidate_cache_forces_the_next_permitted_read_to_re_probe(): void {
		$probe = new SslProbe();
		$probe->snapshot( '127.0.0.1', 1, true );
		$this->assertNotSame( [], $this->transients );

		$probe->invalidate_cache();

		$this->assertSame( [], $this->transients );
		$this->assertNull( $probe->snapshot( '127.0.0.1', 1, false ) );
	}

	public function test_force_bypasses_a_cached_reading(): void {
		$this->transients[ SslProbe::CACHE_KEY ] = [
			'result'     => SslProbe::RESULT_READ,
			'expires_at' => 1893456000,
			'starts_at'  => null,
			'issuer'     => null,
			'message'    => '',
			'probed_at'  => 1,
		];

		$snapshot = ( new SslProbe() )->snapshot( '127.0.0.1', 1, false, true );

		$this->assertIsArray( $snapshot );
		$this->assertSame( SslProbe::RESULT_UNREACHABLE, $snapshot['result'] );
	}

	public function test_the_connect_timeout_stays_short_enough_never_to_hang_a_request(): void {
		$this->assertLessThanOrEqual( 5, SslProbe::CONNECT_TIMEOUT_SEC );
		$this->assertSame( 43200, SslProbe::CACHE_TTL_SEC, 'Cached for roughly twelve hours.' );
	}
}
