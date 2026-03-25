<?php
/**
 * BandwidthLimiter — limits the total data served to AI bots per crawl session.
 *
 * Tracks KB served to each bot (keyed by SHA-256 of bot name + IP) using
 * WP Transients with a 24-hour window. When the per-day cap is exceeded,
 * a 429 is returned and execution stops.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_option;
use function wp_parse_args;
use function get_transient;
use function set_transient;
use function sanitize_text_field;
use function wp_unslash;
use function status_header;
use function absint;

defined( 'ABSPATH' ) || exit;

/**
 * BandwidthLimiter class.
 */
class BandwidthLimiter {

	/** Window for the bandwidth budget: 24 hours (in seconds). */
	const WINDOW = DAY_IN_SECONDS;

	/** Default max KB served per bot per day. */
	const DEFAULT_KB_LIMIT = 5120; // 5 MB.

	/**
	 * Retrieves the relevant settings.
	 *
	 * @return array
	 */
	private function get_settings(): array {
		$defaults = array(
			'bandwidth_limit_enabled' => true,
			'bandwidth_kb_limit'      => self::DEFAULT_KB_LIMIT,
		);
		return wp_parse_args( get_option( 'aitamer_settings', array() ), $defaults );
	}

	/**
	 * Checks remaining bandwidth budget and blocks if exhausted.
	 * Call *after* the response body size is known (or estimate it).
	 *
	 * @param array $agent  Classified agent from Detector::classify().
	 * @param int   $kb_est Estimated response size in KB for this request.
	 */
	public function check( array $agent, int $kb_est = 50 ): void {
		if ( ! $agent['matched'] ) {
			return; // Never limit humans.
		}

		$settings = $this->get_settings();

		if ( empty( $settings['bandwidth_limit_enabled'] ) ) {
			return;
		}

		$limit = absint( $settings['bandwidth_kb_limit'] ?: self::DEFAULT_KB_LIMIT );
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'aitamer_bw_' . hash( 'sha256', $agent['name'] . $ip );

		$used = (int) get_transient( $key );

		if ( $used >= $limit ) {
			status_header( 429 );
			header( 'Retry-After: ' . self::WINDOW );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'Bandwidth limit reached. Please retry tomorrow.'; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		// Accumulate usage; set or refresh the transient.
		set_transient( $key, $used + $kb_est, self::WINDOW );
	}
}
