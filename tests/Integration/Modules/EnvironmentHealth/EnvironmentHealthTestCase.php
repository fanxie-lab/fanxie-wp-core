<?php
/**
 * Shared integration harness for the Environment Health module.
 *
 * @package FanxieLab\Warden\Tests\Integration\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Integration\Modules\EnvironmentHealth;

use FanxieLab\Warden\Modules\EnvironmentHealth\EnvironmentHealth;
use FanxieLab\Warden\Modules\EnvironmentHealth\Runtime\SslProbe;
use FanxieLab\Warden\Modules\EnvironmentHealth\Runtime\WporgScanner;
use FanxieLab\Warden\Modules\EnvironmentHealth\StatusInspector;
use FanxieLab\Warden\Plugin;
use WP_UnitTestCase;

/**
 * Base class for the module's integration tests.
 *
 * Two things it guarantees for every test, both about *not* reaching the
 * network from a test run:
 *
 *   - `pre_http_request` is filtered, so an accidental api.wordpress.org call
 *     is captured and asserted on rather than actually made.
 *   - The TLS probe is switched off by default. Tests that need a certificate
 *     reading seed the probe's transient directly.
 */
abstract class EnvironmentHealthTestCase extends WP_UnitTestCase {

	/**
	 * Option key the module persists its settings under.
	 */
	protected const OPTION_KEY = 'fanxie_warden_environment-health_settings';

	/**
	 * Administrator created for every test.
	 *
	 * @var int
	 */
	protected int $admin_user_id = 0;

	/**
	 * Outbound requests intercepted by `pre_http_request`.
	 *
	 * @var array<int, string>
	 */
	protected array $requested = [];

	/**
	 * Canned responses returned by `pre_http_request`, consumed in order.
	 *
	 * @var array<int, mixed>
	 */
	protected array $responses = [];

	public function set_up(): void {
		parent::set_up();

		Plugin::activate();

		$this->admin_user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->requested     = [];
		$this->responses     = [];

		$this->reset_module_state();

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );

		$this->reset_module_state();

		$administrator = get_role( 'administrator' );
		if ( $administrator instanceof \WP_Role ) {
			$administrator->remove_cap( Plugin::CAPABILITY );
		}

		parent::tear_down();
	}

	/**
	 * Clear every scrap of module state between tests.
	 *
	 * The TLS check starts off so a test run never opens a socket; individual
	 * tests opt back in.
	 */
	protected function reset_module_state(): void {
		delete_option( WporgScanner::CACHE_OPTION );
		delete_transient( StatusInspector::CACHE_KEY );
		delete_transient( SslProbe::CACHE_KEY );
		wp_clear_scheduled_hook( EnvironmentHealth::SCAN_HOOK );
		wp_clear_scheduled_hook( EnvironmentHealth::SCAN_HOOK, [ 'follow-up' ] );

		update_option( self::OPTION_KEY, [ 'ssl_check_enabled' => false ] );
	}

	/**
	 * Short-circuit every outbound HTTP request.
	 *
	 * @param mixed                $preempt Short-circuit value.
	 * @param array<string, mixed> $args    Request args.
	 * @param string               $url     Request URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function intercept_http( $preempt, $args, $url ) {
		unset( $preempt, $args );

		$this->requested[] = (string) $url;

		$queued = array_shift( $this->responses );
		if ( null !== $queued ) {
			return $queued;
		}

		return $this->wporg_response( gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Build a wp.org plugin-information response.
	 *
	 * @param string $last_updated Value for the `last_updated` field.
	 * @return array<string, mixed>
	 */
	protected function wporg_response( string $last_updated ): array {
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( [ 'last_updated' => $last_updated ] ),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * The module instance held by the plugin container.
	 */
	protected function module(): EnvironmentHealth {
		$plugin = Plugin::instance();
		$this->assertInstanceOf( Plugin::class, $plugin );

		$module = $plugin->get( EnvironmentHealth::class );
		$this->assertInstanceOf( EnvironmentHealth::class, $module );

		return $module;
	}

	/**
	 * Locate a serialised check by id in a report payload.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @param string               $id     Stable check id.
	 * @return array<string, mixed>
	 */
	protected function check_in( array $report, string $id ): array {
		foreach ( $report['checks'] as $check ) {
			if ( is_array( $check ) && ( $check['id'] ?? '' ) === $id ) {
				return $check;
			}
		}

		$this->fail( "Report has no check with id `{$id}`." );
	}
}
