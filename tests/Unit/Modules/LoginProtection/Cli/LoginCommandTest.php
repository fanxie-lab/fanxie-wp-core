<?php
/**
 * Unit tests for the Login Protection WP-CLI recovery command.
 *
 * @package FanxieLab\Warden\Tests\Unit\Modules\LoginProtection\Cli
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\LoginProtection\Cli;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\LoginProtection\BanStore;
use FanxieLab\Warden\Modules\LoginProtection\Cli\LoginCommand;
use FanxieLab\Warden\Modules\LoginProtection\Runtime\AttemptLimiter;
use Mockery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../stubs/wp-cli.php';

/**
 * Exercises the two subcommands' pure logic under Brain Monkey:
 *
 *   - `unlock`: dry-run reports without deleting; apply clears both lockout
 *     dimensions via `AttemptLimiter::clear_subject()` (observed through the
 *     `delete_transient` it calls) and removes both ban types.
 *   - `reveal`: reports the effective slug and its source (stored vs constant),
 *     and handles the no-slug case.
 *
 * The `WP_CLI` facade is a recording stub (`tests/stubs/wp-cli.php`); `BanStore`
 * is mocked through its interface.
 */
final class LoginCommandTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static fn ( $single, $plural, $number ): string => 1 === (int) $number ? $single : $plural
		);
		\WP_CLI::reset();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Minimal module config: only `hide_login` is read (by `reveal`).
	 *
	 * @param string $slug    Stored login slug.
	 * @param bool   $enabled Whether hide-login is toggled on.
	 * @return array<string, mixed>
	 */
	private function config( string $slug = 'secret-login', bool $enabled = true ): array {
		return [
			'hide_login' => [
				'enabled' => $enabled,
				'slug'    => $slug,
			],
		];
	}

	public function test_unlock_apply_clears_both_lockout_dimensions_and_removes_both_bans(): void {
		$subject = '203.0.113.7';

		$deleted = [];
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$deleted ): bool {
				$deleted[] = (string) $key;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );

		$bans = Mockery::mock( BanStore::class );
		$bans->shouldReceive( 'remove' )->once()->with( 'ip', $subject )->andReturn( 1 );
		$bans->shouldReceive( 'remove' )->once()->with( 'username', $subject )->andReturn( 0 );

		$command = new LoginCommand( $this->config(), $bans );
		$command->unlock( [ $subject ], [] );

		// `clear_subject('ip', …)` and `clear_subject('user', …)` each delete the
		// lock transient for their dimension — proof both dimensions were cleared.
		$this->assertContains( 'fanxie_warden_lp_lock_ip_' . md5( $subject ), $deleted );
		$this->assertContains( 'fanxie_warden_lp_lock_user_' . md5( $subject ), $deleted );
		$this->assertNotEmpty( \WP_CLI::messages_for( 'success' ) );
	}

	public function test_unlock_dry_run_reports_without_deleting_or_removing(): void {
		$subject = 'attacker';

		Functions\when( 'delete_transient' )->alias(
			static function (): bool {
				throw new \LogicException( 'delete_transient must not run during --dry-run.' );
			}
		);

		// Drive the "locked" verdict off the exact key AttemptLimiter builds for the
		// username dimension (reflected from its own private `lock_key()`), so the
		// dry-run exercises the real `AttemptLimiter::is_locked()` read seam rather
		// than a hardcoded key mirror — a drift in the key format would now surface
		// through this path too. The IP dimension has no lock transient.
		$lock_key      = new \ReflectionMethod( AttemptLimiter::class, 'lock_key' );
		$user_lock_key = (string) $lock_key->invoke( null, 'user', $subject );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( $user_lock_key ): mixed {
				return (string) $key === $user_lock_key ? 1 : false;
			}
		);

		$bans = Mockery::mock( BanStore::class );
		$bans->shouldReceive( 'is_banned' )->andReturn( true );
		$bans->shouldNotReceive( 'remove' );

		$command = new LoginCommand( $this->config(), $bans );
		$command->unlock( [ $subject ], [ 'dry-run' => true ] );

		// The username lock (keyed exactly as AttemptLimiter::lock_key() produces) is
		// reported present — proof the dry-run resolved lock state through the seam.
		$this->assertMatchesRegularExpression( '/present|would be cleared/i', \WP_CLI::all_text() );
		$this->assertNotEmpty( \WP_CLI::messages_for( 'success' ) );
	}

	public function test_unlock_dry_run_reports_clean_subject_without_deleting(): void {
		Functions\when( 'delete_transient' )->alias(
			static function (): bool {
				throw new \LogicException( 'delete_transient must not run during --dry-run.' );
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );

		$bans = Mockery::mock( BanStore::class );
		$bans->shouldReceive( 'is_banned' )->andReturn( false );
		$bans->shouldNotReceive( 'remove' );

		$command = new LoginCommand( $this->config(), $bans );
		$command->unlock( [ '198.51.100.9' ], [ 'dry-run' => true ] );

		$this->assertMatchesRegularExpression( '/nothing/i', \WP_CLI::all_text() );
		$this->assertNotEmpty( \WP_CLI::messages_for( 'success' ) );
	}

	public function test_unlock_errors_on_empty_subject(): void {
		$this->expectException( \RuntimeException::class );

		$command = new LoginCommand( $this->config(), Mockery::mock( BanStore::class ) );
		$command->unlock( [ '' ], [] );
	}

	public function test_reveal_reports_effective_slug_from_stored_config(): void {
		Functions\when( 'sanitize_title' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( '/%postname%/' );
		Functions\when( 'user_trailingslashit' )->returnArg( 1 );
		Functions\when( 'home_url' )->alias(
			static fn ( $path = '/' ): string => 'https://example.test/' . ltrim( (string) $path, '/' )
		);

		$command = new LoginCommand( $this->config( 'secret-login', true ), Mockery::mock( BanStore::class ) );
		$command->reveal();

		$text = \WP_CLI::all_text();
		$this->assertStringContainsString( 'secret-login', $text );
		$this->assertStringContainsString( 'https://example.test/secret-login', $text );
		$this->assertMatchesRegularExpression( '/stored|settings/i', $text );
		$this->assertNotEmpty( \WP_CLI::messages_for( 'success' ) );
	}

	public function test_reveal_warns_when_slug_configured_but_hide_login_disabled(): void {
		Functions\when( 'sanitize_title' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( '/%postname%/' );
		Functions\when( 'user_trailingslashit' )->returnArg( 1 );
		Functions\when( 'home_url' )->alias(
			static fn ( $path = '/' ): string => 'https://example.test/' . ltrim( (string) $path, '/' )
		);

		// Slug is stored but the "Hide wp-login.php" toggle is off.
		$command = new LoginCommand( $this->config( 'disabled-slug', false ), Mockery::mock( BanStore::class ) );
		$command->reveal();

		$this->assertStringContainsString( 'disabled-slug', \WP_CLI::all_text() );
		$this->assertNotEmpty( \WP_CLI::messages_for( 'warning' ) );
	}

	public function test_reveal_reports_no_slug_when_unconfigured(): void {
		Functions\when( 'sanitize_title' )->returnArg( 1 );
		Functions\when( 'wp_login_url' )->justReturn( 'https://example.test/wp-login.php' );

		$command = new LoginCommand( $this->config( '', false ), Mockery::mock( BanStore::class ) );
		$command->reveal();

		$text = \WP_CLI::all_text();
		$this->assertStringContainsString( 'wp-login.php', $text );
		$this->assertMatchesRegularExpression( '/not active|no custom login slug|default/i', $text );
	}

	/**
	 * The constant path needs a real `define()` (PHP `defined()`/`constant()`
	 * cannot be mocked), so it runs isolated to avoid leaking the constant into
	 * the other in-process reveal tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reveal_reports_slug_source_as_constant_when_defined(): void {
		define( 'FX_CORE_LOGIN_SLUG', 'wpconfig-slug' );

		Functions\when( 'sanitize_title' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'home_url' )->alias(
			static fn ( $path = '/' ): string => 'https://example.test/' . ltrim( (string) $path, '/' )
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $key, $value, $url ): string => rtrim( (string) $url, '/' ) . '/?' . $key
		);

		// Stored slug differs, so the assertion proves the constant wins.
		$command = new LoginCommand( $this->config( 'stored-slug', true ), Mockery::mock( BanStore::class ) );
		$command->reveal();

		$text = \WP_CLI::all_text();
		$this->assertStringContainsString( 'wpconfig-slug', $text );
		$this->assertStringContainsString( 'FX_CORE_LOGIN_SLUG', $text );
	}
}
