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

		// Tabs.
		add_filter('aitamer_admin_tabs', array($this, 'add_pro_tabs'));

		// Active Defense hooks.
		add_filter('aitamer_admin_defense_strategies', array($this, 'add_pro_strategies'));

		// POST actions.
		add_action('admin_init', array($this, 'handle_licensing_actions'));

		// Footer JS for conditional settings.
		add_action('aitamer_admin_render_defense_footer', array($this, 'render_conditional_settings_js'));
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
	}

	/**
	 * Register Pro submenus.
	 *
	 * @param Admin $admin The main admin instance.
	 */
	public function register_menus($admin)
	{
		// Licensing submenu (Hidden from sidebar).
		add_submenu_page(
			null,
			__('Licensing', 'ai-tamer'),
			__('Licensing', 'ai-tamer'),
			'manage_options',
			'ai-tamer-licensing',
			array($this, 'render_licensing_page')
		);

		// Monetization submenu (Hidden from sidebar).
		add_submenu_page(
			null,
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
	 * Adds Pro-specific tabs to the navigation.
	 *
	 * @param array $tabs The existing core tabs.
	 * @return array The updated tabs.
	 */
	public function add_pro_tabs(array $tabs): array
	{
		$tabs['ai-tamer-licensing']   = __('Licensing', 'ai-tamer');
		$tabs['ai-tamer-monetization'] = __('Monetization', 'ai-tamer');

		return $tabs;
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
			$scope_type   = sanitize_text_field(wp_unslash($_POST['scope_type'] ?? 'global'));
			$scope_id     = sanitize_text_field(wp_unslash($_POST['scope_id'] ?? ''));
			$credits      = absint($_POST['credits'] ?? 0);

			$final_scope = 'global';
			if ('post' === $scope_type && ! empty($scope_id)) {
				$final_scope = 'post:' . $scope_id;
			} elseif ('category' === $scope_type && ! empty($scope_id)) {
				$final_scope = 'category:' . $scope_id;
			}

			$token = LicenseVerifier::issue_token($agent_name, $days, $final_scope, $credits);
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

		global $title;
		$title = __('Licensing', 'ai-tamer');

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

		global $title;
		$title = __('Monetization', 'ai-tamer');

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
		$settings['whitelist_dev_tools']  = ! empty($input['whitelist_dev_tools']);
		$settings['enable_bot_markdown']   = ! empty($input['enable_bot_markdown']);

		$settings['usdt_address']      = sanitize_text_field($input['usdt_address'] ?? '');
		$settings['usdt_network']      = sanitize_text_field($input['usdt_network'] ?? 'polygon');
		$settings['usdt_price_usd']    = max(0.01, (float) ($input['usdt_price_usd'] ?? 0.10));
		$settings['usdt_verifier_url'] = esc_url_raw($input['usdt_verifier_url'] ?? 'https://verifier.aitamer.io/api/verify');

		return $settings;
	}


	/**
	 * Register Pro settings.
	 *
	 * @param Admin $admin The main admin instance.
	 */
	public function register_settings($admin)
	{
		// 1. Bot Identification & Whitelisting.
		add_settings_section(
			'aitamer_bot_id',
			__('Bot Identification & Whitelisting', 'ai-tamer'),
			null,
			'ai-tamer-settings'
		);

		add_settings_field(
			'whitelist_dev_tools',
			__('Whitelist Developer Tools', 'ai-tamer'),
			array($admin, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_bot_id',
			array(
				'key'         => 'whitelist_dev_tools',
				'description' => __('Allow common development tools like curl, Postman, and Python to bypass active defense challenges. Recommended for debugging.', 'ai-tamer'),
			)
		);

		add_settings_field(
			'enable_bot_markdown',
			__('Enable Bot-Friendly Markdown Delivery', 'ai-tamer'),
			array($admin, 'render_checkbox_field'),
			'ai-tamer-settings',
			'aitamer_bot_id',
			array(
				'key'         => 'enable_bot_markdown',
				'description' => __('Serve clean Markdown instead of HTML to validated bots. Optimized for AI agents and LLMs.', 'ai-tamer'),
			)
		);


		// 3. USDT P2P Monetization.
		add_settings_section(
			'aitamer_monetization_usdt',
			__('USDT P2P Monetization (Non-Custodial)', 'ai-tamer'),
			null,
			'ai-tamer-settings'
		);

		add_settings_field(
			'usdt_address',
			__('USDT Wallet Address', 'ai-tamer'),
			array($admin, 'render_text_field'),
			'ai-tamer-settings',
			'aitamer_monetization_usdt',
			array(
				'key'         => 'usdt_address',
				'description' => __('Your public ERC-20 / Polygon address to receive direct USDT payments.', 'ai-tamer'),
			)
		);

		add_settings_field(
			'usdt_network',
			__('Blockchain Network', 'ai-tamer'),
			array($this, 'render_network_select'),
			'ai-tamer-settings',
			'aitamer_monetization_usdt',
			array(
				'key' => 'usdt_network',
			)
		);

		add_settings_field(
			'usdt_price_usd',
			__('Toll Price (USD)', 'ai-tamer'),
			array($admin, 'render_text_field'),
			'ai-tamer-settings',
			'aitamer_monetization_usdt',
			array(
				'key'         => 'usdt_price_usd',
				'description' => __('Base price per post. The plugin will append unique "micro-cents" based on post ID for verification.', 'ai-tamer'),
			)
		);

		add_settings_field(
			'usdt_verifier_url',
			__('Verifier API URL', 'ai-tamer'),
			array($admin, 'render_text_field'),
			'ai-tamer-settings',
			'aitamer_monetization_usdt',
			array(
				'key'         => 'usdt_verifier_url',
				'description' => __('The endpoint of your Vercel Verifier API.', 'ai-tamer'),
			)
		);
	}

	/**
	 * Renders the network dropdown.
	 */
	public function render_network_select(array $args): void
	{
		$settings = get_option('aitamer_settings', array());
		$current  = $settings[$args['key']] ?? 'polygon';
		$networks = array(
			'polygon'  => 'Polygon (Recommended)',
			'ethereum' => 'Ethereum',
			'bsc'      => 'BNB Smart Chain',
			'arbitrum' => 'Arbitrum',
		);
?>
		<select id="<?php echo esc_attr($args['key']); ?>" name="aitamer_settings[<?php echo esc_attr($args['key']); ?>]">
			<?php foreach ($networks as $value => $label) : ?>
				<option value="<?php echo esc_attr($value); ?>" <?php selected($current, $value); ?>><?php echo esc_html($label); ?></option>
			<?php endforeach; ?>
		</select>
	<?php
	}

	/**
	 * Adds Pro strategies to the defense dropdown.
	 */
	public function add_pro_strategies($strategies)
	{
		$strategies[DefenseStrategy::PAYMENT->value] = __('Payment Required (USDT P2P - 402)', 'ai-tamer');
		return $strategies;
	}

	/**
	 * Renders JavaScript to handle conditional visibility of USDT settings.
	 */
	public function render_conditional_settings_js(): void
	{
		$settings = get_option('aitamer_settings', array());
		$current_defense = $settings['active_defense'] ?? 'block';
	?>
		<script>
			(function($) {
				$(function() {
					const $defense = $('#active_defense');
					const $usdtRows = $('#usdt_address, #usdt_network, #usdt_price_usd, #usdt_verifier_url').closest('tr');
					// Match the header registered in register_settings.
					const $usdtHeader = $('h2:contains("<?php echo esc_js(__('USDT P2P Monetization', 'ai-tamer')); ?>")');

					function toggleUSDT(val) {
						if (val === 'payment') {
							$usdtRows.show();
							$usdtHeader.show();
						} else {
							$usdtRows.hide();
							$usdtHeader.hide();
						}
					}

					// Update on change if element exists (Settings tab)
					if ($defense.length) {
						$defense.on('change', function() {
							toggleUSDT($(this).val());
						});
						toggleUSDT($defense.val());
					}
				});
			})(jQuery);
		</script>
<?php
	}
}
