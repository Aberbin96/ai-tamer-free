<?php

namespace AiTamer;

use AiTamer\Enums\DefenseStrategy;
use AiTamer\Enums\LicensePolicy;

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
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

		// Active Defense hooks.
		add_filter('aitamer_admin_defense_strategies', array($this, 'add_poison_strategy'));
		add_action('aitamer_admin_render_defense_footer', array($this, 'render_poison_footer'));

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
			$days         = absint($_POST['days'] ?? 365) ?: 365;
			$sub_id       = sanitize_text_field(wp_unslash($_POST['sub_id'] ?? ''));
			$scope_type   = sanitize_text_field(wp_unslash($_POST['scope_type'] ?? 'global'));
			$scope_id     = sanitize_text_field(wp_unslash($_POST['scope_id'] ?? ''));

			$final_scope = 'global';
			if ('post' === $scope_type && ! empty($scope_id)) {
				$final_scope = 'post:' . $scope_id;
			} elseif ('category' === $scope_type && ! empty($scope_id)) {
				$final_scope = 'category:' . $scope_id;
			}

			$token = LicenseVerifier::issue_token($agent_name, $days, $sub_id, $final_scope);
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
		$settings['enable_micropayments'] = ! empty($input['enable_micropayments']);
		$settings['enable_watermarking']  = ! empty($input['enable_watermarking']);
		$settings['active_stylistic_dna'] = ! empty($input['active_stylistic_dna']);
		$settings['enable_c2pa']          = ! empty($input['enable_c2pa']);
		$settings['show_c2pa_badge']      = ! empty($input['show_c2pa_badge']);

		return $settings;
	}

	/**
	 * Register Pro settings.
	 *
	 * @param Admin $admin The main admin instance.
	 */
	public function register_settings($admin)
	{
		// 1. Micropayments field (General section).
		add_settings_field(
			'enable_micropayments',
			__('Enable Micropayments (Protocol 402)', 'ai-tamer'),
			array($admin, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array('key' => 'enable_micropayments', 'description' => __('When unauthorized bots access content, return a 402 Payment Required status with a direct checkout link.', 'ai-tamer'))
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
			'active_stylistic_dna',
			__('Active Stylistic DNA (Experimental)', 'ai-tamer'),
			array($admin, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_authenticity',
			array('key' => 'active_stylistic_dna', 'description' => __('Deep defense: subtly alters word choices using synonyms (e.g., "perhaps" vs "maybe") to track content even if rephrased by AI.', 'ai-tamer'))
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
	}

	/**
	 * Adds the "Poison" strategy to the defense dropdown.
	 */
	public function add_poison_strategy($strategies)
	{
		$strategies[DefenseStrategy::POISON->value] = __('Poison (Serve truncated/degraded preview)', 'ai-tamer');
		return $strategies;
	}

	/**
	 * Renders the Poison-specific description and preview button.
	 */
	public function render_poison_footer($selected)
	{
		$latest_post = get_posts(array(
			'numberposts' => 1,
			'post_status' => 'publish',
		));

		$preview_url = home_url('/?aitamer_preview_poison=1');
		if (! empty($latest_post)) {
			$preview_url = add_query_arg('aitamer_preview_poison', '1', get_permalink($latest_post[0]->ID));
		}

		echo '<p class="description">' . esc_html__('Choose how to handle unauthorized AI agents trying to access protected content. "Poison" will serve a degraded teaser version of the text and inject decoy media from the plugin, ensuring your real images, videos, and full text are NEVER leaked to the scraper.', 'ai-tamer') . '</p>';

		if (class_exists('AiTamer\Poisoner')) {
			printf(
				'<p><a href="%s" target="_blank" class="button button-secondary">%s</a></p>',
				esc_url($preview_url),
				__('Preview Poisoned Content (Frontend)', 'ai-tamer')
			);
		}
	}
}
