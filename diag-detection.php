<?php
/**
 * Diagnostic tool for testing bot detection logic.
 *
 * @package Ai_Tamer
 */

// Prevent direct web access.
if ( ! defined( 'ABSPATH' ) && 'cli' !== php_sapi_name() ) {
	exit;
}

// Look for wp-load.php up several levels
$aitamer_wp_load = null;
$aitamer_dir     = __DIR__;
for ( $aitamer_i = 0; $aitamer_i < 5; $aitamer_i++ ) {
	if ( file_exists( $aitamer_dir . '/wp-load.php' ) ) {
		$aitamer_wp_load = $aitamer_dir . '/wp-load.php';
		break;
	}
	$aitamer_dir = dirname( $aitamer_dir );
}

if ( ! $aitamer_wp_load ) {
	die( "Could not find wp-load.php\n" );
}
require_once $aitamer_wp_load;

$aitamer_diag_url = '2026/03/27/ai-text/';
$aitamer_post_id  = (int) url_to_postid( home_url( $aitamer_diag_url ) );

echo 'URL: ' . esc_html( $aitamer_diag_url ) . "\n";
echo 'Resolved Post ID: ' . (int) $aitamer_post_id . "\n";

if ( $aitamer_post_id ) {
	$aitamer_diag_post = get_post( $aitamer_post_id );
	echo 'Post Type: ' . esc_html( $aitamer_diag_post->post_type ?? 'N/A' ) . "\n";
	echo 'Post Status: ' . esc_html( $aitamer_diag_post->post_status ?? 'N/A' ) . "\n";
} else {
	echo "Could not resolve URL to Post ID.\n";
}

$aitamer_detector = new AiTamer\Detector();
$aitamer_agent    = $aitamer_detector->classify(); // classify uses current request headers by default
echo 'Detector Result: ' . esc_html( wp_json_encode( $aitamer_agent ) ) . "\n";

$aitamer_diag_settings = get_option( 'aitamer_settings', array() );
echo 'Active Defense Strategy: ' . esc_html( $aitamer_diag_settings['active_defense'] ?? 'block' ) . "\n";
echo 'Enable Micropayments: ' . ( ! empty( $aitamer_diag_settings['enable_micropayments'] ) ? 'yes' : 'no' ) . "\n";
