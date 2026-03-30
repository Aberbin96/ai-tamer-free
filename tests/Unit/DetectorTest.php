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
		$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
		$_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
		$_SERVER['HTTP_SEC_CH_UA'] = '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"';

		$detector = new Detector();
		$result   = $detector->classify();

		$this->assertFalse( $result['matched'], 'Human visitor was incorrectly matched as a bot.' );
		$this->assertEquals( 'human', $result['name'], 'Human visitor was not named human.' );
	}

	/**
	 * Tests that stealth bots impersonating Chrome without proper headers are blocked.
	 */
	public function test_it_identifies_anomalous_stealth_bots(): void {
		// Scraper pretending to be Chrome 120 but missing crucial modern headers.
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
		unset( $_SERVER['HTTP_SEC_FETCH_DEST'] );
		unset( $_SERVER['HTTP_SEC_FETCH_MODE'] );
		unset( $_SERVER['HTTP_SEC_CH_UA'] );
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		// Scrapers typically request wildcard
		$_SERVER['HTTP_ACCEPT'] = '*/*';

		$detector = new Detector();
		$result   = $detector->classify();

		$this->assertTrue( $result['matched'], 'Anomaly detector failed to catch stealth bot.' );
		$this->assertEquals( 'stealth_bot', $result['name'] );
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
