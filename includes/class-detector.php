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
		return $this->current;
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
