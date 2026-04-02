<?php

/**
 * Detector — classifies requests as AI agents or humans.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function hash;
use function md5;
use function stripos;
use function preg_match;
use function explode;
use function trim;
use function is_array;
use function array_merge;
use function json_decode;
use function file_get_contents;
use function file_exists;

defined('ABSPATH') || exit;

/**
 * Detector class.
 *
 * Checks User-Agent strings against a known list of AI crawlers
 * and performs basic heuristic validation.
 */
class Detector
{

	/** @var array Cached bot definitions. */
	private array $bots = array();

	/**
	 * Constructor.
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

		// Use WP_Filesystem if available, otherwise fallback to safe read for test environments.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			$file_inc = ABSPATH . 'wp-admin/includes/file.php';
			if ( file_exists( $file_inc ) ) {
				require_once $file_inc;
				\WP_Filesystem();
			}
		}

		if ( ! empty( $wp_filesystem ) ) {
			$json = $wp_filesystem->get_contents( $file );
		} else {
			// Fallback for tests/CLI where WP_Filesystem isn't bootstrapped.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json = @file_get_contents( $file );
		}

		if ( ! $json ) {
			return array();
		}

		$data = json_decode( $json, true );
		return is_array( $data['bots'] ?? null ) ? $data['bots'] : array();
	}

	/**
	 * Classifies the current request.
	 *
	 * @return array{matched: bool, name: string, type: string, confidence: float}
	 */
	public function classify(): array {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		
		// Whitelist Dev Tools (curl, Postman, etc.) if enabled.
		$settings = \get_option('aitamer_settings', array());
		if ( ! empty( $settings['whitelist_dev_tools'] ) ) {
			if ( preg_match( '/(curl|PostmanRuntime|Wget)/i', $ua ) ) {
				return array(
					'matched'    => false,
					'name'       => 'human',
					'type'       => 'human',
					'confidence' => 1.0,
				);
			}
		}

		if ( empty( $ua ) ) {
			return array(
				'matched'    => true,
				'name'       => 'Anonymous Scraper',
				'type'       => 'scraper',
				'confidence' => 0.5,
			);
		}

		// 1. Exact list match.
		foreach ( $this->bots as $bot ) {
			if ( stripos( $ua, (string) $bot['user_agent'] ) !== false ) {
				// Trigger notification for first-time detection in this window.
				$this->maybe_notify_new_bot($bot);

				return array(
					'matched'    => true,
					'name'       => $bot['name'],
					'type'       => $bot['type'],
					'confidence' => 1.0,
				);
			}
		}

		// 2. Heuristic check: look for "bot", "crawler", "scraper", "spider".
		if ( preg_match( '/(bot|crawler|scraper|spider)/i', $ua ) ) {
			return array(
				'matched'    => true,
				'name'       => 'Generic Bot',
				'type'       => 'scraper',
				'confidence' => 0.8,
			);
		}

		// 3. Behavioral fingerprint (Anomaly Detection).
		if ( $this->is_anomalous_request( $ua ) ) {
			return array(
				'matched'    => true,
				'name'       => 'Anomalous Bot',
				'type'       => 'scraper',
				'confidence' => 0.6,
			);
		}

		return array(
			'matched'    => false,
			'name'       => 'Human Visitor',
			'type'       => 'human',
			'confidence' => 1.0,
		);
	}

	/**
	 * Notifies about a new bot detection if not already notified recently.
	 */
	private function maybe_notify_new_bot( array $bot ): void {
		$key = 'ait_notify_new_' . md5( (string) $bot['name'] );
		if ( ! \get_transient( $key ) ) {
			\set_transient( $key, true, DAY_IN_SECONDS );
			\do_action( 'aitamer_notification', 'new_bot', $bot );
		}
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
			return false;
		}

		$anomaly_score = 0;

		// 1. Missing Accept-Language
		$accept_language = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		if ( empty( $accept_language ) ) {
			$anomaly_score += 2;
		}

		// 2. Anomalous Accept Header
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( empty( $accept ) || $accept === '*/*' || $accept === 'application/json' ) {
			$anomaly_score += 1;
		}

		// 3. Missing Modern Fetch Metadata
		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';
		$mode = isset( $_SERVER['HTTP_SEC_FETCH_MODE'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_SEC_FETCH_MODE'] ) ) : '';
		
		if ( empty( $dest ) || empty( $mode ) ) {
			$anomaly_score += 2;
		}

		// 4. Missing Client Hints (Chrome/Edge 89+)
		if ( preg_match( '/(?:Chrome|Edg)\/([0-9]{2,})/', $ua, $matches ) ) {
			$version = (int) $matches[1];
			if ( $version >= 90 && ( false === stripos( $ua, 'Safari' ) || preg_match( '/Chrome\//i', $ua ) ) ) {
				$ch_ua = isset( $_SERVER['HTTP_SEC_CH_UA'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_SEC_CH_UA'] ) ) : '';
				if ( empty( $ch_ua ) ) {
					$anomaly_score += 2;
				}
			}
		}

		return $anomaly_score >= 3;
	}

	/**
	 * Returns true if current agent is classified as training/scraper.
	 *
	 * @return bool
	 */
	public function is_training_agent(): bool {
		$agent = $this->classify();
		return in_array( $agent['type'] ?? '', array( 'training', 'scraper' ), true );
	}

	/**
	 * Returns list of bots.
	 *
	 * @return array
	 */
	public function get_bots(): array {
		return $this->bots;
	}
}
