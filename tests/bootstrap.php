<?php

/**
 * PHPUnit Bootstrap for AI Tamer Tests.
 *
 * This file initializes the BrainMonkey testing environment and
 * loads the composer autoloader.
 */

// Load Composer autoloader.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Initialize BrainMonkey.
Brain\Monkey\setUp();

// Additional setup if needed.
if (! defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
if (! defined('AITAMER_PLUGIN_DIR')) {
	define('AITAMER_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (! defined('DAY_IN_SECONDS')) {
	define('DAY_IN_SECONDS', 86400);
}

// Manually load Enums.
require_once AITAMER_PLUGIN_DIR . 'includes/enums/class-defense-strategy.php';
require_once AITAMER_PLUGIN_DIR . 'includes/enums/class-license-policy.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/enums/class-license-scope.php';

// Core Classes (needed by Pro)
require_once AITAMER_PLUGIN_DIR . 'includes/class-rest-api.php';

// Pro Classes (Moved to pro/)
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-license-verifier.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-rest-api-pro.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-content-filter-pro.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-meta-box-pro.php';

require_once AITAMER_PLUGIN_DIR . 'includes/pro/interface-payment-provider.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-stripe-manager.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-watermarker.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-heuristic-detector.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-c2pa-manager.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-media-pro.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-web3-toll.php';

// Ensure BrainMonkey is torn down after tests.
register_shutdown_function(function () {
	Brain\Monkey\tearDown();
});

// Load WP stubs.
require_once __DIR__ . '/Unit/stubs.php';
