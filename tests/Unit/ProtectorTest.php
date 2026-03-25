<?php
/**
 * ProtectorTest — Unit tests for header and meta injection.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use AiTamer\Protector;
use AiTamer\Detector;

class ProtectorTest extends TestCase {

	/**
	 * Setup BrainMonkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock standard WP functions as stubs.
		Monkey\Functions\stubs( array(
			'get_option'          => array(),
			'wp_parse_args'       => function( $args, $defaults ) { return array_merge( $defaults, $args ); },
			'home_url'            => 'https://example.com',
			'sanitize_text_field' => function( $val ) { return $val; },
			'esc_attr'            => function( $val ) { return $val; },
			'esc_url'             => function( $val ) { return $val; },
			'absint'              => function( $val ) { return (int) $val; },
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
	 * Tests that protection headers are correctly injected.
	 */
	public function test_it_injects_robots_headers(): void {
		$detector  = $this->createMock( Detector::class );
		$protector = new Protector( $detector );
		$headers   = array( 'Existing' => 'Value' );
		
		$result = $protector->inject_headers( $headers );

		$this->assertArrayHasKey( 'X-Robots-Tag', $result );
		$this->assertStringContainsString( 'noai', $result['X-Robots-Tag'] );
		$this->assertStringContainsString( 'noimageai', $result['X-Robots-Tag'] );
	}

	/**
	 * Tests meta tag output.
	 */
	public function test_it_outputs_meta_tags(): void {
		$detector  = $this->createMock( Detector::class );
		$protector = new Protector( $detector );
		
		$this->expectOutputRegex( '/<meta name="robots" content="noai/i' );
		$protector->inject_meta_tags();
	}

	/**
	 * Tests robots.txt modification.
	 */
	public function test_it_modifies_robots_txt(): void {
		$detector = $this->createMock( Detector::class );
		$detector->method( 'get_bots' )->willReturn( array(
			array( 'user_agent' => 'GPTBot', 'type' => 'training' ),
			array( 'user_agent' => 'Googlebot', 'type' => 'search' ), // Should be skipped.
		) );

		$protector = new Protector( $detector );
		$output    = "User-agent: *\nAllow: /";
		
		$result = $protector->append_robots_txt( $output, true );

		$this->assertStringContainsString( 'User-agent: GPTBot', $result );
		$this->assertStringContainsString( 'Disallow: /', $result );
		$this->assertStringNotContainsString( 'User-agent: Googlebot', $result );
	}
}
