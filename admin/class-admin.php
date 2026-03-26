<?php

/**
 * Admin — settings page and menu.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function add_settings_field;
use function add_settings_section;
use function absint;
use function add_query_arg;
use function admin_url;
use function checked;
use function check_admin_referer;
use function current_user_can;
use function do_settings_sections;
use function get_admin_page_title;
use function get_option;
use function number_format_i18n;
use function register_setting;
use function settings_fields;
use function status_header;
use function submit_button;
use function wp_nonce_url;
use function wp_safe_redirect;
use function wp_delete_file;
use function wp_cache_delete;
use function __;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function plugin_dir_url;
use function selected;

defined('ABSPATH') || exit;

/**
 * Admin class.
 */
class Admin
{

	/** @var Admin|null Singleton. */
	private static ?Admin $instance = null;

	/**
	 * @return Admin
	 */
	public static function get_instance(): Admin
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct()
	{
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_post_aitamer_download_report', array($this, 'handle_download_report'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('save_post', array($this, 'clear_api_cache'));
	}

	/**
	 * Enqueue admin assets only on AI Tamer pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets(string $hook): void
	{
		if (false === strpos($hook, 'ai-tamer')) {
			return;
		}
		$url = plugin_dir_url(AITAMER_PLUGIN_FILE) . 'admin/assets/css/admin-style.css';
		wp_enqueue_style('aitamer-admin', $url, array(), AITAMER_VERSION);
	}

	/**
	 * Registers the top-level admin menu item.
	 */
	public function register_menu(): void
	{
		// Top level menu — shows Dashboard as default.
		add_menu_page(
			__('AI Tamer — Scraper Protection', 'ai-tamer'),
			__('AI Tamer', 'ai-tamer'),
			'manage_options',
			'ai-tamer',
			array($this, 'render_dashboard_page'),
			'dashicons-shield',
			80
		);

		// Dashboard submenu.
		add_submenu_page(
			'ai-tamer',
			__('Dashboard', 'ai-tamer'),
			__('Dashboard', 'ai-tamer'),
			'manage_options',
			'ai-tamer',
			array($this, 'render_dashboard_page')
		);

		// Settings submenu.
		add_submenu_page(
			'ai-tamer',
			__('Settings', 'ai-tamer'),
			__('Settings', 'ai-tamer'),
			'manage_options',
			'ai-tamer-settings',
			array($this, 'render_settings_page')
		);

		// Audit Reports submenu.
		add_submenu_page(
			'ai-tamer',
			__('Audit Reports', 'ai-tamer'),
			__('Audit Reports', 'ai-tamer'),
			'manage_options',
			'ai-tamer-audit',
			array($this, 'render_audit_page')
		);

		// Licensing submenu.
		add_submenu_page(
			'ai-tamer',
			__('Licensing', 'ai-tamer'),
			__('Licensing', 'ai-tamer'),
			'manage_options',
			'ai-tamer-licensing',
			array($this, 'render_licensing_page')
		);


		// Monetization submenu (Pro).
		add_submenu_page(
			'ai-tamer',
			__('Monetization', 'ai-tamer'),
			__('Monetization', 'ai-tamer'),
			'manage_options',
			'ai-tamer-monetization',
			array($this, 'render_monetization_page')
		);
	}

	/**
	 * Registers settings using the Settings API.
	 */
	public function register_settings(): void
	{
		register_setting(
			'aitamer_settings_group',
			'aitamer_settings',
			array(
				'sanitize_callback' => array($this, 'sanitize_settings'),
			)
		);

		register_setting(
			'aitamer_settings_group',
			'aitamer_stripe_settings',
			array(
				'sanitize_callback' => array($this, 'sanitize_stripe_settings'),
			)
		);

		add_settings_section(
			'aitamer_general',
			__('General Protection', 'ai-tamer'),
			null,
			'ai-tamer-settings'
		);

		add_settings_field(
			'block_training_bots',
			__('Block training bots in robots.txt', 'ai-tamer'),
			array($this, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array('key' => 'block_training_bots')
		);

		add_settings_field(
			'inject_meta_tags',
			__('Inject noai meta tags', 'ai-tamer'),
			array($this, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array('key' => 'inject_meta_tags')
		);

		add_settings_field(
			'inject_http_headers',
			__('Inject X-Robots-Tag HTTP headers', 'ai-tamer'),
			array($this, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array('key' => 'inject_http_headers')
		);

		add_settings_field(
			'crawl_delay_enabled',
			__('Add Crawl-delay to blocked bots', 'ai-tamer'),
			array($this, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array('key' => 'crawl_delay_enabled')
		);

		add_settings_field(
			'crawl_delay',
			__('Crawl-delay (seconds)', 'ai-tamer'),
			array($this, 'render_number_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array('key' => 'crawl_delay', 'min' => 1, 'max' => 120)
		);

		add_settings_field(
			'license_policy',
			__('AI License Policy (meta tag)', 'ai-tamer'),
			array($this, 'render_license_policy_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array()
		);

		add_settings_field(
			'auto_update_bots',
			__( 'Auto-update bot list from GitHub', 'ai-tamer' ),
			array( $this, 'render_checkbox_field' ),
			'ai-tamer-settings',
			'aitamer_general',
			array( 'key' => 'auto_update_bots' )
		);

		// Rate Limiting section.
		add_settings_section(
			'aitamer_rate_limiting',
			__('Rate Limiting', 'ai-tamer'),
			null,
			'ai-tamer-settings'
		);

		add_settings_field(
			'rate_limit_enabled',
			__('Enable rate limiting for bots', 'ai-tamer'),
			array($this, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_rate_limiting',
			array('key' => 'rate_limit_enabled')
		);

		add_settings_field(
			'rpm',
			__('Max requests per minute (RPM)', 'ai-tamer'),
			array($this, 'render_number_field'),
			'ai-tamer-settings',
			'aitamer_rate_limiting',
			array('key' => 'rpm', 'min' => 1, 'max' => 500)
		);

		// Bandwidth section.
		add_settings_section(
			'aitamer_bandwidth',
			__('Bandwidth Limiting', 'ai-tamer'),
			null,
			'ai-tamer-settings'
		);

		add_settings_field(
			'bandwidth_limit_enabled',
			__('Enable daily bandwidth cap for bots', 'ai-tamer'),
			array($this, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_bandwidth',
			array('key' => 'bandwidth_limit_enabled')
		);

		add_settings_field(
			'bandwidth_kb_limit',
			__('Max KB served per bot per day', 'ai-tamer'),
			array($this, 'render_number_field'),
			'ai-tamer-settings',
			'aitamer_bandwidth',
			array('key' => 'bandwidth_kb_limit', 'min' => 100, 'max' => 102400)
		);
	}

	/**
	 * Sanitizes and validates settings before saving.
	 *
	 * @param array $input Raw form input.
	 * @return array Sanitized values.
	 */
	public function sanitize_settings($input): array
	{
		if (! is_array($input)) {
			return get_option('aitamer_settings', array());
		}
		$allowed_policies = array('no-training', 'allow', 'allow-with-attribution');
		$policy           = $input['license_policy'] ?? 'no-training';
		if (! in_array($policy, $allowed_policies, true)) {
			$policy = 'no-training';
		}
		return array(
			'block_training_bots'     => ! empty($input['block_training_bots']),
			'auto_update_bots'        => ! empty($input['auto_update_bots']),
			'inject_meta_tags'        => ! empty($input['inject_meta_tags']),
			'inject_http_headers'     => ! empty($input['inject_http_headers']),
			'crawl_delay_enabled'     => ! empty($input['crawl_delay_enabled']),
			'crawl_delay'             => absint($input['crawl_delay'] ?? 10) ?: 10,
			'license_policy'          => $policy,
			'rate_limit_enabled'      => ! empty($input['rate_limit_enabled']),
			'rpm'                     => absint($input['rpm'] ?? 30) ?: 30,
			'bandwidth_limit_enabled' => ! empty($input['bandwidth_limit_enabled']),
			'bandwidth_kb_limit'      => absint($input['bandwidth_kb_limit'] ?? 5120) ?: 5120,
		);
	}

	/**
	 * Sanitizes Stripe-specific settings.
	 *
	 * @param mixed $input Raw form input.
	 * @return array Sanitized values.
	 */
	public function sanitize_stripe_settings($input): array
	{
		if (! is_array($input)) {
			return get_option('aitamer_stripe_settings', array());
		}

		return array(
			'enabled'          => (isset($input['enabled']) && 'yes' === $input['enabled']) ? 'yes' : 'no',
			'test_mode'        => (isset($input['test_mode']) && 'no' === $input['test_mode']) ? 'no' : 'yes',
			'test_publishable' => sanitize_text_field($input['test_publishable'] ?? ''),
			'test_secret'      => sanitize_text_field($input['test_secret'] ?? ''),
			'live_publishable' => sanitize_text_field($input['live_publishable'] ?? ''),
			'live_secret'      => sanitize_text_field($input['live_secret'] ?? ''),
			'price_id'         => sanitize_text_field($input['price_id'] ?? ''),
		);
	}

	/**
	 * Renders a settings checkbox field.
	 *
	 * @param array $args Field args (expects 'key').
	 */
	public function render_checkbox_field(array $args): void
	{
		$key      = $args['key'];
		$settings = get_option('aitamer_settings', array());
		$checked  = ! empty($settings[$key]);
		printf(
			'<input type="checkbox" id="%1$s" name="aitamer_settings[%1$s]" value="1" %2$s>',
			esc_attr($key),
			checked($checked, true, false)
		);
	}

	/**
	 * Renders a number input field.
	 *
	 * @param array $args Field args (expects 'key', 'min', 'max').
	 */
	public function render_number_field(array $args): void
	{
		$key      = $args['key'];
		$settings = get_option('aitamer_settings', array());
		$value    = isset($settings[$key]) ? absint($settings[$key]) : 30;
		printf(
			'<input type="number" id="%1$s" name="aitamer_settings[%1$s]" value="%2$d" min="%3$d" max="%4$d" class="small-text">',
			esc_attr($key),
			(int) $value,
			absint($args['min'] ?? 1),
			absint($args['max'] ?? 500)
		);
	}

	/**
	 * Renders the AI license policy select field.
	 */
	public function render_license_policy_field(): void
	{
		$settings = get_option('aitamer_settings', array());
		$selected = $settings['license_policy'] ?? 'no-training';
		$options  = array(
			'no-training'              => __('No Training (default)', 'ai-tamer'),
			'allow'                    => __('Allow all AI use', 'ai-tamer'),
			'allow-with-attribution'   => __('Allow with attribution', 'ai-tamer'),
		);
		echo '<select id="license_policy" name="aitamer_settings[license_policy]">';
		foreach ($options as $value => $label) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr($value),
				selected($selected, $value, false),
				esc_html($label)
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__('Controls the value of the <meta name="ai-license"> tag output on frontend pages.', 'ai-tamer') . '</p>';
	}

	/**
	 * Renders the dashboard page.
	 */
	public function render_dashboard_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		require_once AITAMER_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Renders the settings page.
	 */
	public function render_settings_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		require_once AITAMER_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Renders the Audit Reports page.
	 */
	public function render_audit_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		require_once AITAMER_PLUGIN_DIR . 'admin/views/audit.php';
	}

	/**
	 * Renders the Licensing page.
	 */
	public function render_licensing_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		require_once AITAMER_PLUGIN_DIR . 'admin/views/licensing.php';
	}


	/**
	 * Renders the Monetization page (Pro).
	 */
	public function render_monetization_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		require_once AITAMER_PLUGIN_DIR . 'admin/views/monetization.php';
	}

	/**
	 * Handles the admin-post action to generate and stream a CSV report download.
	 */
	public function handle_download_report(): void
	{
		check_admin_referer('aitamer_download_report');

		if (! current_user_can('manage_options')) {
			wp_safe_redirect(admin_url());
			exit;
		}

		$days = absint($_GET['days'] ?? 30) ?: 30;
		$file = AuditReport::generate($days);

		if (! $file || ! file_exists($file)) {
			wp_safe_redirect(
				add_query_arg('aitamer_error', '1', admin_url('admin.php?page=ai-tamer-audit'))
			);
			exit;
		}

		// Stream the file to the browser as a download.
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="' . basename($file) . '"');
		header('Content-Length: ' . filesize($file));
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		readfile($file);

		// Security: Delete the file after it has been streamed to the user.
		wp_delete_file($file);
		exit;
	}
	/**
	 * Clears the REST API content cache for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function clear_api_cache($post_id): void
	{
		// Clear all 8 possible combinations of granular blocking.
		for ($i = 0; $i < 8; $i++) {
			$key = 'aitamer_content_' . (int) $post_id . '_' . ($i & 4 ? '1' : '0') . ($i & 2 ? '1' : '0') . ($i & 1 ? '1' : '0');
			wp_cache_delete($key, 'ai-tamer');
		}
	}
}
