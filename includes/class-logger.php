<?php
/**
 * Logger — records AI agent access events.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function add_action;
use function current_time;
use function get_the_ID;
use function sanitize_text_field;
use function wp_parse_args;

defined( 'ABSPATH' ) || exit;

/**
 * Logger class.
 *
 * Creates the custom DB table on activation and writes log entries
 * for every AI agent request. Human visitors are never logged.
 */
class Logger {

	/** @var string DB table name (without prefix). */
	const TABLE = 'aitamer_logs';

	/**
	 * Creates or upgrades the log table. Called on plugin activation.
	 */
	public static function install_table(): void {
		global $wpdb;

		$table      = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bot_name    VARCHAR(100)        NOT NULL DEFAULT '',
			bot_type    VARCHAR(50)         NOT NULL DEFAULT '',
			post_id     BIGINT(20) UNSIGNED          DEFAULT NULL,
			request_uri TEXT                NOT NULL,
			ip_hash     VARCHAR(64)         NOT NULL DEFAULT '',
			user_agent  VARCHAR(255)        NOT NULL DEFAULT '',
			protection  VARCHAR(50)         NOT NULL DEFAULT 'none',
			created_at  DATETIME            NOT NULL,
			PRIMARY KEY  (id),
			KEY bot_idx (bot_name(50), created_at),
			KEY post_idx (post_id, created_at)
		) {$charset_collate};"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		update_option( 'aitamer_db_version', '1.1' );
	}

	/**
	 * Drops the log table. Called on plugin uninstall.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/**
	 * Logs the current request if it comes from an AI agent.
	 *
	 * @param array  $agent      Result from Detector::classify().
	 * @param string $protection Protection level applied (e.g. 'none', 'blocked', 'filtered').
	 * @param int    $post_id    Optional post ID if known.
	 */
	public function log( array $agent, string $protection = 'none', int $post_id = 0 ): void {
		if ( ! $agent['matched'] ) {
			return; // Never log human visitors.
		}

		global $wpdb;

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . self::TABLE,
			array(
				'bot_name'    => $agent['name'],
				'bot_type'    => $agent['type'],
				'post_id'     => $post_id ?: ( get_the_ID() ?: null ),
				'request_uri' => $uri,
				'ip_hash'     => hash( 'sha256', $ip ), // GDPR: never store raw IPs.
				'user_agent'  => substr( (string) ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ), 0, 255 ),
				'protection'  => sanitize_text_field( $protection ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns aggregated stats: top bots and top posts.
	 *
	 * @param int $limit Number of rows to return per group.
	 * @return array{top_bots: array, top_posts: array, total: int}
	 */
	public static function get_stats( int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$top_bots = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT bot_name, bot_type, COUNT(*) AS hits FROM `{$table}` GROUP BY bot_name, bot_type ORDER BY hits DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		$top_posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_id, COUNT(*) AS hits FROM `{$table}` WHERE post_id IS NOT NULL GROUP BY post_id ORDER BY hits DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM `{$table}`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return compact( 'top_bots', 'top_posts', 'total' );
	}

	/**
	 * Purges log entries older than N days.
	 *
	 * @param int $days Number of days to keep.
	 */
	public static function purge_old_logs( int $days = 90 ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			)
		);
	}
}
