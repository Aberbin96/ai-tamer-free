<?php
/**
 * PHPUnit Bootstrap for AI Tamer Tests.
 *
 * This file initializes the BrainMonkey testing environment and
 * loads the composer autoloader.
 */

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Initialize BrainMonkey.
Brain\Monkey\setUp();

// Additional setup if needed.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'AITAMER_PLUGIN_DIR' ) ) {
	define( 'AITAMER_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Ensure BrainMonkey is torn down after tests.
register_shutdown_function( function() {
	Brain\Monkey\tearDown();
} );

// Load WP stubs.
require_once __DIR__ . '/Unit/stubs.php';
