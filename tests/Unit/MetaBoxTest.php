<?php
/**
 * MetaBoxTest — Unit tests for the MetaBox setting retrieval.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use AiTamer\MetaBox;

class MetaBoxTest extends TestCase {

	/**
	 * Setup BrainMonkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Teardown BrainMonkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests get_setting method.
	 */
	public function test_get_setting_returns_correct_value(): void {
		Monkey\Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, '_aitamer_protection', true )
			->andReturn( 'block_all' );

		$setting = MetaBox::get_setting( 123 );
		$this->assertEquals( 'block_all', $setting );
	}

	/**
	 * Tests default value for get_setting.
	 */
	public function test_get_setting_returns_inherit_by_default(): void {
		Monkey\Functions\expect( 'get_post_meta' )
			->once()
			->andReturn( false );

		$setting = MetaBox::get_setting( 123 );
		$this->assertEquals( 'inherit', $setting );
	}
}
