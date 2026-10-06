<?php
/**
 * Minimal `WP_CLI` stub.
 *
 * Serves two purposes, both outside the real WordPress/WP-CLI runtime:
 *
 *   1. Unit tests — the Brain Monkey unit suite has no WP-CLI runtime, so this
 *      stand-in records every message the command emits (instead of printing to
 *      STDOUT, which `beStrictAboutOutputDuringTests` would flag) so tests can
 *      assert on it. `error()` throws so the halting behaviour is observable.
 *   2. PHPStan — `WP_CLI` is not part of `php-stubs/wordpress-stubs`, so this
 *      file is registered under `scanFiles` in `phpstan.neon.dist` to give the
 *      analyser the `WP_CLI` symbol when it reads the CLI command class.
 *
 * Only the surface Fanxie Warden's CLI commands touch is implemented. Mirrors
 * the lazy, unit-mode-only stub idiom of {@see WP_Error} in `wp-error.php`.
 *
 * @package FanxieLab\Warden\Tests\Stubs
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_CLI', false ) ) {

	/**
	 * Test-scope stand-in for the WP-CLI runtime facade.
	 */
	class WP_CLI {

		/**
		 * Messages recorded in call order; each entry is `{ level, message }`.
		 *
		 * @var array<int, array{level: string, message: string}>
		 */
		public static array $messages = [];

		/**
		 * When false, confirm() aborts like the real CLI answering "n".
		 *
		 * @var bool
		 */
		public static bool $confirm_answer = true;

		/**
		 * Clear the recorded-message buffer. Call in each test's `setUp()`.
		 */
		public static function reset(): void {
			self::$messages       = [];
			self::$confirm_answer = true;
		}

		/**
		 * Record a confirmation prompt; skipped by `--yes`, aborts when declined.
		 *
		 * @param string               $question   Prompt text.
		 * @param array<string, mixed> $assoc_args Command flags.
		 * @throws \RuntimeException When the stubbed answer is "no".
		 */
		public static function confirm( string $question, array $assoc_args = [] ): void {
			self::$messages[] = [
				'level'   => 'confirm',
				'message' => $question,
			];
			if ( ! empty( $assoc_args['yes'] ) ) {
				return;
			}
			if ( ! self::$confirm_answer ) {
				throw new \RuntimeException( 'confirm-declined' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stub.
			}
		}

		/**
		 * Messages recorded at a given level.
		 *
		 * @param string $level One of `log`, `success`, `warning`, `error`.
		 * @return array<int, string>
		 */
		public static function messages_for( string $level ): array {
			$out = [];
			foreach ( self::$messages as $entry ) {
				if ( $entry['level'] === $level ) {
					$out[] = $entry['message'];
				}
			}
			return $out;
		}

		/**
		 * Every recorded message joined by newlines — handy for substring asserts.
		 */
		public static function all_text(): string {
			return implode( "\n", array_column( self::$messages, 'message' ) );
		}

		/**
		 * Record an informational log line.
		 *
		 * @param string $message Message text.
		 */
		public static function log( string $message ): void {
			self::$messages[] = [
				'level'   => 'log',
				'message' => $message,
			];
		}

		/**
		 * Record a success line.
		 *
		 * @param string $message Message text.
		 */
		public static function success( string $message ): void {
			self::$messages[] = [
				'level'   => 'success',
				'message' => $message,
			];
		}

		/**
		 * Record a warning line.
		 *
		 * @param string $message Message text.
		 */
		public static function warning( string $message ): void {
			self::$messages[] = [
				'level'   => 'warning',
				'message' => $message,
			];
		}

		/**
		 * Record an error line and halt, mirroring the real facade's exit.
		 *
		 * @param string $message Message text.
		 * @throws \RuntimeException Always — stands in for WP-CLI's process halt.
		 */
		public static function error( string $message ): void {
			self::$messages[] = [
				'level'   => 'error',
				'message' => $message,
			];
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stub; the message is internal and never rendered to a browser.
			throw new \RuntimeException( $message );
		}

		/**
		 * Register a command with the WP-CLI runtime.
		 *
		 * Symbol-only stand-in so the static analyser can resolve
		 * `WP_CLI::add_command()` in the Login Protection module's
		 * `register_hooks()` wiring. The real facade ships with WP-CLI and is only
		 * reached under a live `WP_CLI` runtime, never inside the test process, so
		 * the stub records nothing.
		 *
		 * @param string               $name    Command name (e.g. `fx-warden login`).
		 * @param object|string        $handler Command implementation (instance or class name).
		 * @param array<string, mixed> $args    Optional registration arguments.
		 */
		public static function add_command( string $name, object|string $handler, array $args = [] ): void {
			unset( $name, $handler, $args );
		}
	}
}

require_once __DIR__ . '/wp-cli-utils.php';
