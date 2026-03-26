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

defined( 'ABSPATH' ) || exit; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

/**
 * Main plugin class. Boots components and registers all hooks.
 */
class Plugin {

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

	/** @var StripeManager */
	private $stripe_manager;

	/**
	 * Returns the single instance, creating it on first call.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {
		$this->detector          = new Detector();
		$this->protector         = new Protector( $this->detector );
		$this->logger            = new Logger();
		$this->limiter           = new Limiter();
		$this->bandwidth_limiter = new BandwidthLimiter();
		$this->content_filter    = new ContentFilter( $this->detector );
		$this->meta_box          = new MetaBox();
		$this->bot_updater       = new BotUpdater();
		$this->license_manager   = new LicenseManager();
		$this->rest_api          = new RestApi();
		$this->stripe_manager    = new StripeManager();
		$this->register_hooks();
	}

	/**
	 * Register all plugin hooks.
	 */
	private function register_hooks(): void {
		// Boot the REST API (always — available on frontend and admin).
		$this->rest_api->register();

		// Auto-create/upgrade the DB table if needed (no deactivation required).
		if ( get_option( 'aitamer_db_version' ) !== '1.0' ) {
			Logger::install_table();
		}

		// Rate-limit bots before anything else runs.
		add_action( 'init', array( $this, 'run_limiter' ), 1 );

		// Inject HTTP headers as early as possible.
		add_filter( 'wp_headers', array( $this->protector, 'inject_headers' ) );

		// Inject <meta> tags in <head>.
		add_action( 'wp_head', array( $this->protector, 'inject_meta_tags' ), 1 );

		// Append rules to the virtual robots.txt.
		add_filter( 'robots_txt', array( $this->protector, 'append_robots_txt' ), 10, 2 );

		// Log after the WP query runs (post context available).
		add_action( 'wp', array( $this, 'log_request' ) );

		// Schedule daily log purge.
		if ( ! wp_next_scheduled( 'aitamer_daily_purge' ) ) {
			wp_schedule_event( time(), 'daily', 'aitamer_daily_purge' );
		}
		add_action( 'aitamer_daily_purge', array( 'AiTamer\Logger', 'purge_old_logs' ) );

		// Boot the admin UI only in the dashboard.
		if ( is_admin() ) {
			Admin::get_instance();
			$this->meta_box->register(); // Meta boxes only needed in WP admin.
		} else {
			// Content filter and licensing headers only run on the frontend.
			$this->content_filter->register();
			$this->license_manager->register(); // Phase 5: inject license headers + JSON-LD.
		}

		// Bot updater: register handler and schedule daily cron.
		$this->bot_updater->register();
		if ( ! wp_next_scheduled( 'aitamer_update_bots' ) ) {
			wp_schedule_event( time(), 'daily', 'aitamer_update_bots' );
		}
	}

	/**
	 * Runs the rate limiter for the current request.
	 */
	public function run_limiter(): void {
		$agent = $this->detector->classify();
		$this->limiter->check( $agent );
		$this->bandwidth_limiter->check( $agent ); // Phase 4: bandwidth cap.
	}

	/**
	 * Logs the current request if it is from a known bot.
	 */
	public function log_request(): void {
		$agent = $this->detector->classify();
		$this->logger->log( $agent );
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		// Install the logging DB table.
		Logger::install_table();

		// Set default options on first activation.
		if ( false === get_option( 'aitamer_settings' ) ) {
			add_option(
				'aitamer_settings',
				array(
					'block_training_bots'  => true,
					'inject_meta_tags'     => true,
					'inject_http_headers'  => true,
					'rate_limit_enabled'   => true,
					'rpm'                  => 30,
					'auto_update_bots'     => true,
				)
			);
		}
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		// Nothing to clean up on deactivation for now.
	}
}
