<?php
/**
 * Agent Detection Engine.
 *
 * Classifies incoming requests as 'human', 'search', 'training', or 'scraper'
 * by matching User-Agent strings against the known-bots list.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function stripos;
use function md5;
use function get_transient;
use function set_transient;
use function do_action;
use function sanitize_text_field;
use function wp_unslash;
use function apply_filters;

defined( 'ABSPATH' ) || exit;

/**
 * Detector class.
 */
class Detector {

	/** @var array Loaded bot definitions from data/bots.json. */
	private array $bots = array();

	/** @var array|null Cache for the current request classification. */
	private ?array $current = null;

	/**
	 * Constructor — loads the bot list.
	 */
	public function __construct() {
		$this->bots = $this->load_bots();
	}

	/**
	 * Loads bot definitions from the JSON data file.
	 *
	 * @return array
	 */
	private function load_bots(): array {
		$file = AITAMER_PLUGIN_DIR . 'data/bots.json';
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$data = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data['bots'] ?? null ) ? $data['bots'] : array();
	}

	/**
	 * Classifies the current HTTP request.
	 * Results are cached for the duration of the PHP process.
	 *
	 * @return array{name: string, type: string, matched: bool}
	 */
	public function classify(): array {
		if ( null !== $this->current ) {
			return $this->current;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		foreach ( $this->bots as $bot ) {
			$pattern = isset( $bot['user_agent'] ) ? $bot['user_agent'] : '';
			if ( $pattern && false !== stripos( $ua, $pattern ) ) {
				$this->current = array(
					'name'    => $bot['name'],
					'type'    => $bot['type'],
					'matched' => true,
				);

				// Notify if this bot was not seen in the last 24h.
				$notify_key = 'ait_notify_new_bot_' . md5( $bot['name'] );
				if ( ! get_transient( $notify_key ) ) {
					set_transient( $notify_key, true, DAY_IN_SECONDS );
					do_action( 'aitamer_notification', 'new_bot', array(
						'bot_name'   => $bot['name'],
						'bot_type'   => $bot['type'],
						'user_agent' => $ua,
					) );
				}

				return $this->current;
			}
		}

		// No match — treat as a human visitor.
		$this->current = array(
			'name'    => 'human',
			'type'    => 'human',
			'matched' => false,
		);

		// Advanced fingerprinting: Check for "stealth" bots via Sec-Fetch headers.
		// Legit browsers send Sec-Fetch-Dest, Sec-Fetch-Mode, etc.
		// If these are missing or anomalous while claimining to be a browser, it's likely a bot.
		if ( $this->is_anomalous_request( $ua ) ) {
			$this->current = array(
				'name'    => 'stealth_bot',
				'type'    => 'scraper',
				'matched' => true,
			);
		}

		return $this->current;
	}

	/**
	 * Checks if the request is anomalous based on browser headers.
	 *
	 * @param string $ua User Agent.
	 * @return bool
	 */
	private function is_anomalous_request( string $ua ): bool {
		// Only check if it claims to be a common browser (Chrome, Safari, Firefox, Edge).
		$is_browser_ua = preg_match( '/(Chrome|Safari|Firefox|Edg)\//i', $ua );
		if ( ! $is_browser_ua ) {
			return false; // If it doesn't pretend to be a browser, let list-based detection handle it.
		}

		$anomaly_score = 0;

		// 1. Missing Accept-Language
		// Real browsers ALWAYS send their language preferences (e.g., es-ES, en-US). Scrapers/Bots often skip it.
		$accept_language = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
		if ( empty( $accept_language ) ) {
			$anomaly_score += 2;
		}

		// 2. Anomalous Accept Header
		// A standard browser navigating a page explicitly asks for HTML. Bots might use wildcard '*/*'.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : '';
		if ( empty( $accept ) || $accept === '*/*' || $accept === 'application/json' ) {
			$anomaly_score += 1;
		}

		// 3. Missing Modern Fetch Metadata (Since ~2020)
		// Missing Sec-Fetch on a request claiming to be a modern Chrome/Firefox is highly suspicious.
		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';
		$mode = isset( $_SERVER['HTTP_SEC_FETCH_MODE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_MODE'] ) ) : '';
		
		if ( empty( $dest ) || empty( $mode ) ) {
			$anomaly_score += 2;
		}

		// 4. Missing Client Hints (Chrome/Edge 89+)
		// If UA says Chrome 90+ but has no Sec-CH-UA, it's very likely a spoofed scraper library.
		if ( preg_match( '/(?:Chrome|Edg)\/([0-9]{2,})/', $ua, $matches ) ) {
			$version = (int) $matches[1];
			// Apple limits Client Hints on WebKit/Safari, so we only strictly penalize non-Safari engines impersonating Chrome
			if ( $version >= 90 && false === stripos( $ua, 'Safari' ) || preg_match( '/Chrome\//i', $ua ) ) {
				$ch_ua = isset( $_SERVER['HTTP_SEC_CH_UA'] ) ? $_SERVER['HTTP_SEC_CH_UA'] : '';
				if ( empty( $ch_ua ) ) {
					$anomaly_score += 2;
				}
			}
		}

		// If the score reaches 3 or more, it has accumulated too many critical falsehoods for a real browser.
		if ( $anomaly_score >= 3 ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns true if this request is from a known training/scraper bot.
	 *
	 * @return bool
	 */
	public function is_training_agent(): bool {
		$agent = $this->classify();
		return in_array( $agent['type'], array( 'training', 'scraper' ), true );
	}

	/**
	 * Returns true if this request is from any known bot.
	 *
	 * @return bool
	 */
	public function is_bot(): bool {
		$agent = $this->classify();
		return $agent['matched'];
	}

	/**
	 * Returns all loaded bot definitions.
	 *
	 * @return array
	 */
	public function get_bots(): array {
		return $this->bots;
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
