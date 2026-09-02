<?php
/**
 * Environment Health module entry point.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth;

use FanxieLab\WPCore\Admin\AjaxRouter;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\SslProbe;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\WporgScanner;
use FanxieLab\WPCore\Modules\ModuleBase;

defined( 'ABSPATH' ) || exit;

/**
 * Module #5 — Environment Health (PRD §7).
 *
 * Reports on software versions, cron health, debug exposure, and plugin/theme
 * hygiene. Everything here is read-only: the module never changes site
 * configuration, and every fix is offered as a copy-paste block for the
 * operator to apply.
 *
 * Composition follows the shape used by Hardening — thin `Runtime/*`
 * collaborators fed a config snapshot, a {@see StatusInspector} that assembles
 * their output into a cached report, an {@see AjaxController} for the admin
 * surface, and this class as the wiring layer.
 *
 * Two design decisions worth stating plainly, because both were deliberate:
 *
 *   - **The support matrix is dates, not comparisons.** PRD §7.2 specified
 *     thresholds as version comparisons ("critical below 8.1"). Those were
 *     already stale by the time this module was written. {@see SupportMatrix}
 *     stores published end-of-support dates instead and derives status from
 *     them, so verdicts stay correct as time passes rather than as code is
 *     edited. A branch missing from the matrix reports `unknown`.
 *   - **Outbound requests never block a render.** The wordpress.org freshness
 *     scan runs in throttled batches on {@see self::SCAN_HOOK}, and the TLS
 *     probe only opens its socket on that event or on an explicit operator
 *     action. Report assembly and the dashboard widget read caches only.
 *
 * The wordpress.org scan is on by default and disclosed in `readme.txt` under
 * "External services"; `wporg_scan_enabled` turns it off and discards the
 * cached results.
 */
final class EnvironmentHealth extends ModuleBase {

	public const MODULE_ID = 'environment-health';

	/**
	 * Recurring event that keeps the networked probes warm.
	 *
	 * Flat name (no slash) — WP-Cron hook names are plain action names.
	 *
	 * @var string
	 */
	public const SCAN_HOOK = 'fanxie_wp_core_environment_health_scan';

	/**
	 * Delay, in seconds, before the follow-up batch of an unfinished scan.
	 *
	 * @var int
	 */
	public const FOLLOW_UP_DELAY_SEC = 60;

	/**
	 * Accepted range and default for every numeric threshold.
	 *
	 * This is the single source of truth: {@see self::get_default_config()}
	 * reads its defaults from here, {@see self::get_settings_fields()} publishes
	 * `min`/`max` to the admin UI from here, and {@see self::clamp_threshold()}
	 * enforces it on the way into storage.
	 *
	 * Why bounds at all — every one of these has a value that quietly breaks the
	 * check it configures rather than erroring:
	 *
	 *   - `cron_overdue_minutes = 0` marks every event overdue the instant it
	 *     comes due, so the cron check alarms permanently and gets ignored.
	 *   - `ssl_expiry_warning_days = 0` means the certificate warning can never
	 *     fire — the check silently stops doing its job.
	 *   - An absurd `abandoned_*` ceiling makes that check permanently green.
	 *
	 * Ranges bracket their own default, and each maximum is the largest value
	 * that still means something: 365 days is a full certificate lifetime,
	 * 1440 minutes is a day, 3650 days is a decade.
	 *
	 * @var array<string, array{min: int, max: int, default: int}>
	 */
	public const THRESHOLD_RANGES = [
		'ssl_expiry_warning_days' => [
			'min'     => 1,
			'max'     => 365,
			'default' => 30,
		],
		'cron_overdue_minutes'    => [
			'min'     => 1,
			'max'     => 1440,
			'default' => 60,
		],
		'abandoned_warning_days'  => [
			'min'     => 30,
			'max'     => 3650,
			'default' => 365,
		],
		'abandoned_critical_days' => [
			'min'     => 30,
			'max'     => 3650,
			'default' => 730,
		],
	];

	/**
	 * Lazily-built TLS probe.
	 *
	 * @var SslProbe|null
	 */
	private ?SslProbe $ssl;

	/**
	 * Lazily-built wordpress.org scanner.
	 *
	 * @var WporgScanner|null
	 */
	private ?WporgScanner $scanner;

	/**
	 * Lazily-built report assembler.
	 *
	 * @var StatusInspector|null
	 */
	private ?StatusInspector $inspector;

	/**
	 * Constructor.
	 *
	 * @param AjaxRouter           $ajax_router Shared AJAX router.
	 * @param SslProbe|null        $ssl         Optional override (tests).
	 * @param WporgScanner|null    $scanner     Optional override (tests).
	 * @param StatusInspector|null $inspector   Optional override (tests).
	 */
	public function __construct(
		private readonly AjaxRouter $ajax_router,
		?SslProbe $ssl = null,
		?WporgScanner $scanner = null,
		?StatusInspector $inspector = null,
	) {
		$this->ssl       = $ssl;
		$this->scanner   = $scanner;
		$this->inspector = $inspector;
	}

	/**
	 * Unique module slug.
	 */
	public function id(): string {
		return self::MODULE_ID;
	}

	/**
	 * Translatable display name.
	 */
	public function name(): string {
		return __( 'Environment Health', 'fanxie-wp-core' );
	}

	/**
	 * TLS probe accessor (tests + wiring).
	 */
	public function ssl_probe(): SslProbe {
		if ( null === $this->ssl ) {
			$this->ssl = new SslProbe();
		}
		return $this->ssl;
	}

	/**
	 * WordPress.org scanner accessor (tests + wiring).
	 */
	public function wporg_scanner(): WporgScanner {
		if ( null === $this->scanner ) {
			$this->scanner = new WporgScanner();
		}
		return $this->scanner;
	}

	/**
	 * Report assembler accessor (tests + wiring).
	 */
	public function status_inspector(): StatusInspector {
		if ( null === $this->inspector ) {
			$this->inspector = new StatusInspector( $this, $this->ssl_probe(), $this->wporg_scanner() );
		}
		return $this->inspector;
	}

	/**
	 * Whether the operator has left the wordpress.org freshness scan on.
	 */
	public function wporg_scan_enabled(): bool {
		$config = $this->get_config();

		return (bool) ( $config['wporg_scan_enabled'] ?? true );
	}

	/**
	 * Default configuration — nested, one leaf per toggle.
	 *
	 * Mirrors the `EnvironmentHealthConfig` TypeScript contract consumed by the
	 * Vue store (`assets/admin/src/modules/EnvironmentHealth/types.ts`).
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_config(): array {
		return [
			'checks'             => [
				'versions'       => true,
				'cron'           => true,
				'debug'          => true,
				'plugins_themes' => true,
			],
			'wporg_scan_enabled' => true,
			'ssl_check_enabled'  => true,
			'dashboard_widget'   => true,
			'thresholds'         => [
				'ssl_expiry_warning_days' => self::THRESHOLD_RANGES['ssl_expiry_warning_days']['default'],
				'cron_overdue_minutes'    => self::THRESHOLD_RANGES['cron_overdue_minutes']['default'],
				'abandoned_warning_days'  => self::THRESHOLD_RANGES['abandoned_warning_days']['default'],
				'abandoned_critical_days' => self::THRESHOLD_RANGES['abandoned_critical_days']['default'],
			],
		];
	}

	/**
	 * Settings schema consumed by the admin UI and the ModuleBase sanitiser.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return [
			[
				'id'        => 'checks.versions',
				'label'     => __( 'Check software versions', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'WordPress, PHP, the database server, the TLS certificate, and whether the site is served over HTTPS.', 'fanxie-wp-core' ),
			],
			[
				'id'        => 'checks.cron',
				'label'     => __( 'Check scheduled tasks', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'Detects a wedged cron lock and events that are running late.', 'fanxie-wp-core' ),
			],
			[
				'id'        => 'checks.debug',
				'label'     => __( 'Check debug settings', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'Finds debug switches left on in production, including a debug log written inside the web root.', 'fanxie-wp-core' ),
			],
			[
				'id'        => 'checks.plugins_themes',
				'label'     => __( 'Check plugins and themes', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'Inactive plugins, unused themes, and — when the wordpress.org check below is on — plugins that look abandoned.', 'fanxie-wp-core' ),
			],
			[
				'id'        => 'wporg_scan_enabled',
				'label'     => __( 'Check plugin freshness on wordpress.org', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'Sends the slug of each active plugin to api.wordpress.org to read its last-updated date, a few at a time on a daily schedule, cached for 24 hours. Nothing about you or your visitors is sent. Turn this off to stop the requests and discard everything already cached.', 'fanxie-wp-core' ),
			],
			[
				'id'        => 'ssl_check_enabled',
				'label'     => __( 'Check the TLS certificate', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'Opens one short-lived connection to this site to read its certificate expiry date, at most twice a day. Hosts that block outbound connections will report "unknown" rather than a false alarm.', 'fanxie-wp-core' ),
			],
			[
				'id'        => 'dashboard_widget',
				'label'     => __( 'Show the dashboard widget', 'fanxie-wp-core' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			$this->threshold_field(
				'ssl_expiry_warning_days',
				__( 'Warn this many days before the certificate expires', 'fanxie-wp-core' ),
				__( 'Most certificate authorities renew automatically about a month out, so 30 days is a good default.', 'fanxie-wp-core' ),
				[ $this, 'sanitize_ssl_expiry_warning_days' ]
			),
			$this->threshold_field(
				'cron_overdue_minutes',
				__( 'Treat an event as overdue after this many minutes', 'fanxie-wp-core' ),
				__( 'On a quiet site the page-load scheduler can legitimately run late, so keep this generous enough not to cry wolf.', 'fanxie-wp-core' ),
				[ $this, 'sanitize_cron_overdue_minutes' ]
			),
			$this->threshold_field(
				'abandoned_warning_days',
				__( 'Warn when a plugin has not been updated in this many days', 'fanxie-wp-core' ),
				'',
				[ $this, 'sanitize_abandoned_warning_days' ]
			),
			$this->threshold_field(
				'abandoned_critical_days',
				__( 'Flag as critical after this many days without an update', 'fanxie-wp-core' ),
				__( 'Must be at least the warning threshold — a critical window below the warning window would mean nothing was ever flagged critical. A lower value is raised to match.', 'fanxie-wp-core' ),
				[ $this, 'sanitize_abandoned_critical_days' ]
			),
		];
	}

	/**
	 * Build one numeric threshold field from {@see self::THRESHOLD_RANGES}.
	 *
	 * `min` and `max` are published to the admin UI so the browser's numeric
	 * bounds are read from the server's own definition rather than duplicated
	 * in TypeScript, where the two could drift apart.
	 *
	 * @param string   $key       Threshold key.
	 * @param string   $label     Translated field label.
	 * @param string   $help      Translated help text ('' to omit).
	 * @param callable $sanitizer Field sanitiser.
	 *
	 * @return array<string, mixed>
	 */
	private function threshold_field( string $key, string $label, string $help, callable $sanitizer ): array {
		$range = self::THRESHOLD_RANGES[ $key ];

		$range_note = sprintf(
			/* translators: 1: minimum accepted value. 2: maximum accepted value. */
			__( 'Accepted range: %1$d–%2$d. Values outside it are clamped to the nearest limit.', 'fanxie-wp-core' ),
			$range['min'],
			$range['max']
		);

		return [
			'id'                 => 'thresholds.' . $key,
			'label'              => $label,
			'type'               => 'number',
			'default'            => $range['default'],
			'min'                => $range['min'],
			'max'                => $range['max'],
			'sanitizer_callback' => $sanitizer,
			'help'               => '' !== $help ? $help . ' ' . $range_note : $range_note,
		];
	}

	/**
	 * Sanitiser: `thresholds.ssl_expiry_warning_days`.
	 *
	 * @param mixed $value Raw value from the payload.
	 */
	public function sanitize_ssl_expiry_warning_days( mixed $value ): int {
		return $this->clamp_threshold( $value, 'ssl_expiry_warning_days' );
	}

	/**
	 * Sanitiser: `thresholds.cron_overdue_minutes`.
	 *
	 * @param mixed $value Raw value from the payload.
	 */
	public function sanitize_cron_overdue_minutes( mixed $value ): int {
		return $this->clamp_threshold( $value, 'cron_overdue_minutes' );
	}

	/**
	 * Sanitiser: `thresholds.abandoned_warning_days`.
	 *
	 * @param mixed $value Raw value from the payload.
	 */
	public function sanitize_abandoned_warning_days( mixed $value ): int {
		return $this->clamp_threshold( $value, 'abandoned_warning_days' );
	}

	/**
	 * Sanitiser: `thresholds.abandoned_critical_days`.
	 *
	 * Bounds only — the ordering rule against `abandoned_warning_days` needs
	 * both values at once and is applied in {@see self::sanitize_config()}.
	 *
	 * @param mixed $value Raw value from the payload.
	 */
	public function sanitize_abandoned_critical_days( mixed $value ): int {
		return $this->clamp_threshold( $value, 'abandoned_critical_days' );
	}

	/**
	 * Coerce a raw threshold to an int inside its documented range.
	 *
	 * Two distinct failure modes, deliberately handled differently:
	 *
	 *   - **Out of range** (`5000` for a 30–3650 field) clamps to the nearest
	 *     limit. Somebody asking for 5000 days wants "the maximum", and
	 *     silently resetting them to the default would discard a clear intent.
	 *   - **Not a number at all** (`''`, `'garbage'`, `true`, an array) falls
	 *     back to the default. There is no nearest limit to clamp toward, and
	 *     `absint()` — which used to guard this — turns every one of them into
	 *     `0`, landing on exactly the value that breaks the check.
	 *
	 * @param mixed  $value Raw value from the payload.
	 * @param string $key   Threshold key in {@see self::THRESHOLD_RANGES}.
	 */
	public function clamp_threshold( mixed $value, string $key ): int {
		$range = self::THRESHOLD_RANGES[ $key ] ?? null;
		if ( null === $range ) {
			return 0;
		}

		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( ! is_numeric( $value ) ) {
			return $range['default'];
		}

		$number = (int) round( (float) $value );

		return max( $range['min'], min( $range['max'], $number ) );
	}

	/**
	 * Schema sanitisation plus the one rule that spans two fields.
	 *
	 * `ModuleBase` sanitises each field in isolation, which is right for
	 * everything except a pair whose *relationship* has to hold: an
	 * `abandoned_critical_days` below `abandoned_warning_days` passes both
	 * bounds checks and still produces a config where nothing is ever flagged
	 * critical, because a plugin old enough to be critical always trips the
	 * warning branch first.
	 *
	 * Resolved by raising critical to meet warning rather than lowering warning
	 * to meet critical: the operator's warning threshold is the value they
	 * actually see acted on, so honouring it loses less of their intent.
	 *
	 * Applied on read as well as write (both routes pass through here), so an
	 * option row written before this rule existed self-heals.
	 *
	 * @param array<string, mixed> $config Raw config.
	 * @return array<string, mixed>
	 */
	protected function sanitize_config( array $config ): array {
		$clean = parent::sanitize_config( $config );

		if ( ! isset( $clean['thresholds'] ) || ! is_array( $clean['thresholds'] ) ) {
			return $clean;
		}

		$warning  = (int) ( $clean['thresholds']['abandoned_warning_days'] ?? 0 );
		$critical = (int) ( $clean['thresholds']['abandoned_critical_days'] ?? 0 );

		if ( $critical < $warning ) {
			$clean['thresholds']['abandoned_critical_days'] = $warning;
		}

		return $clean;
	}

	/**
	 * Wire the module.
	 *
	 * Mounts the AJAX surface, arms the recurring scan (which is the only place
	 * outbound requests originate on their own), and registers the dashboard
	 * widget when the operator has left it on.
	 */
	public function register_hooks(): void {
		$config = $this->get_config();

		$inspector = $this->status_inspector();

		( new AjaxController( $this, $inspector, $this->wporg_scanner(), $this->ssl_probe() ) )
			->register( $this->ajax_router );

		add_action( self::SCAN_HOOK, [ $this, 'run_scheduled_scan' ] );
		$this->ensure_scan_scheduled();

		if ( ! empty( $config['dashboard_widget'] ) ) {
			$widget = new DashboardWidget( $inspector );
			add_action( 'wp_dashboard_setup', [ $widget, 'register' ] );
		}
	}

	/**
	 * Cron handler: refresh the networked probes and rebuild the report.
	 *
	 * This is the only unattended path that touches the network. It refreshes
	 * the TLS certificate reading, advances the wordpress.org scan by one
	 * scheduled-size batch, and reschedules itself a minute out when slugs
	 * remain — so a site with sixty plugins finishes over a handful of quick
	 * passes instead of one long stall.
	 */
	public function run_scheduled_scan(): void {
		$inspector = $this->status_inspector();

		if ( $this->wporg_scan_enabled() ) {
			$result = $this->wporg_scanner()->scan(
				$inspector->active_plugin_slugs(),
				WporgScanner::SCHEDULED_BATCH
			);

			if ( ! $result['complete'] ) {
				$this->schedule_follow_up_scan();
			}
		}

		$inspector->invalidate_cache();
		$inspector->report( true, true );
	}

	/**
	 * Queue a one-off follow-up pass for an unfinished scan.
	 *
	 * Guarded against stacking: `wp_next_scheduled()` with no args matches the
	 * recurring event too, so we look specifically for a pending single event
	 * near our target time.
	 */
	public function schedule_follow_up_scan(): void {
		$when = time() + self::FOLLOW_UP_DELAY_SEC;

		if ( false !== wp_next_scheduled( self::SCAN_HOOK, [ 'follow-up' ] ) ) {
			return;
		}

		wp_schedule_single_event( $when, self::SCAN_HOOK, [ 'follow-up' ] );
	}

	/**
	 * Arm the recurring scan if it is not already scheduled.
	 *
	 * Twice daily rather than daily so the 12-hour TLS cache always has a
	 * fresh reading behind it.
	 */
	private function ensure_scan_scheduled(): void {
		if ( false !== wp_next_scheduled( self::SCAN_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::SCAN_HOOK );
	}
}
