<?php
/**
 * TLS certificate expiry probe.
 *
 * @package FanxieLab\Warden\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\EnvironmentHealth\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the leaf certificate presented by the site's own host and reports how
 * long it has left (PRD §7.2, "SSL Certificate").
 *
 * Deliberately conservative, because this is the one check in the module that
 * opens a raw socket:
 *
 *   - **Never blocking a page render.** The probe only runs when the caller
 *     explicitly passes `$allow_probe`, which happens on the scheduled scan
 *     and on an operator-triggered refresh — never on a dashboard render.
 *   - **Short timeout.** {@see self::CONNECT_TIMEOUT_SEC} seconds, once.
 *   - **Cached** for {@see self::CACHE_TTL_SEC} (12 hours).
 *   - **Graceful degradation.** A socket that will not open yields an
 *     `unknown` result carrying the reason, not a false "certificate missing"
 *     alarm. Plenty of hosts block outbound connections — including back to
 *     themselves — and a security plugin that cries wolf gets ignored.
 *
 * Peer verification is intentionally disabled: the goal is to *read* the
 * certificate the host serves, including an expired or self-signed one, and
 * report on it. Verification failures would return no certificate at all,
 * which is strictly less information.
 */
final class SslProbe {

	/**
	 * Transient holding the last probe result.
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'fanxie_warden_environment_health_ssl';

	/**
	 * Cache lifetime in seconds (12 hours).
	 *
	 * @var int
	 */
	public const CACHE_TTL_SEC = 43200;

	/**
	 * Socket connect + TLS handshake budget, in seconds.
	 *
	 * @var int
	 */
	public const CONNECT_TIMEOUT_SEC = 5;

	/**
	 * Probe outcome: a certificate was read.
	 *
	 * @var string
	 */
	public const RESULT_READ = 'read';

	/**
	 * Probe outcome: the host could not be reached or offered no certificate.
	 *
	 * @var string
	 */
	public const RESULT_UNREACHABLE = 'unreachable';

	/**
	 * Probe outcome: the environment cannot perform TLS probes at all
	 * (no OpenSSL extension, or `stream_socket_client` disabled).
	 *
	 * @var string
	 */
	public const RESULT_UNSUPPORTED = 'unsupported';

	/**
	 * Return the cached probe result, optionally refreshing it.
	 *
	 * @param string $host        Hostname to probe.
	 * @param int    $port        TLS port.
	 * @param bool   $allow_probe Permit a live socket connection when the cache is cold.
	 * @param bool   $force       Bypass the cache entirely (implies `$allow_probe`).
	 *
	 * @return array{result: string, expires_at: int|null, starts_at: int|null, issuer: string|null, message: string, probed_at: int}|null
	 *         Null when nothing is cached and probing was not permitted.
	 */
	public function snapshot( string $host, int $port = 443, bool $allow_probe = false, bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['result'], $cached['probed_at'] ) ) {
				/**
				 * Cached probe payload, narrowed by the shape guard above.
				 *
				 * @var array{result: string, expires_at: int|null, starts_at: int|null, issuer: string|null, message: string, probed_at: int} $cached
				 */
				return $cached;
			}
		}

		if ( ! $allow_probe && ! $force ) {
			return null;
		}

		$snapshot = $this->probe( $host, $port );
		set_transient( self::CACHE_KEY, $snapshot, self::CACHE_TTL_SEC );

		return $snapshot;
	}

	/**
	 * Drop the cached result so the next permitted read re-probes.
	 */
	public function invalidate_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Open one short-lived TLS connection and parse the peer certificate.
	 *
	 * @param string $host Hostname.
	 * @param int    $port TLS port.
	 *
	 * @return array{result: string, expires_at: int|null, starts_at: int|null, issuer: string|null, message: string, probed_at: int}
	 */
	private function probe( string $host, int $port ): array {
		$failure = function ( string $result, string $message ): array {
			return [
				'result'     => $result,
				'expires_at' => null,
				'starts_at'  => null,
				'issuer'     => null,
				'message'    => $message,
				'probed_at'  => time(),
			];
		};

		if ( ! function_exists( 'openssl_x509_parse' ) || ! function_exists( 'stream_socket_client' ) ) {
			return $failure( self::RESULT_UNSUPPORTED, 'openssl_or_sockets_unavailable' );
		}

		if ( '' === $host ) {
			return $failure( self::RESULT_UNSUPPORTED, 'no_host' );
		}

		$context = stream_context_create(
			[
				'ssl' => [
					// Read whatever the host serves — including an expired or
					// self-signed chain, which is precisely what we want to warn about.
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				],
			]
		);

		$errno  = 0;
		$errstr = '';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a refused/filtered outbound connection raises a PHP warning we deliberately swallow: the failure is reported to the operator as an `unknown` status, not as noise in their error log.
		$client = @stream_socket_client(
			'ssl://' . $host . ':' . $port,
			$errno,
			$errstr,
			self::CONNECT_TIMEOUT_SEC,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ( ! is_resource( $client ) ) {
			return $failure(
				self::RESULT_UNREACHABLE,
				is_string( $errstr ) && '' !== $errstr ? $errstr : 'connection_failed'
			);
		}

		$params = stream_context_get_params( $client );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem abstracts files on disk; this handle is a TLS socket it has no API for.
		fclose( $client );

		$certificate = null;
		$ssl_options = $params['options']['ssl'] ?? null;
		if ( is_array( $ssl_options ) && isset( $ssl_options['peer_certificate'] ) ) {
			$certificate = $ssl_options['peer_certificate'];
		}

		if ( null === $certificate ) {
			return $failure( self::RESULT_UNREACHABLE, 'no_peer_certificate' );
		}

		$parsed = openssl_x509_parse( $certificate );
		if ( ! is_array( $parsed ) ) {
			return $failure( self::RESULT_UNREACHABLE, 'unparseable_certificate' );
		}

		$issuer = null;
		if ( isset( $parsed['issuer'] ) && is_array( $parsed['issuer'] ) ) {
			$organisation = $parsed['issuer']['O'] ?? ( $parsed['issuer']['CN'] ?? null );
			$issuer       = is_string( $organisation ) ? $organisation : null;
		}

		return [
			'result'     => self::RESULT_READ,
			'expires_at' => isset( $parsed['validTo_time_t'] ) ? (int) $parsed['validTo_time_t'] : null,
			'starts_at'  => isset( $parsed['validFrom_time_t'] ) ? (int) $parsed['validFrom_time_t'] : null,
			'issuer'     => $issuer,
			'message'    => '',
			'probed_at'  => time(),
		];
	}
}
