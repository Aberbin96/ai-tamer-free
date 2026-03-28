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

use AiTamer\Enums\LicenseScope;
use function get_option;
use function update_option;
use function home_url;
use function add_action;
use function wp_remote_post;
use function wp_remote_get;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function wp_parse_args;
use function get_transient;
use function set_transient;

defined('ABSPATH') || exit;

if (! defined('HOUR_IN_SECONDS')) {
	define('HOUR_IN_SECONDS', 3600);
}

/**
 * StripeManager class (Pro).
 */
class StripeManager implements PaymentProvider
{

	/**
	 * Option key for Stripe settings.
	 */
	const SETTINGS_KEY = 'aitamer_stripe_settings';

	/** @var string DB table name (without prefix). */
	const TABLE = 'aitamer_billing';

	/**
	 * Registers hooks.
	 */
	public function register(): void
	{
		// Placeholder for webhook or other hooks.
	}

	/**
	 * Returns current Stripe settings.
	 *
	 * @return array
	 */
	public static function get_settings(): array
	{
		return wp_parse_args(
			get_option(self::SETTINGS_KEY, array()),
			array(
				'enabled'           => 'no',
				'test_mode'         => 'yes',
				'test_publishable'  => '',
				'test_secret'       => '',
				'live_publishable'  => '',
				'live_secret'       => '',
				'price_id'          => '',
				'price_id_micropayment' => '',
			)
		);
	}

	/**
	 * Creates a Stripe Checkout Session for a license purchase.
	 *
	 * @param string $agent_name Optional agent name to link to the token.
	 * @param int    $post_id    Optional post ID to scope the token to.
	 * @return string|null Redirect URL to Stripe Checkout.
	 */
	public function create_checkout_session(string $agent_name = '', int $post_id = 0): ?string
	{
		$settings = self::get_settings();
		$secret   = ('yes' === $settings['test_mode']) ? $settings['test_secret'] : $settings['live_secret'];

		if (empty($secret)) {
			return null;
		}

		$price_id = $settings['price_id'];
		$success_url = add_query_arg('aitamer_stripe', 'success', home_url('/'));

		if ($post_id > 0 && ! empty($settings['price_id_micropayment'])) {
			$price_id = $settings['price_id_micropayment'];
			$success_url = add_query_arg('aitamer_stripe', 'success', get_permalink($post_id));
		}

		$body = array(
			'success_url' => $success_url,
			'cancel_url'  => add_query_arg('aitamer_stripe', 'cancel', home_url('/')),
			'mode'        => ($price_id === $settings['price_id'] && ! empty($settings['price_id']) && strpos($settings['price_id'], 'price_') === 0) ? 'subscription' : 'payment',
			'line_items'  => array(
				array(
					'price'    => $price_id,
					'quantity' => 1,
				),
			),
		);

		// Add metadata for the webhook.
		if (! empty($agent_name)) {
			$body['metadata']['agent_name'] = $agent_name;
		}
		if ($post_id > 0) {
			$body['metadata']['post_id']      = $post_id;
			$body['metadata']['license_days'] = 1; // Indicator.
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/checkout/sessions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
				'body'    => http_build_query($body),
			)
		);

		if (200 !== wp_remote_retrieve_response_code($response)) {
			return null;
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);
		return $data['url'] ?? null;
	}

	/**
	 * Handles Stripe incoming webhooks.
	 *
	 * @param string $payload The raw request body.
	 * @param string $sig     The Stripe-Signature header.
	 */
	public function handle_webhook(string $payload, string $sig): void
	{
		// Log the event or verify signature.
		// If event is 'checkout.session.completed', issue a token.

		Plugin::log('AI Tamer Webhook: received payload');

		$event = json_decode($payload);
		if (! $event || ! isset($event->type)) {
			Plugin::log('AI Tamer Webhook: invalid payload or missing type');
			return;
		}

		Plugin::log('AI Tamer Webhook: type = ' . $event->type);

		if ('checkout.session.completed' === $event->type) {
			$session = $event->data->object;
			$agent   = $session->metadata->agent_name ?? 'AI Agent';
			$days    = (int) ($session->metadata->license_days ?? 365);
			$sub_id  = $session->subscription ?? '';

			Plugin::log("AI Tamer Webhook: agent={$agent}, days={$days}, sub_id={$sub_id}");

			$scope = \AiTamer\Enums\LicenseScope::GLOBAL->value;
			if (! empty($session->metadata->post_id)) {
				$scope = 'post:' . $session->metadata->post_id;
				$days  = 1 / 24; // 1 hour for micropayment.
				Plugin::log("AI Tamer Webhook: scoped to post {$session->metadata->post_id} for 1 hour");
			}

			$token = LicenseVerifier::issue_token($agent, $days, $sub_id, $scope);

			// Log the transaction in the billing table.
			$this->log_transaction(array(
				'agent_name'  => $agent,
				'amount'      => ($session->amount_total / 100), // Stripe is in cents.
				'currency'    => strtoupper($session->currency),
				'provider_id' => $session->id,
				'status'      => 'completed',
			));

			Plugin::log("AI Tamer: Issued token via webhook for {$agent}. Token: " . substr($token, 0, 10) . '...');
		}
	}

	/**
	 * Issues a new token after successful payment.
	 *
	 * @param string $name   Name of the purchaser.
	 * @param string $email  Email of the purchaser.
	 * @param string $sub_id Subscription ID (optional).
	 */
	private function issue_purchased_token(string $name, string $email, string $sub_id = ''): void
	{
		$agent_name = $name . ' (' . $email . ')';
		LicenseVerifier::issue_token($agent_name, 365, $sub_id);
	}

	/**
	 * Get provider name.
	 */
	public function get_name(): string
	{
		return 'stripe';
	}

	/**
	 * Check if enabled.
	 */
	public function is_enabled(): bool
	{
		$settings = self::get_settings();
		return 'yes' === $settings['enabled'];
	}

	/**
	 * Generate checkout URL (Stub for discovery).
	 *
	 * @param string $bot_name The bot identifier.
	 */
	public function get_checkout_url(string $bot_name): string
	{
		// This would ideally create a session and return the URL.
		return 'https://checkout.stripe.com/pay/...';
	}

	/**
	 * Verifies if a subscription is active via Stripe API.
	 *
	 * @param string $subscription_id The provider-specific subscription ID.
	 * @return bool
	 */
	public function verify_subscription(string $subscription_id): bool
	{
		if (empty($subscription_id)) {
			return false;
		}

		// 1. Check Cache first (Transient).
		$cache_key = 'ait_sub_' . hash('md5', $subscription_id);
		$status    = get_transient($cache_key);

		if (false !== $status) {
			return 'active' === $status || 'trialing' === $status;
		}

		$settings = self::get_settings();
		$secret   = ('yes' === $settings['test_mode']) ? $settings['test_secret'] : $settings['live_secret'];

		if (empty($secret)) {
			return false;
		}

		$response = wp_remote_get(
			'https://api.stripe.com/v1/subscriptions/' . $subscription_id,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
			)
		);

		if (200 !== wp_remote_retrieve_response_code($response)) {
			return false;
		}

		$data   = json_decode(wp_remote_retrieve_body($response), true);
		$status = $data['status'] ?? 'inactive';

		// 3. Cache result for 1 hour.
		set_transient($cache_key, $status, HOUR_IN_SECONDS);

		return 'active' === $status || 'trialing' === $status;
	}

	/**
	 * Creates the billing table.
	 */
	public static function install_table(): void
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_name  VARCHAR(100)        NOT NULL DEFAULT '',
			amount      DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
			currency    VARCHAR(10)         NOT NULL DEFAULT 'USD',
			provider_id VARCHAR(100)        NOT NULL DEFAULT '',
			status      VARCHAR(50)         NOT NULL DEFAULT 'pending',
			created_at  DATETIME            NOT NULL,
			PRIMARY KEY  (id),
			KEY agent_idx (agent_name(50))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);

		update_option('aitamer_billing_db_version', '1.0');
	}

	/**
	 * Records a transaction in the database.
	 *
	 * @param array $data Transaction data (agent_name, amount, currency, provider_id, status).
	 */
	public function log_transaction(array $data): void
	{
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'agent_name'  => sanitize_text_field($data['agent_name']),
				'amount'      => (float) $data['amount'],
				'currency'    => sanitize_text_field($data['currency']),
				'provider_id' => sanitize_text_field($data['provider_id']),
				'status'      => sanitize_text_field($data['status']),
				'created_at'  => current_time('mysql'),
			),
			array('%s', '%f', '%s', '%s', '%s', '%s')
		);
	}

	/**
	 * Returns recent transactions for the Admin UI.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public function get_transactions(int $limit = 20): array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
	}
}
