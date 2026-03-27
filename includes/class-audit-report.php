<?php
/**
 * AuditReport — generates downloadable audit evidence files.
 *
 * Creates a summary report (CSV + text) from the aitamer_logs table.
 * Reports can be triggered from the admin UI or via WP-CLI.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function current_user_can;
use function get_bloginfo;
use function gmdate;
use function wp_date;
use function number_format;
use function wp_upload_dir;
use function trailingslashit;
use function wp_mkdir_p;
use function sanitize_file_name;

defined( 'ABSPATH' ) || exit;

/**
 * AuditReport class.
 */
class AuditReport {

	/**
	 * Generates a CSV audit report and saves it to the uploads directory.
	 *
	 * @param int $days_back Number of days to include in the report.
	 * @return string|false Absolute path to the generated file, or false on failure.
	 */
	public static function generate( int $days_back = 30 ) {
		global $wpdb;

		$table   = $wpdb->prefix . Logger::TABLE;
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT bot_name, bot_type, post_id, request_uri, ip_hash, user_agent, protection, created_at FROM `{$table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days_back
			),
			ARRAY_A
		);

		if ( null === $results ) {
			return false;
		}

		// Prepare the private upload directory.
		$upload    = wp_upload_dir();
		$dir       = trailingslashit( $upload['basedir'] ) . 'aitamer-reports/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Place an .htaccess to block direct web access to the dir.
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $htaccess, "deny from all\n" );
		}

		// Build the filename.
		$filename = sanitize_file_name(
			sprintf(
				'aitamer-audit-%s-%dd.csv',
				gmdate( 'Y-m-d' ),
				$days_back
			)
		);
		$filepath = $dir . $filename;

		// Open the file and write CSV rows.
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$fp = fopen( $filepath, 'w' );
		if ( ! $fp ) {
			return false;
		}

		// Header row.
		fputcsv( $fp, array( 'Bot Name', 'Type', 'Post ID', 'Request URI', 'IP Hash', 'User Agent', 'Protection', 'Date (UTC)' ) );

		// Summary header block.
		$total    = count( $results );
		$site     = get_bloginfo( 'name' );
		$gen_date = gmdate( 'Y-m-d H:i:s' ) . ' UTC';

		// Meta block as comments at top.
		rewind( $fp );
		$meta = "# AI Tamer – Audit Report\n";
		$meta .= "# Site: {$site}\n";
		$meta .= "# Generated: {$gen_date}\n";
		$meta .= "# Period: last {$days_back} days\n";
		$meta .= "# Total records: {$total}\n";
		$meta .= "#\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $filepath, $meta );

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$fp = fopen( $filepath, 'a' );
		if ( ! $fp ) {
			return false;
		}

		fputcsv( $fp, array( 'Bot Name', 'Type', 'Post ID', 'Request URI', 'IP Hash', 'User Agent', 'Protection', 'Date (UTC)' ) );

		foreach ( $results as $row ) {
			fputcsv( $fp, array(
				$row['bot_name'],
				$row['bot_type'],
				$row['post_id'] ?? '',
				$row['request_uri'],
				$row['ip_hash'],
				$row['user_agent'] ?? '',
				$row['protection'] ?? 'none',
				$row['created_at'],
			) );
		}

		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $filepath;
	}

	/**
	 * Returns the URL to trigger a report download from the admin.
	 *
	 * @param int $days_back Days to include.
	 * @return string Admin action URL.
	 */
	public static function get_download_url( int $days_back = 30 ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'aitamer_download_report',
					'days'     => $days_back,
				),
				admin_url( 'admin-post.php' )
			),
			'aitamer_download_report'
		);
	}
}
