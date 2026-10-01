<?php
/**
 * Hardening module entry point.
 *
 * @package FanxieLab\Warden\Modules\Hardening
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\Hardening;

use FanxieLab\Warden\Admin\AjaxRouter;
use FanxieLab\Warden\Modules\Hardening\Runtime\ApplicationPasswordGate;
use FanxieLab\Warden\Modules\Hardening\Runtime\FileEditGuard;
use FanxieLab\Warden\Modules\Hardening\Runtime\HeaderStripper;
use FanxieLab\Warden\Modules\Hardening\Runtime\LoginErrorObfuscator;
use FanxieLab\Warden\Modules\Hardening\Runtime\UserEnumerationGuard;
use FanxieLab\Warden\Modules\Hardening\Runtime\VersionHider;
use FanxieLab\Warden\Modules\Hardening\Runtime\XmlRpcGate;
use FanxieLab\Warden\Modules\ModuleBase;

defined( 'ABSPATH' ) || exit;

/**
 * Module #2 — Hardening (information leakage prevention).
 *
 * PRD §4. Eliminates fingerprinting vectors (version strings, `X-Powered-By`,
 * `readme.html`, generator meta) and closes common attack surfaces (user
 * enumeration, XML-RPC, uploads PHP execution, dashboard file editing,
 * application passwords).
 *
 * Each concern is a thin `Runtime/*` class consuming a snapshot of the
 * module config. This class is the wiring layer: it instantiates collaborators,
 * registers the AJAX surface, binds runtime hooks, and exposes accessors
 * for the admin controller + plugin activation.
 */
final class Hardening extends ModuleBase {

	public const MODULE_ID = 'hardening';

	/**
	 * Constructor.
	 *
	 * @param AjaxRouter            $ajax_router Shared AJAX router.
	 * @param UploadsProtector|null $uploads     Optional override (tests).
	 * @param StatusInspector|null  $inspector   Optional override (tests).
	 */
	public function __construct(
		private readonly AjaxRouter $ajax_router,
		private ?UploadsProtector $uploads = null,
		private ?StatusInspector $inspector = null,
	) {}

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
		return __( 'Hardening', 'fanxie-warden' );
	}

	/**
	 * Uploads protector accessor (used by `Plugin::activate()` + tests).
	 */
	public function uploads_protector(): UploadsProtector {
		if ( null === $this->uploads ) {
			$this->uploads = new UploadsProtector( $this->get_config() );
		}
		return $this->uploads;
	}

	/**
	 * Status inspector accessor (tests).
	 */
	public function status_inspector(): StatusInspector {
		if ( null === $this->inspector ) {
			$this->inspector = new StatusInspector( $this->uploads_protector() );
		}
		return $this->inspector;
	}

	/**
	 * Default configuration — nested, one leaf per toggle.
	 *
	 * Mirrors the `HardeningConfig` TypeScript contract consumed by the Vue
	 * store (see `assets/admin/src/modules/Hardening/types.ts`).
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_config(): array {
		return [
			'user_enumeration'      => [
				'block_author_archive'      => true,
				'block_rest_users_endpoint' => true,
			],
			'xmlrpc'                => [
				'mode'        => XmlRpcGate::MODE_DISABLED,
				'allowed_ips' => [],
			],
			'version_hiding'        => [
				'remove_powered_by'    => true,
				'remove_wp_generator'  => true,
				'remove_rss_generator' => true,
				'strip_version_query'  => true,
				'block_readme_license' => true,
			],
			'uploads'               => [
				'drop_index'          => true,
				'block_php_execution' => true,
			],
			'login'                 => [
				'obfuscate_errors' => true,
			],
			'file_editing'          => [
				// Preferred path: `define( 'DISALLOW_FILE_EDIT', true )` in
				// wp-config.php — the UI surfaces the detection, this toggle
				// is a runtime fallback.
				'runtime_enforce' => false,
			],
			'application_passwords' => [
				// Gated by the UI to only writable when no AP exist.
				'disable' => false,
			],
		];
	}

	/**
	 * Settings schema consumed by the admin UI and ModuleBase sanitiser.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return [
			// User enumeration.
			[
				'id'        => 'user_enumeration.block_author_archive',
				'label'     => __( 'Block author enumeration', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
				'help'      => __( 'Block guest requests to `/?author=N` that leak login slugs.', 'fanxie-warden' ),
			],
			[
				'id'        => 'user_enumeration.block_rest_users_endpoint',
				'label'     => __( 'Block REST users endpoint for guests', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],

			// XML-RPC.
			[
				'id'                 => 'xmlrpc.mode',
				'label'              => __( 'XML-RPC mode', 'fanxie-warden' ),
				'type'               => 'select',
				'default'            => XmlRpcGate::MODE_DISABLED,
				'sanitizer_callback' => [ $this, 'sanitize_xmlrpc_mode' ],
				'options'            => [
					XmlRpcGate::MODE_DISABLED         => __( 'Disabled (recommended)', 'fanxie-warden' ),
					XmlRpcGate::MODE_RESTRICT_METHODS => __( 'Restrict dangerous methods', 'fanxie-warden' ),
					XmlRpcGate::MODE_RESTRICT_IPS     => __( 'Restrict by IP allowlist', 'fanxie-warden' ),
					XmlRpcGate::MODE_OFF              => __( 'Off (no changes)', 'fanxie-warden' ),
				],
			],
			[
				'id'                 => 'xmlrpc.allowed_ips',
				'label'              => __( 'XML-RPC allowed IPs', 'fanxie-warden' ),
				'type'               => 'textarea',
				'default'            => [],
				'sanitizer_callback' => [ $this, 'sanitize_allowed_ips' ],
			],

			// Version hiding.
			[
				'id'        => 'version_hiding.remove_powered_by',
				'label'     => __( 'Remove X-Powered-By header', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'version_hiding.remove_wp_generator',
				'label'     => __( 'Remove WordPress generator meta', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'version_hiding.remove_rss_generator',
				'label'     => __( 'Remove WordPress generator from feeds', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'version_hiding.strip_version_query',
				'label'     => __( 'Strip ?ver= cache-busters from assets', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'version_hiding.block_readme_license',
				'label'     => __( 'Block /readme.html and /license.txt', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],

			// Uploads.
			[
				'id'        => 'uploads.drop_index',
				'label'     => __( 'Drop index.php in uploads (suppress directory listing)', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],
			[
				'id'        => 'uploads.block_php_execution',
				'label'     => __( 'Block PHP execution in uploads', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],

			// Login.
			[
				'id'        => 'login.obfuscate_errors',
				'label'     => __( 'Obfuscate login error messages', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => true,
				'sanitizer' => 'bool',
			],

			// File editing.
			[
				'id'        => 'file_editing.runtime_enforce',
				'label'     => __( 'Disable theme/plugin editor at runtime', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
				'help'      => __( 'Prefer adding DISALLOW_FILE_EDIT to wp-config.php. This toggle is a runtime fallback.', 'fanxie-warden' ),
			],

			// Application Passwords.
			[
				'id'        => 'application_passwords.disable',
				'label'     => __( 'Disable Application Passwords', 'fanxie-warden' ),
				'type'      => 'toggle',
				'default'   => false,
				'sanitizer' => 'bool',
				'help'      => __( 'Only recommended when no Application Passwords exist on the site.', 'fanxie-warden' ),
			],
		];
	}

	/**
	 * Constrain `xmlrpc.mode` to the whitelist, falling back to `disabled`.
	 *
	 * @param mixed $value Raw value from the payload.
	 */
	public function sanitize_xmlrpc_mode( mixed $value ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';

		return in_array( $value, XmlRpcGate::ALL_MODES, true ) ? $value : XmlRpcGate::MODE_DISABLED;
	}

	/**
	 * Clean + cap the XML-RPC IP allowlist.
	 *
	 * @param mixed $value Raw value from the payload.
	 * @return array<int, string>
	 */
	public function sanitize_allowed_ips( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', trim( $value ) );
		}
		if ( ! is_array( $value ) ) {
			return [];
		}

		$clean = [];
		foreach ( $value as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( false === filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( ! in_array( $candidate, $clean, true ) ) {
				$clean[] = $candidate;
			}
			if ( count( $clean ) >= 50 ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Register every hook the module needs.
	 *
	 * Instantiates the seven runtime classes + UploadsProtector + AJAX
	 * controller, each with a snapshot of the current config.
	 */
	public function register_hooks(): void {
		$config    = $this->get_config();
		$uploads   = $this->uploads_protector();
		$inspector = $this->status_inspector();

		// AJAX surface.
		$ajax = new AjaxController( $this, $inspector, $uploads );
		$ajax->register( $this->ajax_router );

		// Runtime emitters. Each one is idempotent — constructing them is
		// cheap, and the guard inside `register_hooks()` means no-op toggles
		// don't attach filters.
		( new UserEnumerationGuard( $config ) )->register_hooks();
		( new XmlRpcGate( $config ) )->register_hooks();
		( new VersionHider( $config ) )->register_hooks();
		( new HeaderStripper( $config ) )->register_hooks();
		( new LoginErrorObfuscator( $config ) )->register_hooks();
		( new FileEditGuard( $config ) )->register_hooks();
		( new ApplicationPasswordGate( $config ) )->register_hooks();
	}
}
