<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Ai_Tamer
 */

use AiTamer\Logger;

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Load plugin constants and autoloader if needed.
 * But since we're uninstalled, we just want to drop the table and delete options.
 */
require_once __DIR__ . '/ai-tamer.php';

// 1. Drop the custom database table.
Logger::drop_table();

// 2. Delete plugin options.
delete_option( 'aitamer_settings' );
delete_option( 'aitamer_db_version' );
delete_option( 'aitamer_bots_last_updated' );

// 3. Clear any scheduled crons.
wp_clear_scheduled_hook( 'aitamer_daily_purge' );
wp_clear_scheduled_hook( 'aitamer_update_bots' );
