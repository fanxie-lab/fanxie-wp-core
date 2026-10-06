<?php
/**
 * Unit tests for the Database Maintenance Settings value object.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\DatabaseMaintenance;

use Brain\Monkey;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Database Maintenance Settings value object.
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_match_the_spec(): void {
		$d = Settings::defaults();

		$this->assertFalse( $d['revision_limit_enabled'] );
		$this->assertSame( 20, $d['revisions_keep'] );
		$this->assertSame( 7, $d['auto_draft_days'] );
		$this->assertSame( 30, $d['trash_days'] );
		$this->assertSame( 15, $d['spam_days'] );
		$this->assertFalse( $d['schedule_enabled'] );
		$this->assertSame( 'weekly', $d['schedule_frequency'] );
		$this->assertSame( 3, $d['schedule_hour'] );
		$this->assertFalse( $d['schedule_tasks']['revisions'] );
		$this->assertFalse( $d['schedule_tasks']['trashed-posts'] );
		$this->assertTrue( $d['schedule_tasks']['expired-transients'] );
		$this->assertTrue( $d['schedule_tasks']['spam-comments'] );
		$this->assertArrayNotHasKey( 'all-transients', $d['schedule_tasks'] );
	}

	/**
	 * @dataProvider clamp_cases
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $input    Raw value.
	 * @param int    $expected Expected clamped value.
	 */
	public function test_clamp( string $key, mixed $input, int $expected ): void {
		$this->assertSame( $expected, Settings::clamp( $key, $input ) );
	}

	public static function clamp_cases(): array {
		return [
			'keep below range'  => [ 'revisions_keep', -4, 0 ],
			'keep above range'  => [ 'revisions_keep', 99, 50 ],
			'keep numeric str'  => [ 'revisions_keep', ' 12 ', 12 ],
			'keep garbage'      => [ 'revisions_keep', 'abc', 20 ],
			'days zero'         => [ 'trash_days', 0, 1 ],
			'days huge'         => [ 'spam_days', 10000, 365 ],
			'hour float rounds' => [ 'schedule_hour', 22.6, 23 ],
			'hour negative'     => [ 'schedule_hour', -1, 0 ],
		];
	}

	public function test_from_array_clamps_hand_edited_values_and_drops_unknown_keys(): void {
		$s = Settings::from_array(
			[
				'revisions_keep'     => 500,
				'trash_days'         => -3,
				'schedule_frequency' => 'hourly',
				'schedule_tasks'     => [
					'revisions'      => 'yes',
					'all-transients' => true,
					'bogus'          => true,
				],
				'evil'               => '<script>',
			]
		);

		$this->assertSame( 50, $s->revisions_keep );
		$this->assertSame( 1, $s->trash_days );
		$this->assertSame( 'weekly', $s->schedule_frequency );
		$this->assertTrue( $s->schedule_tasks['revisions'] );
		$this->assertArrayNotHasKey( 'all-transients', $s->schedule_tasks );
		$this->assertArrayNotHasKey( 'bogus', $s->schedule_tasks );
		$this->assertTrue( $s->schedule_tasks['expired-transients'], 'Missing keys fall back to defaults.' );
		$this->assertArrayNotHasKey( 'evil', $s->to_array() );
	}
}
