<?php

/**
 * Core plugin class — singleton loader.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function add_action;
use function add_filter;
use function add_option;
use function get_option;
use function update_option;
use function is_admin;
use function wp_schedule_event;
use function wp_next_scheduled;
use function __;
use function wp_enqueue_script;
use function wp_localize_script;
use function esc_url_raw;
use function rest_url;
use function wp_create_nonce;

defined('ABSPATH') || exit; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

/**
 * Main plugin class. Boots components and registers all hooks.
 */
class Plugin
{

	/** @var Plugin|null Singleton instance. */
	private static $instance = null;

	/** @var Detector */
	private $detector;

	/** @var Protector */
	private $protector;

	/** @var Logger */
	private $logger;

	/** @var Limiter */
	private $limiter;

	/** @var BandwidthLimiter */
	private $bandwidth_limiter;

	/** @var ContentFilter */
	private $content_filter;

	/** @var MetaBox */
	private $meta_box;

	/** @var BotUpdater */
	private $bot_updater;

	/** @var LicenseManager */
	private $license_manager;

	/** @var RestApi */
	private $rest_api;

	/** @var Notifications */
	private $notifications;

	/** @var array Registry for extra components (Pro). */
	private $components = array();

	/**
	 * Returns the single instance, creating it on first call.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin
	{
		if (null === self::$instance) {
			self::$instance = new self();
			$GLOBALS['ai_tamer'] = self::$instance;
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct()
	{
		$this->detector          = new Detector();
		$this->protector         = new Protector($this->detector);
		$this->logger            = new Logger();
		$this->limiter           = new Limiter();
		$this->bandwidth_limiter = new BandwidthLimiter();

		// Inject Content Filter (can be overridden by Pro).
		$this->content_filter = apply_filters('aitamer_content_filter', new ContentFilter($this->detector), $this->detector);

		// Inject Meta Box (can be overridden by Pro).
		$this->meta_box = apply_filters('aitamer_meta_box', new MetaBox());

		$this->bot_updater     = new BotUpdater();
		$this->license_manager = new LicenseManager();
		$this->notifications   = new Notifications();

		// Inject REST API (can be overridden by Pro).
		$this->rest_api = apply_filters('aitamer_rest_api', new RestApi($this->detector, $this->logger), $this->detector, $this->logger);

		// Let Pro register extra components.
		do_action('aitamer_plugin_register_components', $this);

		$this->register_hooks();
	}

	/**
	 * Returns the Stripe manager.
	 *
	 * @return object|null
	 */
	public function get_stripe_manager(): ?object
	{
		return $this->get_component('stripe_manager');
	}

	/**
	 * Register all plugin hooks.
	 */
	private function register_hooks(): void
	{
		// Boot the REST API (always — available on frontend and admin).
		$this->rest_api->register();
		$this->notifications->register();

		// Auto-create/upgrade the DB table if needed.
		if (get_option('aitamer_db_version') !== '1.1') {
			Logger::install_table();
		}

		// Let Pro register its hooks and DB updates.
		do_action('aitamer_plugin_register_hooks', $this);

		// Rate-limit bots before anything else runs.
		add_action('init', array($this, 'run_limiter'), 1);

		// Inject HTTP headers as early as possible.
		add_filter('wp_headers', array($this->protector, 'inject_headers'));

		// Inject <meta> tags in <head>.
		add_action('wp_head', array($this->protector, 'inject_meta_tags'), 1);

		// Fingerprinting Script Injection
		add_action('wp_enqueue_scripts', array($this, 'enqueue_fingerprint_script'));

		// Append rules to the virtual robots.txt.
		add_filter('robots_txt', array($this->protector, 'append_robots_txt'), 10, 2);

		// Active Defense (Block/402) on frontend.
		add_action('template_redirect', array($this->protector, 'handle_llms_txt'), 5);
		add_action('template_redirect', array($this->protector, 'handle_active_defense'));

		// Log after the WP query runs (post context available).
		add_action('wp', array($this, 'log_request'));

		// Schedule daily log purge.
		if (! wp_next_scheduled('aitamer_daily_purge')) {
			wp_schedule_event(time(), 'daily', 'aitamer_daily_purge');
		}
		add_action('aitamer_daily_purge', array('AiTamer\Logger', 'purge_old_logs'));

		// Boot the admin UI only in the dashboard.
		if (is_admin()) {
			Admin::get_instance();
			$this->meta_box->register(); // Meta boxes only needed in WP admin.
		} else {
			// Content filter and licensing headers only run on the frontend.
			$this->content_filter->register();
			$this->license_manager->register(); // Phase 5: inject license headers + JSON-LD.
		}

		// Bot updater: register handler and schedule daily cron.
		$this->bot_updater->register();
		if (! wp_next_scheduled('aitamer_update_bots')) {
			wp_schedule_event(time(), 'daily', 'aitamer_update_bots');
		}
		add_action('aitamer_update_bots', array($this->bot_updater, 'run'));

		// Async Log Flushing: scheduled every minute.
		add_filter('cron_schedules', array($this, 'add_cron_schedules'));
		if (! wp_next_scheduled('aitamer_flush_logs')) {
			wp_schedule_event(time(), 'every_minute', 'aitamer_flush_logs');
		}
		add_action('aitamer_flush_logs', array('AiTamer\Logger', 'flush_buffer'));
	}

	/**
	 * Adds custom cron schedules (every_minute).
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules(array $schedules): array
	{
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => __('Every Minute', 'ai-tamer'),
		);
		return $schedules;
	}



	/**
	 * Runs the rate limiter for the current request.
	 */
	public function run_limiter(): void
	{
		$agent = $this->detector->classify();
		$this->limiter->check($agent);
		$this->bandwidth_limiter->check($agent); // Phase 4: bandwidth cap.
	}

	/**
	 * Logs the current request if it is from a known bot.
	 */
	public function log_request(): void
	{
		$agent = $this->detector->classify();
		// On the frontend, if it's a bot, we apply at least header/meta protection.
		$protection = $agent['matched'] ? 'headers' : 'none';
		$this->logger->log($agent, $protection);
	}

	/**
	 * Enqueues the fingerprint script on the frontend.
	 */
	public function enqueue_fingerprint_script(): void
	{
		$settings = get_option('aitamer_settings', array());
		if (empty($settings['enable_fingerprinting'])) {
			return; // Can be toggled from settings if desired, or assume always on
		}

		wp_enqueue_script(
			'aitamer-fingerprint',
			AITAMER_PLUGIN_URL . 'assets/js/fingerprint.js',
			array(),
			AITAMER_VERSION,
			true
		);

		wp_localize_script('aitamer-fingerprint', 'aiTamerApi', array(
			'root'  => esc_url_raw(rest_url()),
			'nonce' => wp_create_nonce('wp_rest'),
		));
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void
	{
		// Install the logging DB table.
		Logger::install_table();

		// Let Pro handle its activation logic.
		do_action('aitamer_plugin_activate');

		// Set default options on first activation.
		if (false === get_option('aitamer_settings')) {
			add_option(
				'aitamer_settings',
				array(
					'block_training_bots'  => true,
					'inject_meta_tags'     => true,
					'inject_http_headers'  => true,
					'rate_limit_enabled'   => true,
					'rpm'                  => 30,
					'auto_update_bots'     => true,
					'enable_watermarking'  => true,
					'enable_c2pa'         => true,
					'show_c2pa_badge'      => false,
				)
			);
		}
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void
	{
		// Nothing to clean up on deactivation for now.
	}

	/**
	 * Generic component registry.
	 */
	public function add_component(string $key, object $instance): void
	{
		$this->components[$key] = $instance;
	}

	/**
	 * Retrieve a component from the registry.
	 */
	public function get_component(string $key): ?object
	{
		return $this->components[$key] ?? null;
	}
	
	/**
	 * Custom logger to bypass system permission issues.
	 */
	public static function log(string $message): void
	{
		$log_file = '/tmp/aitamer.log';
		$timestamp = date('Y-m-d H:i:s');
		$entry = "[{$timestamp}] {$message}\n";
		file_put_contents($log_file, $entry, FILE_APPEND);
	}
}
