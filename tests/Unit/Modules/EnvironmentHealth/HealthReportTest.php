<?php
/**
 * Unit tests for the HealthCheck / HealthReport value objects.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\EnvironmentHealth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\Warden\Modules\EnvironmentHealth\HealthReport;
use PHPUnit\Framework\TestCase;

/**
 * These two objects are the serialisation contract with the Vue tab, so the
 * assertions here are about shape and about the closed enums holding.
 */
final class HealthReportTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function check( string $id, string $status, string $group = HealthCheck::GROUP_DEBUG ): HealthCheck {
		return new HealthCheck( $id, $group, 'Label', $status, 'value', 'summary' );
	}

	public function test_to_array_emits_every_contract_key(): void {
		$check = new HealthCheck(
			'php_version',
			HealthCheck::GROUP_VERSIONS,
			'PHP version',
			HealthCheck::STATUS_WARNING,
			'8.2.14',
			'Security-only support.',
			'Longer prose.',
			[ HealthCheck::snippet( 'php', "define( 'WP_DEBUG', false );", 'Turn it off' ) ],
			[ 'branch' => '8.2' ]
		);

		$array = $check->to_array();

		$this->assertSame(
			[ 'id', 'group', 'label', 'status', 'value', 'summary', 'detail', 'remediation', 'meta' ],
			array_keys( $array )
		);
		$this->assertSame( 'php_version', $array['id'] );
		$this->assertSame( [ 'branch' => '8.2' ], $array['meta'] );
		$this->assertSame( 'snippet', $array['remediation'][0]['kind'] );
		$this->assertSame( 'php', $array['remediation'][0]['language'] );
	}

	public function test_out_of_contract_enum_values_are_clamped_at_serialisation(): void {
		$rogue = new HealthCheck( 'x', 'not_a_group', 'Label', 'catastrophic', '', '' );

		$array = $rogue->to_array();

		$this->assertSame( HealthCheck::GROUP_VERSIONS, $array['group'] );
		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $array['status'] );
	}

	public function test_link_remediation_passes_the_url_through_esc_url_raw(): void {
		$link = HealthCheck::link( 'https://example.test/docs', 'Read the docs' );

		$this->assertSame(
			[
				'kind'  => 'link',
				'url'   => 'https://example.test/docs',
				'label' => 'Read the docs',
			],
			$link
		);
	}

	public function test_counts_are_zero_filled_across_every_status(): void {
		$report = new HealthReport( [ $this->check( 'a', HealthCheck::STATUS_OK ) ], 1000, 2000 );

		$this->assertSame(
			[
				HealthCheck::STATUS_OK       => 1,
				HealthCheck::STATUS_WARNING  => 0,
				HealthCheck::STATUS_CRITICAL => 0,
				HealthCheck::STATUS_UNKNOWN  => 0,
			],
			$report->counts()
		);
	}

	public function test_counts_tally_each_status(): void {
		$report = new HealthReport(
			[
				$this->check( 'a', HealthCheck::STATUS_OK ),
				$this->check( 'b', HealthCheck::STATUS_OK ),
				$this->check( 'c', HealthCheck::STATUS_WARNING ),
				$this->check( 'd', HealthCheck::STATUS_CRITICAL ),
				$this->check( 'e', HealthCheck::STATUS_UNKNOWN ),
			],
			1000,
			2000
		);

		$counts = $report->counts();

		$this->assertSame( 2, $counts[ HealthCheck::STATUS_OK ] );
		$this->assertSame( 1, $counts[ HealthCheck::STATUS_WARNING ] );
		$this->assertSame( 1, $counts[ HealthCheck::STATUS_CRITICAL ] );
		$this->assertSame( 1, $counts[ HealthCheck::STATUS_UNKNOWN ] );
	}

	public function test_worst_status_ranks_unknown_above_ok(): void {
		$all_clear = new HealthReport( [ $this->check( 'a', HealthCheck::STATUS_OK ) ], 0, 0 );
		$uncertain = new HealthReport(
			[
				$this->check( 'a', HealthCheck::STATUS_OK ),
				$this->check( 'b', HealthCheck::STATUS_UNKNOWN ),
			],
			0,
			0
		);
		$warned    = new HealthReport(
			[
				$this->check( 'a', HealthCheck::STATUS_UNKNOWN ),
				$this->check( 'b', HealthCheck::STATUS_WARNING ),
			],
			0,
			0
		);
		$broken    = new HealthReport(
			[
				$this->check( 'a', HealthCheck::STATUS_WARNING ),
				$this->check( 'b', HealthCheck::STATUS_CRITICAL ),
			],
			0,
			0
		);

		$this->assertSame( HealthCheck::STATUS_OK, $all_clear->worst_status() );
		$this->assertSame( HealthCheck::STATUS_UNKNOWN, $uncertain->worst_status() );
		$this->assertSame( HealthCheck::STATUS_WARNING, $warned->worst_status() );
		$this->assertSame( HealthCheck::STATUS_CRITICAL, $broken->worst_status() );
	}

	public function test_worst_from_counts_works_without_a_report_instance(): void {
		$this->assertSame(
			HealthCheck::STATUS_CRITICAL,
			HealthReport::worst_from_counts( [ 'critical' => 1 ] )
		);
		$this->assertSame( HealthCheck::STATUS_OK, HealthReport::worst_from_counts( [] ) );
	}

	public function test_report_to_array_matches_the_frozen_shape(): void {
		$report = new HealthReport( [ $this->check( 'a', HealthCheck::STATUS_OK ) ], 1756800000, 1756886400 );

		$array = $report->to_array();

		$this->assertSame( [ 'generated_at', 'cached_until', 'counts', 'checks' ], array_keys( $array ) );
		$this->assertSame( 1756800000, $array['generated_at'] );
		$this->assertSame( 1756886400, $array['cached_until'] );
		$this->assertCount( 1, $array['checks'] );
		$this->assertSame( 'a', $array['checks'][0]['id'] );
	}
}
