<?php
/**
 * Value object representing a single CSP preset.
 *
 * @package FanxieLab\Warden\Modules\SecurityHeaders\Csp
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\SecurityHeaders\Csp;

defined( 'ABSPATH' ) || exit;

/**
 * A named fragment of CSP directives that can be merged into the site policy.
 */
final class Preset {

	/**
	 * Constructor.
	 *
	 * @param string                            $id         Stable machine id (kebab-case).
	 * @param string                            $label      Translated human-readable label.
	 * @param array<string, array<int, string>> $directives Directive-map fragment.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly array $directives,
	) {}

	/**
	 * Array form for AJAX / REST payloads.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'         => $this->id,
			'label'      => $this->label,
			'directives' => $this->directives,
		];
	}
}
