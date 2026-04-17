<?php
/**
 * Immutable value object representing a single CSP violation row.
 *
 * @package FanxieLab\WPCore\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\SecurityHeaders;

defined( 'ABSPATH' ) || exit;

/**
 * Value object mirroring one row of `{$wpdb->prefix}fanxie_core_csp_violations`.
 *
 * The controller constructs a record from a raw CSP report, the repository
 * writes/reads records, and the AJAX layer serialises them back into the
 * admin UI. Keeping the shape centralised avoids drifting between layers.
 *
 * All properties are readonly — instances are immutable.
 */
final class ViolationRecord {

	/**
	 * Constructor.
	 *
	 * @param int|null    $id           Database id or null for unpersisted rows.
	 * @param string      $directive    Violated directive (e.g. `script-src`).
	 * @param string      $blocked_uri  The URI the browser refused to load.
	 * @param string      $document_uri The page that triggered the violation.
	 * @param string|null $source_file  Optional source file (if provided).
	 * @param int|null    $line_number  Optional line number (if provided).
	 * @param string|null $user_agent   Optional reporting user agent.
	 * @param int         $count        Occurrence count (dedup'd rows).
	 * @param string|null $created_at   Creation timestamp (`Y-m-d H:i:s`, UTC).
	 * @param string|null $last_seen_at Most-recent timestamp (`Y-m-d H:i:s`, UTC).
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $directive,
		public readonly string $blocked_uri,
		public readonly string $document_uri,
		public readonly ?string $source_file = null,
		public readonly ?int $line_number = null,
		public readonly ?string $user_agent = null,
		public readonly int $count = 1,
		public readonly ?string $created_at = null,
		public readonly ?string $last_seen_at = null,
	) {}

	/**
	 * Associative-array representation — handy for REST / AJAX payloads.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'           => $this->id,
			'directive'    => $this->directive,
			'blocked_uri'  => $this->blocked_uri,
			'document_uri' => $this->document_uri,
			'source_file'  => $this->source_file,
			'line_number'  => $this->line_number,
			'user_agent'   => $this->user_agent,
			'count'        => $this->count,
			'created_at'   => $this->created_at,
			'last_seen_at' => $this->last_seen_at,
		];
	}
}
