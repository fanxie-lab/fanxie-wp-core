<?php
/**
 * Database Maintenance module (PRD §8).
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance;

use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\ModuleBase;

defined( 'ABSPATH' ) || exit;

/**
 * Database Maintenance module.
 */
final class DatabaseMaintenance extends ModuleBase {

	public const MODULE_ID = 'database-maintenance';

	/**
	 * Constructor.
	 *
	 * @param AjaxRouter $ajax_router Shared AJAX router.
	 */
	public function __construct( private readonly AjaxRouter $ajax_router ) {}

	/**
	 * Module slug.
	 */
	public function id(): string {
		return self::MODULE_ID;
	}

	/**
	 * Translatable display name.
	 */
	public function name(): string {
		return __( 'Database Maintenance', 'fanxie-warden' );
	}

	/**
	 * Default configuration.
	 */
	public function get_default_config(): array {
		return Settings::defaults();
	}

	/**
	 * Settings schema.
	 */
	public function get_settings_fields(): array {
		$defaults = Settings::defaults();
		$fields   = [
			[
				'id'        => 'revision_limit_enabled',
				'label'     => __( 'Limit stored revisions', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'schedule_enabled',
				'label'     => __( 'Run cleanups on a schedule', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
			],
			[
				'id'                 => 'schedule_frequency',
				'label'              => __( 'Frequency', 'fanxie-warden' ),
				'type'               => 'select',
				'default'            => 'weekly',
				'sanitizer_callback' => [ Settings::class, 'sanitize_frequency' ],
			],
			[
				'id'                 => 'schedule_tasks',
				'label'              => __( 'Scheduled cleanups', 'fanxie-warden' ),
				'type'               => 'map',
				'default'            => $defaults['schedule_tasks'],
				'sanitizer_callback' => [ Settings::class, 'sanitize_schedule_tasks' ],
			],
		];

		foreach ( array_keys( Settings::RANGES ) as $key ) {
			$fields[] = [
				'id'                 => $key,
				'label'              => $key,
				'type'               => 'number',
				'default'            => Settings::RANGES[ $key ][2],
				'min'                => Settings::RANGES[ $key ][0],
				'max'                => Settings::RANGES[ $key ][1],
				'sanitizer_callback' => static fn ( mixed $v ): int => Settings::clamp( $key, $v ),
			];
		}

		return $fields;
	}

	/**
	 * Clamped settings value object for the stored config.
	 */
	public function settings(): Settings {
		return Settings::from_array( $this->get_config() );
	}

	/**
	 * The site's own revision setting, or null when wp-config leaves it at
	 * core's default (`true` = unlimited). Core always defines the constant,
	 * so "defined" alone would lock every site.
	 */
	public static function revisions_constant(): int|bool|null {
		if ( ! defined( 'WP_POST_REVISIONS' ) ) {
			return null;
		}

		$value = constant( 'WP_POST_REVISIONS' );

		if ( true === $value ) {
			return null;
		}

		return is_bool( $value ) ? $value : (int) $value;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'wp_revisions_to_keep', [ $this, 'filter_revisions_to_keep' ], 10, 1 );

		( new AjaxController( $this ) )->register( $this->ajax_router );
	}

	/**
	 * Task factory bound to the current settings.
	 */
	public function task_factory(): Cleanup\TaskFactory {
		return new Cleanup\TaskFactory( $this->settings() );
	}

	/**
	 * Batch runner.
	 */
	public function runner(): Cleanup\CleanupRunner {
		return new Cleanup\CleanupRunner();
	}

	/**
	 * Next scheduled run as ISO 8601. Replaced in Task 9 to read the cron event.
	 *
	 * @phpstan-ignore return.unusedType (placeholder until Task 9)
	 */
	public function next_run_iso(): ?string {
		return null;
	}

	/**
	 * Settings-saved hook. Replaced in Task 9 to reschedule cron.
	 */
	public function on_settings_saved(): void {}

	/**
	 * Apply the revision cap unless wp-config already sets one.
	 *
	 * @param int $num Revisions to keep.
	 */
	public function filter_revisions_to_keep( int $num ): int {
		if ( null !== self::revisions_constant() ) {
			return $num;
		}

		$settings = $this->settings();

		return $settings->revision_limit_enabled ? $settings->revisions_keep : $num;
	}
}
