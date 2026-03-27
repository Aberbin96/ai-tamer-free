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

use function sanitize_text_field;
use function wp_unslash;

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
		// Only check if it claims to be a common browser (Chrome, Firefox, Safari, Edge).
		$is_browser_ua = preg_match( '/(Chrome|Safari|Firefox|Edg)\//i', $ua );
		if ( ! $is_browser_ua ) {
			return false;
		}

		// Modern browsers sending these headers since 2019/2020.
		// If missing completely on a "Chrome" request, it's suspicious.
		$dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
		$mode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';

		if ( empty( $dest ) || empty( $mode ) ) {
			// Most simple scrapers (python-requests, axios, curl) don't send these.
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
}
