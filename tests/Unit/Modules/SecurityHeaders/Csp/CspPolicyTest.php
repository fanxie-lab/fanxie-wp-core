<?php
/**
 * Unit tests for CspPolicy.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\SecurityHeaders\Csp
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\SecurityHeaders\Csp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\SecurityHeaders\Csp\CspPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CspPolicy.
 */
final class CspPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_serialise_formats_directives_per_spec(): void {
		$policy = new CspPolicy(
			[
				'default-src' => [ "'self'" ],
				'script-src'  => [ "'self'", 'https://cdn.example.test' ],
				'object-src'  => [ "'none'" ],
			],
			CspPolicy::MODE_REPORT_ONLY,
		);

		$value = $policy->serialise();

		$this->assertStringContainsString( "default-src 'self'", $value );
		$this->assertStringContainsString( "script-src 'self' https://cdn.example.test", $value );
		$this->assertStringContainsString( "object-src 'none'", $value );
	}

	public function test_serialise_appends_report_uri_when_not_off(): void {
		$policy = new CspPolicy(
			[ 'default-src' => [ "'self'" ] ],
			CspPolicy::MODE_REPORT_ONLY,
			'https://example.test/r',
		);

		$this->assertStringContainsString( '; report-uri https://example.test/r', $policy->serialise() );
	}

	public function test_serialise_skips_report_uri_when_off(): void {
		$policy = new CspPolicy(
			[ 'default-src' => [ "'self'" ] ],
			CspPolicy::MODE_OFF,
			'https://example.test/r',
		);

		$this->assertStringNotContainsString( 'report-uri', $policy->serialise() );
	}

	public function test_merge_unions_values_preserving_order_and_dedup(): void {
		$policy = new CspPolicy(
			[
				'script-src' => [ "'self'", 'https://a.test' ],
				'img-src'    => [ 'data:' ],
			]
		);

		$merged = $policy->merge(
			[
				'script-src' => [ "'self'", 'https://b.test' ], // 'self' already present; b.test new.
				'style-src'  => [ "'self'" ],                   // new directive.
			]
		);

		$directives = $merged->directives();

		$this->assertSame( [ "'self'", 'https://a.test', 'https://b.test' ], $directives['script-src'] );
		$this->assertSame( [ 'data:' ], $directives['img-src'] );
		$this->assertSame( [ "'self'" ], $directives['style-src'] );
	}

	public function test_merge_ignores_non_string_values(): void {
		$policy = new CspPolicy( [ 'script-src' => [ "'self'" ] ] );

		$merged = $policy->merge(
			[
				'script-src' => [ 42, null, 'https://valid.test' ],
				''           => [ 'x' ], // empty key.
			]
		);

		$this->assertSame( [ "'self'", 'https://valid.test' ], $merged->directives()['script-src'] );
		$this->assertArrayNotHasKey( '', $merged->directives() );
	}

	public function test_filter_is_applied_before_serialisation(): void {
		$policy = new CspPolicy( [ 'default-src' => [ "'self'" ] ] );

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'fanxie_warden/security_headers/csp_directives' === $hook && is_array( $value ) ) {
					$value['x-filter'] = [ 'added' ];
				}
				return $value;
			}
		);

		$value = $policy->serialise();
		$this->assertStringContainsString( 'x-filter added', $value );
	}
}
