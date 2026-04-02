<?php

namespace AiTamer\Tests\Unit;

use AiTamer\PricingEngine;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * PricingEngineTest — Tests for the Dynamic Pricing Engine.
 */
class PricingEngineTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test: Manual mode returns the global lnbits_price_sats value.
	 */
	public function test_manual_mode_returns_global_sats(): void
	{
		Functions\when('get_option')->justReturn([
			'lnbits_pricing_mode' => 'manual',
			'lnbits_price_sats'   => 250,
		]);
		Functions\when('get_post_meta')->justReturn('');
		Functions\when('apply_filters')->returnArg(2);

		$price = PricingEngine::get_base_price(0);
		$this->assertEquals(250, $price);
	}

	/**
	 * Test: Per-post override takes precedence over global settings.
	 */
	public function test_per_post_override_takes_precedence(): void
	{
		Functions\when('get_option')->justReturn([
			'lnbits_pricing_mode' => 'manual',
			'lnbits_price_sats'   => 100,
		]);

		// Return the per-post override value for _aitamer_price_sats.
		Functions\when('get_post_meta')->justReturn('500');
		Functions\when('apply_filters')->returnArg(2);

		$price = PricingEngine::get_base_price(42);
		$this->assertEquals(500, $price);
	}

	/**
	 * Test: Fiat conversion calculates correctly when transient is cached.
	 */
	public function test_fiat_conversion_calculates_correctly(): void
	{
		// BTC = $100,000. $0.01 = 1 sat.
		Functions\when('get_transient')->justReturn(['usd' => 100000, 'eur' => 92000]);
		Functions\when('set_transient')->justReturn(true);

		$sats = PricingEngine::convert_fiat_to_sats(0.01, 'usd');
		// (0.01 / 100000) * 100_000_000 = 10 sats
		$this->assertEquals(10, $sats);
	}

	/**
	 * Test: Fiat conversion for EUR.
	 */
	public function test_fiat_conversion_eur(): void
	{
		Functions\when('get_transient')->justReturn(['usd' => 100000, 'eur' => 92000]);
		Functions\when('set_transient')->justReturn(true);

		$sats = PricingEngine::convert_fiat_to_sats(0.01, 'eur');
		// (0.01 / 92000) * 100_000_000 ≈ 11 sats (rounded up)
		$this->assertEquals(11, $sats);
	}

	/**
	 * Test: Training bot multiplier applies 1.5x when dynamic pricing is enabled.
	 */
	public function test_training_bot_multiplier(): void
	{
		Functions\when('get_option')->justReturn([
			'lnbits_pricing_mode'    => 'manual',
			'lnbits_price_sats'      => 100,
			'lnbits_dynamic_pricing' => true,
		]);
		Functions\when('get_post_meta')->justReturn('');
		Functions\when('get_post')->justReturn(null);

		// Return the multiplier unchanged (no filter override).
		Functions\when('apply_filters')->alias(function ($tag, $value) {
			// For aitamer_pricing_multiplier, return the computed value.
			// For aitamer_base_price_sats, return the 2nd arg.
			return func_get_arg(1);
		});

		$agent = ['name' => 'GPTBot', 'type' => 'training', 'matched' => true];
		$price = PricingEngine::get_price(0, $agent);

		// 100 * 1.5 (bot) * 1.0 (content, post=0) = 150
		$this->assertEquals(150, $price);
	}

	/**
	 * Test: Content length multiplier applies surcharge for long articles.
	 */
	public function test_content_length_multiplier(): void
	{
		Functions\when('get_option')->justReturn([
			'lnbits_pricing_mode'    => 'manual',
			'lnbits_price_sats'      => 100,
			'lnbits_dynamic_pricing' => true,
		]);
		Functions\when('get_post_meta')->justReturn('');

		// Create a fake post with ~5000 words (> 1000 threshold).
		// (5000 - 1000) / 2000 = 2 increments → +20% → 1.2x
		$long_content = str_repeat('word ', 5000);
		$fake_post = (object) ['post_content' => $long_content];
		Functions\when('get_post')->justReturn($fake_post);
		Functions\when('apply_filters')->alias(function () {
			return func_get_arg(1);
		});

		$agent = ['name' => 'Googlebot', 'type' => 'search', 'matched' => true];
		$price = PricingEngine::get_price(42, $agent);

		// 100 * 1.0 (search bot) * 1.2 (content) = 120
		$this->assertEquals(120, $price);
	}

	/**
	 * Test: Exchange rate is read from transient cache without calling API.
	 */
	public function test_exchange_rate_cached_in_transient(): void
	{
		Functions\when('get_transient')->justReturn(['usd' => 65000]);

		// wp_remote_get should NOT be called because the transient exists.
		Functions\expect('wp_remote_get')->never();
		Functions\when('set_transient')->justReturn(true);

		$rate = PricingEngine::get_btc_exchange_rate('usd');
		$this->assertEquals(65000.0, $rate);
	}

	/**
	 * Test: When API fails, convert_fiat_to_sats returns 0 (triggers fallback).
	 */
	public function test_fallback_on_api_failure(): void
	{
		// No cached rate.
		Functions\when('get_transient')->justReturn(false);
		// API returns WP_Error.
		Functions\when('wp_remote_get')->justReturn(new \WP_Error('timeout', 'Request timed out'));
		Functions\when('is_wp_error')->justReturn(true);
		Functions\when('set_transient')->justReturn(true);

		$sats = PricingEngine::convert_fiat_to_sats(0.01, 'usd');
		$this->assertEquals(0, $sats);
	}

	/**
	 * Test: get_price returns at least 1 sat even with weird inputs.
	 */
	public function test_minimum_price_is_one_sat(): void
	{
		Functions\when('get_option')->justReturn([
			'lnbits_pricing_mode' => 'manual',
			'lnbits_price_sats'   => 0,
		]);
		Functions\when('get_post_meta')->justReturn('');
		Functions\when('apply_filters')->returnArg(2);

		$price = PricingEngine::get_base_price(0);
		$this->assertGreaterThanOrEqual(1, $price);
	}
}
