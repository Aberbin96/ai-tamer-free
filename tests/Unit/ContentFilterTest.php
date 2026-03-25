<?php
/**
 * ContentFilterTest — Unit tests for the content triaging logic.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use AiTamer\ContentFilter;
use AiTamer\Detector;

class ContentFilterTest extends TestCase {

	/**
	 * Setup BrainMonkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock standard WP functions.
		Monkey\Functions\expect( 'is_singular' )->andReturn( true );
		Monkey\Functions\expect( 'get_the_ID' )->andReturn( 123 );
		Monkey\Functions\expect( 'get_option' )->andReturn( array() );
		Monkey\Functions\expect( 'get_post_meta' )->andReturn( 'inherit' );
	}

	/**
	 * Teardown BrainMonkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that data-noai elements are stripped for bots.
	 */
	public function test_it_strips_noai_content_for_bots(): void {
		$detector = $this->createMock( Detector::class );
		$detector->method( 'classify' )->willReturn( array(
			'matched' => true,
			'name'    => 'GPTBot',
			'type'    => 'training',
		) );
		$detector->method( 'is_training_agent' )->willReturn( true );

		$filter  = new ContentFilter( $detector );
		$content = '<p>Visible</p><div data-noai>Hidden</div><p>Visible also</p>';
		$result  = $filter->filter_content( $content );

		$this->assertStringContainsString( 'Visible', $result );
		$this->assertStringNotContainsString( 'Hidden', $result );
		$this->assertStringNotContainsString( 'data-noai', $result );
	}

	/**
	 * Tests that content is NOT stripped for human visitors.
	 */
	public function test_it_does_not_strip_content_for_humans(): void {
		$detector = $this->createMock( Detector::class );
		$detector->method( 'classify' )->willReturn( array(
			'matched' => false,
			'name'    => 'human',
			'type'    => 'human',
		) );

		$filter  = new ContentFilter( $detector );
		$content = '<p>Visible</p><div data-noai>Stay</div>';
		$result  = $filter->filter_content( $content );

		$this->assertStringContainsString( 'Visible', $result );
		$this->assertStringContainsString( 'Stay', $result );
	}
}
