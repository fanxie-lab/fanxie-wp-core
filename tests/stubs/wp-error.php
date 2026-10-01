<?php
/**
 * Minimal `WP_Error` shim loaded by the unit-mode bootstrap.
 *
 * Only implements what Fanxie Warden callers touch: `__construct(
 * $code, $message )`, `get_error_code()`, and `get_error_message()`.
 * Anything beyond that should be added as tests grow.
 *
 * @package FanxieLab\Warden\Tests\Stubs
 */

declare( strict_types=1 );

/**
 * Test-scope stand-in for the WordPress `WP_Error` class.
 */
class WP_Error {

	/**
	 * Error code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Human-readable error message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Resolve the primary error code.
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * Resolve the primary error message.
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}
