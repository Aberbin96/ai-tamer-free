<?php
/**
 * PaymentProvider Interface.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

interface PaymentProvider {
	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Check if the provider is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * Generate a checkout URL for a bot.
	 *
	 * @param string $bot_name The bot identifier.
	 * @return string
	 */
	public function get_checkout_url( string $bot_name ): string;
}
