<?php

/**
 * PricingEngine — Dynamic pricing for Lightning (L402) micropayments.
 *
 * Resolves the final price in Satoshis for a given post+agent by:
 * 1. Checking per-post meta override (_aitamer_price_sats).
 * 2. Falling back to the global setting (manual sats or fiat→sats conversion).
 * 3. Applying dynamic multipliers based on bot type and content length.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use WP_Error;
use function get_option;
use function get_post_meta;
use function get_post;
use function get_transient;
use function set_transient;
use function wp_remote_get;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function is_wp_error;
use function str_word_count;
use function strip_tags;
use function apply_filters;
use function absint;

defined('ABSPATH') || exit;

/**
 * Class PricingEngine
 */
class PricingEngine
{
	/** Transient key for cached BTC exchange rate. */
	const RATE_TRANSIENT_KEY = 'aitamer_btc_rate';

	/** Cache TTL in seconds (15 minutes). */
	const RATE_CACHE_TTL = 900;

	/** CoinGecko free API endpoint. */
	const COINGECKO_API = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd,eur';

	/** Satoshis per Bitcoin. */
	const SATS_PER_BTC = 100_000_000;

	/**
	 * Returns the final price in Satoshis for a given post and agent.
	 *
	 * This is the main entry point used by the REST API and Protector.
	 *
	 * @param int   $post_id Post ID (0 for global/default).
	 * @param array $agent   Agent classification array from Detector::classify().
	 * @return int Price in Satoshis (always >= 1).
	 */
	public static function get_price(int $post_id, array $agent = []): int
	{
		$base_sats = self::get_base_price($post_id);

		$settings = get_option('aitamer_settings', []);
		$dynamic_enabled = !empty($settings['lnbits_dynamic_pricing']);

		if (!$dynamic_enabled || empty($agent)) {
			return max(1, $base_sats);
		}

		$bot_multiplier     = self::get_bot_multiplier($agent);
		$content_multiplier = self::get_content_multiplier($post_id);

		/**
		 * Filter the combined pricing multiplier.
		 *
		 * @param float $multiplier  Combined multiplier (bot × content).
		 * @param int   $post_id     Post ID.
		 * @param array $agent       Agent classification.
		 * @param int   $base_sats   Base price before multipliers.
		 */
		$multiplier = apply_filters(
			'aitamer_pricing_multiplier',
			$bot_multiplier * $content_multiplier,
			$post_id,
			$agent,
			$base_sats
		);

		$final = (int) ceil($base_sats * max(1.0, (float) $multiplier));

		return max(1, $final);
	}

	/**
	 * Returns the base price in Satoshis for a post (before multipliers).
	 *
	 * Resolution order:
	 * 1. Per-post override (_aitamer_price_sats).
	 * 2. Global setting: fiat→sats conversion OR manual sats.
	 *
	 * @param int $post_id Post ID (0 for global default).
	 * @return int Base price in Satoshis.
	 */
	public static function get_base_price(int $post_id): int
	{
		// 1. Check per-post override.
		if ($post_id > 0) {
			$override = get_post_meta($post_id, '_aitamer_price_sats', true);
			if ('' !== $override && false !== $override) {
				$override_int = absint($override);
				if ($override_int > 0) {
					return $override_int;
				}
			}
		}

		// 2. Global setting.
		$settings = get_option('aitamer_settings', []);
		$mode     = $settings['lnbits_pricing_mode'] ?? 'manual';

		if ('fiat' === $mode) {
			$currency = $settings['lnbits_pricing_currency'] ?? 'usd';
			$fiat     = (float) ($settings['lnbits_pricing_fiat'] ?? 0.01);

			$sats = self::convert_fiat_to_sats($fiat, $currency);
			if ($sats > 0) {
				/**
				 * Filter the base price after fiat conversion.
				 *
				 * @param int    $sats     Converted price in Satoshis.
				 * @param float  $fiat     Original fiat amount.
				 * @param string $currency Currency code.
				 * @param int    $post_id  Post ID.
				 */
				return (int) apply_filters('aitamer_base_price_sats', $sats, $fiat, $currency, $post_id);
			}
		}

		// Fallback: manual sats setting.
		$manual = absint($settings['lnbits_price_sats'] ?? 100);

		return max(1, $manual);
	}

	/**
	 * Converts a fiat amount to Satoshis using the cached exchange rate.
	 *
	 * @param float  $fiat     Amount in fiat currency (e.g. 0.01).
	 * @param string $currency Currency code: 'usd' or 'eur'.
	 * @return int Amount in Satoshis, or 0 on failure.
	 */
	public static function convert_fiat_to_sats(float $fiat, string $currency = 'usd'): int
	{
		if ($fiat <= 0) {
			return 0;
		}

		$rate = self::get_btc_exchange_rate($currency);
		if ($rate <= 0) {
			return 0;
		}

		// sats = (fiat / btc_price) × 100,000,000
		return (int) ceil(($fiat / $rate) * self::SATS_PER_BTC);
	}

	/**
	 * Gets the current BTC exchange rate for a given currency.
	 *
	 * Uses a WordPress transient to cache the rate for 15 minutes.
	 *
	 * @param string $currency Currency code: 'usd' or 'eur'.
	 * @return float BTC price in fiat, or 0.0 on failure.
	 */
	public static function get_btc_exchange_rate(string $currency = 'usd'): float
	{
		$currency   = strtolower($currency);
		$cached     = get_transient(self::RATE_TRANSIENT_KEY);

		if (is_array($cached) && isset($cached[$currency])) {
			return (float) $cached[$currency];
		}

		// Fetch from CoinGecko.
		$response = wp_remote_get(self::COINGECKO_API, [
			'timeout' => 10,
			'headers' => [
				'Accept' => 'application/json',
			],
		]);

		if (is_wp_error($response)) {
			return 0.0;
		}

		$code = wp_remote_retrieve_response_code($response);
		if (200 !== $code) {
			return 0.0;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		$rates = $body['bitcoin'] ?? [];

		if (empty($rates)) {
			return 0.0;
		}

		// Cache all returned currencies.
		set_transient(self::RATE_TRANSIENT_KEY, $rates, self::RATE_CACHE_TTL);

		return (float) ($rates[$currency] ?? 0.0);
	}

	/**
	 * Returns a multiplier based on bot type.
	 *
	 * Training bots pay more because they consume content for model training.
	 * Scrapers pay a moderate premium. AEO/Search bots pay standard price.
	 *
	 * @param array $agent Agent classification from Detector::classify().
	 * @return float Multiplier (>= 1.0).
	 */
	private static function get_bot_multiplier(array $agent): float
	{
		$type = $agent['type'] ?? 'human';

		$multipliers = [
			'training' => 1.5,
			'scraper'  => 1.25,
			'aeo'      => 1.0,
			'search'   => 1.0,
			'human'    => 1.0,
		];

		return $multipliers[$type] ?? 1.0;
	}

	/**
	 * Returns a multiplier based on content length.
	 *
	 * Adds 10% for every 2,000 words above the first 1,000 words.
	 *
	 * @param int $post_id Post ID.
	 * @return float Multiplier (>= 1.0).
	 */
	private static function get_content_multiplier(int $post_id): float
	{
		if ($post_id <= 0) {
			return 1.0;
		}

		$post = get_post($post_id);
		if (!$post || empty($post->post_content)) {
			return 1.0;
		}

		$word_count = str_word_count(strip_tags($post->post_content));
		$threshold  = 1000;
		$step       = 2000;

		if ($word_count <= $threshold) {
			return 1.0;
		}

		$extra_words = $word_count - $threshold;
		$increments  = ceil($extra_words / $step);

		return 1.0 + ($increments * 0.10);
	}
}
