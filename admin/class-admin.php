<?php

/**
 * Admin — settings page and menu.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

defined('ABSPATH') || exit;

use AiTamer\Enums\DefenseStrategy;
use AiTamer\Enums\LicensePolicy;
use AiTamer\Logger;

use function add_action;
use function add_filter;
use function apply_filters;
use function add_menu_page;
use function add_submenu_page;
use function wp_add_dashboard_widget;
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
use function get_posts;
use function get_permalink;
use function home_url;
use function number_format_i18n;
use function register_setting;
use function get_post_types;
use function settings_fields;
use function status_header;
use function submit_button;
use function wp_nonce_url;
use function wp_safe_redirect;
use function wp_delete_file;
use function wp_cache_delete;
use function delete_transient;
use function __;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function plugin_dir_url;
use function selected;
use function wp_enqueue_script;
use function wp_localize_script;
use function rest_url;
use function wp_create_nonce;
use function sanitize_text_field;



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
		add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_post_aitamer_download_report', array($this, 'handle_download_report'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('save_post', array($this, 'clear_api_cache'));
		add_filter('attachment_fields_to_edit', array($this, 'add_certification_field'), 10, 2);
		add_action('edit_attachment', array($this, 'save_certification_field'));
	}

	/**
	 * Registers the WordPress dashboard widget.
	 */
	public function register_dashboard_widget(): void
	{
		wp_add_dashboard_widget(
			'aitamer_monetization_widget',
			__('AI Tamer — Monetization', 'ai-tamer'),
			array($this, 'render_wp_dashboard_widget')
		);
	}

	/**
	 * Renders the WordPress dashboard widget.
	 */
	public function render_wp_dashboard_widget(): void
	{
		$stats = Logger::get_stats(10); // Display stats for recent period
		$total_bots = (int) ($stats['total'] ?? 0);
		$intercepted = 0;
		$potential_earnings = 0.0;
		$current_earnings = apply_filters('aitamer_monetization_earnings', 0.00);

		foreach (($stats['top_bots'] ?? array()) as $bot) {
			if (in_array($bot['bot_type'], array('training', 'scraper'), true)) {
				$intercepted += (int) $bot['hits'];
				// Pessimistically calculate earnings per bot type.
				$bot_val = apply_filters('aitamer_bot_monetization_value', 0.0, $bot['bot_name']);
				if (empty($bot_val)) {
					$normalized = strtolower($bot['bot_name']);
					if (strpos($normalized, 'gptbot') !== false || strpos($normalized, 'chatgpt') !== false) {
						$bot_val = 0.001;
					} elseif (strpos($normalized, 'claudebot') !== false || strpos($normalized, 'anthropic') !== false) {
						$bot_val = 0.0005;
					} elseif (strpos($normalized, 'google') !== false) {
						$bot_val = 0.0002;
					} else {
						// Scrapers generally do not pay.
						$bot_val = 0.00;
					}
				}
				$potential_earnings += (int) $bot['hits'] * $bot_val;
			}
		}

?>
		<div class="aitamer-wp-dashboard-widget" style="padding: 10px 0;">
			<p style="margin: 0 0 8px;">
				<strong><?php esc_html_e('Bots Entered:', 'ai-tamer'); ?></strong>
				<span><?php echo esc_html(number_format_i18n($total_bots)); ?></span>
			</p>
			<p style="margin: 0 0 15px;">
				<strong><?php esc_html_e('Bots Intercepted:', 'ai-tamer'); ?></strong>
				<span style="color: #d63638;"><?php echo esc_html(number_format_i18n($intercepted)); ?></span>
			</p>
			<hr style="border: 0; border-top: 1px solid #ddd; margin: 15px 0;" />
			<p style="margin: 0 0 8px; font-weight: 600;">
				<?php esc_html_e('Potential Earnings (Est.):', 'ai-tamer'); ?>
				<br />
				<span style="font-size: 1.2em; color: #2271b1;">$<?php echo esc_html(number_format_i18n($potential_earnings, 4)); ?></span>
			</p>
			<p style="margin: 0 0 15px; font-weight: 600;">
				<?php esc_html_e('Current Earnings:', 'ai-tamer'); ?>
				<br />
				<span style="font-size: 1.2em; color: <?php echo apply_filters('aitamer_is_pro_active', false) ? '#00a32a' : '#888'; ?>;">
					$<?php echo esc_html(number_format_i18n($current_earnings, 2)); ?>
				</span>
			</p>
			<?php if (! apply_filters('aitamer_is_pro_active', false)) : ?>
				<p style="margin-top: 15px;">
					<a href="<?php echo esc_url(admin_url('admin.php?page=ai-tamer')); ?>" class="button button-primary">
						<?php esc_html_e('Monetize Agents', 'ai-tamer'); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
<?php
	}


	/**
	 * Enqueue admin assets only on AI Tamer pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets(string $hook): void
	{
		// General Admin Pages
		// Admin style (shared).
		if (false !== strpos($hook, 'ai-tamer')) {
			$url = plugin_dir_url(AITAMER_PLUGIN_FILE) . 'admin/assets/css/admin-style.css';
			wp_enqueue_style('aitamer-admin', $url, array(), AITAMER_VERSION);
		}

		/**
		 * Allow Pro version to enqueue its own assets.
		 */
		do_action('aitamer_admin_enqueue_assets', $hook, $this);
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

		// Register Pro submenus via hook (obfuscated from Free).
		do_action('aitamer_admin_register_menus', $this);
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
			__('Auto-update bot list from GitHub', 'ai-tamer'),
			array($this, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array('key' => 'auto_update_bots')
		);

		add_settings_field(
			'enable_llms_txt',
			__('Enable llms.txt support', 'ai-tamer'),
			array($this, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array('key' => 'enable_llms_txt', 'description' => __('Exposes a virtual /llms.txt file to help AI agents discover your content and terms.', 'ai-tamer'))
		);

		add_settings_field(
			'active_defense',
			__('Active Defense Strategy', 'ai-tamer'),
			array($this, 'render_active_defense_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array()
		);

		add_settings_field(
			'protected_post_types',
			__('Protected Post Types', 'ai-tamer'),
			array($this, 'render_post_types_field'),
			'ai-tamer-settings',
			'aitamer_general',
			array()
		);

		// Register Pro settings via hook (obfuscated from Free).
		do_action('aitamer_admin_register_settings', $this);

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

		// Notifications section.
		add_settings_section(
			'aitamer_notifications',
			__('Real-time Notifications', 'ai-tamer'),
			null,
			'ai-tamer-settings'
		);

		add_settings_field(
			'notifications_enabled',
			__('Enable notifications', 'ai-tamer'),
			array($this, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_notifications',
			array('key' => 'notifications_enabled')
		);

		add_settings_field(
			'notification_channels',
			__('Notification Channels', 'ai-tamer'),
			array($this, 'render_notification_channels_field'),
			'ai-tamer-settings',
			'aitamer_notifications',
			array()
		);

		add_settings_field(
			'slack_webhook_url',
			__('Slack Webhook URL', 'ai-tamer'),
			array($this, 'render_text_field'),
			'ai-tamer-settings',
			'aitamer_notifications',
			array('key' => 'slack_webhook_url', 'class' => 'regular-text')
		);

		add_settings_field(
			'discord_webhook_url',
			__('Discord Webhook URL', 'ai-tamer'),
			array($this, 'render_text_field'),
			'ai-tamer-settings',
			'aitamer_notifications',
			array('key' => 'discord_webhook_url', 'class' => 'regular-text')
		);

		add_settings_field(
			'notification_events',
			__('Notification Triggers', 'ai-tamer'),
			array($this, 'render_notification_events_field'),
			'ai-tamer-settings',
			'aitamer_notifications',
			array()
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
		$settings = get_option('aitamer_settings', array());

		if (! is_array($input)) {
			return $settings;
		}

		// List of all known keys to handle them correctly.
		$keys = array(
			'block_training_bots',
			'auto_update_bots',
			'inject_meta_tags',
			'inject_http_headers',
			'crawl_delay_enabled',
			'crawl_delay',
			'rate_limit_enabled',
			'rpm',
			'bandwidth_limit_enabled',
			'bandwidth_kb_limit',
			'enable_llms_txt',
			'active_defense',
			'license_policy',
			'protected_post_types',
			'notifications_enabled',
			'notification_channels',
			'slack_webhook_url',
			'discord_webhook_url',
			'notification_events',
		);

		foreach ($keys as $key) {
			if (isset($input[$key])) {
				switch ($key) {
					case 'active_defense':
						$allowed_defenses = array_map(fn($case) => $case->value, DefenseStrategy::cases());
						$settings[$key]   = in_array($input[$key], $allowed_defenses, true) ? $input[$key] : DefenseStrategy::BLOCK->value;
						break;
					case 'license_policy':
						$allowed_policies = array_map(fn($case) => $case->value, LicensePolicy::cases());
						$settings[$key]   = in_array($input[$key], $allowed_policies, true) ? $input[$key] : LicensePolicy::NO_TRAINING->value;
						break;
					case 'protected_post_types':
					case 'notification_channels':
					case 'notification_events':
						$settings[$key] = is_array($input[$key]) ? array_map('sanitize_text_field', $input[$key]) : array();
						break;
					case 'crawl_delay':
					case 'rpm':
					case 'bandwidth_kb_limit':
						$settings[$key] = absint($input[$key]) ?: 10;
						break;
					case 'slack_webhook_url':
					case 'discord_webhook_url':
						$settings[$key] = esc_url_raw($input[$key]);
						break;
					default:
						// Handle checkboxes and others.
						$settings[$key] = ! empty($input[$key]);
						break;
				}
			} else {
				// Special handling for checkboxes: if we are on a page that HAS the checkbox but it's not sent, it's UNCHECKED.
				// If we are on a different page, we keep the previous value.
				// We detect this by checking if OTHER fields from the same form are present.
				$is_general_form = isset($input['active_defense']) || isset($input['license_policy']);
				$is_notify_form  = isset($input['slack_webhook_url']) || isset($input['discord_webhook_url']);

				$checkbox_keys = array(
					'block_training_bots',
					'auto_update_bots',
					'inject_meta_tags',
					'inject_http_headers',
					'crawl_delay_enabled',
					'rate_limit_enabled',
					'bandwidth_limit_enabled',
					'enable_llms_txt',
					'notifications_enabled'
				);

				if (in_array($key, $checkbox_keys, true)) {
					if ($is_general_form && ! in_array($key, array('notifications_enabled'), true)) {
						$settings[$key] = false;
					} elseif ($is_notify_form && 'notifications_enabled' === $key) {
						$settings[$key] = false;
					}
				}
			}
		}

		// Let Pro handle extra sanitization.
		$settings = apply_filters('aitamer_admin_sanitize_settings', $settings, $input);

		return $settings;
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

		$settings = array(); // Handled completely by Pro

		// Let Pro handle Stripe fields.
		return apply_filters('aitamer_admin_sanitize_stripe_settings', $settings, $input);
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
		if (! empty($args['description'])) {
			echo '<p class="description">' . esc_html($args['description']) . '</p>';
		}
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
	 * Renders a text input field.
	 */
	public function render_text_field(array $args): void
	{
		$key      = $args['key'];
		$settings = get_option('aitamer_settings', array());
		$value    = $settings[$key] ?? '';
		printf(
			'<input type="text" id="%1$s" name="aitamer_settings[%1$s]" value="%2$s" class="%3$s">',
			esc_attr($key),
			esc_attr($value),
			esc_attr($args['class'] ?? 'regular-text')
		);
	}

	/**
	 * Renders notification channels checkboxes.
	 */
	public function render_notification_channels_field(): void
	{
		$settings = get_option('aitamer_settings', array());
		$selected = $settings['notification_channels'] ?? array('email');
		$channels = array(
			'email'   => __('Email', 'ai-tamer'),
			'slack'   => __('Slack (Webhook)', 'ai-tamer'),
			'discord' => __('Discord (Webhook)', 'ai-tamer'),
		);

		foreach ($channels as $id => $label) {
			printf(
				'<label><input type="checkbox" name="aitamer_settings[notification_channels][]" value="%s" %s> %s</label><br>',
				esc_attr($id),
				checked(in_array($id, (array) $selected, true), true, false),
				esc_html($label)
			);
		}
	}

	/**
	 * Renders notification events checkboxes.
	 */
	public function render_notification_events_field(): void
	{
		$settings = get_option('aitamer_settings', array());
		$selected = $settings['notification_events'] ?? array();
		$events = array(
			'high_intensity'   => __('High Intensity Activity (Rate Limits)', 'ai-tamer'),
			'payment_received' => __('New Payments (Stripe/Crypto)', 'ai-tamer'),
			'security_alert'   => __('Security Threats (Fingerprint Blocks)', 'ai-tamer'),
			'new_bot'          => __('New Bots Detected', 'ai-tamer'),
		);

		foreach ($events as $id => $label) {
			printf(
				'<label><input type="checkbox" name="aitamer_settings[notification_events][]" value="%s" %s> %s</label><br>',
				esc_attr($id),
				checked(in_array($id, (array) $selected, true), true, false),
				esc_html($label)
			);
		}
	}

	/**
	 * Renders the AI License Policy select field.
	 */
	public function render_license_policy_field(): void
	{
		$settings = get_option('aitamer_settings', array());
		$selected = $settings['license_policy'] ?? LicensePolicy::NO_TRAINING->value;

		echo '<select id="license_policy" name="aitamer_settings[license_policy]">';
		foreach (LicensePolicy::cases() as $case) {
			$label = match ($case) {
				LicensePolicy::NO_TRAINING => __('No Training (default)', 'ai-tamer'),
				LicensePolicy::ALLOW       => __('Allow all AI use', 'ai-tamer'),
				LicensePolicy::ATTRIBUTION => __('Allow with attribution', 'ai-tamer'),
			};
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr($case->value),
				selected($selected, $case->value, false),
				esc_html($label)
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__('Select the default license for AI agents visiting your site.', 'ai-tamer') . '</p>';
	}

	/**
	 * Renders the active defense strategy select field.
	 */
	public function render_active_defense_field(): void
	{
		$settings = get_option('aitamer_settings', array());
		$selected = $settings['active_defense'] ?? DefenseStrategy::BLOCK->value;

		$strategies = array(
			DefenseStrategy::BLOCK->value => __('Block (Return 401 Unauthorized)', 'ai-tamer'),
		);

		// Let Pro add more strategies (like Payment Required).
		$strategies = apply_filters('aitamer_admin_defense_strategies', $strategies);

		echo '<select id="active_defense" name="aitamer_settings[active_defense]">';
		foreach ($strategies as $value => $label) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr($value),
				selected($selected, $value, false),
				esc_html($label)
			);
		}
		echo '</select>';

		// Let Pro render extra description or preview buttons.
		do_action('aitamer_admin_render_defense_footer', $selected);
	}

	/**
	 * Renders the protected post types checkboxes.
	 */
	public function render_post_types_field(): void
	{
		$settings = get_option('aitamer_settings', array());
		$selected = $settings['protected_post_types'] ?? array('post');

		$post_types = get_post_types(array('public' => true), 'objects');

		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__('Protected Post Types', 'ai-tamer') . '</span></legend>';
		foreach ($post_types as $pt) {
			if ('attachment' === $pt->name) {
				continue;
			}
			printf(
				'<label><input type="checkbox" name="aitamer_settings[protected_post_types][]" value="%s" %s> %s</label><br>',
				esc_attr($pt->name),
				checked(in_array($pt->name, (array) $selected, true), true, false),
				esc_html($pt->label)
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__('Select which post types should be protected by AI Tamer.', 'ai-tamer') . '</p>';
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
		// 1. Clear REST API granular caches.
		for ($i = 0; $i < 8; $i++) {
			$suffix = ($i & 4 ? '1' : '0') . ($i & 2 ? '1' : '0') . ($i & 1 ? '1' : '0');
			$key    = 'ait_c_' . (int) $post_id . '_' . $suffix;

			wp_cache_delete($key, 'ai-tamer');
			delete_transient($key);
		}
	}

	/**
	 * Adds a "Certify Human Origin" checkbox to the media edit screen.
	 *
	 * @param array   $form_fields Form fields.
	 * @param \WP_Post $post        Attachment post.
	 * @return array Modified fields.
	 */
	public function add_certification_field(array $form_fields, \WP_Post $post): array
	{
		if (strpos($post->post_mime_type, 'image/') !== 0) {
			return $form_fields;
		}

		$certified = get_post_meta($post->ID, '_aitamer_iptc_certified', true) === 'yes';
		$form_fields['aitamer_certify_human'] = array(
			'label' => __('AI Tamer: Human Origin', 'ai-tamer'),
			'input' => 'html',
			'html'  => sprintf(
				'<input type="checkbox" name="attachments[%1$d][aitamer_certify_human]" id="attachments[%1$d][aitamer_certify_human]" value="yes" %2$s> %3$s' .
					'<input type="hidden" name="aitamer_media_nonce_%1$d" value="%4$s">',
				$post->ID,
				checked($certified, true, false),
				__('Certify this media has human origin (Injects IPTC metadata on save)', 'ai-tamer'),
				wp_create_nonce('aitamer_save_media_' . $post->ID)
			),
		);

		return $form_fields;
	}

	/**
	 * Saves the certification field and triggers IPTC injection.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function save_certification_field(int $post_id): void
	{
		// Nonce verification.
		$nonce = isset($_REQUEST["aitamer_media_nonce_{$post_id}"]) ? sanitize_key(wp_unslash($_REQUEST["aitamer_media_nonce_{$post_id}"])) : '';

		if (! wp_verify_nonce($nonce, 'aitamer_save_media_' . $post_id)) {
			return;
		}

		if (! empty($_REQUEST['attachments'][$post_id]['aitamer_certify_human'])) {
			$was_certified = get_post_meta($post_id, '_aitamer_iptc_certified', true) === 'yes';

			if (!$was_certified) {
				update_post_meta($post_id, '_aitamer_iptc_certified', 'yes');
				update_post_meta($post_id, '_aitamer_iptc_status', 'pending');

				// Trigger IPTC injection asynchronously.
				if (! wp_next_scheduled('aitamer_process_media', array($post_id))) {
					wp_schedule_single_event(time(), 'aitamer_process_media', array($post_id));
				}
			}
		} else {
			delete_post_meta($post_id, '_aitamer_iptc_certified');
			delete_post_meta($post_id, '_aitamer_iptc_status');
		}
	}
}
