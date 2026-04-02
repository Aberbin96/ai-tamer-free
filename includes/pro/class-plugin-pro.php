<?php

namespace AiTamer;

use function add_filter;
use function add_action;
use function get_option;
use function update_option;
use function wp_enqueue_script;
use function plugin_dir_url;
use function wp_localize_script;
use function esc_url_raw;
use function rest_url;
use function wp_create_nonce;

/**
 * PluginPro class — handles Pro-only plugin initialization and hooks.
 */
class PluginPro
{
	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Content Filter injection.
		add_filter('aitamer_content_filter', array($this, 'get_content_filter_pro'), 10, 2);

		// Meta Box injection.
		add_filter('aitamer_meta_box', array($this, 'get_meta_box_pro'));

		// REST API injection.
		add_filter('aitamer_rest_api', array($this, 'get_rest_api_pro'), 10, 3);

		// Component registration.
		add_action('aitamer_plugin_register_components', array($this, 'register_pro_components'));

		// Hook registration.
		add_action('aitamer_plugin_register_hooks', array($this, 'register_pro_hooks'));

		// Activation logic.
		add_action('aitamer_plugin_activate', array($this, 'handle_pro_activation'));

		// Mark Pro as active.
		add_filter('aitamer_is_pro_active', '__return_true');
	}

	/**
	 * Replaces the default ContentFilter with ContentFilterPro.
	 */
	public function get_content_filter_pro($filter, $detector)
	{
		return new ContentFilterPro($detector);
	}

	/**
	 * Replaces the default MetaBox with MetaBoxPro.
	 */
	public function get_meta_box_pro($meta_box)
	{
		return new MetaBoxPro();
	}

	/**
	 * Replaces the default RestApi with RestApiPro.
	 */
	public function get_rest_api_pro($api, $detector, $logger)
	{
		return new RestApiPro($detector, $logger);
	}

	/**
	 * Registers Pro-specific components (Stripe, C2PA).
	 *
	 * @param Plugin $plugin The main plugin instance.
	 */
	public function register_pro_components($plugin)
	{
		$plugin->add_component('billing_manager', new BillingManager());
		$plugin->add_component('c2pa_manager', new C2paManager());
		$plugin->add_component('media_pro', new MediaPro());
		$plugin->add_component('usdt_verifier', new USDTVerifier());
	}

	/**
	 * Registers Pro-specific action hooks.
	 *
	 * @param Plugin $plugin The main plugin instance.
	 */
	public function register_pro_hooks($plugin)
	{
		// Billing & Wallet DB upgrade.
		$current_version = get_option('aitamer_billing_db_version', '1.0');
		if (version_compare($current_version, '1.4', '<')) {
			BillingManager::install_table();
		}

		// C2PA registration.
		$c2pa = $plugin->get_component('c2pa_manager');
		if ($c2pa) {
			$c2pa->register();
		}

		// Media Pro registration.
		$media_pro = $plugin->get_component('media_pro');
		if ($media_pro) {
			$media_pro->register();
		}

		// Async Media Processing Hooks.
		add_action('aitamer_process_media', array('\AiTamer\Watermarker', 'process_media_async'));
	}

	/**
	 * Handles Pro-specific activation logic.
	 */
	public function handle_pro_activation()
	{
		// Install billing table.
		BillingManager::install_table();

		// Add Pro-specific default options.
		$settings = get_option('aitamer_settings', array());
		$pro_defaults = array(
			'enable_watermarking' => true,
			'enable_c2pa'         => true,
			'show_c2pa_badge'      => false,
		);
		update_option('aitamer_settings', array_merge($pro_defaults, $settings));
	}
}
