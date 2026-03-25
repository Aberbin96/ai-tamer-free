<?php
/**
 * AuditReportTest — Unit tests for stats aggregation.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use AiTamer\AuditReport;

class AuditReportTest extends TestCase {

	/**
	 * Setup BrainMonkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}

		// Mock global $wpdb.
		global $wpdb;
		$wpdb = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// Mock standard WP functions as stubs.
		Monkey\Functions\stubs( array(
			'wp_upload_dir' => array( 'basedir' => '/tmp' ),
			'wp_mkdir_p'    => true,
			'get_bloginfo'  => 'Test Site',
			'sanitize_file_name' => function($v) { return $v; },
			'wp_nonce_url'  => 'https://example.com',
			'add_query_arg' => 'https://example.com',
			'admin_url'     => 'https://example.com/wp-admin',
		) );

		if ( ! is_dir( '/tmp/aitamer-reports' ) ) {
			mkdir( '/tmp/aitamer-reports', 0777, true );
		}
	}

	/**
	 * Teardown BrainMonkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests report generation.
	 */
	public function test_it_generates_report(): void {
		global $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturnArg( 0 );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array(
			array( 'bot_name' => 'GPTBot', 'bot_type' => 'training', 'post_id' => 1, 'request_uri' => '/', 'ip_hash' => 'abc', 'created_at' => '2025-01-01' ),
		) );

		$file = AuditReport::generate( 30 );

		$this->assertNotFalse( $file );
		$this->assertStringContainsString( 'aitamer-audit', $file );
	}
}
