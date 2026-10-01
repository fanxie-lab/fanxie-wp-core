<?php
/**
 * Minimal `WP_Theme` shim for the unit suite.
 *
 * @package FanxieLab\Warden\Tests\Stubs
 */

declare( strict_types=1 );

/**
 * Test-scope stand-in for the WordPress `WP_Theme` class.
 *
 * Implements only the surface the Environment Health module touches:
 * `exists()`, `get_stylesheet()`, `parent()`, and `get( 'Name' )`.
 */
class WP_Theme {

	/**
	 * Stylesheet directory name.
	 *
	 * @var string
	 */
	private string $stylesheet;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Parent theme, when this is a child theme.
	 *
	 * @var WP_Theme|null
	 */
	private ?WP_Theme $parent;

	/**
	 * Whether the theme's directory is present.
	 *
	 * @var bool
	 */
	private bool $exists;

	/**
	 * Constructor.
	 *
	 * @param string        $stylesheet Stylesheet directory name.
	 * @param string        $name       Display name.
	 * @param WP_Theme|null $parent_theme Parent theme, if any.
	 * @param bool          $exists     Whether the theme exists on disk.
	 */
	public function __construct( string $stylesheet, string $name = '', ?WP_Theme $parent_theme = null, bool $exists = true ) {
		$this->stylesheet = $stylesheet;
		$this->name       = '' !== $name ? $name : $stylesheet;
		$this->parent     = $parent_theme;
		$this->exists     = $exists;
	}

	/**
	 * Whether the theme exists on disk.
	 */
	public function exists(): bool {
		return $this->exists;
	}

	/**
	 * Stylesheet directory name.
	 */
	public function get_stylesheet(): string {
		return $this->stylesheet;
	}

	/**
	 * Parent theme, or null.
	 *
	 * @return WP_Theme|false
	 */
	public function parent() {
		return null !== $this->parent ? $this->parent : false;
	}

	/**
	 * Header lookup — only `Name` is supported.
	 *
	 * @param string $header Header key.
	 * @return string|false
	 */
	public function get( string $header ) {
		return 'Name' === $header ? $this->name : false;
	}
}
