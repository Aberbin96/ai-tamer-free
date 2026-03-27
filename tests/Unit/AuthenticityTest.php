<?php

namespace AiTamer\Tests\Unit;

require_once __DIR__ . '/../../includes/pro/class-watermarker.php';
require_once __DIR__ . '/../../includes/pro/class-c2pa-manager.php';
require_once __DIR__ . '/../../includes/pro/class-heuristic-detector.php';

use AiTamer\Watermarker;
use AiTamer\C2paManager;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class AuthenticityTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();
		
		// Mock WP functions.
		Functions\stubs([
			'get_bloginfo' => 'AI Tamer Test',
			'get_locale' => 'en_US',
			'home_url' => 'https://example.com',
			'wp_json_encode' => function($data) { return json_encode($data); },
			'get_the_ID' => 123,
			'esc_html__' => function($text) { return $text; },
			'add_action' => true,
			'add_filter' => true,
			'current_time' => '2023-01-01 12:00:00',
		]);
		
		Functions\expect('is_singular')->andReturn(true);

		if (!defined('AUTH_KEY')) {
			define('AUTH_KEY', 'test_secret_key');
		}
		if (!defined('AITAMER_VERSION')) {
			define('AITAMER_VERSION', '2.0.0');
		}
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_watermarking_invisible_injection()
	{
		$content = '<p>Original content.</p>';
		$post_id = 123;

		// Mock settings to enable watermarking.
		Functions\expect('get_option')
			->with('aitamer_settings', [])
			->andReturn(['enable_watermarking' => true]);

		$watermarked = Watermarker::apply($content, $post_id);

		$this->assertStringContainsString('<p>', $watermarked);
		$this->assertStringContainsString('</p>', $watermarked);
		
		// The watermark should be before </p>.
		// ZWSP is \x{200b}, ZWNJ is \x{200c}.
		$this->assertMatchesRegularExpression('/[\x{200b}\x{200c}]+<\/p>/u', $watermarked);
	}

	public function test_stylistic_dna_substitution()
	{
		$content = '<p>Perhaps this is important.</p>';
		$post_id = 123;

		// Mock settings to enable stylistic DNA.
		Functions\expect('get_option')
			->with('aitamer_settings', [])
			->andReturn([
				'enable_watermarking' => true,
				'active_stylistic_dna' => true
			]);

		$watermarked = Watermarker::apply($content, $post_id);

		// "Perhaps" or "important" might be swapped.
		// We check for some common replacements from the map.
		$possible_words = ['maybe', 'possibly', 'relevant', 'essential'];
		$found = false;
		foreach ($possible_words as $word) {
			if (stripos($watermarked, $word) !== false) {
				$found = true;
				break;
			}
		}
		
		// Note: The substitution only happens every 3rd occurrence in the current implementation,
		// so with 1 occurrence it might NOT swap if the deterministic index doesn't match.
		// Wait, I should verify the index logic.
		// If I have 1 occurrence, $count becomes 1. 1 % 3 !== 0.
		// I'll update the test to have 3 occurrences.
		
		$content_3 = '<p>Perhaps, perhaps, perhaps.</p>';
		$watermarked_3 = Watermarker::apply($content_3, $post_id);
		
		$this->assertTrue(
			stripos($watermarked_3, 'maybe') !== false || 
			stripos($watermarked_3, 'possibly') !== false,
			'At least one synonym should be injected for 3 occurrences.'
		);
	}

	public function test_c2pa_manifest_generation()
	{
		$post_id = 123;
		$post = (object)[
			'ID' => $post_id,
			'post_title' => 'Test Post',
			'post_content' => 'Test content',
			'post_date_gmt' => '2023-01-01 00:00:00'
		];

		Functions\expect('get_post')
			->with($post_id)
			->andReturn($post);

		$c2pa = new C2paManager();
		
		// Access private method via reflection or just test the public side if possible.
		// Since generate_manifest is private, we test inject_manifest which calls it.
		
		Functions\expect('get_option')
			->with('aitamer_settings', [])
			->andReturn([
				'enable_c2pa' => true,
				'show_c2pa_badge' => true
			]);

		// Mock is_verified_human to return true (default for clean content)
		// Or better, let it run and mock the meta.
		Functions\expect('get_post_meta')
			->andReturn('yes'); // Certified human

		ob_start();
		$c2pa->inject_manifest();
		$output = ob_get_clean();

		$this->assertStringContainsString('application/ld+json', $output);
		$this->assertStringContainsString('DigitalDocument', $output);
		$this->assertStringContainsString('Certified Human Origin', $output);
		$this->assertStringContainsString('urn:sha256', $output);
	}

	public function test_heuristic_detector()
	{
		$ai_content = "As an AI model, I conclude. In conclusion, furthermore, delving into this tapestry is a testament to quality.";
		$human_content = "This is a simple sentence for testing purposes.";

		$this->assertGreaterThan(50, \AiTamer\HeuristicDetector::get_ai_score($ai_content));
		$this->assertLessThan(20, \AiTamer\HeuristicDetector::get_ai_score($human_content));
		
		$this->assertFalse(\AiTamer\HeuristicDetector::is_likely_human($ai_content));
		$this->assertTrue(\AiTamer\HeuristicDetector::is_likely_human($human_content));
	}
}
