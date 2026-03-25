<?php
/**
 * DetectorTest — Unit tests for the bot detection engine.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use AiTamer\Detector;

class DetectorTest extends TestCase {

	/**
	 * Setup BrainMonkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock standard WP functions used in Detector.
		Monkey\Functions\expect( 'sanitize_text_field' )
			->andReturnFirstArg();
		Monkey\Functions\expect( 'wp_unslash' )
			->andReturnFirstArg();
	}

	/**
	 * Teardown BrainMonkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that known AI bots are correctly identified.
	 *
	 * @dataProvider provide_ai_user_agents
	 */
	public function test_it_identifies_ai_bots( string $ua, string $expected_name ): void {
		$_SERVER['HTTP_USER_AGENT'] = $ua;
		
		$detector = new Detector();
		$result   = $detector->classify();

		$this->assertTrue( $result['matched'], "Failed to match: $ua" );
		$this->assertEquals( $expected_name, $result['name'] );
	}

	/**
	 * Tests that human visitors are NOT identified as bots.
	 */
	public function test_it_does_not_match_human_visitors(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

		$detector = new Detector();
		$result   = $detector->classify();

		$this->assertFalse( $result['matched'] );
		$this->assertEquals( 'human', $result['name'] );
	}

	/**
	 * Data provider for AI user agents.
	 */
	public function provide_ai_user_agents(): array {
		return array(
			array( 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)', 'GPTBot' ),
			array( 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +https://anthropic.com/claudebot)', 'ClaudeBot' ),
			array( 'Mozilla/5.0 (compatible; Google-Extended; +https://example.com)', 'Google-Extended' ),
			array( 'CCBot/2.0 (https://commoncrawl.org/faq/)', 'CCBot' ),
		);
	}
}
