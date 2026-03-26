<?php
/**
 * MetaBoxTest — Unit tests for the MetaBox setting retrieval.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

// Manually require classes if autoloader fails
require_once dirname( dirname( __DIR__ ) ) . '/includes/class-meta-box.php';

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
			->atLeast()->once()
			->with( 123, '_aitamer_protection', true )
			->andReturn( 'block_all' );

		$setting = MetaBox::get_setting( 123 );
		$this->assertEquals( 'block_all', $setting );
	}

	/**
	 * Tests granular metadata retrieval.
	 */
	public function test_granular_meta_retrieval(): void {
		Monkey\Functions\expect( 'get_post_meta' )
			->atLeast()->once()
			->with( 123, '_aitamer_block_images', true )
			->andReturn( 'yes' );

		$value = get_post_meta( 123, '_aitamer_block_images', true );
		$this->assertEquals( 'yes', $value );
	}
}
