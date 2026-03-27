<?php
/**
 * LicenseManagerTest — Unit tests for license management.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

// Manually require classes if autoloader fails
require_once dirname( dirname( __DIR__ ) ) . '/includes/class-license-manager.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/pro/class-license-verifier.php';
require_once dirname( dirname( __DIR__ ) ) . '/includes/class-meta-box.php';

use AiTamer\LicenseManager;
use AiTamer\MetaBox;

class LicenseManagerTest extends TestCase {

	/**
	 * Setup BrainMonkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		
		// Mock standard WP functions as stubs.
		Monkey\Functions\stubs( array(
			'get_option'    => array(),
			'wp_parse_args' => function( $args, $defaults ) { return array_merge( $defaults, $args ); },
			'is_singular'   => false,
		) );
	}

	/**
	 * Teardown BrainMonkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests header injection with granular permissions.
	 */
	public function test_it_injects_granular_license_headers(): void {
		Monkey\Functions\when( 'is_singular' )->justReturn( true );
		Monkey\Functions\when( 'get_the_ID' )->justReturn( 456 );
		Monkey\Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		
		Monkey\Functions\when( 'get_post_meta' )
			->alias( function( $post_id, $key, $single ) {
				if ( '_aitamer_protection' === $key ) return 'custom';
				if ( '_aitamer_block_text' === $key ) return 'yes';
				if ( '_aitamer_block_images' === $key ) return 'no';
				if ( '_aitamer_block_video' === $key ) return 'yes';
				return '';
			} );

		$manager = new LicenseManager();
		$headers = array();
		$result  = $manager->inject_license_header( $headers );
		
		$this->assertArrayHasKey( 'AI-Content-License', $result );
		$this->assertStringContainsString( 'text=prohibited', $result['AI-Content-License'] );
		$this->assertStringContainsString( 'images=permitted', $result['AI-Content-License'] );
		$this->assertStringContainsString( 'video=prohibited', $result['AI-Content-License'] );
	}
}
