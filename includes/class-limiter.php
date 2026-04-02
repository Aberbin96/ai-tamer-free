<?php

/**
 * Limiter — rate limiting for AI agents using Transients.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_transient;
use function set_transient;
use function do_action;
use function absint;
use function sanitize_text_field;
use function wp_unslash;
use function get_option;
use function wp_parse_args;
use function hash;
use function md5;
use function status_header;

defined('ABSPATH') || exit;

/**
 * Limiter class.
 *
 * Implements a sliding-window rate limiter using WP Transients.
 * When a bot exceeds its RPM threshold, a 429 is returned and execution stops.
 */
class Limiter
{

	/** Window in seconds (1 minute). */
	const WINDOW = 60;

	/** Default max requests per window for training bots. */
	const DEFAULT_RPM = 30;

	/**
	 * Terminates execution.
	 * Isolated for testing.
	 *
	 * @return void
	 */
	protected function terminate(): void
	{
		exit;
	}

	/**
	 * Wrapper for header().
	 *
	 * @param string $string Header string.
	 */
	protected function header(string $string): void
	{
		header($string);
	}

	/**
	 * Wrapper for status_header().
	 *
	 * @param int $code HTTP status code.
	 */
	protected function status_header(int $code): void
	{
		status_header($code);
	}

	/**
	 * Checks request rate for the given agent and blocks if over threshold.
	 *
	 * @param array $agent Classified agent from Detector::classify().
	 */
	public function check(array $agent): void
	{
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';

		// Fingerprinting Block Check (Highest Priority)
		if (get_transient('aitamer_fp_block_' . md5($ip))) {
			// Throttle security alert: once per hour per IP.
			$throttle_key = 'ait_notify_fp_' . md5($ip);
			if (! get_transient($throttle_key)) {
				set_transient($throttle_key, true, HOUR_IN_SECONDS);
				do_action('aitamer_notification', 'security_alert', array(
					'ip'         => $ip,
					'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'unknown',
					'reason'     => 'Fingerprinting validation failed (Automated behavior)',
				));
			}

			$this->status_header(403);
			$this->header('Content-Type: text/plain; charset=UTF-8');
			echo 'Access denied (Automated activity detected).'; // phpcs:ignore WordPress.Security.EscapeOutput
			$this->terminate();
		}

		if (! $agent['matched']) {
			return; // Never limit human visitors.
		}

		$settings = get_option('aitamer_settings', array());
		$settings = wp_parse_args(
			$settings,
			array('rate_limit_enabled' => true, 'rpm' => self::DEFAULT_RPM)
		);

		if (empty($settings['rate_limit_enabled'])) {
			return;
		}

		$rpm = absint($settings['rpm'] ?: self::DEFAULT_RPM);
		$key = 'aitamer_rate_' . hash('sha256', $agent['name'] . $ip);

		$count = (int) get_transient($key);
		$count++;

		set_transient($key, $count, self::WINDOW);

		if ($count > $rpm) {
			// Throttle high intensity alert: once per hour per bot per IP.
			$throttle_key = 'ait_notify_rate_' . hash('sha256', $agent['name'] . $ip);
			if (! get_transient($throttle_key)) {
				set_transient($throttle_key, true, HOUR_IN_SECONDS);
				do_action('aitamer_notification', 'high_intensity', array(
					'bot_name' => $agent['name'],
					'bot_type' => $agent['type'],
					'ip'       => $ip,
					'rpm'      => $rpm,
					'count'    => $count,
				));
			}

			$this->status_header(429);
			$this->header('Retry-After: ' . self::WINDOW);
			$this->header('Content-Type: text/plain; charset=UTF-8');
			echo 'Too Many Requests. Please retry later.'; // phpcs:ignore WordPress.Security.EscapeOutput
			$this->terminate();
		}
	}
}
