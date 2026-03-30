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
	 * Tests that logging adds to the buffer.
	 */
	public function test_it_logs_requests_from_bots_to_buffer(): void {
		$agent = array(
			'matched' => true,
			'name'    => 'GPTBot',
			'type'    => 'training',
		);

		// log() should call wp_cache_set and set_transient.
		Monkey\Functions\expect( 'wp_cache_set' )->once();
		Monkey\Functions\expect( 'set_transient' )->once();

		$logger = new Logger();
		$logger->log( $agent );

		$this->assertTrue( true );
	}

	/**
	 * Tests that flush_buffer calls $wpdb->query.
	 */
	public function test_it_flushes_buffer(): void {
		global $wpdb;

		$sample_buffer = array(
			array(
				'bot_name'    => 'GPTBot',
				'bot_type'    => 'training',
				'post_id'     => 123,
				'request_uri' => '/',
				'ip_hash'     => 'abc',
				'user_agent'  => 'Mozilla',
				'protection'  => 'none',
				'created_at'  => '2025-01-01 12:00:00',
			)
		);

		Monkey\Functions\expect( 'wp_cache_get' )->andReturn( $sample_buffer );
		Monkey\Functions\expect( 'wp_cache_delete' )->once();
		Monkey\Functions\expect( 'delete_transient' )->once();

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'INSERT INTO wp_aitamer_logs ...' );
		$wpdb->shouldReceive( 'query' )->once()->with( 'INSERT INTO wp_aitamer_logs ...' );

		Logger::flush_buffer();

		$this->assertTrue( true );
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
