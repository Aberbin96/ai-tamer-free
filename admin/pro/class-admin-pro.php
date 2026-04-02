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
use function esc_js;
use function wp_add_inline_script;
use function error_log;
use function dirname;
use function file_put_contents;

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

		// Assets.
		add_action('aitamer_admin_enqueue_assets', array($this, 'enqueue_pro_assets'), 10, 2);

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
	public function enqueue_pro_assets($hook): void
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

		$is_monetization = (isset($_GET['page']) && 'ai-tamer-monetization' === $_GET['page']) || false !== strpos($hook, 'ai-tamer-monetization');

		if ($is_monetization) {
			$data = array(
				'rest_url'      => rest_url('ai-tamer/v1/lightning-stats'),
				'nonce'         => wp_create_nonce('wp_rest'),
				'poll_interval' => 30000,
				'i18n'          => array(
					'polling'       => __('Live — polling every 30s', 'ai-tamer'),
					'error'         => __('Error', 'ai-tamer'),
					'network_error' => __('Network error', 'ai-tamer'),
					'parse_error'   => __('Parse error', 'ai-tamer'),
					'timeout'       => __('Timeout', 'ai-tamer'),
					'http_error'    => __('HTTP', 'ai-tamer'),
					'no_tx'         => __('No Lightning transactions yet.', 'ai-tamer'),
					'rate_missing'  => __('Exchange rate unavailable — will use manual sats fallback.', 'ai-tamer'),
					'approx_sats'   => __('≈ %s sats (based on current exchange rate)', 'ai-tamer'),
				),
				'btc_rates'     => get_transient(PricingEngine::RATE_TRANSIENT_KEY) ?: array(),
			);

			// Bulletproof localization: attach to jquery-core.
			$js_data = 'window.aitamerLN = ' . wp_json_encode($data) . ';';
			$js_data .= ' console.log("⚡ AI Tamer Pro: Assets block triggered.");';
			wp_add_inline_script('jquery-core', $js_data, 'after');

			// Use unique handles to bypass any previous registry issues.
			$stats_handle = 'aitamer-pro-stats';
			$sync_handle  = 'aitamer-pro-sync';

			wp_enqueue_script(
				$stats_handle,
				plugin_dir_url(AITAMER_PLUGIN_FILE) . 'admin/pro/assets/js/lightning-stats.js',
				array('jquery'),
				AITAMER_VERSION,
				true
			);

			wp_enqueue_script(
				$sync_handle,
				plugin_dir_url(AITAMER_PLUGIN_FILE) . 'admin/pro/assets/js/pricing-sync.js',
				array('jquery', $stats_handle),
				AITAMER_VERSION,
				true
			);
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

		$settings['plugin_license_key']     = sanitize_text_field($input['plugin_license_key'] ?? '');

		$allowed_currencies = array('usd', 'eur');
		$currency = strtolower(sanitize_text_field($input['lnbits_pricing_currency'] ?? 'usd'));
		$settings['lnbits_pricing_currency'] = in_array($currency, $allowed_currencies, true) ? $currency : 'usd';

		$settings['lnbits_pricing_fiat']     = max(0, (float) ($input['lnbits_pricing_fiat'] ?? 0.01));

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


		// 3. LNbits Lightning section.
		add_settings_section(
			'aitamer_lnbits',
			__('Lightning Network (LNbits / L402)', 'ai-tamer'),
			null,
			'ai-tamer-monetization'
		);

		add_settings_field(
			'lnbits_enabled',
			__('Enable Lightning Micropayments', 'ai-tamer'),
			array($admin, 'render_checkbox_field'),
			'ai-tamer-monetization',
			'aitamer_lnbits',
			array('key' => 'lnbits_enabled', 'description' => __('Allow robots/AI agents to pay per article in Satoshis via LNbits.', 'ai-tamer'))
		);

		add_settings_field(
			'lnbits_url',
			__('LNbits Instance URL', 'ai-tamer'),
			function () use ($admin) {
				$settings = get_option('aitamer_settings', array());
				$value = $settings['lnbits_url'] ?? 'https://legend.lnbits.com';
				printf('<input type="url" id="lnbits_url" name="aitamer_settings[lnbits_url]" value="%s" class="regular-text">', esc_url($value));
				echo '<p class="description">' . esc_html__('Default: https://legend.lnbits.com. Or your own self-hosted instance.', 'ai-tamer') . '</p>';
			},
			'ai-tamer-monetization',
			'aitamer_lnbits'
		);

		add_settings_field(
			'lnbits_api_key',
			__('LNbits Invoice API Key', 'ai-tamer'),
			function () use ($admin) {
				$settings = get_option('aitamer_settings', array());
				$value = $settings['lnbits_api_key'] ?? '';
				printf('<input type="password" id="lnbits_api_key" name="aitamer_settings[lnbits_api_key]" value="%s" class="regular-text">', esc_attr($value));
				echo '<p class="description">' . esc_html__('Your LNbits "Invoice Read/Write" API key.', 'ai-tamer') . '</p>';
			},
			'ai-tamer-monetization',
			'aitamer_lnbits'
		);



		// Fiat Currency selector.
		add_settings_field(
			'lnbits_pricing_currency',
			__('Fiat Currency', 'ai-tamer'),
			function () {
				$settings = get_option('aitamer_settings', array());
				$currency = $settings['lnbits_pricing_currency'] ?? 'usd';
				printf(
					'<div id="aitamer-fiat-currency-row"><select name="aitamer_settings[lnbits_pricing_currency]" id="lnbits_pricing_currency">' .
						'<option value="usd" %s>USD ($)</option>' .
						'<option value="eur" %s>EUR (€)</option>' .
						'</select><p class="description">%s</p></div>',
					selected($currency, 'usd', false),
					selected($currency, 'eur', false),
					esc_html__('Currency used for the fiat price. Exchange rate is fetched from CoinGecko every 15 minutes.', 'ai-tamer')
				);
			},
			'ai-tamer-monetization',
			'aitamer_lnbits'
		);

		// Fiat Price amount.
		add_settings_field(
			'lnbits_pricing_fiat',
			__('Fiat Price Per Article', 'ai-tamer'),
			function () {
				$settings = get_option('aitamer_settings', array());
				$value    = $settings['lnbits_pricing_fiat'] ?? 0.01;

				// Show live equivalent if we have a cached rate.
				$currency  = $settings['lnbits_pricing_currency'] ?? 'usd';
				$sats_equiv = PricingEngine::convert_fiat_to_sats((float) $value, $currency);
				$equiv_text = ($sats_equiv > 0)
					? sprintf(
						/* translators: %s: number of satoshis */
						__('≈ %s sats (based on current exchange rate)', 'ai-tamer'),
						number_format($sats_equiv)
					)
					: __('Exchange rate unavailable — will use manual sats fallback.', 'ai-tamer');

				printf(
					'<div id="aitamer-fiat-price-row">' .
						'<input type="number" step="0.001" min="0.001" id="lnbits_pricing_fiat" name="aitamer_settings[lnbits_pricing_fiat]" value="%s" class="small-text">' .
						'<p class="description" id="aitamer-fiat-equiv">%s</p></div>',
					esc_attr($value),
					esc_html($equiv_text)
				);
			},
			'ai-tamer-monetization',
			'aitamer_lnbits'
		);



		// Real-time conversion handled via admin/pro/assets/js/pricing-sync.js
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
