<?php

/**
 * Heuristic Detector — lightweight AI pattern matching.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function wp_strip_all_tags;

defined('ABSPATH') || exit;

/**
 * HeuristicDetector class.
 */
class HeuristicDetector
{
	/**
	 * Common AI-generated phrases and markers (English and Spanish).
	 */
	private static array $ai_markers = array(
		// English Indicators
		'as an ai model',
		'important to note',
		'in conclusion',
		'furthermore',
		'moreover',
		'it should be noted',
		'delve into',
		'tapestry of',
		'testament to',
		'in the modern era',
		'pivotal role',
		'transformation of global society',
		'increasingly digitized future',
		'essential to understand',
		'optimize processes',
		'not limited exclusively to',
		'significantly impacts',
		'phenomenon also brings about',
		'critical and balanced perspective',

		// Spanish Indicators
		'como modelo de ai',
		'es importante notar',
		'en conclusión',
		'además',
		'por otro lado',
		'cabe destacar',
		'profundizar en',
		'tapiz de',
		'testimonio de',
		'en la era contemporánea',
		'papel fundamental',
		'transformación de la sociedad',
		'futuro cada vez más digitalizado',
		'es esencial comprender',
		'optimizar procesos',
		'no se limita exclusivamente a',
		'impacta significativamente',
		'fenómeno también conlleva',
		'perspectiva crítica y equilibrada',
	);

	/**
	 * Analyzes content and returns an AI probability score (0-100).
	 *
	 * @param string $content HTML or plain text content.
	 * @return int Probability score.
	 */
	public static function get_ai_score(string $content): int
	{
		$text = function_exists('\wp_strip_all_tags') ? \wp_strip_all_tags($content) : strip_tags($content);
		
		// Use mb_strtolower for Unicode/Spanish support if available.
		if (function_exists('mb_strtolower')) {
			$text = mb_strtolower($text, 'UTF-8');
		} else {
			$text = strtolower($text);
		}
		
		$found_markers = 0;
		foreach (self::$ai_markers as $marker) {
			if (strpos($text, $marker) !== false) {
				$found_markers++;
			}
		}

		// Density-Based Scoring (Markers per 100 words).
		// This provides a much more accurate result for mixed content and long articles.
		$words      = explode(' ', $text);
		$word_count = count($words);
		
		// Avoid division by zero for empty content.
		if ($word_count < 10) {
			return $found_markers > 0 ? 35 : 10;
		}

		$density = ($found_markers / $word_count) * 100;

		// Scoring based on Density + Minimum absolute markers.
		// Density of 4.0+ in AI filler text is extremely high confidence.
		if ($density >= 4.0 && $found_markers >= 5) {
			return 98;
		} elseif ($density >= 2.0 && $found_markers >= 3) {
			return 85;
		} elseif ($density >= 1.0 && $found_markers >= 2) {
			return 65;
		} elseif ($found_markers >= 1) {
			return 35;
		}

		return 10;

		return 10;
	}

	/**
	 * Returns true if content is likely human-generated.
	 *
	 * @param string $content Content to check.
	 * @param int    $threshold Max allowable AI score.
	 * @return bool
	 */
	public static function is_likely_human(string $content, int $threshold = 50): bool
	{
		return self::get_ai_score($content) < $threshold;
	}

	/**
	 * Evaluates fingerprint data to detect headless browsers and scrapers.
	 *
	 * @param array $data Fingerprint payload.
	 * @return int Risk score (0-100).
	 */
	public static function evaluate_fingerprint(array $data): int
	{
		$score = 0;

		// 1. Direct Headless indicator (Selenium, Puppeteer without stealth)
		if (!empty($data['webdriver']) && $data['webdriver'] === true) {
			$score += 50;
		}

		// 2. Chrome object missing (Headless Chrome defaults)
		if (isset($data['chrome']) && $data['chrome'] === false) {
			$score += 30;
		}

		// 3. No plugins or MIME types installed (headless environments)
		if (isset($data['plugins']) && (int)$data['plugins'] === 0) {
			$score += 20;
		}

		// 4. Software WebGL renderers (run on VPS without GPUs)
		if (!empty($data['webgl'])) {
			$webgl = strtolower($data['webgl']);
			if (strpos($webgl, 'swiftshader') !== false || strpos($webgl, 'llvmpipe') !== false || strpos($webgl, 'mesa offscreen') !== false) {
				$score += 40;
			}
		}

		// 5. Unrealistic window dimensions
		if (isset($data['innerWidth']) && isset($data['outerWidth'])) {
			if ($data['innerWidth'] === 800 && $data['outerWidth'] === 800) {
				$score += 15; // Common default viewport for puppeteer
			}
		}

		return min(100, $score);
	}
}
