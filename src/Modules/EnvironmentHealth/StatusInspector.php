<?php
/**
 * Read-only environment probing for the Environment Health module.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth;

use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\CronInspector;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\DebugInspector;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\PluginThemeInspector;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\SslProbe;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\VersionInspector;
use FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime\WporgScanner;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the {@see HealthReport} from the four group inspectors and caches
 * the serialised result.
 *
 * Purely observational — nothing here changes site state. The one thing it is
 * strict about is *when* network I/O may happen:
 *
 *   - Report assembly performs no outbound requests. Both networked probes
 *     (TLS certificate, wordpress.org freshness) read their own caches and
 *     degrade to `unknown` when those are cold.
 *   - The TLS probe may open its single short socket when the caller passes
 *     `$allow_network` — the scheduled scan and an operator-triggered refresh
 *     do; a dashboard render never does.
 *   - The wordpress.org scan is *never* run from here. It is driven in
 *     batches by {@see EnvironmentHealth::run_scheduled_scan()} and by the
 *     refresh AJAX handler.
 *
 * Disabled groups are skipped entirely: their checks are absent from `checks`
 * rather than present with a placeholder status, so counts stay honest.
 */
final class StatusInspector {

	/**
	 * Transient holding the serialised report.
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'fanxie_wp_core_environment_health_report';

	/**
	 * Report cache lifetime, in seconds.
	 *
	 * Short by design — the local checks are cheap, and the expensive parts
	 * (TLS, wp.org) have their own much longer caches behind this one.
	 *
	 * @var int
	 */
	public const CACHE_TTL_SEC = 3600;

	/**
	 * Constructor.
	 *
	 * @param EnvironmentHealth $module  Module instance (source of truth for config).
	 * @param SslProbe          $ssl     TLS certificate probe.
	 * @param WporgScanner      $scanner wordpress.org freshness cache.
	 * @param int|null          $now     Evaluation timestamp (tests).
	 */
	public function __construct(
		private readonly EnvironmentHealth $module,
		private readonly SslProbe $ssl,
		private readonly WporgScanner $scanner,
		private readonly ?int $now = null,
	) {}

	/**
	 * Return the report payload, from cache when possible.
	 *
	 * @param bool $force         Bypass the report cache and re-assemble.
	 * @param bool $allow_network Permit the TLS probe to open a socket when its own cache is cold.
	 *
	 * @return array{generated_at: int, cached_until: int, counts: array<string, int>, checks: array<int, array<string, mixed>>}
	 */
	public function report( bool $force = false, bool $allow_network = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && $this->is_valid_shape( $cached ) ) {
				/**
				 * Cached payload, narrowed by the shape guard above.
				 *
				 * @var array{generated_at: int, cached_until: int, counts: array<string, int>, checks: array<int, array<string, mixed>>} $cached
				 */
				return $cached;
			}
		}

		$payload = $this->build( $allow_network, $force )->to_array();
		set_transient( self::CACHE_KEY, $payload, self::CACHE_TTL_SEC );

		return $payload;
	}

	/**
	 * Drop the cached report so the next read re-assembles it.
	 */
	public function invalidate_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Return the cached report payload without ever building one.
	 *
	 * Used by the dashboard widget, which must stay free: on a cold cache it
	 * renders an "not measured yet" state instead of doing the work inline.
	 *
	 * @return array{generated_at: int, cached_until: int, counts: array<string, int>, checks: array<int, array<string, mixed>>}|null
	 */
	public function cached_report(): ?array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && $this->is_valid_shape( $cached ) ) {
			/**
			 * Cached payload, narrowed by the shape guard above.
			 *
			 * @var array{generated_at: int, cached_until: int, counts: array<string, int>, checks: array<int, array<string, mixed>>} $cached
			 */
			return $cached;
		}

		return null;
	}

	/**
	 * Slugs of every active plugin — the input set for the wp.org scan.
	 *
	 * @return array<int, string>
	 */
	public function active_plugin_slugs(): array {
		return $this->plugin_theme_inspector()->active_plugin_slugs();
	}

	/**
	 * Assemble a fresh report.
	 *
	 * @param bool $allow_network Permit the TLS probe to open a socket.
	 * @param bool $force         Force the TLS probe to re-read even when cached.
	 */
	private function build( bool $allow_network, bool $force ): HealthReport {
		$config = $this->module->get_config();
		$groups = $this->enabled_groups( $config );
		$now    = $this->now();

		$checks = [];

		if ( in_array( HealthCheck::GROUP_VERSIONS, $groups, true ) ) {
			$versions = new VersionInspector( $config, $this->ssl, $allow_network, $allow_network && $force, $this->now );
			$checks   = array_merge( $checks, $versions->checks() );
		}

		if ( in_array( HealthCheck::GROUP_CRON, $groups, true ) ) {
			$checks = array_merge( $checks, ( new CronInspector( $config, $this->now ) )->checks() );
		}

		if ( in_array( HealthCheck::GROUP_DEBUG, $groups, true ) ) {
			$checks = array_merge( $checks, ( new DebugInspector() )->checks() );
		}

		if ( in_array( HealthCheck::GROUP_PLUGINS_THEMES, $groups, true ) ) {
			$checks = array_merge( $checks, $this->plugin_theme_inspector( $config )->checks() );
		}

		return new HealthReport( $checks, $now, $now + self::CACHE_TTL_SEC );
	}

	/**
	 * Which check groups the config has switched on.
	 *
	 * @param array<string, mixed> $config Module config.
	 * @return array<int, string>
	 */
	private function enabled_groups( array $config ): array {
		$toggles = isset( $config['checks'] ) && is_array( $config['checks'] ) ? $config['checks'] : [];

		$enabled = [];
		foreach ( HealthCheck::ALL_GROUPS as $group ) {
			if ( ! array_key_exists( $group, $toggles ) || ! empty( $toggles[ $group ] ) ) {
				$enabled[] = $group;
			}
		}

		return $enabled;
	}

	/**
	 * Build a plugin/theme inspector against the current config.
	 *
	 * @param array<string, mixed>|null $config Optional pre-read config.
	 */
	private function plugin_theme_inspector( ?array $config = null ): PluginThemeInspector {
		return new PluginThemeInspector(
			null !== $config ? $config : $this->module->get_config(),
			$this->scanner,
			$this->now
		);
	}

	/**
	 * Current evaluation timestamp.
	 */
	private function now(): int {
		return null !== $this->now ? $this->now : time();
	}

	/**
	 * Defensive shape check — reject cached payloads written by older code.
	 *
	 * @param array<string, mixed> $payload Candidate payload.
	 */
	private function is_valid_shape( array $payload ): bool {
		foreach ( [ 'generated_at', 'cached_until', 'counts', 'checks' ] as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				return false;
			}
		}

		return is_array( $payload['counts'] ) && is_array( $payload['checks'] );
	}
}
