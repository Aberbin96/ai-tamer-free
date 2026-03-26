<?php
/**
 * StripeManager — handles automated licensing payments.
 *
 * This class provides integration with Stripe Checkout to allow AI agents
 * or their operators to purchase access tokens.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_option;
use function update_option;
use function home_url;
use function add_action;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function wp_parse_args;

defined( 'ABSPATH' ) || exit;

/**
 * StripeManager class (Pro).
 */
class StripeManager implements PaymentProvider {

	/**
	 * Option key for Stripe settings.
	 */
	const SETTINGS_KEY = 'aitamer_stripe_settings';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		// Placeholder for webhook or other hooks.
	}

	/**
	 * Returns current Stripe settings.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		return wp_parse_args(
			get_option( self::SETTINGS_KEY, array() ),
			array(
				'enabled'           => 'no',
				'test_mode'         => 'yes',
				'test_publishable'  => '',
				'test_secret'       => '',
				'live_publishable'  => '',
				'live_secret'       => '',
				'price_id'          => '',
			)
		);
	}

	/**
	 * Creates a Stripe Checkout Session for a license purchase.
	 *
	 * @return string|null Redirect URL to Stripe Checkout.
	 */
	public function create_checkout_session(): ?string {
		$settings = self::get_settings();
		$secret   = ( 'yes' === $settings['test_mode'] ) ? $settings['test_secret'] : $settings['live_secret'];

		if ( empty( $secret ) ) {
			return null;
		}

		// Simplified Stripe API call using wp_remote_post.
		// In a real production environment, we might use the Stripe SDK.
		$response = wp_remote_post(
			'https://api.stripe.com/v1/checkout/sessions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
				'body'    => array(
					'success_url' => home_url( '/ai-usage-policy/?status=success' ),
					'cancel_url'  => home_url( '/ai-usage-policy/?status=cancel' ),
					'mode'        => 'payment',
					'line_items'  => array(
						array(
							'price'    => $settings['price_id'],
							'quantity' => 1,
						),
					),
				),
			)
		);

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $data['url'] ?? null;
	}

	/**
	 * Handles Stripe incoming webhooks.
	 *
	 * @param string $payload The raw request body.
	 * @param string $sig     The Stripe-Signature header.
	 */
	public function handle_webhook( string $payload, string $sig ): void {
		// Log the event or verify signature.
		// If event is 'checkout.session.completed', issue a token.
		
		$event = json_decode( $payload, true );
		if ( ! $event || ! isset( $event['type'] ) ) {
			return;
		}

		if ( 'checkout.session.completed' === $event['type'] ) {
			$session = $event['data']['object'];
			$email   = $session['customer_details']['email'] ?? 'ai-agent@unknown.com';
			$name    = $session['customer_details']['name'] ?? 'AI Agent';
			
			// Automatically issue a token via LicenseVerifier.
			$this->issue_purchased_token( $name, $email );
		}
	}

	/**
	 * Issues a new token after successful payment.
	 *
	 * @param string $name  Name of the purchaser.
	 * @param string $email Email of the purchaser.
	 */
	private function issue_purchased_token( string $name, string $email ): void {
		$agent_name = $name . ' (' . $email . ')';
		LicenseVerifier::issue_token( $agent_name, 365 );
	}

	/**
	 * Get provider name.
	 */
	public function get_name(): string {
		return 'stripe';
	}

	/**
	 * Check if enabled.
	 */
	public function is_enabled(): bool {
		$settings = self::get_settings();
		return 'yes' === $settings['enabled'];
	}

	/**
	 * Generate checkout URL (Stub for discovery).
	 *
	 * @param string $bot_name The bot identifier.
	 */
	public function get_checkout_url( string $bot_name ): string {
		// This would ideally create a session and return the URL.
		return 'https://checkout.stripe.com/pay/...';
	}
}
