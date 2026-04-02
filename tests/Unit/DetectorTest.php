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
use Mockery;

if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
if (!defined('WEEK_IN_SECONDS')) define('WEEK_IN_SECONDS', 604800);
if (!defined('MONTH_IN_SECONDS')) define('MONTH_IN_SECONDS', 2592000);

class DetectorTest extends TestCase
{

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs(array(
			'sanitize_text_field' => function ($val) {
				return $val;
			},
			'wp_unslash'          => function ($val) {
				return $val;
			},
			'set_transient'       => true,
			'get_option'          => ['notifications_enabled' => true],
			'wp_parse_args'       => function ($args, $defaults) {
				return array_merge($defaults, $args);
			},
		));
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider provide_ai_user_agents
	 */
	public function test_it_identifies_ai_bots(string $ua, string $expected_name, string $expected_type = 'training'): void
	{
		$_SERVER['HTTP_USER_AGENT'] = $ua;
		$detector = new Detector();
		$result   = $detector->classify();
		$this->assertTrue($result['matched'], "Failed to match: $ua");
		$this->assertEquals($expected_name, $result['name']);
		$this->assertEquals($expected_type, $result['type']);
	}

	public function test_it_whitelists_dev_tools_when_enabled(): void
	{
		$_SERVER['HTTP_USER_AGENT'] = 'curl/7.68.0';
		
		// 1. Setting disabled (default) -> Should be a bot
		Monkey\Functions\when('get_option')->justReturn(['whitelist_dev_tools' => false]);
		$detector = new Detector();
		$result = $detector->classify();
		$this->assertTrue($result['matched'], 'curl should be a bot when whitelisting is OFF');
		$this->assertEquals('scraper', $result['type']);

		// 2. Setting enabled -> Should be human
		Monkey\Functions\when('get_option')->justReturn(['whitelist_dev_tools' => true]);
		$result = (new Detector())->classify();
		$this->assertFalse($result['matched'], 'curl should be human when whitelisting is ON');
		$this->assertEquals('human', $result['name']);
	}

	public function test_it_prioritizes_google_extended_over_googlebot(): void
	{
		// This UA contains both "Googlebot" and "Google-Extended"
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html) Google-Extended';
		$detector = new Detector();
		$result = $detector->classify();
		$this->assertEquals('Google-Extended', $result['name']);
		$this->assertEquals('training', $result['type']);
	}

	public function test_it_allows_normal_googlebot_as_search(): void
	{
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$detector = new Detector();
		$result = $detector->classify();
		$this->assertEquals('Googlebot', $result['name']);
		$this->assertEquals('search', $result['type']);
	}

	public function test_it_does_not_match_human_visitors(): void
	{
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
		$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
		$_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
		$_SERVER['HTTP_SEC_CH_UA'] = '"Chromium";v="122"';
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
		$detector = new Detector();
		$result   = $detector->classify();
		$this->assertFalse($result['matched'], 'Human visitor was incorrectly matched.');
	}

	public function test_it_identifies_anomalous_stealth_bots(): void
	{
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
		unset($_SERVER['HTTP_SEC_FETCH_DEST']);
		unset($_SERVER['HTTP_SEC_CH_UA']);
		$_SERVER['HTTP_ACCEPT'] = '*/*';
		$detector = new Detector();
		$result   = $detector->classify();
		$this->assertTrue($result['matched'], 'Anomaly detector failed to catch stealth bot.');
		$this->assertEquals('stealth_bot', $result['name']);
	}

	public function test_it_triggers_notification_for_new_bots(): void
	{
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';

		Monkey\Functions\expect('get_transient')
			->zeroOrMoreTimes()
			->andReturn(false);

		Monkey\Actions\expectDone('aitamer_notification')
			->once()
			->with('new_bot', Mockery::type('array'));

		$detector = new Detector();
		$result = $detector->classify();

		$this->assertTrue($result['matched'], 'Detector failed to match GPTBot');
	}

	public function provide_ai_user_agents(): array
	{
		return array(
			array('Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)', 'GPTBot'),
			array('Mozilla/5.0 (compatible; ClaudeBot/1.0; +https://anthropic.com/claudebot)', 'ClaudeBot'),
			array('Mozilla/5.0 (compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)', 'AmazonBot'),
			array('FacebookBot/1.0', 'FacebookBot'),
			array('Mozilla/5.0 (compatible; Google-Extended)', 'Google-Extended'),
			array('PostmanRuntime/7.26.8', 'Postman', 'scraper'),
		);
	}
}
