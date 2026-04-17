<?php
/**
 * REST controller for receiving CSP violation reports.
 *
 * @package FanxieLab\WPCore\Modules\SecurityHeaders\Csp
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\SecurityHeaders\Csp;

use FanxieLab\WPCore\Modules\SecurityHeaders\SecurityHeaders;
use FanxieLab\WPCore\Modules\SecurityHeaders\ViolationRecord;
use FanxieLab\WPCore\Modules\SecurityHeaders\ViolationRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint: `POST /fanxie-wp-core/v1/csp-report`.
 *
 * Browsers post CSP violation reports anonymously — there is no nonce, no
 * capability check. We compensate with a strict per-IP rate limit and a
 * hand-written schema validator that accepts the two report payload shapes
 * currently in the wild (`application/csp-report` and the newer
 * `application/reports+json`).
 *
 * The REST route is registered unconditionally so stale browser caches posting
 * to a retired URL still get a clean response. When `csp.mode === 'off'` the
 * handler silently accepts-and-drops (204 without persisting) so violation
 * storage doesn't accumulate junk while CSP is disabled.
 *
 * Responses:
 *   - 204 No Content — report accepted (stored when CSP is on; dropped when off).
 *   - 400 Bad Request — payload missing or malformed.
 *   - 429 Too Many Requests — rate limit exceeded (no body written).
 */
final class CspReportController {

	public const ROUTE_NAMESPACE = 'fanxie-wp-core/v1';
	public const ROUTE           = '/csp-report';

	/**
	 * Default rate limit window, seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_WINDOW = 60;

	/**
	 * Default rate limit ceiling per window.
	 *
	 * @var int
	 */
	private const DEFAULT_CEILING = 60;

	/**
	 * Constructor.
	 *
	 * @param ViolationRepository  $repository Persistence layer.
	 * @param SecurityHeaders|null $module     Optional module reference used to
	 *                                         read `csp.mode` at request time.
	 *                                         When null (e.g. tests), mode is
	 *                                         treated as active.
	 */
	public function __construct(
		private readonly ViolationRepository $repository,
		private readonly ?SecurityHeaders $module = null,
	) {}

	/**
	 * Register the route on `rest_api_init`.
	 */
	public function register(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				// Public endpoint: browsers POST CSP violations anonymously,
				// so no nonce / capability check is possible. Rate-limited
				// per-IP inside `handle()` to compensate.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle one incoming CSP violation report.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		// --- Rate limit check ----------------------------------------------------
		[ $window, $ceiling ] = $this->resolve_rate_limit();
		$ip                   = $this->client_ip();
		$key                  = 'fanxie_wp_core_csp_rate_' . sha1( $ip );

		$hits = (int) get_transient( $key );

		if ( $hits >= $ceiling ) {
			$response = new WP_REST_Response( null, 429 );
			$response->header( 'Retry-After', (string) $window );
			return $response;
		}

		set_transient( $key, $hits + 1, $window );

		// --- CSP off? Accept-and-drop ------------------------------------------
		// The route stays registered unconditionally so stale browser caches
		// don't see a 404. When the operator has switched CSP off, skip
		// persistence so reports (which the browser may keep sending for a
		// while) don't pollute the violation log.
		if ( null !== $this->module ) {
			$config = $this->module->get_config();
			$mode   = isset( $config['csp']['mode'] ) && is_string( $config['csp']['mode'] )
				? $config['csp']['mode']
				: '';
			if ( CspPolicy::MODE_OFF === $mode ) {
				return new WP_REST_Response( null, 204 );
			}
		}

		// --- Parse the body ------------------------------------------------------
		$raw  = (string) $request->get_body();
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( [ 'code' => 'invalid_json' ], 400 );
		}

		$normalised = $this->normalise_report( $data );
		if ( null === $normalised ) {
			return new WP_REST_Response( [ 'code' => 'invalid_report' ], 400 );
		}

		// --- Persist -------------------------------------------------------------
		$user_agent = (string) $request->get_header( 'user_agent' );
		if ( '' === $user_agent ) {
			$ua_header = $request->get_header( 'User-Agent' );
			if ( is_string( $ua_header ) ) {
				$user_agent = $ua_header;
			}
		}

		$record = new ViolationRecord(
			null,
			$normalised['directive'],
			$normalised['blocked_uri'],
			$normalised['document_uri'],
			$normalised['source_file'],
			$normalised['line_number'],
			'' === $user_agent ? null : mb_substr( $user_agent, 0, 500 ),
		);

		$this->repository->record( $record );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Resolve the effective rate-limit window + ceiling.
	 *
	 * @return array{0:int,1:int} `[window_seconds, ceiling_per_window]`
	 */
	private function resolve_rate_limit(): array {
		/**
		 * Filter: fanxie_wp_core/security_headers/rate_limit
		 *
		 * Override the CSP report endpoint's rate limit as `[window, ceiling]`.
		 *
		 * @since 0.1.0-dev
		 *
		 * @param array{0:int,1:int} $limits Default `[60, 60]` — 60 reports per 60s per IP.
		 */
		$filtered = apply_filters(
			'fanxie_wp_core/security_headers/rate_limit',
			[ self::DEFAULT_WINDOW, self::DEFAULT_CEILING ]
		);

		if ( ! is_array( $filtered ) || ! isset( $filtered[0], $filtered[1] ) ) {
			return [ self::DEFAULT_WINDOW, self::DEFAULT_CEILING ];
		}

		$window  = max( 1, (int) $filtered[0] );
		$ceiling = max( 1, (int) $filtered[1] );

		return [ $window, $ceiling ];
	}

	/**
	 * Extract and sanitise the fields we care about from either report shape.
	 *
	 * Returns null when no useful fields could be found — the caller translates
	 * that into a 400 response.
	 *
	 * @param array<string, mixed> $data Decoded body.
	 * @return array{directive:string, blocked_uri:string, document_uri:string, source_file:?string, line_number:?int}|null
	 */
	private function normalise_report( array $data ): ?array {
		// Shape 1: `application/reports+json` — an array of report envelopes.
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$envelope = $data[0];
			$body     = isset( $envelope['body'] ) && is_array( $envelope['body'] ) ? $envelope['body'] : $envelope;
			return $this->extract_fields( $body, true );
		}

		// Shape 2: `application/csp-report` — `{ "csp-report": { ... } }`.
		if ( isset( $data['csp-report'] ) && is_array( $data['csp-report'] ) ) {
			return $this->extract_fields( $data['csp-report'], false );
		}

		// Some deployments post the body flat.
		if ( isset( $data['violated-directive'] ) || isset( $data['effectiveDirective'] ) ) {
			return $this->extract_fields( $data, isset( $data['effectiveDirective'] ) );
		}

		return null;
	}

	/**
	 * Pull the canonical fields out of either report shape.
	 *
	 * @param array<string, mixed> $body        Report body.
	 * @param bool                 $reports_api True for the newer Reports API shape.
	 * @return array{directive:string, blocked_uri:string, document_uri:string, source_file:?string, line_number:?int}|null
	 */
	private function extract_fields( array $body, bool $reports_api ): ?array {
		if ( $reports_api ) {
			$directive    = (string) ( $body['effectiveDirective'] ?? $body['violatedDirective'] ?? '' );
			$blocked_uri  = (string) ( $body['blockedURL'] ?? $body['blockedUri'] ?? '' );
			$document_uri = (string) ( $body['documentURL'] ?? $body['documentUri'] ?? '' );
			$source_file  = isset( $body['sourceFile'] ) ? (string) $body['sourceFile'] : null;
			$line_number  = isset( $body['lineNumber'] ) ? (int) $body['lineNumber'] : null;
		} else {
			$directive    = (string) ( $body['violated-directive'] ?? $body['effective-directive'] ?? '' );
			$blocked_uri  = (string) ( $body['blocked-uri'] ?? '' );
			$document_uri = (string) ( $body['document-uri'] ?? '' );
			$source_file  = isset( $body['source-file'] ) ? (string) $body['source-file'] : null;
			$line_number  = isset( $body['line-number'] ) ? (int) $body['line-number'] : null;
		}

		// The directive is the one field we always need — without it the row
		// is useless.
		if ( '' === $directive ) {
			return null;
		}

		// Directives sometimes arrive as `script-src https://evil.test` — keep
		// just the directive name.
		$directive_parts = preg_split( '/\s+/', trim( $directive ), 2 );
		$directive_name  = is_array( $directive_parts ) && isset( $directive_parts[0] ) ? (string) $directive_parts[0] : trim( $directive );

		return [
			'directive'    => mb_substr( sanitize_text_field( $directive_name ), 0, 100 ),
			'blocked_uri'  => sanitize_text_field( $blocked_uri ),
			'document_uri' => sanitize_text_field( $document_uri ),
			'source_file'  => null === $source_file || '' === $source_file ? null : mb_substr( sanitize_text_field( $source_file ), 0, 500 ),
			'line_number'  => null === $line_number ? null : max( 0, $line_number ),
		];
	}

	/**
	 * Best-effort client IP for rate-limit bucketing.
	 */
	private function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return '' === $remote ? '0.0.0.0' : $remote;
	}
}
