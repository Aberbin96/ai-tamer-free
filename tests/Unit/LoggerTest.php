<?php
/**
 * LoggerTest — Unit tests for the DB logger.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use AiTamer\Logger;

class LoggerTest extends TestCase {

	/**
	 * Setup BrainMonkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock standard WP functions as stubs.
		Monkey\Functions\stubs( array(
			'get_the_ID' => 123,
			'sanitize_text_field' => function($v) { return $v; },
			'wp_unslash' => function($v) { return $v; },
			'current_time' => '2025-01-01 12:00:00',
		) );

		// Mock global $wpdb.
		global $wpdb;
		$wpdb = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
	}

	/**
	 * Teardown BrainMonkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that logging only occurs for matched agents.
	 */
	public function test_it_logs_requests_from_bots(): void {
		global $wpdb;
		
		$agent = array(
			'matched' => true,
			'name'    => 'GPTBot',
			'type'    => 'training',
		);

		// We expect $wpdb->insert to be called.
		// Since $wpdb is a stdClass, we use Mockery or just expect the call via Brain Monkey.
		// Actually, Brain Monkey doesn't mock objects easily, so we rely on theMockery stdClass we created.
		
		$wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				\Mockery::on( function( $table ) { return strpos( $table, 'aitamer_logs' ) !== false; } ),
				\Mockery::on( function( $data ) {
					return $data['bot_name'] === 'GPTBot' && ! empty( $data['ip_hash'] );
				} ),
				\Mockery::any()
			);

		$logger = new Logger();
		$logger->log( $agent );

		$this->assertTrue( true ); // Verify no exception thrown and mock expectations met.
	}

	/**
	 * Tests that humans are not logged.
	 */
	public function test_it_does_not_log_humans(): void {
		global $wpdb;
		
		$agent = array(
			'matched' => false,
			'name'    => 'human',
		);

		$wpdb->shouldReceive( 'insert' )->never();

		$logger = new Logger();
		$logger->log( $agent );

		$this->assertTrue( true );
	}
}
