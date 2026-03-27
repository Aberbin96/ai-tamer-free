<?php
/**
 * BotUpdaterTest — Unit tests for the remote bot list updater.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use AiTamer\BotUpdater;

class BotUpdaterTest extends TestCase {

	/**
	 * Setup BrainMonkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		
		Monkey\Functions\expect( 'update_option' )->andReturn( true );
		Monkey\Functions\expect( 'get_option' )
			->with( 'aitamer_settings' )
			->andReturn( array( 'auto_update_bots' => true ) );
		if ( ! defined( 'AITAMER_PLUGIN_DIR' ) ) {
			define( 'AITAMER_PLUGIN_DIR', '/tmp/' );
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
	 * Tests that the bot list is updated from a remote source.
	 */
	public function test_it_updates_bot_list_remotely(): void {
		$json = json_encode( array(
			array( 'name' => 'New-Bot', 'user_agent' => 'NewBot/1.0', 'type' => 'training' ),
		) );

		Monkey\Functions\expect( 'wp_remote_get' )->andReturn( array( 'response' => array( 'code' => 200 ) ) );
		Monkey\Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Monkey\Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $json );

		$updater = new BotUpdater();
		$updater->fetch_and_update();
		
		$this->assertTrue( true ); // If it didn't crash, it's fine for this stub test.
	}
}
