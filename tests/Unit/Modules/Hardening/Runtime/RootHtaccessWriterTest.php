<?php
/**
 * Unit tests for RootHtaccessWriter.
 *
 * @package FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Unit\Modules\Hardening\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FanxieLab\WPCore\Modules\Hardening\Runtime\RootHtaccessWriter;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the idempotent `# BEGIN Fanxie WP Core` block management.
 */
final class RootHtaccessWriterTest extends TestCase {

	private string $path = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->path = sys_get_temp_dir() . '/fanxie-rootht-' . uniqid( '', true );
	}

	protected function tearDown(): void {
		if ( '' !== $this->path && file_exists( $this->path ) ) {
			unlink( $this->path );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ensure_block_creates_file_when_missing(): void {
		$writer = new RootHtaccessWriter( $this->path );

		$this->assertTrue( $writer->ensure_readme_license_block() );
		$this->assertFileExists( $this->path );

		$contents = (string) file_get_contents( $this->path );
		$this->assertStringContainsString( RootHtaccessWriter::MARKER_BEGIN, $contents );
		$this->assertStringContainsString( RootHtaccessWriter::MARKER_END, $contents );
		$this->assertStringContainsString( 'readme.html', $contents );
		$this->assertStringContainsString( 'license.txt', $contents );
	}

	public function test_ensure_block_preserves_existing_content(): void {
		file_put_contents(
			$this->path,
			"# BEGIN WordPress\nRewriteRule /foo /bar\n# END WordPress\n"
		);

		$writer = new RootHtaccessWriter( $this->path );
		$writer->ensure_readme_license_block();

		$contents = (string) file_get_contents( $this->path );
		$this->assertStringContainsString( '# BEGIN WordPress', $contents );
		$this->assertStringContainsString( '# END WordPress', $contents );
		$this->assertStringContainsString( RootHtaccessWriter::MARKER_BEGIN, $contents );
	}

	public function test_ensure_block_is_idempotent(): void {
		$writer = new RootHtaccessWriter( $this->path );

		$writer->ensure_readme_license_block();
		$first = (string) file_get_contents( $this->path );

		$writer->ensure_readme_license_block();
		$second = (string) file_get_contents( $this->path );

		$this->assertSame( $first, $second );
	}

	public function test_remove_block_strips_only_our_section(): void {
		file_put_contents(
			$this->path,
			"# BEGIN WordPress\nRewriteRule /foo /bar\n# END WordPress\n\n# BEGIN Fanxie WP Core\nrule\n# END Fanxie WP Core\n"
		);

		$writer = new RootHtaccessWriter( $this->path );
		$this->assertTrue( $writer->remove_block() );

		$contents = (string) file_get_contents( $this->path );
		$this->assertStringContainsString( '# BEGIN WordPress', $contents );
		$this->assertStringNotContainsString( RootHtaccessWriter::MARKER_BEGIN, $contents );
	}

	public function test_remove_block_is_noop_when_file_missing(): void {
		$writer = new RootHtaccessWriter( $this->path );

		$this->assertTrue( $writer->remove_block() );
		$this->assertFileDoesNotExist( $this->path );
	}

	public function test_block_present_reports_state(): void {
		$writer = new RootHtaccessWriter( $this->path );

		$this->assertFalse( $writer->block_present() );

		$writer->ensure_readme_license_block();
		$this->assertTrue( $writer->block_present() );

		$writer->remove_block();
		$this->assertFalse( $writer->block_present() );
	}
}
