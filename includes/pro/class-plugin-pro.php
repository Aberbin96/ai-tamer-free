<?php

namespace AiTamer;

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

		// Active Defense injection.
		add_filter('aitamer_active_defense', array($this, 'apply_active_defense'), 10, 3);
		add_filter('aitamer_preview_defense', array($this, 'preview_active_defense'));
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
		$plugin->add_component('stripe_manager', new StripeManager());
		$plugin->add_component('c2pa_manager', new C2paManager());
	}

	/**
	 * Registers Pro-specific action hooks.
	 *
	 * @param Plugin $plugin The main plugin instance.
	 */
	public function register_pro_hooks($plugin)
	{
		// Billing DB upgrade.
		if (get_option('aitamer_billing_db_version') !== '1.0') {
			StripeManager::install_table();
		}

		// C2PA registration.
		$c2pa = $plugin->get_component('c2pa_manager');
		if ($c2pa) {
			$c2pa->register();
		}
	}

	/**
	 * Handles Pro-specific activation logic.
	 */
	public function handle_pro_activation()
	{
		// Install billing table.
		StripeManager::install_table();

		// Add Pro-specific default options.
		$settings = get_option('aitamer_settings', array());
		$pro_defaults = array(
			'enable_watermarking' => true,
			'enable_c2pa'         => true,
			'show_c2pa_badge'      => false,
		);
		
		update_option('aitamer_settings', array_merge($pro_defaults, $settings));
	}

	/**
	 * Applies active defense (Poisoning) if configured.
	 */
	public function apply_active_defense($content, $defense, $post_id)
	{
		if ('poison' === $defense && class_exists('AiTamer\Poisoner')) {
			$cache_key = 'ait_p_' . $post_id;
			$cached    = wp_cache_get($cache_key, 'ai-tamer');
			if (false === $cached) {
				$cached = get_transient($cache_key);
			}

			if (false !== $cached) {
				return $cached;
			}

			$poisoned = Poisoner::poison($content);

			// Cache for 24 hours.
			wp_cache_set($cache_key, $poisoned, 'ai-tamer', DAY_IN_SECONDS);
			set_transient($cache_key, $poisoned, DAY_IN_SECONDS);

			return $poisoned;
		}

		return $content;
	}

	/**
	 * Previews active defense for admins.
	 */
	public function preview_active_defense($content)
	{
		if (! empty($_GET['aitamer_preview_poison']) && current_user_can('manage_options') && class_exists('AiTamer\Poisoner')) {
			return Poisoner::poison($content);
		}
		return $content;
	}
}
