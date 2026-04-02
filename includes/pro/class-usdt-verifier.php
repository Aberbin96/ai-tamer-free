<?php

namespace AiTamer;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * USDTVerifier - Handles blockchain transaction verification via remote Vercel API.
 */
class USDTVerifier
{
	/**
	 * Returns the remote verifier API endpoint.
	 * Default to a placeholder, but in production this should be the Vercel API URL.
	 */
	public function get_api_url(): string
	{
		$settings = get_option('aitamer_settings', array());
		return $settings['usdt_verifier_url'] ?? 'https://verifier.aitamer.io/api/verify';
	}

	/**
	 * Generates a unique USDT amount for a specific post to avoid transaction collisions.
	 * 
	 * Example: 0.10 (base) + 0.000042 (post_id 42) = 0.100042 USDT
	 *
	 * @param float $base_price The base price in USD/USDT.
	 * @param int   $post_id    The WordPress post ID.
	 * @return float The unique amount with 6 decimal precision.
	 */
	public static function get_unique_amount(float $base_price, int $post_id): float
	{
		// We use 6 decimal places to embed the post_id as a unique identifier.
		return round($base_price + ($post_id / 1000000), 6);
	}

	/**
	 * Calls the remote Vercel API to verify a transaction hash.
	 *
	 * @param string $hash      The transaction hash (X-Payment-Hash).
	 * @param float  $amount    The expected unique amount.
	 * @param string $recipient The user's USDT wallet address.
	 * @param string $network   The blockchain network (polygon, ethereum, etc.).
	 * @return bool|WP_Error True if verified, false if not, or WP_Error on API failure.
	 */
	public function verify(string $hash, float $amount, string $recipient, string $network)
	{
		$url = $this->get_api_url();
		
		$query_url = add_query_arg(array(
			'hash'      => sanitize_text_field($hash),
			'amount'    => (string) $amount,
			'recipient' => sanitize_text_field($recipient),
			'network'   => sanitize_text_field($network),
		), $url);

		$response = wp_remote_get($query_url, array(
			'timeout' => 15,
			'headers' => array(
				'Accept' => 'application/json',
			),
		));

		if (is_wp_error($response)) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body   = json_decode(wp_remote_retrieve_body($response), true);

		// The Verifier API should return { "verified": true } if the tx matches.
		if (200 === $status && ! empty($body['verified'])) {
			return true;
		}

		return false;
	}
}
