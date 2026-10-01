<?php
/**
 * Tests for the `wp fx-warden db` command.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Tests\Unit\Modules\DatabaseMaintenance;

require_once __DIR__ . '/../../../stubs/wp-cli.php';

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\CleanupRunner;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup\TaskFactory;
use FanxieLab\Warden\Modules\DatabaseMaintenance\Cli\DbCommand;
use PHPUnit\Framework\TestCase;
use WP_CLI;

/**
 * DbCommand tests.
 */
final class DbCommandTest extends TestCase {

	private FakeTask $task;
	private TaskFactory $factory;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WP_CLI::reset();
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( fn ( $s, $p, $n ) => 1 === $n ? $s : $p );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'size_format' )->alias( fn ( $b ) => $b . ' B' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->task    = new FakeTask( 7 );
		$this->factory = $this->createMock( TaskFactory::class );
		$this->factory->method( 'cli_task' )->willReturnCallback( fn ( string $id ) => 'nope' === $id ? null : $this->task );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function cmd(): DbCommand {
		return new DbCommand( $this->factory, new CleanupRunner() );
	}

	public function test_dry_run_prints_count_and_deletes_nothing(): void {
		$this->cmd()->spam( [], [ 'dry-run' => true ] );

		$this->assertSame( 7, $this->task->count() );
		$this->assertStringContainsString( '7', WP_CLI::all_text() );
		$this->assertSame( [], WP_CLI::messages_for( 'confirm' ) );
	}

	public function test_yes_skips_prompt_and_purges(): void {
		$this->cmd()->spam( [], [ 'yes' => true ] );

		$this->assertSame( 0, $this->task->count() );
		$this->assertNotEmpty( WP_CLI::messages_for( 'success' ) );
	}

	public function test_declined_confirmation_deletes_nothing(): void {
		WP_CLI::$confirm_answer = false;
		try {
			$this->cmd()->spam( [], [] );
			$this->fail( 'Expected the declined confirmation to abort.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'confirm-declined', $e->getMessage() );
		}
		$this->assertSame( 7, $this->task->count() );
	}

	/**
	 * @dataProvider invalid_flags
	 *
	 * @param string                $method Command method.
	 * @param array<string, string> $assoc  Flags.
	 */
	public function test_out_of_range_flags_error( string $method, array $assoc ): void {
		$this->expectException( \RuntimeException::class ); // The stub's WP_CLI::error() throws, like the real one exits.
		$this->cmd()->{$method}( [], $assoc );
	}

	/**
	 * @return array<string, array{string, array<string, string>}>
	 */
	public static function invalid_flags(): array {
		return [
			'keep too high' => [ 'revisions', [ 'keep' => '51' ] ],
			'days zero'     => [ 'trash', [ 'days' => '0' ] ],
			'days text'     => [ 'spam', [ 'days' => 'abc' ] ],
			'bad type'      => [ 'orphans', [ 'type' => 'link' ] ],
		];
	}

	public function test_clean_without_all_errors(): void {
		$this->expectException( \RuntimeException::class );
		$this->cmd()->clean( [], [] );
	}

	public function test_status_lists_every_admin_task(): void {
		$this->cmd()->status( [], [ 'format' => 'json' ] );

		$this->assertStringContainsString( 'format:json', WP_CLI::all_text() );
		$this->assertStringContainsString( 'spam-comments', WP_CLI::all_text() );
	}
}
