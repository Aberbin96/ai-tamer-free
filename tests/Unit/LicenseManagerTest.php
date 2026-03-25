<?php
/**
 * LicenseManagerTest — Unit tests for license management.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use AiTamer\LicenseManager;

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
	 * Tests header injection.
	 */
	public function test_it_injects_license_header(): void {
		Monkey\Functions\expect( 'get_bloginfo' )->andReturn( 'Test Site' );

		$manager = new LicenseManager();
		$headers = array();
		$result  = $manager->inject_license_header( $headers );
		
		$this->assertArrayHasKey( 'AI-Content-License', $result );
		$this->assertStringContainsString( 'training=prohibited', $result['AI-Content-License'] );
	}
}
