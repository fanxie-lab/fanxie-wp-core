<?php
/**
 * Immutable value object for a single environment health check result.
 *
 * @package FanxieLab\WPCore\Modules\EnvironmentHealth
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Modules\EnvironmentHealth;

defined( 'ABSPATH' ) || exit;

/**
 * One row of the health report.
 *
 * The serialised shape is a frozen contract with the Vue admin tab and the
 * dashboard widget — see `assets/admin/src/modules/EnvironmentHealth/types.ts`:
 *
 *   {
 *     id:          string,   // stable snake_case key — the frontend's map key
 *     group:       'versions' | 'cron' | 'debug' | 'plugins_themes',
 *     label:       string,   // translated
 *     status:      'ok' | 'warning' | 'critical' | 'unknown',
 *     value:       string,   // display-ready observed value
 *     summary:     string,   // translated one-liner
 *     detail:      string,   // translated prose, may be ''
 *     remediation: Array<Snippet | Link>,
 *     meta:        Record<string, scalar|null>
 *   }
 *
 * `status` and `group` are closed enums: nothing outside the constants below
 * is ever emitted. Remediation `code` is deliberately NOT translated —
 * translating a wp-config snippet would break it.
 */
final class HealthCheck {

	/**
	 * Status: everything is as it should be.
	 *
	 * @var string
	 */
	public const STATUS_OK = 'ok';

	/**
	 * Status: works today, needs attention soon.
	 *
	 * @var string
	 */
	public const STATUS_WARNING = 'warning';

	/**
	 * Status: acting now is required.
	 *
	 * @var string
	 */
	public const STATUS_CRITICAL = 'critical';

	/**
	 * Status: the probe could not reach a conclusion.
	 *
	 * Distinct from "fine" — an outbound-blocked host, a database that does
	 * not report its version, or a branch missing from the support matrix all
	 * land here rather than inventing a false pass or a false alarm.
	 *
	 * @var string
	 */
	public const STATUS_UNKNOWN = 'unknown';

	/**
	 * Every valid status, in ascending severity for `ok`→`critical` plus the
	 * out-of-band `unknown`.
	 *
	 * @var array<int, string>
	 */
	public const ALL_STATUSES = [
		self::STATUS_OK,
		self::STATUS_WARNING,
		self::STATUS_CRITICAL,
		self::STATUS_UNKNOWN,
	];

	/**
	 * Group: PRD §7.2 — software and certificate versions.
	 *
	 * @var string
	 */
	public const GROUP_VERSIONS = 'versions';

	/**
	 * Group: PRD §7.3 — WP-Cron health.
	 *
	 * @var string
	 */
	public const GROUP_CRON = 'cron';

	/**
	 * Group: PRD §7.4 — debug-mode exposure.
	 *
	 * @var string
	 */
	public const GROUP_DEBUG = 'debug';

	/**
	 * Group: PRD §7.5 — plugin and theme hygiene.
	 *
	 * @var string
	 */
	public const GROUP_PLUGINS_THEMES = 'plugins_themes';

	/**
	 * Every valid group, in display order.
	 *
	 * @var array<int, string>
	 */
	public const ALL_GROUPS = [
		self::GROUP_VERSIONS,
		self::GROUP_CRON,
		self::GROUP_DEBUG,
		self::GROUP_PLUGINS_THEMES,
	];

	/**
	 * Remediation entry kind: a copy-paste code block.
	 *
	 * @var string
	 */
	public const REMEDIATION_SNIPPET = 'snippet';

	/**
	 * Remediation entry kind: an outbound documentation link.
	 *
	 * @var string
	 */
	public const REMEDIATION_LINK = 'link';

	/**
	 * Constructor.
	 *
	 * @param string                            $id          Stable snake_case key.
	 * @param string                            $group       One of `self::ALL_GROUPS`.
	 * @param string                            $label       Translated check name.
	 * @param string                            $status      One of `self::ALL_STATUSES`.
	 * @param string                            $value       Display-ready observed value.
	 * @param string                            $summary     Translated one-line verdict.
	 * @param string                            $detail      Translated longer prose ('' when unused).
	 * @param array<int, array<string, string>> $remediation Ordered remediation entries.
	 * @param array<string, scalar|null>        $meta        Check-specific scalar map.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $group,
		public readonly string $label,
		public readonly string $status,
		public readonly string $value,
		public readonly string $summary,
		public readonly string $detail = '',
		public readonly array $remediation = [],
		public readonly array $meta = [],
	) {}

	/**
	 * Build a `snippet` remediation entry.
	 *
	 * @param string $language Highlighting hint: `php`, `bash`, or `nginx`.
	 * @param string $code     The snippet itself — never translated.
	 * @param string $label    Translated caption.
	 *
	 * @return array<string, string>
	 */
	public static function snippet( string $language, string $code, string $label ): array {
		return [
			'kind'     => self::REMEDIATION_SNIPPET,
			'language' => $language,
			'code'     => $code,
			'label'    => $label,
		];
	}

	/**
	 * Build a `link` remediation entry.
	 *
	 * @param string $url   Absolute URL (escaped at serialisation time).
	 * @param string $label Translated caption.
	 *
	 * @return array<string, string>
	 */
	public static function link( string $url, string $label ): array {
		return [
			'kind'  => self::REMEDIATION_LINK,
			'url'   => esc_url_raw( $url ),
			'label' => $label,
		];
	}

	/**
	 * Serialise for the AJAX payload.
	 *
	 * Enum fields are re-validated here rather than trusted from the caller:
	 * this is the single choke point every check passes through, so an
	 * out-of-contract `status` or `group` from a future check can never reach
	 * the frontend.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'          => $this->id,
			'group'       => in_array( $this->group, self::ALL_GROUPS, true ) ? $this->group : self::GROUP_VERSIONS,
			'label'       => $this->label,
			'status'      => in_array( $this->status, self::ALL_STATUSES, true ) ? $this->status : self::STATUS_UNKNOWN,
			'value'       => $this->value,
			'summary'     => $this->summary,
			'detail'      => $this->detail,
			'remediation' => array_values( $this->remediation ),
			'meta'        => $this->meta,
		];
	}
}
