<?php
/**
 * Limiter — rate limiting for AI agents using Transients.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_transient;
use function set_transient;
use function get_option;
use function wp_parse_args;
use function status_header;
use function sanitize_text_field;
use function wp_unslash;

defined( 'ABSPATH' ) || exit;

/**
 * Limiter class.
 *
 * Implements a sliding-window rate limiter using WP Transients.
 * When a bot exceeds its RPM threshold, a 429 is returned and execution stops.
 */
class Limiter {

	/** Window in seconds (1 minute). */
	const WINDOW = 60;

	/** Default max requests per window for training bots. */
	const DEFAULT_RPM = 30;

	/**
	 * Checks request rate for the given agent and blocks if over threshold.
	 *
	 * @param array $agent Classified agent from Detector::classify().
	 */
	public function check( array $agent ): void {
		if ( ! $agent['matched'] ) {
			return; // Never limit human visitors.
		}

		$settings = get_option( 'aitamer_settings', array() );
		$settings = wp_parse_args(
			$settings,
			array( 'rate_limit_enabled' => true, 'rpm' => self::DEFAULT_RPM )
		);

		if ( empty( $settings['rate_limit_enabled'] ) ) {
			return;
		}

		$rpm = absint( $settings['rpm'] ?: self::DEFAULT_RPM );
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'aitamer_rate_' . hash( 'sha256', $agent['name'] . $ip );

		$count = (int) get_transient( $key );
		$count++;

		set_transient( $key, $count, self::WINDOW );

		if ( $count > $rpm ) {
			status_header( 429 );
			header( 'Retry-After: ' . self::WINDOW );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'Too Many Requests. Please retry later.'; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
	}
}
