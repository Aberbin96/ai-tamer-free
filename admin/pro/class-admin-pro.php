<?php

namespace AiTamer;

use AiTamer\Enums\DefenseStrategy;
use AiTamer\Enums\LicensePolicy;
use function add_action;
use function add_filter;
use function apply_filters;
use function __;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function plugin_dir_url;
use function wp_enqueue_script;
use function wp_localize_script;
use function rest_url;
use function wp_create_nonce;
use function add_submenu_page;
use function current_user_can;
use function wp_verify_nonce;
use function sanitize_text_field;
use function wp_unslash;
use function absint;
use function wp_safe_redirect;
use function add_query_arg;
use function admin_url;
use function set_transient;
use function get_posts;
use function get_permalink;
use function home_url;
use function add_settings_field;
use function add_settings_section;
use function esc_url_raw;
use function get_option;

/**
 * AdminPro class — handles Pro-only admin registrations.
 */
class AdminPro
{
	/**
	 * Constructor.
	 */
	public function __construct()
	{
		add_action('aitamer_admin_register_menus', array($this, 'register_menus'));
		add_action('aitamer_admin_register_settings', array($this, 'register_settings'));
		add_filter('aitamer_admin_sanitize_settings', array($this, 'sanitize_pro_settings'), 10, 2);
		add_filter('aitamer_admin_sanitize_stripe_settings', array($this, 'sanitize_pro_stripe_settings'), 10, 2);

		// Assets.
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

		// Active Defense hooks.
		add_filter('aitamer_admin_defense_strategies', array($this, 'add_pro_strategies'));

		// POST actions.
		add_action('admin_init', array($this, 'handle_licensing_actions'));
	}

	/**
	 * Enqueues Pro-specific admin assets.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets($hook): void
	{
		if (in_array($hook, array('post.php', 'post-new.php'), true)) {
			$js_url = plugin_dir_url(AITAMER_PLUGIN_FILE) . 'admin/pro/assets/js/admin-editor.js';
			wp_enqueue_script(
				'aitamer-admin-editor',
				$js_url,
				array('jquery', 'wp-data', 'wp-editor'),
				AITAMER_VERSION,
				true
			);

			wp_localize_script('aitamer-admin-editor', 'aitamer_admin', array(
				'rest_url' => rest_url(),
				'nonce'    => wp_create_nonce('wp_rest'),
			));
		}
	}

	/**
	 * Register Pro submenus.
	 *
	 * @param Admin $admin The main admin instance.
	 */
	public function register_menus($admin)
	{
		add_submenu_page(
			'ai-tamer',
			__('Licensing', 'ai-tamer'),
			__('Licensing', 'ai-tamer'),
			'manage_options',
			'ai-tamer-licensing',
			array($this, 'render_licensing_page')
		);

		add_submenu_page(
			'ai-tamer',
			__('Monetization', 'ai-tamer'),
			__('Monetization', 'ai-tamer'),
			'manage_options',
			'ai-tamer-monetization',
			array($this, 'render_monetization_page')
		);

		add_submenu_page(
			'ai-tamer',
			__('Technical Standards', 'ai-tamer'),
			__('Technical Standards', 'ai-tamer'),
			'manage_options',
			'ai-tamer-standards',
			array($this, 'render_standards_page')
		);
	}

	/**
	 * Handles token revocation and issuance via POST.
	 */
	public function handle_licensing_actions(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		// Handle token revocation.
		if (
			isset($_POST['aitamer_revoke_nonce'], $_POST['revoke_index'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['aitamer_revoke_nonce'])), 'aitamer_revoke_token')
		) {
			LicenseVerifier::revoke_token(absint($_POST['revoke_index']));
			wp_safe_redirect(add_query_arg('aitamer_revoked', '1', admin_url('admin.php?page=ai-tamer-licensing')));
			exit;
		}

		// Handle token issuance.
		if (
			isset($_POST['aitamer_issue_token_nonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['aitamer_issue_token_nonce'])), 'aitamer_issue_token')
		) {
			$agent_name   = sanitize_text_field(wp_unslash($_POST['agent_name'] ?? ''));
			$days         = isset($_POST['days']) && $_POST['days'] !== '' ? absint($_POST['days']) : 365;
			$sub_id       = sanitize_text_field(wp_unslash($_POST['sub_id'] ?? ''));
			$scope_type   = sanitize_text_field(wp_unslash($_POST['scope_type'] ?? 'global'));
			$scope_id     = sanitize_text_field(wp_unslash($_POST['scope_id'] ?? ''));
			$credits      = absint($_POST['credits'] ?? 0);

			$final_scope = 'global';
			if ('post' === $scope_type && ! empty($scope_id)) {
				$final_scope = 'post:' . $scope_id;
			} elseif ('category' === $scope_type && ! empty($scope_id)) {
				$final_scope = 'category:' . $scope_id;
			}

			$token = LicenseVerifier::issue_token($agent_name, $days, $sub_id, $final_scope, $credits);
			set_transient('aitamer_last_issued_token', $token, 30);
			wp_safe_redirect(add_query_arg('aitamer_issued', '1', admin_url('admin.php?page=ai-tamer-licensing')));
			exit;
		}
	}

	/**
	 * Renders the Licensing page.
	 */
	public function render_licensing_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		require_once AITAMER_PLUGIN_DIR . 'admin/pro/views/licensing.php';
	}



	/**
	 * Renders the Monetization page (Pro).
	 */
	public function render_monetization_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		$view_path = AITAMER_PLUGIN_DIR . 'admin/pro/views/monetization.php';
		if (file_exists($view_path)) {
			require_once $view_path;
		}
	}

	/**
	 * Renders the Technical Standards page.
	 */
	public function render_standards_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		$view_path = AITAMER_PLUGIN_DIR . 'admin/pro/views/standards.php';
		if (file_exists($view_path)) {
			require_once $view_path;
		}
	}

	/**
	 * Sanitize Pro settings.
	 *
	 * @param array $settings The sanitized settings so far.
	 * @param array $input    The raw settings input.
	 * @return array The updated settings.
	 */
	public function sanitize_pro_settings($settings, $input): array
	{
		// Sanitize license_policy.
		if (isset($input['license_policy'])) {
			$allowed_policies = array_map(fn($case) => $case->value, LicensePolicy::cases());
			$policy           = $input['license_policy'];
			if (in_array($policy, $allowed_policies, true)) {
				$settings['license_policy'] = $policy;
			}
		}

		// Pro-specific checkboxes.
		$settings['enable_watermarking']  = ! empty($input['enable_watermarking']);
		$settings['enable_c2pa']          = ! empty($input['enable_c2pa']);
		$settings['show_c2pa_badge']      = ! empty($input['show_c2pa_badge']);

		// Migration: if enable_micropayments was previously true and defense is block, move to payment.
		if (! empty($input['enable_micropayments']) && $settings['active_defense'] === DefenseStrategy::BLOCK->value) {
			$settings['active_defense'] = DefenseStrategy::PAYMENT->value;
		}

		// Web3 Data Toll Settings
		$settings['web3_toll_enabled'] = ! empty($input['web3_toll_enabled']);
		$settings['base_wallet_address'] = sanitize_text_field($input['base_wallet_address'] ?? '');
		$settings['usdc_price_per_request'] = sanitize_text_field($input['usdc_price_per_request'] ?? '0.01');
		$settings['base_rpc_node_url'] = esc_url_raw($input['base_rpc_node_url'] ?? 'https://mainnet.base.org');
		$settings['plugin_license_key'] = sanitize_text_field($input['plugin_license_key'] ?? '');

		return $settings;
	}

	/**
	 * Sanitize Pro Stripe settings.
	 *
	 * @param array $settings Sanitized Stripe settings.
	 * @param array $input    Raw Input.
	 * @return array
	 */
	public function sanitize_pro_stripe_settings(array $settings, array $input): array
	{
		$settings['enabled']               = (isset($input['enabled']) && 'yes' === $input['enabled']) ? 'yes' : 'no';
		$settings['test_mode']             = (isset($input['test_mode']) && 'no' === $input['test_mode']) ? 'no' : 'yes';
		$settings['test_publishable']      = sanitize_text_field($input['test_publishable'] ?? '');
		$settings['test_secret']           = sanitize_text_field($input['test_secret'] ?? '');
		$settings['live_publishable']      = sanitize_text_field($input['live_publishable'] ?? '');
		$settings['live_secret']           = sanitize_text_field($input['live_secret'] ?? '');
		$settings['price_id']              = sanitize_text_field($input['price_id'] ?? '');
		$settings['price_id_micropayment'] = sanitize_text_field($input['price_id_micropayment'] ?? '');

		if (isset($input['price_id_voucher'])) {
			$settings['price_id_voucher'] = sanitize_text_field($input['price_id_voucher']);
		}
		if (isset($input['voucher_credits'])) {
			$settings['voucher_credits'] = absint($input['voucher_credits']);
		}
		if (isset($input['voucher_validity_days'])) {
			$settings['voucher_validity_days'] = absint($input['voucher_validity_days']);
		}
		return $settings;
	}

	/**
	 * Register Pro settings.
	 *
	 * @param Admin $admin The main admin instance.
	 */
	public function register_settings($admin)
	{
		// 1. Plugin License section.
		add_settings_section(
			'aitamer_plugin_license',
			__('Pro License Key', 'ai-tamer'),
			null,
			'ai-tamer-settings'
		);

		add_settings_field(
			'plugin_license_key',
			__('AI Tamer Pro License', 'ai-tamer'),
			function () use ($admin) {
				$settings = get_option('aitamer_settings', array());
				$value = $settings['plugin_license_key'] ?? '';
				printf('<input type="password" id="plugin_license_key" name="aitamer_settings[plugin_license_key]" value="%s" class="regular-text">', esc_attr($value));
				echo '<p class="description">' . esc_html__('Enter your product key to enable automatic plugin updates.', 'ai-tamer') . '</p>';
			},
			'ai-tamer-settings',
			'aitamer_plugin_license'
		);

		// 2. Content Authenticity section (v3).
		add_settings_section(
			'aitamer_authenticity',
			__('Content Authenticity & Attribution', 'ai-tamer'),
			null,
			'ai-tamer-settings'
		);

		add_settings_field(
			'enable_watermarking',
			__('Enable Invisible Watermarking', 'ai-tamer'),
			array($admin, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_authenticity',
			array('key' => 'enable_watermarking', 'description' => __('Injects an invisible cryptographic signature (Zero-Width characters) into your content. Visual appearance remains unchanged.', 'ai-tamer'))
		);

		add_settings_field(
			'enable_c2pa',
			__('Enable C2PA Origin Proof (Experimental)', 'ai-tamer'),
			array($admin, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_authenticity',
			array('key' => 'enable_c2pa', 'description' => __('Generates a verifiable digital manifest (JSON-LD) to prove "Proof of Human Origin". Note: Automated heuristic detection is experimental and may not be 100% accurate.', 'ai-tamer'))
		);

		add_settings_field(
			'show_c2pa_badge',
			__('Show Verified Human Badge (Frontend)', 'ai-tamer'),
			array($admin, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_authenticity',
			array('key' => 'show_c2pa_badge', 'description' => __('Display a visual "Verified Human" shield at the bottom of authenticated posts.', 'ai-tamer'))
		);

		// 2. Web3 Data Toll section.
		add_settings_section(
			'aitamer_web3_toll',
			__('Web3 Data Toll (Crypto Micropayments)', 'ai-tamer'),
			null,
			'ai-tamer-monetization'
		);

		add_settings_field(
			'web3_toll_enabled',
			__('Enable Web3 Data Toll', 'ai-tamer'),
			array($admin, 'render_checkbox_field'),
			'ai-tamer-monetization',
			'aitamer_web3_toll',
			array('key' => 'web3_toll_enabled', 'description' => __('Allow AI agents to pay per article request using USDC on the Base network. Requires Active Defense to be set to "Payment Required".', 'ai-tamer'))
		);

		add_settings_field(
			'base_wallet_address',
			__('Base Wallet Address', 'ai-tamer'),
			function () use ($admin) {
				$settings = get_option('aitamer_settings', array());
				$value = $settings['base_wallet_address'] ?? '';
				printf('<input type="text" id="base_wallet_address" name="aitamer_settings[base_wallet_address]" value="%s" class="regular-text">', esc_attr($value));
				echo '<p class="description">' . esc_html__('Your EVM compatible wallet address on the Base network to receive USDC.', 'ai-tamer') . '</p>';
			},
			'ai-tamer-monetization',
			'aitamer_web3_toll'
		);

		add_settings_field(
			'usdc_price_per_request',
			__('USDC Price Per Post', 'ai-tamer'),
			function () use ($admin) {
				$settings = get_option('aitamer_settings', array());
				$value = $settings['usdc_price_per_request'] ?? '0.01';
				printf('<input type="text" id="usdc_price_per_request" name="aitamer_settings[usdc_price_per_request]" value="%s" class="small-text">', esc_attr($value));
				echo '<p class="description">' . esc_html__('Amount of USDC required per article view (e.g. 0.05).', 'ai-tamer') . '</p>';
			},
			'ai-tamer-monetization',
			'aitamer_web3_toll'
		);

		add_settings_field(
			'base_rpc_node_url',
			__('Base RPC Node URL', 'ai-tamer'),
			function () use ($admin) {
				$settings = get_option('aitamer_settings', array());
				$value = $settings['base_rpc_node_url'] ?? 'https://mainnet.base.org';
				printf('<input type="url" id="base_rpc_node_url" name="aitamer_settings[base_rpc_node_url]" value="%s" class="regular-text">', esc_url($value));
				echo '<p class="description">' . esc_html__('Public RPC node or your personal Alchemy/Infura Base URL used to verify transactions.', 'ai-tamer') . '</p>';
			},
			'ai-tamer-monetization',
			'aitamer_web3_toll'
		);
	}

	/**
	 * Adds Pro strategies to the defense dropdown.
	 */
	public function add_pro_strategies($strategies)
	{
		$strategies[DefenseStrategy::PAYMENT->value] = __('Payment Required (Fiat & Crypto - 402)', 'ai-tamer');
		return $strategies;
	}

}
