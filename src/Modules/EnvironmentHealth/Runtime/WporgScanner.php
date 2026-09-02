<?php
/**
 * Throttled api.wordpress.org plugin freshness scanner.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up each active plugin's `last_updated` date on api.wordpress.org so
 * abandoned plugins can be surfaced (PRD §7.5.3).
 *
 * This is the module's only outbound call to a third party, and it is
 * disclosed in `readme.txt` under "External services". Design constraints that
 * keep it defensible:
 *
 *   - **Opt-out.** `wporg_scan_enabled` turns it off completely; when off,
 *     nothing is ever requested and cached rows are discarded.
 *   - **Never synchronous with a page render.** Scanning happens on the
 *     module's scheduled event, or on an explicit operator refresh. Report
 *     generation only ever *reads* the cache.
 *   - **Throttled.** At most `$budget` slugs per invocation, so a 60-plugin
 *     site never fires 60 blocking requests at once. When the batch runs out
 *     the caller reschedules and the remainder is picked up next pass.
 *   - **Cached 24h** per slug (errors far shorter, so a blip does not stick).
 *
 * Results are held in an option rather than a transient on purpose: partial
 * progress across batches must survive an object-cache flush, otherwise a
 * large site could never finish a scan.
 */
final class WporgScanner {

	/**
	 * Option holding the slug → result map.
	 *
	 * @var string
	 */
	public const CACHE_OPTION = 'fanxie_wp_core_environment_health_wporg_cache';

	/**
	 * The api.wordpress.org plugin information endpoint.
	 *
	 * @var string
	 */
	public const API_ENDPOINT = 'https://api.wordpress.org/plugins/info/1.2/';

	/**
	 * Freshness window for a successful lookup, in seconds (24 hours).
	 *
	 * @var int
	 */
	public const CACHE_TTL_SEC = 86400;

	/**
	 * Freshness window for a failed lookup, in seconds (1 hour).
	 *
	 * A transport error is usually transient; re-trying tomorrow would leave
	 * the operator staring at `unknown` for a day over a one-second blip.
	 *
	 * @var int
	 */
	public const ERROR_TTL_SEC = 3600;

	/**
	 * Per-request timeout, in seconds.
	 *
	 * @var int
	 */
	public const REQUEST_TIMEOUT_SEC = 5;

	/**
	 * Slugs looked up per scheduled pass.
	 *
	 * @var int
	 */
	public const SCHEDULED_BATCH = 25;

	/**
	 * Slugs looked up per operator-triggered refresh — smaller, because the
	 * operator is watching a spinner.
	 *
	 * @var int
	 */
	public const INTERACTIVE_BATCH = 5;

	/**
	 * Entry state: the plugin was found on wp.org.
	 *
	 * @var string
	 */
	public const STATE_FOUND = 'found';

	/**
	 * Entry state: wp.org has no listing (premium or custom plugin).
	 *
	 * @var string
	 */
	public const STATE_NOT_ON_WPORG = 'not_on_wporg';

	/**
	 * Entry state: the lookup failed (timeout, HTTP error, malformed body).
	 *
	 * @var string
	 */
	public const STATE_ERROR = 'error';

	/**
	 * The full slug → entry map, pruned of anything unparseable.
	 *
	 * @return array<string, array{state: string, last_updated: int|null, checked_at: int, message: string}>
	 */
	public function results(): array {
		$stored = get_option( self::CACHE_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$clean = [];
		foreach ( $stored as $slug => $entry ) {
			if ( ! is_string( $slug ) || ! is_array( $entry ) ) {
				continue;
			}
			if ( ! isset( $entry['state'], $entry['checked_at'] ) ) {
				continue;
			}

			$clean[ $slug ] = [
				'state'        => (string) $entry['state'],
				'last_updated' => isset( $entry['last_updated'] ) ? (int) $entry['last_updated'] : null,
				'checked_at'   => (int) $entry['checked_at'],
				'message'      => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			];
		}

		return $clean;
	}

	/**
	 * How many of the supplied slugs still need a lookup.
	 *
	 * @param array<int, string> $slugs Plugin slugs.
	 */
	public function pending_count( array $slugs ): int {
		return count( $this->stale_slugs( $slugs ) );
	}

	/**
	 * Look up as many stale slugs as the budget allows.
	 *
	 * @param array<int, string> $slugs  Plugin slugs to keep fresh.
	 * @param int                $budget Maximum lookups this invocation.
	 *
	 * @return array{checked: int, pending: int, complete: bool}
	 */
	public function scan( array $slugs, int $budget ): array {
		$stale   = $this->stale_slugs( $slugs );
		$budget  = max( 0, $budget );
		$batch   = array_slice( $stale, 0, $budget );
		$results = $this->results();

		foreach ( $batch as $slug ) {
			$results[ $slug ] = $this->fetch( $slug );
		}

		if ( [] !== $batch ) {
			// Keep the map from growing without bound on sites that churn
			// plugins: drop entries for slugs we were not asked about.
			$results = array_intersect_key( $results, array_flip( $slugs ) );
			update_option( self::CACHE_OPTION, $results, false );
		}

		$pending = max( 0, count( $stale ) - count( $batch ) );

		return [
			'checked'  => count( $batch ),
			'pending'  => $pending,
			'complete' => 0 === $pending,
		];
	}

	/**
	 * Discard every cached row (used when the scan is switched off).
	 */
	public function forget_all(): void {
		delete_option( self::CACHE_OPTION );
	}

	/**
	 * Query api.wordpress.org for a single plugin slug.
	 *
	 * Mirrors PRD §7.5.3's reference implementation, with the error paths
	 * spelled out: a `WP_Error` transport failure and a non-200 response both
	 * become `error` (retried in an hour), while a 200 that carries no
	 * `last_updated` becomes `not_on_wporg` — the premium-plugin case, which
	 * is a fact about the plugin, not a failure.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return array{state: string, last_updated: int|null, checked_at: int, message: string}
	 */
	public function fetch( string $slug ): array {
		$now = time();

		$url = add_query_arg(
			[
				'action'  => 'plugin_information',
				'slug'    => $slug,
				'fields'  => 'last_updated',
				'request' => [ 'slug' => $slug ],
			],
			self::API_ENDPOINT
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => self::REQUEST_TIMEOUT_SEC,
				'user-agent' => 'FanxieWPCore/' . ( defined( 'FANXIE_WP_CORE_VERSION' ) ? (string) constant( 'FANXIE_WP_CORE_VERSION' ) : '0' ) . '; ' . home_url( '/' ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'state'        => self::STATE_ERROR,
				'last_updated' => null,
				'checked_at'   => $now,
				'message'      => (string) $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// 404 is wp.org's answer for "no such plugin" — a definitive
		// not-listed, not a transport failure, so it caches for the full day.
		if ( 404 === $code ) {
			return [
				'state'        => self::STATE_NOT_ON_WPORG,
				'last_updated' => null,
				'checked_at'   => $now,
				'message'      => '',
			];
		}

		if ( 200 !== $code ) {
			return [
				'state'        => self::STATE_ERROR,
				'last_updated' => null,
				'checked_at'   => $now,
				'message'      => 'http_' . $code,
			];
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return [
				'state'        => self::STATE_ERROR,
				'last_updated' => null,
				'checked_at'   => $now,
				'message'      => 'malformed_response',
			];
		}

		$raw_last_updated = $decoded['last_updated'] ?? null;
		if ( ! is_string( $raw_last_updated ) || '' === $raw_last_updated ) {
			return [
				'state'        => self::STATE_NOT_ON_WPORG,
				'last_updated' => null,
				'checked_at'   => $now,
				'message'      => '',
			];
		}

		$timestamp = strtotime( $raw_last_updated );

		return [
			'state'        => self::STATE_FOUND,
			'last_updated' => false === $timestamp ? null : $timestamp,
			'checked_at'   => $now,
			'message'      => '',
		];
	}

	/**
	 * Slugs whose cached entry is missing or past its freshness window.
	 *
	 * @param array<int, string> $slugs Plugin slugs.
	 * @return array<int, string>
	 */
	private function stale_slugs( array $slugs ): array {
		$results = $this->results();
		$now     = time();
		$stale   = [];

		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}

			$entry = $results[ $slug ] ?? null;
			if ( null === $entry ) {
				$stale[] = $slug;
				continue;
			}

			$ttl = self::STATE_ERROR === $entry['state'] ? self::ERROR_TTL_SEC : self::CACHE_TTL_SEC;
			if ( ( $now - $entry['checked_at'] ) >= $ttl ) {
				$stale[] = $slug;
			}
		}

		return array_values( array_unique( $stale ) );
	}
}
