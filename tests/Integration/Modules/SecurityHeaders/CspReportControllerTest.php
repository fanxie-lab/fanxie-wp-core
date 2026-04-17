<?php
/**
 * Integration tests for the CSP REST controller.
 *
 * @package FanxieLab\WPCore\Tests\Integration\Modules\SecurityHeaders
 */

declare( strict_types=1 );

namespace FanxieLab\WPCore\Tests\Integration\Modules\SecurityHeaders;

use FanxieLab\WPCore\Modules\SecurityHeaders\Csp\CspReportController;
use FanxieLab\WPCore\Modules\SecurityHeaders\ViolationRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration tests for the CSP REST report controller.
 */
final class CspReportControllerTest extends WP_UnitTestCase {

	/**
	 * Repository backing the controller under test.
	 *
	 * @var ViolationRepository
	 */
	private ViolationRepository $repo;

	/**
	 * Controller under test.
	 *
	 * @var CspReportController
	 */
	private CspReportController $controller;

	protected function set_up(): void {
		parent::set_up();
		delete_option( ViolationRepository::SCHEMA_VERSION_OPTION );
		$this->repo       = new ViolationRepository();
		$this->repo->install();
		$this->controller = new CspReportController( $this->repo );

		// Flush rate-limit transients to keep tests independent.
		$_SERVER['REMOTE_ADDR'] = '10.0.0.' . wp_rand( 1, 254 );
		delete_transient( 'fanxie_wp_core_csp_rate_' . sha1( (string) $_SERVER['REMOTE_ADDR'] ) );
	}

	protected function tear_down(): void {
		global $wpdb;
		$table = $this->repo->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( ViolationRepository::SCHEMA_VERSION_OPTION );
		parent::tear_down();
	}

	private function make_request( string $json, string $content_type = 'application/csp-report' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', CspReportController::ROUTE );
		$request->set_header( 'Content-Type', $content_type );
		$request->set_header( 'User-Agent', 'PHPUnit/1.0' );
		$request->set_body( $json );
		return $request;
	}

	public function test_valid_report_inserts_row_and_returns_204(): void {
		$json = wp_json_encode(
			[
				'csp-report' => [
					'document-uri'       => 'https://example.test/page',
					'violated-directive' => 'script-src',
					'blocked-uri'        => 'https://evil.test/x.js',
					'source-file'        => 'https://example.test/page',
					'line-number'        => 42,
				],
			]
		);

		$response = $this->controller->handle( $this->make_request( (string) $json ) );

		$this->assertSame( 204, $response->get_status() );

		$rows = $this->repo->query( [], 1, 25 );
		$this->assertSame( 1, $rows['total'] );
		$this->assertSame( 'script-src', $rows['rows'][0]->directive );
	}

	public function test_malformed_report_returns_400(): void {
		$response = $this->controller->handle( $this->make_request( '{"not":"a report"}' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_invalid_json_returns_400(): void {
		$response = $this->controller->handle( $this->make_request( '<<<not json>>>' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_rate_limit_kicks_in_on_the_61st_request(): void {
		$payload = (string) wp_json_encode(
			[
				'csp-report' => [
					'document-uri'       => 'https://example.test/p',
					'violated-directive' => 'script-src',
					'blocked-uri'        => 'https://evil.test/x',
				],
			]
		);

		$last_status = null;
		for ( $i = 1; $i <= 61; $i++ ) {
			$response    = $this->controller->handle( $this->make_request( $payload ) );
			$last_status = $response->get_status();
			if ( 429 === $last_status ) {
				break;
			}
		}

		$this->assertSame( 429, $last_status );
	}
}
