<?php
/**
 * WP-Cron health checks for the Environment Health module.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime;

use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;

defined( 'ABSPATH' ) || exit;

/**
 * PRD §7.3 — is scheduled work actually running?
 *
 * Three signals, deliberately kept apart because they mean different things:
 *
 *   1. `DISABLE_WP_CRON` — a *configuration*, not a fault. Turning WP-Cron off
 *      and driving `wp-cron.php` from a real system crontab is the recommended
 *      setup on any site with real traffic. So this check reports the constant
 *      and grades it by whether the queue is actually moving, rather than
 *      treating the constant itself as a problem.
 *   2. The `doing_cron` lock — a stale lock means a cron run died mid-flight
 *      and nothing has picked the queue back up.
 *   3. Overdue events — the ground truth. If events are late, something is
 *      broken regardless of how cron is configured.
 *
 * Nothing here is auto-fixed. Every remediation is a copy-paste block the
 * operator applies themselves, per the PRD.
 */
final class CronInspector {

	/**
	 * Core-scheduled hooks. An overdue event on one of these means WP-Cron is
	 * not running at all, as opposed to one misbehaving plugin task.
	 *
	 * @var array<int, string>
	 */
	public const CORE_HOOKS = [
		'wp_version_check',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_scheduled_delete',
		'wp_scheduled_auto_draft_delete',
		'delete_expired_transients',
		'wp_privacy_delete_old_export_files',
		'recovery_mode_clean_expired_keys',
		'wp_site_health_scheduled_check',
		'wp_https_detection',
	];

	/**
	 * How stale the `doing_cron` lock may get before we call it wedged.
	 *
	 * Core's own `WP_CRON_LOCK_TIMEOUT` is 60 seconds; five minutes gives a
	 * slow but healthy run plenty of room before we accuse it of hanging.
	 *
	 * @var int
	 */
	public const STALE_LOCK_SECONDS = 300;

	/**
	 * Maximum overdue hooks listed in `meta` (the UI shows a sample, not a dump).
	 *
	 * @var int
	 */
	private const MAX_LISTED_HOOKS = 10;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config Module config snapshot.
	 * @param int|null             $now    Evaluation timestamp (tests).
	 */
	public function __construct(
		private readonly array $config,
		private readonly ?int $now = null,
	) {}

	/**
	 * Build every check in the `cron` group.
	 *
	 * @return array<int, HealthCheck>
	 */
	public function checks(): array {
		$overdue = $this->overdue_events();

		return [
			$this->disable_wp_cron_check( $overdue ),
			$this->stale_lock_check(),
			$this->overdue_check( $overdue ),
		];
	}

	/**
	 * Current evaluation timestamp.
	 */
	private function now(): int {
		return null !== $this->now ? $this->now : time();
	}

	/**
	 * Overdue-event tolerance in seconds, from config (default one hour).
	 */
	private function overdue_threshold(): int {
		$thresholds = isset( $this->config['thresholds'] ) && is_array( $this->config['thresholds'] )
			? $this->config['thresholds']
			: [];

		$minutes = isset( $thresholds['cron_overdue_minutes'] ) ? (int) $thresholds['cron_overdue_minutes'] : 60;

		return max( 1, $minutes ) * 60;
	}

	/**
	 * `DISABLE_WP_CRON` — reported, and graded on whether the queue is moving.
	 *
	 * @param array{count: int, core: array<int, string>, other: array<int, string>, oldest: int|null} $overdue Overdue-event summary.
	 */
	private function disable_wp_cron_check( array $overdue ): HealthCheck {
		$disabled = defined( 'DISABLE_WP_CRON' ) && (bool) constant( 'DISABLE_WP_CRON' );
		$backlog  = $overdue['count'] > 0;

		$crontab_snippet = HealthCheck::snippet(
			'bash',
			"# crontab -e — run WordPress's scheduler every five minutes.\n*/5 * * * * wget -q -O - " . site_url( 'wp-cron.php?doing_wp_cron' ) . ' >/dev/null 2>&1',
			__( 'System crontab entry', 'fanxie-wp-core' )
		);

		$wp_config_snippet = HealthCheck::snippet(
			'php',
			"// wp-config.php — stop WordPress running the scheduler on page loads.\ndefine( 'DISABLE_WP_CRON', true );",
			__( 'Disable the page-load scheduler', 'fanxie-wp-core' )
		);

		if ( $disabled && $backlog ) {
			return new HealthCheck(
				'cron_disabled',
				HealthCheck::GROUP_CRON,
				__( 'WP-Cron configuration', 'fanxie-wp-core' ),
				HealthCheck::STATUS_CRITICAL,
				'DISABLE_WP_CRON = true',
				__( 'The page-load scheduler is off and nothing is running the queue in its place.', 'fanxie-wp-core' ),
				__( 'Defining DISABLE_WP_CRON is only safe when a real system cron calls wp-cron.php. Events are overdue, so that call is either missing or failing. Add the crontab entry below, or remove the constant to fall back to the page-load scheduler.', 'fanxie-wp-core' ),
				[ $crontab_snippet ],
				[ 'disable_wp_cron' => true ]
			);
		}

		if ( $disabled ) {
			return new HealthCheck(
				'cron_disabled',
				HealthCheck::GROUP_CRON,
				__( 'WP-Cron configuration', 'fanxie-wp-core' ),
				HealthCheck::STATUS_OK,
				'DISABLE_WP_CRON = true',
				__( 'Driven by a real system cron — the recommended setup.', 'fanxie-wp-core' ),
				'',
				[],
				[ 'disable_wp_cron' => true ]
			);
		}

		return new HealthCheck(
			'cron_disabled',
			HealthCheck::GROUP_CRON,
			__( 'WP-Cron configuration', 'fanxie-wp-core' ),
			HealthCheck::STATUS_OK,
			'DISABLE_WP_CRON = false',
			__( 'Scheduled work runs on visitor page loads.', 'fanxie-wp-core' ),
			__( 'This is the WordPress default and it works. On a busy site it adds latency to a random visitor’s request; on a quiet one, events can be hours late because nobody visited. Moving to a system crontab fixes both — apply the two snippets together.', 'fanxie-wp-core' ),
			[ $wp_config_snippet, $crontab_snippet ],
			[ 'disable_wp_cron' => false ]
		);
	}

	/**
	 * The `doing_cron` lock — set when a run starts, cleared when it finishes.
	 */
	private function stale_lock_check(): HealthCheck {
		$raw = get_transient( 'doing_cron' );

		if ( ! is_scalar( $raw ) || '' === (string) $raw ) {
			return new HealthCheck(
				'cron_lock',
				HealthCheck::GROUP_CRON,
				__( 'Cron lock', 'fanxie-wp-core' ),
				HealthCheck::STATUS_OK,
				__( 'Not held', 'fanxie-wp-core' ),
				__( 'No cron run is in progress.', 'fanxie-wp-core' ),
				'',
				[],
				[ 'locked_since' => null ]
			);
		}

		$started = (float) $raw;
		$age     = $this->now() - (int) $started;

		if ( $age < self::STALE_LOCK_SECONDS ) {
			return new HealthCheck(
				'cron_lock',
				HealthCheck::GROUP_CRON,
				__( 'Cron lock', 'fanxie-wp-core' ),
				HealthCheck::STATUS_OK,
				__( 'Run in progress', 'fanxie-wp-core' ),
				__( 'A cron run is currently in progress.', 'fanxie-wp-core' ),
				'',
				[],
				[ 'locked_since' => (int) $started ]
			);
		}

		return new HealthCheck(
			'cron_lock',
			HealthCheck::GROUP_CRON,
			__( 'Cron lock', 'fanxie-wp-core' ),
			HealthCheck::STATUS_WARNING,
			$this->format_duration( $age ),
			__( 'A cron run started but never finished.', 'fanxie-wp-core' ),
			__( 'WordPress sets a lock when it begins processing the queue and clears it on completion. A lock this old means the run was killed part-way — usually a PHP fatal error, a memory limit, or a request timeout in a scheduled task. Check the PHP error log for what ran last, then let the lock expire or clear the doing_cron transient.', 'fanxie-wp-core' ),
			[
				HealthCheck::snippet(
					'bash',
					'wp transient delete doing_cron',
					__( 'Clear the stuck lock (WP-CLI)', 'fanxie-wp-core' )
				),
			],
			[ 'locked_since' => (int) $started ]
		);
	}

	/**
	 * Events whose scheduled time has passed by more than the threshold.
	 *
	 * @param array{count: int, core: array<int, string>, other: array<int, string>, oldest: int|null} $overdue Overdue-event summary.
	 */
	private function overdue_check( array $overdue ): HealthCheck {
		$threshold_minutes = (int) round( $this->overdue_threshold() / 60 );

		if ( 0 === $overdue['count'] ) {
			return new HealthCheck(
				'cron_overdue_events',
				HealthCheck::GROUP_CRON,
				__( 'Overdue scheduled events', 'fanxie-wp-core' ),
				HealthCheck::STATUS_OK,
				'0',
				__( 'Every scheduled event is running on time.', 'fanxie-wp-core' ),
				'',
				[],
				[
					'threshold_minutes' => $threshold_minutes,
					'core_overdue'      => 0,
				]
			);
		}

		$core_overdue = count( $overdue['core'] );
		$status       = $core_overdue > 0 ? HealthCheck::STATUS_CRITICAL : HealthCheck::STATUS_WARNING;

		$detail = $core_overdue > 0
			? __( 'WordPress’s own maintenance events are late, which means the scheduler is not running at all — update checks, transient cleanup, and trash emptying have all stopped. Wire wp-cron.php to a system crontab.', 'fanxie-wp-core' )
			: __( 'Core’s own events are on time, so the scheduler itself works. One or more plugin tasks are late — usually a task that fatals, or one scheduled with a hook nothing listens to any more.', 'fanxie-wp-core' );

		return new HealthCheck(
			'cron_overdue_events',
			HealthCheck::GROUP_CRON,
			__( 'Overdue scheduled events', 'fanxie-wp-core' ),
			$status,
			(string) $overdue['count'],
			sprintf(
				/* translators: 1: number of overdue events. 2: overdue threshold in minutes. */
				_n(
					'%1$d event is more than %2$d minutes overdue.',
					'%1$d events are more than %2$d minutes overdue.',
					$overdue['count'],
					'fanxie-wp-core'
				),
				$overdue['count'],
				$threshold_minutes
			),
			$detail,
			[
				HealthCheck::snippet(
					'bash',
					'wp cron event list --fields=hook,next_run_relative,recurrence',
					__( 'Inspect the queue (WP-CLI)', 'fanxie-wp-core' )
				),
			],
			[
				'threshold_minutes' => $threshold_minutes,
				'core_overdue'      => $core_overdue,
				'core_hooks'        => implode( ', ', $overdue['core'] ),
				'other_hooks'       => implode( ', ', $overdue['other'] ),
				'oldest_due_at'     => $overdue['oldest'],
			]
		);
	}

	/**
	 * Walk the cron array and collect everything past its due time.
	 *
	 * @return array{count: int, core: array<int, string>, other: array<int, string>, oldest: int|null}
	 */
	private function overdue_events(): array {
		$cron = _get_cron_array();

		$core    = [];
		$other   = [];
		$count   = 0;
		$oldest  = null;
		$cutoff  = $this->now() - $this->overdue_threshold();
		$is_core = static fn ( string $hook ): bool => in_array( $hook, self::CORE_HOOKS, true );

		if ( ! is_array( $cron ) ) {
			return [
				'count'  => 0,
				'core'   => [],
				'other'  => [],
				'oldest' => null,
			];
		}

		foreach ( $cron as $timestamp => $hooks ) {
			$timestamp = (int) $timestamp;
			if ( $timestamp > $cutoff || ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( array_keys( $hooks ) as $hook ) {
				if ( ! is_string( $hook ) ) {
					continue;
				}

				++$count;

				if ( null === $oldest || $timestamp < $oldest ) {
					$oldest = $timestamp;
				}

				if ( $is_core( $hook ) ) {
					if ( count( $core ) < self::MAX_LISTED_HOOKS && ! in_array( $hook, $core, true ) ) {
						$core[] = $hook;
					}
				} elseif ( count( $other ) < self::MAX_LISTED_HOOKS && ! in_array( $hook, $other, true ) ) {
					$other[] = $hook;
				}
			}
		}

		return [
			'count'  => $count,
			'core'   => $core,
			'other'  => $other,
			'oldest' => $oldest,
		];
	}

	/**
	 * Human-readable duration, e.g. "2 hours".
	 *
	 * @param int $seconds Elapsed seconds.
	 */
	private function format_duration( int $seconds ): string {
		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			__( 'Held for %s', 'fanxie-wp-core' ),
			human_time_diff( $this->now() - max( 0, $seconds ), $this->now() )
		);
	}
}
