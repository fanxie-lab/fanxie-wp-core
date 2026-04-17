<?php
/**
 * Unit tests for ModuleRegistry.
 *
 * Runs in unit mode (no WordPress). Brain Monkey stubs `do_action` so that
 * ModuleRegistry::register() executes cleanly without a real WP runtime.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\ModuleBase;
use FanxieLab\WPCore\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Locally-scoped fixture extending ModuleBase.
 *
 * We keep it inline (rather than under tests/Unit/Fixtures/) because it is
 * only useful to this test file and has no reason to be autoloaded elsewhere.
 */
final class FanxieTestModule extends ModuleBase {

	/**
	 * Track register_hooks() invocations per-instance so tests can assert.
	 *
	 * @var int
	 */
	public int $register_hooks_calls = 0;

	/**
	 * Module id (ctor arg so tests can register several instances).
	 *
	 * @var string
	 */
	private string $module_id;

	/**
	 * Constructor.
	 *
	 * @param string $id Module id.
	 */
	public function __construct( string $id ) {
		$this->module_id = $id;
	}

	public function id(): string {
		return $this->module_id;
	}

	public function name(): string {
		return 'Test Module (' . $this->module_id . ')';
	}

	public function register_hooks(): void {
		++$this->register_hooks_calls;
	}

	public function get_default_config(): array {
		return [];
	}

	public function get_settings_fields(): array {
		return [];
	}
}

/**
 * ModuleRegistry test suite.
 */
final class ModuleRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// ModuleRegistry::register() fires do_action(); stub as a no-op so we
		// don't have to depend on a real WP runtime.
		Functions\when( 'do_action' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_stores_module_retrievable_via_get(): void {
		$registry = new ModuleRegistry();
		$module   = new FanxieTestModule( 'alpha' );

		$registry->register( $module );

		$this->assertSame( $module, $registry->get( 'alpha' ) );
	}

	public function test_get_returns_null_for_unknown_id(): void {
		$registry = new ModuleRegistry();

		$this->assertNull( $registry->get( 'does-not-exist' ) );
	}

	public function test_all_returns_every_registered_module_indexed_by_id(): void {
		$registry = new ModuleRegistry();
		$alpha    = new FanxieTestModule( 'alpha' );
		$beta     = new FanxieTestModule( 'beta' );

		$registry->register( $alpha );
		$registry->register( $beta );

		$all = $registry->all();

		$this->assertCount( 2, $all );
		$this->assertArrayHasKey( 'alpha', $all );
		$this->assertArrayHasKey( 'beta', $all );
		$this->assertSame( $alpha, $all['alpha'] );
		$this->assertSame( $beta, $all['beta'] );
	}

	public function test_boot_calls_register_hooks_on_every_registered_module(): void {
		$registry = new ModuleRegistry();
		$alpha    = new FanxieTestModule( 'alpha' );
		$beta     = new FanxieTestModule( 'beta' );

		$registry->register( $alpha );
		$registry->register( $beta );

		$registry->boot();

		$this->assertSame( 1, $alpha->register_hooks_calls, 'Every registered module should have register_hooks() called once.' );
		$this->assertSame( 1, $beta->register_hooks_calls, 'Every registered module should have register_hooks() called once.' );
	}

	public function test_boot_reinvokes_register_hooks_on_subsequent_calls(): void {
		$registry = new ModuleRegistry();
		$module   = new FanxieTestModule( 'alpha' );

		$registry->register( $module );
		$registry->boot();
		$registry->boot();

		$this->assertSame( 2, $module->register_hooks_calls, 'Each boot() call should re-invoke register_hooks() on every module.' );
	}

	public function test_register_overwrites_existing_module_with_same_id(): void {
		$registry = new ModuleRegistry();
		$first    = new FanxieTestModule( 'alpha' );
		$second   = new FanxieTestModule( 'alpha' );

		$registry->register( $first );
		$registry->register( $second );

		$this->assertSame( $second, $registry->get( 'alpha' ) );
		$this->assertCount( 1, $registry->all() );
	}
}
