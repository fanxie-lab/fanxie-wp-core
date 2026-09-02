<?php
/**
 * Plugin and theme hygiene checks for the Environment Health module.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime;

use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;

defined( 'ABSPATH' ) || exit;

/**
 * PRD §7.5 — installed-but-unused code, and code nobody maintains any more.
 *
 * The first two checks are local and free. The third reads whatever the
 * throttled {@see WporgScanner} has cached; it never triggers a lookup itself,
 * so building a report — including on a dashboard render — performs no network
 * I/O at all.
 */
final class PluginThemeInspector {

	/**
	 * Maximum names listed in a check's `meta` payload.
	 *
	 * @var int
	 */
	private const MAX_LISTED = 15;

	/**
	 * Above this many items, a summary sentence stops naming them inline.
	 *
	 * Naming two or three things reads well and saves the user a click; naming
	 * fifteen produces an unreadable one-liner. Past this threshold the summary
	 * falls back to a bare count and the names live in `meta`, where the admin
	 * UI lists them in the expanded detail.
	 *
	 * @var int
	 */
	private const MAX_NAMED_INLINE = 3;

	/**
	 * Memoised `get_plugins()` result.
	 *
	 * Two of the three checks need the plugin headers and building a report is
	 * on the dashboard render path, so read the directory once per instance.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $installed_plugins_cache = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config  Module config snapshot.
	 * @param WporgScanner         $scanner wp.org freshness cache reader.
	 * @param int|null             $now     Evaluation timestamp (tests).
	 */
	public function __construct(
		private readonly array $config,
		private readonly WporgScanner $scanner,
		private readonly ?int $now = null,
	) {}

	/**
	 * Build every check in the `plugins_themes` group.
	 *
	 * @return array<int, HealthCheck>
	 */
	public function checks(): array {
		return [
			$this->inactive_plugins_check(),
			$this->inactive_themes_check(),
			$this->abandoned_plugins_check(),
		];
	}

	/**
	 * Slugs of every plugin currently active on this site.
	 *
	 * Network-activated plugins count as active on multisite, otherwise every
	 * subsite would report the network's plugins as dead weight.
	 *
	 * @return array<int, string>
	 */
	public function active_plugin_slugs(): array {
		$slugs = [];

		foreach ( array_keys( $this->active_plugins() ) as $file ) {
			$slug = $this->slug_from_file( $file );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Current evaluation timestamp.
	 */
	private function now(): int {
		return null !== $this->now ? $this->now : time();
	}

	/**
	 * Installed plugins that are not active on this site.
	 */
	private function inactive_plugins_check(): HealthCheck {
		$installed = $this->installed_plugins();
		$active    = $this->active_plugins();
		$inactive  = array_diff_key( $installed, $active );
		$count     = count( $inactive );

		$names = [];
		foreach ( $inactive as $file => $data ) {
			$names[] = $this->plugin_name( (string) $file, $data );
		}

		[ $listed, $omitted ] = $this->cap( $names );

		if ( 0 === $count ) {
			return new HealthCheck(
				'inactive_plugins',
				HealthCheck::GROUP_PLUGINS_THEMES,
				__( 'Inactive plugins', 'fanxie-wp-core' ),
				HealthCheck::STATUS_OK,
				'0',
				__( 'Every installed plugin is in use.', 'fanxie-wp-core' ),
				'',
				[],
				[
					'total_installed' => count( $installed ),
					'names'           => [],
					'names_omitted'   => 0,
				]
			);
		}

		return new HealthCheck(
			'inactive_plugins',
			HealthCheck::GROUP_PLUGINS_THEMES,
			__( 'Inactive plugins', 'fanxie-wp-core' ),
			HealthCheck::STATUS_WARNING,
			(string) $count,
			$count <= self::MAX_NAMED_INLINE
				? sprintf(
					/* translators: 1: number of inactive plugins, 2: comma-separated list of those plugin names (e.g. "Akismet, Hello Dolly"). */
					_n(
						'%1$d installed plugin is not active: %2$s.',
						'%1$d installed plugins are not active: %2$s.',
						$count,
						'fanxie-wp-core'
					),
					$count,
					$this->join_names( $listed )
				)
				: sprintf(
					/* translators: %d: number of inactive plugins. */
					_n( '%d installed plugin is not active.', '%d installed plugins are not active.', $count, 'fanxie-wp-core' ),
					$count
				),
			__( 'A deactivated plugin still has its PHP files on disk, and a vulnerability in a directly-reachable file does not care whether the plugin is switched on. Deactivated code also stops being watched: it is the code people forget to update. Delete what you are not using; the plugin can always be reinstalled.', 'fanxie-wp-core' ),
			[
				HealthCheck::link( admin_url( 'plugins.php?plugin_status=inactive' ), __( 'Review inactive plugins', 'fanxie-wp-core' ) ),
			],
			[
				'total_installed' => count( $installed ),
				'names'           => $listed,
				'names_omitted'   => $omitted,
			]
		);
	}

	/**
	 * Installed themes that are neither active nor a WordPress default.
	 */
	private function inactive_themes_check(): HealthCheck {
		$themes = wp_get_themes();
		$active = wp_get_theme();

		$keep = [];
		if ( $active->exists() ) {
			$keep[] = $active->get_stylesheet();
			$parent = $active->parent();
			if ( $parent instanceof \WP_Theme ) {
				$keep[] = $parent->get_stylesheet();
			}
		}

		$inactive_default = 0;
		$removable        = [];

		foreach ( $themes as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;
			if ( in_array( $stylesheet, $keep, true ) ) {
				continue;
			}

			// One current WordPress default is kept deliberately: it is the
			// fallback core switches to when the active theme fatals, so
			// deleting every default turns a theme bug into a white screen.
			if ( $this->is_default_theme( $stylesheet ) ) {
				++$inactive_default;
				continue;
			}

			$name        = $theme->get( 'Name' );
			$removable[] = is_string( $name ) && '' !== $name ? $name : $stylesheet;
		}

		$count                = count( $removable );
		[ $listed, $omitted ] = $this->cap( $removable );

		if ( 0 === $count ) {
			return new HealthCheck(
				'inactive_themes',
				HealthCheck::GROUP_PLUGINS_THEMES,
				__( 'Inactive themes', 'fanxie-wp-core' ),
				HealthCheck::STATUS_OK,
				'0',
				__( 'Only the active theme and a default fallback are installed.', 'fanxie-wp-core' ),
				'',
				[],
				[
					'total_installed'  => count( $themes ),
					'default_retained' => $inactive_default,
					'names'            => [],
					'names_omitted'    => 0,
				]
			);
		}

		return new HealthCheck(
			'inactive_themes',
			HealthCheck::GROUP_PLUGINS_THEMES,
			__( 'Inactive themes', 'fanxie-wp-core' ),
			HealthCheck::STATUS_WARNING,
			(string) $count,
			$count <= self::MAX_NAMED_INLINE
				? sprintf(
					/* translators: 1: number of inactive non-default themes, 2: comma-separated list of those theme names (e.g. "Twenty Twenty-Three, Twenty Twenty-Two"). */
					_n(
						'%1$d unused theme is installed: %2$s.',
						'%1$d unused themes are installed: %2$s.',
						$count,
						'fanxie-wp-core'
					),
					$count,
					$this->join_names( $listed )
				)
				: sprintf(
					/* translators: %d: number of inactive non-default themes. */
					_n( '%d unused theme is installed.', '%d unused themes are installed.', $count, 'fanxie-wp-core' ),
					$count
				),
			__( 'Unused themes are attack surface with no upside, and their template files have historically been a source of vulnerabilities. Keep the active theme, its parent if it has one, and one current WordPress default as a recovery fallback — delete the rest.', 'fanxie-wp-core' ),
			[
				HealthCheck::link( admin_url( 'themes.php' ), __( 'Review installed themes', 'fanxie-wp-core' ) ),
			],
			[
				'total_installed'  => count( $themes ),
				'default_retained' => $inactive_default,
				'names'            => $listed,
				'names_omitted'    => $omitted,
			]
		);
	}

	/**
	 * Active plugins whose wp.org listing has not been updated in a long time.
	 *
	 * Reads only the scanner's cache. Plugins with no cached answer yet are
	 * reported as pending rather than as a pass, and plugins that wp.org has
	 * never heard of (`not_on_wporg` — premium and bespoke plugins) are
	 * excluded from the verdict entirely: absence from the directory says
	 * nothing about whether a plugin is maintained.
	 */
	private function abandoned_plugins_check(): HealthCheck {
		$label = __( 'Abandoned plugins', 'fanxie-wp-core' );

		if ( ! $this->wporg_scan_enabled() ) {
			return new HealthCheck(
				'abandoned_plugins',
				HealthCheck::GROUP_PLUGINS_THEMES,
				$label,
				HealthCheck::STATUS_UNKNOWN,
				'',
				__( 'The wordpress.org freshness check is switched off.', 'fanxie-wp-core' ),
				__( 'No request is made to wordpress.org while this setting is off. Turn it on to be told when an active plugin has gone a year or more without an update.', 'fanxie-wp-core' ),
				[],
				[ 'enabled' => false ]
			);
		}

		$slugs   = $this->active_plugin_slugs();
		$results = $this->scanner->results();
		$now     = $this->now();

		$warning_after  = $this->threshold( 'abandoned_warning_days', 365 );
		$critical_after = $this->threshold( 'abandoned_critical_days', 730 );

		$names        = $this->plugin_names_by_slug();
		$critical     = [];
		$warning      = [];
		$not_on_wporg = 0;
		$errors       = 0;
		$pending      = 0;

		foreach ( $slugs as $slug ) {
			$entry = $results[ $slug ] ?? null;

			if ( null === $entry ) {
				++$pending;
				continue;
			}

			if ( WporgScanner::STATE_NOT_ON_WPORG === $entry['state'] ) {
				++$not_on_wporg;
				continue;
			}

			if ( WporgScanner::STATE_FOUND !== $entry['state'] || null === $entry['last_updated'] ) {
				++$errors;
				continue;
			}

			$age_days = (int) floor( ( $now - $entry['last_updated'] ) / DAY_IN_SECONDS );

			if ( $age_days >= $critical_after ) {
				$critical[] = $names[ $slug ] ?? $slug;
			} elseif ( $age_days >= $warning_after ) {
				$warning[] = $names[ $slug ] ?? $slug;
			}
		}

		[ $critical_listed, $critical_omitted ] = $this->cap( $critical );
		[ $warning_listed, $warning_omitted ]   = $this->cap( $warning );

		$meta = [
			'enabled'          => true,
			'checked'          => count( $slugs ) - $pending,
			'pending'          => $pending,
			'not_on_wporg'     => $not_on_wporg,
			'errors'           => $errors,
			'critical_names'   => $critical_listed,
			'critical_omitted' => $critical_omitted,
			'warning_names'    => $warning_listed,
			'warning_omitted'  => $warning_omitted,
			'warning_after'    => $warning_after,
			'critical_after'   => $critical_after,
		];

		if ( [] !== $critical || [] !== $warning ) {
			$count  = count( $critical ) + count( $warning );
			$status = [] !== $critical ? HealthCheck::STATUS_CRITICAL : HealthCheck::STATUS_WARNING;

			return new HealthCheck(
				'abandoned_plugins',
				HealthCheck::GROUP_PLUGINS_THEMES,
				$label,
				$status,
				(string) $count,
				$count <= self::MAX_NAMED_INLINE
					? sprintf(
						/* translators: 1: number of stale active plugins, 2: comma-separated list of those plugin names (e.g. "Contact Form 7, Akismet"). */
						_n(
							'%1$d active plugin has not been updated in over a year: %2$s.',
							'%1$d active plugins have not been updated in over a year: %2$s.',
							$count,
							'fanxie-wp-core'
						),
						$count,
						$this->join_names( array_merge( $critical_listed, $warning_listed ) )
					)
					: sprintf(
						/* translators: %d: number of stale active plugins. */
						_n(
							'%d active plugin has not been updated in over a year.',
							'%d active plugins have not been updated in over a year.',
							$count,
							'fanxie-wp-core'
						),
						$count
					),
				__( 'An unmaintained plugin will not be patched when the next vulnerability is found in it, and it will eventually break on a new PHP or WordPress release. Look for a maintained alternative before you need one urgently. Premium plugins are excluded from this check — wordpress.org has no record of them.', 'fanxie-wp-core' ),
				[
					HealthCheck::link( admin_url( 'plugins.php' ), __( 'Review active plugins', 'fanxie-wp-core' ) ),
				],
				$meta
			);
		}

		if ( count( $slugs ) > 0 && count( $slugs ) === $pending ) {
			return new HealthCheck(
				'abandoned_plugins',
				HealthCheck::GROUP_PLUGINS_THEMES,
				$label,
				HealthCheck::STATUS_UNKNOWN,
				'',
				__( 'Not checked yet.', 'fanxie-wp-core' ),
				__( 'Plugins are looked up on wordpress.org a few at a time, on a schedule, so a large site never fires dozens of requests at once. Results appear here as each batch completes.', 'fanxie-wp-core' ),
				[],
				$meta
			);
		}

		return new HealthCheck(
			'abandoned_plugins',
			HealthCheck::GROUP_PLUGINS_THEMES,
			$label,
			HealthCheck::STATUS_OK,
			'0',
			$pending > 0
				? sprintf(
					/* translators: %d: number of plugins still queued for a wordpress.org lookup. */
					_n(
						'No abandoned plugins so far; %d still to check.',
						'No abandoned plugins so far; %d still to check.',
						$pending,
						'fanxie-wp-core'
					),
					$pending
				)
				: __( 'Every active plugin listed on wordpress.org has been updated within the last year.', 'fanxie-wp-core' ),
			'',
			[],
			$meta
		);
	}

	/**
	 * Cap a name list at {@see MAX_LISTED}, reporting how many were dropped.
	 *
	 * The caller emits both halves: the UI needs the count of omitted names to
	 * render a "+N more" affordance without doing arithmetic on a display
	 * string, and a truncated list with no such marker silently lies about how
	 * much there is to clean up.
	 *
	 * @param array<int, string> $names Every matching name, in display order.
	 *
	 * @return array{0: list<string>, 1: int} Listed names, then the count omitted.
	 */
	private function cap( array $names ): array {
		$listed = array_slice( array_values( $names ), 0, self::MAX_LISTED );

		return [ $listed, count( $names ) - count( $listed ) ];
	}

	/**
	 * Join names for an inline summary sentence.
	 *
	 * Only ever used for {@see MAX_NAMED_INLINE} names or fewer — `meta` keeps
	 * the list as a real array, because names legitimately contain commas and a
	 * joined string cannot be split back apart safely.
	 *
	 * @param array<int, string> $names Names to join.
	 */
	private function join_names( array $names ): string {
		return implode( _x( ', ', 'separator between item names in a summary sentence', 'fanxie-wp-core' ), $names );
	}

	/**
	 * Display name for a plugin: its header `Name`, or its slug as a fallback.
	 *
	 * A plugin with an unreadable or empty header still has to be nameable, and
	 * the directory slug is the next most recognisable thing about it.
	 *
	 * @param string               $file Plugin file, relative to the plugins directory.
	 * @param array<string, mixed> $data Plugin header data from `get_plugins()`.
	 */
	private function plugin_name( string $file, array $data ): string {
		$name = isset( $data['Name'] ) && is_string( $data['Name'] ) ? trim( $data['Name'] ) : '';

		return '' !== $name ? $name : $this->slug_from_file( $file );
	}

	/**
	 * Display names for every installed plugin, keyed by directory slug.
	 *
	 * The wp.org scanner works in slugs; users recognise "Contact Form 7", not
	 * `contact-form-7`. Where two plugin files resolve to the same slug the
	 * first wins — the alternative is inventing a distinction the user cannot
	 * see anyway.
	 *
	 * @return array<string, string>
	 */
	private function plugin_names_by_slug(): array {
		$map = [];

		foreach ( $this->installed_plugins() as $file => $data ) {
			$slug = $this->slug_from_file( (string) $file );

			if ( '' === $slug || isset( $map[ $slug ] ) ) {
				continue;
			}

			$map[ $slug ] = $this->plugin_name( (string) $file, $data );
		}

		return $map;
	}

	/**
	 * Every installed plugin, keyed by plugin file.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function installed_plugins(): array {
		if ( null !== $this->installed_plugins_cache ) {
			return $this->installed_plugins_cache;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$this->installed_plugins_cache = get_plugins();

		return $this->installed_plugins_cache;
	}

	/**
	 * Every active plugin file, as a set keyed by plugin file.
	 *
	 * @return array<string, true>
	 */
	private function active_plugins(): array {
		$active = get_option( 'active_plugins', [] );
		$active = is_array( $active ) ? $active : [];

		$set = [];
		foreach ( $active as $file ) {
			if ( is_string( $file ) && '' !== $file ) {
				$set[ $file ] = true;
			}
		}

		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', [] );
			if ( is_array( $network ) ) {
				foreach ( array_keys( $network ) as $file ) {
					if ( is_string( $file ) && '' !== $file ) {
						$set[ $file ] = true;
					}
				}
			}
		}

		return $set;
	}

	/**
	 * Directory-name slug for a plugin file (`akismet/akismet.php` → `akismet`).
	 *
	 * Single-file plugins living directly in `wp-content/plugins` fall back to
	 * the basename without its extension.
	 *
	 * @param string $file Plugin file, relative to the plugins directory.
	 */
	private function slug_from_file( string $file ): string {
		$directory = dirname( $file );

		if ( '.' !== $directory && '' !== $directory && '/' !== $directory ) {
			return $directory;
		}

		return (string) preg_replace( '/\.php$/', '', basename( $file ) );
	}

	/**
	 * Whether a stylesheet directory is one of WordPress's bundled themes.
	 *
	 * Matching on the `twenty*` prefix rather than an enumerated list means a
	 * default shipped after this release is still recognised.
	 *
	 * @param string $stylesheet Theme stylesheet directory.
	 */
	private function is_default_theme( string $stylesheet ): bool {
		return 1 === preg_match( '/^twenty[a-z-]*$/i', $stylesheet );
	}

	/**
	 * Whether the wp.org freshness scan is switched on.
	 */
	private function wporg_scan_enabled(): bool {
		return (bool) ( $this->config['wporg_scan_enabled'] ?? true );
	}

	/**
	 * Read a positive integer threshold from config.
	 *
	 * @param string $key      Threshold key.
	 * @param int    $fallback Default when unset or invalid.
	 */
	private function threshold( string $key, int $fallback ): int {
		$thresholds = isset( $this->config['thresholds'] ) && is_array( $this->config['thresholds'] )
			? $this->config['thresholds']
			: [];

		$value = isset( $thresholds[ $key ] ) ? (int) $thresholds[ $key ] : $fallback;

		return $value > 0 ? $value : $fallback;
	}
}
