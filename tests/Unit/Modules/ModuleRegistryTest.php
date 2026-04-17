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
	 * Whether is_enabled() should return true.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * Constructor.
	 *
	 * @param string $id      Module id.
	 * @param bool   $enabled Whether the module reports as enabled.
	 */
	public function __construct( string $id, bool $enabled = false ) {
		$this->module_id = $id;
		$this->enabled   = $enabled;
	}

	public function id(): string {
		return $this->module_id;
	}

	public function name(): string {
		return 'Test Module (' . $this->module_id . ')';
	}

	public function is_enabled(): bool {
		return $this->enabled;
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

	public function test_enabled_filters_to_only_enabled_modules(): void {
		$registry = new ModuleRegistry();
		$on       = new FanxieTestModule( 'on', true );
		$off      = new FanxieTestModule( 'off', false );

		$registry->register( $on );
		$registry->register( $off );

		$enabled = $registry->enabled();

		$this->assertCount( 1, $enabled );
		$this->assertArrayHasKey( 'on', $enabled );
		$this->assertArrayNotHasKey( 'off', $enabled );
	}

	public function test_boot_calls_register_hooks_only_on_enabled_modules(): void {
		$registry = new ModuleRegistry();
		$on       = new FanxieTestModule( 'on', true );
		$off      = new FanxieTestModule( 'off', false );

		$registry->register( $on );
		$registry->register( $off );

		$registry->boot();

		$this->assertSame( 1, $on->register_hooks_calls, 'Enabled module should have register_hooks() called once.' );
		$this->assertSame( 0, $off->register_hooks_calls, 'Disabled module must not have register_hooks() called.' );
	}

	public function test_boot_is_idempotent_per_call_for_enabled_modules(): void {
		$registry = new ModuleRegistry();
		$module   = new FanxieTestModule( 'alpha', true );

		$registry->register( $module );
		$registry->boot();
		$registry->boot();

		$this->assertSame( 2, $module->register_hooks_calls, 'Each boot() call should re-invoke register_hooks() on enabled modules.' );
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
