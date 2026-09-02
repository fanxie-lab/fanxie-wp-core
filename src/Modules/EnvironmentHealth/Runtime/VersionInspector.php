<?php
/**
 * Version + transport checks for the Environment Health module.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime;

use FanxieLab\WPCore\Modules\EnvironmentHealth\HealthCheck;
use FanxieLab\WPCore\Modules\EnvironmentHealth\SupportMatrix;

defined( 'ABSPATH' ) || exit;

/**
 * PRD §7.2 — WordPress, PHP, database, TLS certificate, and HTTPS checks.
 *
 * Every version verdict is delegated to {@see SupportMatrix} so status follows
 * from published end-of-support dates rather than from comparisons frozen into
 * this file. The WordPress check reads core's own `update_core` site transient
 * instead of calling api.wordpress.org itself — core already polls that
 * endpoint, so we get the answer for free and add no traffic.
 */
final class VersionInspector {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config      Module config snapshot.
	 * @param SslProbe             $ssl         TLS certificate probe.
	 * @param bool                 $allow_probe Whether a cold TLS cache may open a socket.
	 * @param bool                 $force       Whether to bypass the TLS cache entirely.
	 * @param int|null             $now         Evaluation timestamp (tests).
	 */
	public function __construct(
		private readonly array $config,
		private readonly SslProbe $ssl,
		private readonly bool $allow_probe = false,
		private readonly bool $force = false,
		private readonly ?int $now = null,
	) {}

	/**
	 * Build every check in the `versions` group.
	 *
	 * @return array<int, HealthCheck>
	 */
	public function checks(): array {
		return [
			$this->wordpress_check(),
			$this->php_check(),
			$this->database_check(),
			$this->ssl_check(),
			$this->https_check(),
		];
	}

	/**
	 * Current evaluation timestamp.
	 */
	private function now(): int {
		return null !== $this->now ? $this->now : time();
	}

	/**
	 * WordPress core version against the newest offer core already knows about.
	 */
	private function wordpress_check(): HealthCheck {
		$current = (string) get_bloginfo( 'version' );
		$latest  = $this->latest_core_version();

		if ( '' === $latest ) {
			return new HealthCheck(
				'wordpress_version',
				HealthCheck::GROUP_VERSIONS,
				__( 'WordPress version', 'fanxie-wp-core' ),
				HealthCheck::STATUS_UNKNOWN,
				$current,
				__( 'Could not determine the latest WordPress release.', 'fanxie-wp-core' ),
				__( 'WordPress has not yet stored the result of its own update check for this site, so there is nothing to compare against. Visit Dashboard → Updates to run one.', 'fanxie-wp-core' ),
				[],
				[ 'latest' => null ]
			);
		}

		if ( version_compare( $current, $latest, '>=' ) ) {
			return new HealthCheck(
				'wordpress_version',
				HealthCheck::GROUP_VERSIONS,
				__( 'WordPress version', 'fanxie-wp-core' ),
				HealthCheck::STATUS_OK,
				$current,
				__( 'Running the latest WordPress release.', 'fanxie-wp-core' ),
				'',
				[],
				[ 'latest' => $latest ]
			);
		}

		$minors_behind = $this->minor_releases_behind( $current, $latest );
		$same_branch   = 0 === $minors_behind;

		if ( $same_branch ) {
			// A patch release on the current branch is a maintenance/security
			// release — core does not ship feature work in those.
			$status  = HealthCheck::STATUS_CRITICAL;
			$summary = __( 'A maintenance or security release is available for your branch.', 'fanxie-wp-core' );
		} elseif ( 1 === $minors_behind ) {
			$status  = HealthCheck::STATUS_WARNING;
			$summary = __( 'One feature release behind.', 'fanxie-wp-core' );
		} else {
			$status  = HealthCheck::STATUS_CRITICAL;
			$summary = sprintf(
				/* translators: %d: number of WordPress feature releases the site is behind. */
				_n( '%d feature release behind.', '%d feature releases behind.', $minors_behind, 'fanxie-wp-core' ),
				$minors_behind
			);
		}

		return new HealthCheck(
			'wordpress_version',
			HealthCheck::GROUP_VERSIONS,
			__( 'WordPress version', 'fanxie-wp-core' ),
			$status,
			$current,
			$summary,
			sprintf(
				/* translators: %s: latest available WordPress version. */
				__( 'WordPress %s is available. Core updates carry security fixes; apply them on a staging copy first if the site is business-critical.', 'fanxie-wp-core' ),
				$latest
			),
			[
				HealthCheck::link( admin_url( 'update-core.php' ), __( 'Open Dashboard → Updates', 'fanxie-wp-core' ) ),
			],
			[
				'latest'        => $latest,
				'minors_behind' => $minors_behind,
			]
		);
	}

	/**
	 * PHP runtime against the support matrix.
	 */
	private function php_check(): HealthCheck {
		$version = PHP_VERSION;
		$verdict = SupportMatrix::php( $version, $this->now() );

		$remediation = [
			HealthCheck::link( 'https://www.php.net/supported-versions.php', __( 'PHP supported versions', 'fanxie-wp-core' ) ),
		];

		return new HealthCheck(
			'php_version',
			HealthCheck::GROUP_VERSIONS,
			__( 'PHP version', 'fanxie-wp-core' ),
			$verdict['status'],
			$version,
			$this->matrix_summary( $verdict, __( 'PHP', 'fanxie-wp-core' ) ),
			$this->matrix_detail( $verdict ),
			HealthCheck::STATUS_OK === $verdict['status'] ? [] : $remediation,
			$this->matrix_meta( $verdict )
		);
	}

	/**
	 * Database server (MySQL or MariaDB) against the support matrix.
	 */
	private function database_check(): HealthCheck {
		$server_info = $this->database_server_info();

		if ( '' === $server_info ) {
			return new HealthCheck(
				'database_version',
				HealthCheck::GROUP_VERSIONS,
				__( 'Database version', 'fanxie-wp-core' ),
				HealthCheck::STATUS_UNKNOWN,
				'',
				__( 'Could not read the database server version.', 'fanxie-wp-core' ),
				__( 'The database driver did not report a version string. This is normal on some managed or proxied database services.', 'fanxie-wp-core' ),
				[],
				[ 'server' => null ]
			);
		}

		$is_mariadb = SupportMatrix::is_mariadb( $server_info );
		$version    = $this->normalise_database_version( $server_info, $is_mariadb );
		$verdict    = $is_mariadb
			? SupportMatrix::mariadb( $version, $this->now() )
			: SupportMatrix::mysql( $version, $this->now() );

		$server_name = $is_mariadb ? __( 'MariaDB', 'fanxie-wp-core' ) : __( 'MySQL', 'fanxie-wp-core' );

		$meta           = $this->matrix_meta( $verdict );
		$meta['server'] = $is_mariadb ? 'mariadb' : 'mysql';

		return new HealthCheck(
			'database_version',
			HealthCheck::GROUP_VERSIONS,
			__( 'Database version', 'fanxie-wp-core' ),
			$verdict['status'],
			$server_name . ' ' . $version,
			$this->matrix_summary( $verdict, $server_name ),
			$this->matrix_detail( $verdict ),
			HealthCheck::STATUS_OK === $verdict['status']
				? []
				: [
					HealthCheck::link(
						$is_mariadb ? 'https://endoflife.date/mariadb' : 'https://endoflife.date/mysql',
						/* translators: %s: database server name, e.g. MySQL. */
						sprintf( __( '%s release lifecycle', 'fanxie-wp-core' ), $server_name )
					),
				],
			$meta
		);
	}

	/**
	 * TLS certificate expiry, degrading to `unknown` when the probe cannot run.
	 */
	private function ssl_check(): HealthCheck {
		$label = __( 'SSL certificate', 'fanxie-wp-core' );

		if ( ! $this->ssl_enabled() ) {
			return new HealthCheck(
				'ssl_certificate',
				HealthCheck::GROUP_VERSIONS,
				$label,
				HealthCheck::STATUS_UNKNOWN,
				'',
				__( 'Certificate checking is switched off.', 'fanxie-wp-core' ),
				'',
				[],
				[ 'enabled' => false ]
			);
		}

		$host = $this->site_host();
		if ( '' === $host ) {
			return new HealthCheck(
				'ssl_certificate',
				HealthCheck::GROUP_VERSIONS,
				$label,
				HealthCheck::STATUS_UNKNOWN,
				'',
				__( 'Could not determine this site’s hostname.', 'fanxie-wp-core' ),
				'',
				[],
				[ 'enabled' => true ]
			);
		}

		$snapshot = $this->ssl->snapshot( $host, 443, $this->allow_probe, $this->force );

		if ( null === $snapshot ) {
			return new HealthCheck(
				'ssl_certificate',
				HealthCheck::GROUP_VERSIONS,
				$label,
				HealthCheck::STATUS_UNKNOWN,
				'',
				__( 'Not checked yet.', 'fanxie-wp-core' ),
				__( 'The certificate is read on a schedule and on demand, never while a page is loading. Use Re-run checks to read it now.', 'fanxie-wp-core' ),
				[],
				[
					'enabled' => true,
					'host'    => $host,
				]
			);
		}

		if ( SslProbe::RESULT_READ !== $snapshot['result'] || null === $snapshot['expires_at'] ) {
			return new HealthCheck(
				'ssl_certificate',
				HealthCheck::GROUP_VERSIONS,
				$label,
				HealthCheck::STATUS_UNKNOWN,
				'',
				__( 'Couldn’t check — this host may block outbound connections.', 'fanxie-wp-core' ),
				__( 'We could not open a TLS connection back to this site to read its certificate. That is common on hosts that firewall outbound traffic, and it does not by itself mean anything is wrong with your certificate. Check it from outside the server to be sure.', 'fanxie-wp-core' ),
				[
					HealthCheck::link( 'https://www.ssllabs.com/ssltest/', __( 'Test this site with SSL Labs', 'fanxie-wp-core' ) ),
				],
				[
					'enabled'   => true,
					'host'      => $host,
					'result'    => $snapshot['result'],
					'message'   => $snapshot['message'],
					'probed_at' => $snapshot['probed_at'],
				]
			);
		}

		$expires_at     = (int) $snapshot['expires_at'];
		$days_remaining = (int) floor( ( $expires_at - $this->now() ) / DAY_IN_SECONDS );
		$warning_days   = $this->ssl_warning_days();
		$expires_label  = $this->format_date( $expires_at );

		if ( $days_remaining < 0 ) {
			$status  = HealthCheck::STATUS_CRITICAL;
			$summary = sprintf(
				/* translators: %s: certificate expiry date. */
				__( 'Certificate expired on %s.', 'fanxie-wp-core' ),
				$expires_label
			);
		} elseif ( $days_remaining <= $warning_days ) {
			$status  = HealthCheck::STATUS_WARNING;
			$summary = sprintf(
				/* translators: %d: number of days until the certificate expires. */
				_n( 'Certificate expires in %d day.', 'Certificate expires in %d days.', $days_remaining, 'fanxie-wp-core' ),
				$days_remaining
			);
		} else {
			$status  = HealthCheck::STATUS_OK;
			$summary = sprintf(
				/* translators: %s: certificate expiry date. */
				__( 'Certificate valid until %s.', 'fanxie-wp-core' ),
				$expires_label
			);
		}

		return new HealthCheck(
			'ssl_certificate',
			HealthCheck::GROUP_VERSIONS,
			$label,
			$status,
			$expires_label,
			$summary,
			HealthCheck::STATUS_OK === $status
				? ''
				: __( 'Most certificate authorities renew automatically about a month out. If yours does not, renew now — an expired certificate makes the whole site unreachable in every modern browser.', 'fanxie-wp-core' ),
			[],
			[
				'enabled'        => true,
				'host'           => $host,
				'expires_at'     => $expires_at,
				'days_remaining' => $days_remaining,
				'issuer'         => $snapshot['issuer'],
				'probed_at'      => $snapshot['probed_at'],
			]
		);
	}

	/**
	 * Whether the site is configured to serve itself over HTTPS.
	 *
	 * `is_ssl()` only describes the current request, which for a WP-Cron or
	 * WP-CLI invocation is meaningless — the durable signal is the scheme of
	 * both `home` and `siteurl`.
	 */
	private function https_check(): HealthCheck {
		$home     = (string) get_option( 'home', '' );
		$site_url = (string) get_option( 'siteurl', '' );

		$home_https = 'https' === wp_parse_url( $home, PHP_URL_SCHEME );
		$site_https = 'https' === wp_parse_url( $site_url, PHP_URL_SCHEME );

		if ( $home_https && $site_https ) {
			return new HealthCheck(
				'https_enforced',
				HealthCheck::GROUP_VERSIONS,
				__( 'HTTPS', 'fanxie-wp-core' ),
				HealthCheck::STATUS_OK,
				$home,
				__( 'Site and home URLs both use HTTPS.', 'fanxie-wp-core' ),
				'',
				[],
				[
					'home_https' => true,
					'site_https' => true,
				]
			);
		}

		return new HealthCheck(
			'https_enforced',
			HealthCheck::GROUP_VERSIONS,
			__( 'HTTPS', 'fanxie-wp-core' ),
			HealthCheck::STATUS_CRITICAL,
			'' !== $home ? $home : $site_url,
			$home_https || $site_https
				? __( 'Only one of the site and home URLs uses HTTPS.', 'fanxie-wp-core' )
				: __( 'This site is not served over HTTPS.', 'fanxie-wp-core' ),
			__( 'Passwords, cookies, and admin sessions travel in the clear without HTTPS, and mixed HTTP/HTTPS URLs break logins in subtle ways. Obtain a certificate, then update both URLs under Settings → General.', 'fanxie-wp-core' ),
			[
				HealthCheck::snippet(
					'php',
					"// wp-config.php — force HTTPS for the admin and login screens.\ndefine( 'FORCE_SSL_ADMIN', true );",
					__( 'Force HTTPS in wp-admin', 'fanxie-wp-core' )
				),
				HealthCheck::link( admin_url( 'options-general.php' ), __( 'Open Settings → General', 'fanxie-wp-core' ) ),
			],
			[
				'home_https' => $home_https,
				'site_https' => $site_https,
			]
		);
	}

	/**
	 * Newest core version core itself has already discovered.
	 *
	 * Reads the `update_core` site transient WordPress maintains — no request
	 * of our own is made.
	 */
	private function latest_core_version(): string {
		$updates = get_site_transient( 'update_core' );
		if ( ! is_object( $updates ) || ! isset( $updates->updates ) || ! is_array( $updates->updates ) ) {
			return '';
		}

		$latest = '';
		foreach ( $updates->updates as $offer ) {
			if ( ! is_object( $offer ) || ! isset( $offer->current ) || ! is_string( $offer->current ) ) {
				continue;
			}
			if ( '' === $latest || version_compare( $offer->current, $latest, '>' ) ) {
				$latest = $offer->current;
			}
		}

		return $latest;
	}

	/**
	 * How many WordPress feature (minor) releases separate two versions.
	 *
	 * WordPress numbers feature releases `major.minor` and rolls the major
	 * over once the minor passes 9 (5.9 → 6.0), so treating each major as ten
	 * slots gives a correct distance for every release since 3.0. A patch-only
	 * difference returns 0, which the caller reads as "maintenance release".
	 *
	 * @param string $current Installed version.
	 * @param string $latest  Newest available version.
	 */
	private function minor_releases_behind( string $current, string $latest ): int {
		$index = static function ( string $version ): ?int {
			if ( 1 !== preg_match( '/^(\d+)\.(\d+)/', $version, $matches ) ) {
				return null;
			}
			return ( (int) $matches[1] * 10 ) + (int) $matches[2];
		};

		$current_index = $index( $current );
		$latest_index  = $index( $latest );

		if ( null === $current_index || null === $latest_index ) {
			return 0;
		}

		return max( 0, $latest_index - $current_index );
	}

	/**
	 * Raw server version string from `$wpdb`.
	 */
	private function database_server_info(): string {
		/**
		 * WordPress database handle.
		 *
		 * @var \wpdb|null $wpdb
		 */
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return '';
		}

		$info = $wpdb->db_server_info();
		if ( is_string( $info ) && '' !== $info ) {
			return $info;
		}

		$version = $wpdb->db_version();

		return is_string( $version ) ? $version : '';
	}

	/**
	 * Strip the compatibility prefix MariaDB reports through the MySQL protocol.
	 *
	 * MariaDB ≥ 10 answers `mysqli_get_server_info()` with a version string
	 * prefixed `5.5.5-` so ancient clients keep working. Taking that at face
	 * value — which `wpdb::db_version()` does — would place a modern MariaDB
	 * in the MySQL 5.5 row of the matrix and report a decade-old EOL.
	 *
	 * @param string $server_info Raw server info string.
	 * @param bool   $is_mariadb  Whether the server identified as MariaDB.
	 */
	private function normalise_database_version( string $server_info, bool $is_mariadb ): string {
		$version = $server_info;

		if ( $is_mariadb && str_starts_with( $version, '5.5.5-' ) ) {
			$version = substr( $version, 6 );
		}

		if ( 1 === preg_match( '/^\d+(?:\.\d+){1,2}/', $version, $matches ) ) {
			return $matches[0];
		}

		return $version;
	}

	/**
	 * One-line verdict for a support-matrix result.
	 *
	 * @param array{branch: string, status: string, phase: string, active_until: string|null, security_until: string|null, days_remaining: int|null} $verdict Matrix verdict.
	 * @param string                                                                                                                                 $name    Translated software name.
	 */
	private function matrix_summary( array $verdict, string $name ): string {
		$security_until = null !== $verdict['security_until'] ? $this->format_date_string( $verdict['security_until'] ) : '';

		switch ( $verdict['phase'] ) {
			case SupportMatrix::PHASE_EOL:
				return sprintf(
					/* translators: 1: software name, e.g. PHP. 2: branch number, e.g. 8.1. 3: end-of-life date. */
					__( '%1$s %2$s reached end of life on %3$s and receives no further security fixes.', 'fanxie-wp-core' ),
					$name,
					$verdict['branch'],
					$security_until
				);

			case SupportMatrix::PHASE_SECURITY:
				return sprintf(
					/* translators: 1: software name, e.g. PHP. 2: branch number. 3: end of security support date. */
					__( '%1$s %2$s is in security-only support until %3$s.', 'fanxie-wp-core' ),
					$name,
					$verdict['branch'],
					$security_until
				);

			case SupportMatrix::PHASE_ACTIVE:
				if ( HealthCheck::STATUS_WARNING === $verdict['status'] ) {
					return sprintf(
						/* translators: 1: software name. 2: branch number. 3: end of support date. */
						__( '%1$s %2$s is supported, but support ends on %3$s.', 'fanxie-wp-core' ),
						$name,
						$verdict['branch'],
						$security_until
					);
				}

				return sprintf(
					/* translators: 1: software name. 2: branch number. 3: end of support date. */
					__( '%1$s %2$s is fully supported until %3$s.', 'fanxie-wp-core' ),
					$name,
					$verdict['branch'],
					$security_until
				);

			default:
				return sprintf(
					/* translators: 1: software name. 2: branch number. */
					__( '%1$s %2$s is not in our support matrix — we cannot say whether it is still supported.', 'fanxie-wp-core' ),
					$name,
					'' !== $verdict['branch'] ? $verdict['branch'] : '?'
				);
		}
	}

	/**
	 * Longer prose for a support-matrix result.
	 *
	 * @param array{branch: string, status: string, phase: string, active_until: string|null, security_until: string|null, days_remaining: int|null} $verdict Matrix verdict.
	 */
	private function matrix_detail( array $verdict ): string {
		if ( SupportMatrix::PHASE_UNKNOWN === $verdict['phase'] ) {
			return sprintf(
				/* translators: %s: date the support matrix was last reviewed. */
				__( 'Our support matrix was last reviewed on %s. A release newer than that will show as unknown until the matrix is updated — that is deliberate, so you are never shown a guess dressed up as a verdict.', 'fanxie-wp-core' ),
				$this->format_date_string( SupportMatrix::REVIEWED_ON )
			);
		}

		if ( SupportMatrix::PHASE_EOL === $verdict['phase'] ) {
			return __( 'Known vulnerabilities found after the end-of-life date are never patched on this branch. Upgrading is the only fix; there is no configuration that makes an unsupported runtime safe.', 'fanxie-wp-core' );
		}

		if ( SupportMatrix::PHASE_SECURITY === $verdict['phase'] ) {
			return __( 'Security fixes still arrive, but bug fixes have stopped. Plan the upgrade before the security window closes rather than after.', 'fanxie-wp-core' );
		}

		return '';
	}

	/**
	 * Scalar meta map for a support-matrix result.
	 *
	 * @param array{branch: string, status: string, phase: string, active_until: string|null, security_until: string|null, days_remaining: int|null} $verdict Matrix verdict.
	 *
	 * @return array<string, scalar|null>
	 */
	private function matrix_meta( array $verdict ): array {
		return [
			'branch'          => $verdict['branch'],
			'phase'           => $verdict['phase'],
			'active_until'    => $verdict['active_until'],
			'security_until'  => $verdict['security_until'],
			'days_remaining'  => $verdict['days_remaining'],
			'matrix_reviewed' => SupportMatrix::REVIEWED_ON,
		];
	}

	/**
	 * Whether the TLS check is switched on in the module config.
	 */
	private function ssl_enabled(): bool {
		return (bool) ( $this->config['ssl_check_enabled'] ?? true );
	}

	/**
	 * Configured "expiring soon" window in days.
	 */
	private function ssl_warning_days(): int {
		$thresholds = isset( $this->config['thresholds'] ) && is_array( $this->config['thresholds'] )
			? $this->config['thresholds']
			: [];

		$days = isset( $thresholds['ssl_expiry_warning_days'] ) ? (int) $thresholds['ssl_expiry_warning_days'] : 30;

		return max( 1, $days );
	}

	/**
	 * Hostname of the site's home URL.
	 */
	private function site_host(): string {
		$host = wp_parse_url( (string) home_url( '/' ), PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}

	/**
	 * Format a timestamp using the site's date format.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function format_date( int $timestamp ): string {
		return (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp );
	}

	/**
	 * Format a `Y-m-d` string using the site's date format.
	 *
	 * @param string $date Date string.
	 */
	private function format_date_string( string $date ): string {
		$timestamp = strtotime( $date . ' 12:00:00 UTC' );

		return false === $timestamp ? $date : $this->format_date( $timestamp );
	}
}
