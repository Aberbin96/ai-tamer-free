<?php

/**
 * AI Tamer Pro Loader.
 *
 * This file handles loading all Pro-specific components.
 * It is only included in the Pro version of the plugin.
 */

namespace AiTamer;

defined('ABSPATH') || exit;

// Define Pro constant.
if (!defined('AITAMER_PRO')) {
	define('AITAMER_PRO', true);
}

// Load Pro Enums.
require_once AITAMER_PLUGIN_DIR . 'includes/pro/enums/class-license-scope.php';

// Load Pro classes.
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-license-verifier.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-rest-api-pro.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-content-filter-pro.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-meta-box-pro.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/interface-payment-provider.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-stripe-manager.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-watermarker.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-heuristic-detector.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-c2pa-manager.php';
require_once AITAMER_PLUGIN_DIR . 'includes/pro/class-plugin-pro.php';
require_once AITAMER_PLUGIN_DIR . 'admin/pro/class-admin-pro.php';

// Instantiate Pro handlers.
new PluginPro();
new AdminPro();
