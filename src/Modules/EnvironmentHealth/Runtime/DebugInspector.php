<?php
/**
 * Debug-mode exposure checks for the Environment Health module.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime;

use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;

defined( 'ABSPATH' ) || exit;

/**
 * PRD §7.4 — debug switches left on in production.
 *
 * Severity is graded by who can see the output, not by whether the switch is
 * on. `WP_DEBUG` on its own only changes what gets logged; `WP_DEBUG_DISPLAY`
 * and PHP's `display_errors` put stack traces, absolute paths, and database
 * errors in front of visitors, which is an information-disclosure bug, so
 * those two are critical.
 *
 * The debug-log check is filesystem-based on purpose: an HTTP probe for
 * `wp-content/debug.log` would be more certain, but it costs an outbound
 * request on every report build. Comparing the resolved log path against
 * `ABSPATH` answers the same question — "is this file inside the document
 * root?" — with no I/O beyond a `file_exists()`.
 */
final class DebugInspector {

	/**
	 * PHP error-reporting bits that indicate a development configuration.
	 *
	 * @var int
	 */
	private const NOISY_ERROR_BITS = E_NOTICE | E_WARNING | E_DEPRECATED;

	/**
	 * Build every check in the `debug` group.
	 *
	 * @return array<int, HealthCheck>
	 */
	public function checks(): array {
		return [
			$this->wp_debug_check(),
			$this->wp_debug_display_check(),
			$this->wp_debug_log_check(),
			$this->script_debug_check(),
			$this->display_errors_check(),
			$this->error_reporting_check(),
		];
	}

	/**
	 * `WP_DEBUG` — the master switch.
	 */
	private function wp_debug_check(): HealthCheck {
		$enabled = $this->constant_is_true( 'WP_DEBUG' );

		return new HealthCheck(
			'wp_debug',
			HealthCheck::GROUP_DEBUG,
			__( 'WP_DEBUG', 'fanxie-wp-core' ),
			$enabled ? HealthCheck::STATUS_WARNING : HealthCheck::STATUS_OK,
			$this->bool_label( $enabled ),
			$enabled
				? __( 'Debug mode is on.', 'fanxie-wp-core' )
				: __( 'Debug mode is off.', 'fanxie-wp-core' ),
			$enabled
				? __( 'WP_DEBUG turns on PHP notices and deprecation warnings across WordPress. That is exactly right on a staging site and wrong on a live one: it costs performance and, combined with any display setting, leaks file paths and query details.', 'fanxie-wp-core' )
				: '',
			$enabled ? [ $this->wp_config_snippet( "define( 'WP_DEBUG', false );", __( 'Turn debug mode off', 'fanxie-wp-core' ) ) ] : [],
			[ 'defined' => defined( 'WP_DEBUG' ) ]
		);
	}

	/**
	 * `WP_DEBUG_DISPLAY` — errors rendered into the page.
	 */
	private function wp_debug_display_check(): HealthCheck {
		// Core's default is `true`, but it only has an effect while WP_DEBUG is
		// on — so an undefined constant is only a finding when debugging is on.
		$debugging = $this->constant_is_true( 'WP_DEBUG' );
		$defined   = defined( 'WP_DEBUG_DISPLAY' );
		$value     = $defined ? (bool) constant( 'WP_DEBUG_DISPLAY' ) : true;
		$exposed   = $debugging && $value;

		return new HealthCheck(
			'wp_debug_display',
			HealthCheck::GROUP_DEBUG,
			__( 'WP_DEBUG_DISPLAY', 'fanxie-wp-core' ),
			$exposed ? HealthCheck::STATUS_CRITICAL : HealthCheck::STATUS_OK,
			$this->bool_label( $value ),
			$exposed
				? __( 'PHP errors are being printed into pages visitors can see.', 'fanxie-wp-core' )
				: __( 'Errors are not rendered into page output.', 'fanxie-wp-core' ),
			$exposed
				? __( 'A single notice can expose absolute server paths, table prefixes, and fragments of SQL — everything an attacker needs to aim the next attempt. Log errors instead of displaying them.', 'fanxie-wp-core' )
				: '',
			$exposed
				? [
					$this->wp_config_snippet(
						"define( 'WP_DEBUG_DISPLAY', false );\n@ini_set( 'display_errors', 0 );",
						__( 'Stop printing errors to the page', 'fanxie-wp-core' )
					),
				]
				: [],
			[
				'defined'   => $defined,
				'debugging' => $debugging,
			]
		);
	}

	/**
	 * `WP_DEBUG_LOG` — and whether the log it writes is reachable over HTTP.
	 */
	private function wp_debug_log_check(): HealthCheck {
		$label   = __( 'Debug log exposure', 'fanxie-wp-core' );
		$enabled = defined( 'WP_DEBUG_LOG' ) && false !== constant( 'WP_DEBUG_LOG' ) && '' !== constant( 'WP_DEBUG_LOG' );

		if ( ! $enabled ) {
			return new HealthCheck(
				'wp_debug_log',
				HealthCheck::GROUP_DEBUG,
				$label,
				HealthCheck::STATUS_OK,
				$this->bool_label( false ),
				__( 'Debug logging is off.', 'fanxie-wp-core' ),
				'',
				[],
				[ 'path' => null ]
			);
		}

		$path       = $this->debug_log_path();
		$in_webroot = $this->is_inside_webroot( $path );
		$exists     = '' !== $path && file_exists( $path );

		if ( ! $in_webroot ) {
			return new HealthCheck(
				'wp_debug_log',
				HealthCheck::GROUP_DEBUG,
				$label,
				HealthCheck::STATUS_OK,
				$path,
				__( 'The debug log is written outside the web root.', 'fanxie-wp-core' ),
				'',
				[],
				[
					'path'       => $path,
					'in_webroot' => false,
					'exists'     => $exists,
				]
			);
		}

		$remediation = [
			$this->wp_config_snippet(
				"// Write the log somewhere the web server will never serve.\ndefine( 'WP_DEBUG_LOG', dirname( ABSPATH ) . '/fanxie-debug.log' );",
				__( 'Move the log outside the web root', 'fanxie-wp-core' )
			),
			HealthCheck::snippet(
				'nginx',
				"location ~* /debug\\.log$ {\n    deny all;\n}",
				__( 'Deny the log in nginx', 'fanxie-wp-core' )
			),
		];

		return new HealthCheck(
			'wp_debug_log',
			HealthCheck::GROUP_DEBUG,
			$label,
			$exists ? HealthCheck::STATUS_CRITICAL : HealthCheck::STATUS_WARNING,
			$path,
			$exists
				? __( 'A debug log exists inside the web root and may be downloadable.', 'fanxie-wp-core' )
				: __( 'Debug logging writes inside the web root.', 'fanxie-wp-core' ),
			$exists
				? __( 'The default log path is public knowledge, so anyone can try to fetch it. Debug logs routinely contain absolute paths, query strings, and occasionally credentials passed to a failing function. Move it above the document root, or deny it at the web-server level.', 'fanxie-wp-core' )
				: __( 'No log file has been written yet, but the first error will create one at a publicly guessable path. Move the destination above the document root before that happens.', 'fanxie-wp-core' ),
			$remediation,
			[
				'path'       => $path,
				'in_webroot' => true,
				'exists'     => $exists,
			]
		);
	}

	/**
	 * `SCRIPT_DEBUG` — unminified core assets.
	 */
	private function script_debug_check(): HealthCheck {
		$enabled = $this->constant_is_true( 'SCRIPT_DEBUG' );

		return new HealthCheck(
			'script_debug',
			HealthCheck::GROUP_DEBUG,
			__( 'SCRIPT_DEBUG', 'fanxie-wp-core' ),
			$enabled ? HealthCheck::STATUS_WARNING : HealthCheck::STATUS_OK,
			$this->bool_label( $enabled ),
			$enabled
				? __( 'WordPress is loading unminified core scripts and styles.', 'fanxie-wp-core' )
				: __( 'Minified core assets are in use.', 'fanxie-wp-core' ),
			$enabled
				? __( 'This is a core-development setting. It roughly doubles the weight of every admin screen and the block editor, and offers a visitor nothing.', 'fanxie-wp-core' )
				: '',
			$enabled ? [ $this->wp_config_snippet( "define( 'SCRIPT_DEBUG', false );", __( 'Use minified assets', 'fanxie-wp-core' ) ) ] : [],
			[ 'defined' => defined( 'SCRIPT_DEBUG' ) ]
		);
	}

	/**
	 * PHP's own `display_errors`, which WordPress cannot fully override.
	 */
	private function display_errors_check(): HealthCheck {
		$raw = (string) ini_get( 'display_errors' );
		$on  = $this->ini_flag_is_on( $raw );

		return new HealthCheck(
			'php_display_errors',
			HealthCheck::GROUP_DEBUG,
			__( 'PHP display_errors', 'fanxie-wp-core' ),
			$on ? HealthCheck::STATUS_CRITICAL : HealthCheck::STATUS_OK,
			'' !== $raw ? $raw : '0',
			$on
				? __( 'PHP is configured to print errors into its output.', 'fanxie-wp-core' )
				: __( 'PHP does not print errors into its output.', 'fanxie-wp-core' ),
			$on
				? __( 'This is set in php.ini or by your host, so WordPress constants alone may not switch it off — errors raised before WordPress boots will still reach the browser. Change it in php.ini, or in a .user.ini if your host allows one.', 'fanxie-wp-core' )
				: '',
			$on
				? [
					HealthCheck::snippet(
						'bash',
						"; php.ini (or .user.ini)\ndisplay_errors = Off\nlog_errors = On",
						__( 'Log errors instead of showing them', 'fanxie-wp-core' )
					),
				]
				: [],
			[ 'raw' => $raw ]
		);
	}

	/**
	 * PHP's `error_reporting` mask.
	 */
	private function error_reporting_check(): HealthCheck {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- read-only: called with no argument, which returns the current mask without changing it. Reporting that mask is the entire point of this check.
		$level = (int) error_reporting();
		$noisy = 0 !== ( $level & self::NOISY_ERROR_BITS );

		return new HealthCheck(
			'php_error_reporting',
			HealthCheck::GROUP_DEBUG,
			__( 'PHP error_reporting', 'fanxie-wp-core' ),
			$noisy ? HealthCheck::STATUS_WARNING : HealthCheck::STATUS_OK,
			$this->error_reporting_label( $level ),
			$noisy
				? __( 'Notices, warnings, and deprecations are being reported.', 'fanxie-wp-core' )
				: __( 'Only significant errors are reported.', 'fanxie-wp-core' ),
			$noisy
				? __( 'Harmless on its own — nothing is exposed while display_errors is off. It does mean a busy site can fill a disk with log lines about deprecations in themes and plugins you do not maintain. Narrow the mask in production, or make sure log rotation is in place.', 'fanxie-wp-core' )
				: '',
			$noisy
				? [
					HealthCheck::snippet(
						'bash',
						"; php.ini (or .user.ini)\nerror_reporting = E_ALL & ~E_DEPRECATED & ~E_NOTICE",
						__( 'Quieten development-only diagnostics', 'fanxie-wp-core' )
					),
				]
				: [],
			[ 'level' => $level ]
		);
	}

	/**
	 * Resolve the path `WP_DEBUG_LOG` writes to.
	 *
	 * A string value is the path itself; `true` means core's default,
	 * `wp-content/debug.log`.
	 */
	private function debug_log_path(): string {
		if ( ! defined( 'WP_DEBUG_LOG' ) ) {
			return '';
		}

		$value = constant( 'WP_DEBUG_LOG' );

		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}

		if ( true === $value && defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( (string) constant( 'WP_CONTENT_DIR' ), '/\\' ) . '/debug.log';
		}

		return '';
	}

	/**
	 * Whether a path sits inside the WordPress document root.
	 *
	 * @param string $path Absolute path.
	 */
	private function is_inside_webroot( string $path ): bool {
		if ( '' === $path || ! defined( 'ABSPATH' ) ) {
			return false;
		}

		$root = wp_normalize_path( rtrim( (string) constant( 'ABSPATH' ), '/\\' ) . '/' );

		return str_starts_with( wp_normalize_path( $path ), $root );
	}

	/**
	 * Whether a constant is both defined and truthy.
	 *
	 * @param string $name Constant name.
	 */
	private function constant_is_true( string $name ): bool {
		return defined( $name ) && (bool) constant( $name );
	}

	/**
	 * Interpret a php.ini boolean-ish flag.
	 *
	 * `stderr` counts as off for our purposes: output goes to the server's
	 * error stream, not into the HTTP response.
	 *
	 * @param string $raw Raw `ini_get()` value.
	 */
	private function ini_flag_is_on( string $raw ): bool {
		$normalised = strtolower( trim( $raw ) );

		return ! in_array( $normalised, [ '', '0', 'off', 'false', 'no', 'none', 'stderr' ], true );
	}

	/**
	 * Display label for an `error_reporting` mask.
	 *
	 * @param int $level The mask.
	 */
	private function error_reporting_label( int $level ): string {
		if ( 0 === $level ) {
			return '0';
		}

		if ( E_ALL === $level ) {
			return 'E_ALL';
		}

		return (string) $level;
	}

	/**
	 * Translated on/off label.
	 *
	 * @param bool $enabled Whether the flag is on.
	 */
	private function bool_label( bool $enabled ): string {
		return $enabled ? __( 'Enabled', 'fanxie-wp-core' ) : __( 'Disabled', 'fanxie-wp-core' );
	}

	/**
	 * Wrap a wp-config.php snippet with the standard preamble.
	 *
	 * @param string $code  The snippet body.
	 * @param string $label Translated caption.
	 *
	 * @return array<string, string>
	 */
	private function wp_config_snippet( string $code, string $label ): array {
		return HealthCheck::snippet( 'php', "// wp-config.php\n" . $code, $label );
	}
}
