<?php
/**
 * Plugin Name:     AI Tamer — Scraper & Crawler Protection
 * Plugin URI:      https://github.com/Aberbin96/ai-tamer-free
 * Description:     Protects your WordPress content from unauthorized AI scraping and training while maintaining SEO visibility.
 * Author:          Alejandro Berbin
 * Author URI:      https://github.com/Aberbin96/
 * Text Domain:     ai-tamer
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Ai_Tamer
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'AITAMER_VERSION', '0.1.0' );
define( 'AITAMER_PLUGIN_FILE', __FILE__ );
define( 'AITAMER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AITAMER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes.
require_once AITAMER_PLUGIN_DIR . 'includes/class-ai-tamer.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-detector.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-protector.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-logger.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-limiter.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-bandwidth-limiter.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-audit-report.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-meta-box.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-content-filter.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-bot-updater.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-license-manager.php';
require_once AITAMER_PLUGIN_DIR . 'includes/class-license-verifier.php';
require_once AITAMER_PLUGIN_DIR . 'admin/class-admin.php';

// Activation / Deactivation hooks.
register_activation_hook( __FILE__, array( 'AiTamer\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AiTamer\\Plugin', 'deactivate' ) );

// Boot the plugin.
add_action( 'plugins_loaded', array( 'AiTamer\\Plugin', 'get_instance' ) );
