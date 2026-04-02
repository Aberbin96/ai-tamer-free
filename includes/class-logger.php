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
use function wp_unslash;
use function update_option;
use function wp_cache_get;
use function wp_cache_set;
use function wp_cache_delete;
use function get_transient;
use function set_transient;
use function delete_transient;
use function wp_next_scheduled;
use function wp_schedule_single_event;

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

	/** @var string Cache key for log buffer. */
	const BUFFER_KEY = 'aitamer_log_buffer';

	/** @var int Max records before forced flush. */
	const BUFFER_LIMIT = 50;

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
		) {$charset_collate};"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		update_option( 'aitamer_db_version', '1.1' );
	}

	/**
	 * Drops the log table. Called on plugin uninstall.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
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

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		$entry = array(
			'bot_name'    => $agent['name'],
			'bot_type'    => $agent['type'],
			'post_id'     => $post_id ?: ( get_the_ID() ?: null ),
			'request_uri' => $uri,
			'ip_hash'     => hash( 'sha256', $ip ),
			'user_agent'  => substr( (string) ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ), 0, 255 ),
			'protection'  => sanitize_text_field( $protection ),
			'created_at'  => current_time( 'mysql' ),
		);

		$buffer = wp_cache_get( self::BUFFER_KEY, 'ai-tamer' );
		if ( false === $buffer ) {
			$buffer = get_transient( self::BUFFER_KEY ) ?: array();
		}

		$buffer[] = $entry;

		wp_cache_set( self::BUFFER_KEY, $buffer, 'ai-tamer', 3600 );
		set_transient( self::BUFFER_KEY, $buffer, 3600 );

		// If buffer is large enough, trigger async flush.
		if ( count( $buffer ) >= self::BUFFER_LIMIT ) {
			if ( ! wp_next_scheduled( 'aitamer_flush_logs' ) ) {
				wp_schedule_single_event( time(), 'aitamer_flush_logs' );
			}
		}
	}

	/**
	 * Flushes the log buffer to the database in a single batch.
	 */
	public static function flush_buffer(): void {
		$buffer = wp_cache_get( self::BUFFER_KEY, 'ai-tamer' );
		if ( false === $buffer ) {
			$buffer = get_transient( self::BUFFER_KEY );
		}

		if ( empty( $buffer ) ) {
			return;
		}

		// Clear buffer immediately to avoid race conditions.
		wp_cache_delete( self::BUFFER_KEY, 'ai-tamer' );
		delete_transient( self::BUFFER_KEY );

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// Perform batch insert.
		$query = 'INSERT INTO %i (bot_name, bot_type, post_id, request_uri, ip_hash, user_agent, protection, created_at) VALUES ';
		$placeholders = array();
		$values       = array( $table );

		foreach ( $buffer as $entry ) {
			$placeholders[] = '( %s, %s, %d, %s, %s, %s, %s, %s )';
			$values[]       = $entry['bot_name'];
			$values[]       = $entry['bot_type'];
			$values[]       = $entry['post_id'];
			$values[]       = $entry['request_uri'];
			$values[]       = $entry['ip_hash'];
			$values[]       = $entry['user_agent'];
			$values[]       = $entry['protection'];
			$values[]       = $entry['created_at'];
		}

		$query .= implode( ', ', $placeholders );

		$wpdb->query( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Returns logs with pagination and filtering.
	 *
	 * @param array $args {
	 *     Optional. Arguments for log retrieval.
	 *     @type int    $limit      Number of records to return. Default 20.
	 *     @type int    $offset     Number of records to skip. Default 0.
	 *     @type string $bot_type   Filter by agent type (e.g. 'training').
	 *     @type string $protection Filter by protection level.
	 *     @type string $s          Search string (matches bot_name or request_uri).
	 * }
	 * @return array List of log entries.
	 */
	public static function get_logs( array $args = array() ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$defaults = array(
			'limit'      => 20,
			'offset'     => 0,
			'bot_type'   => '',
			'protection' => '',
			's'          => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$params = array();

		if ( ! empty( $args['bot_type'] ) ) {
			$where[]  = 'bot_type = %s';
			$params[] = $args['bot_type'];
		}
		if ( ! empty( $args['protection'] ) ) {
			$where[]  = 'protection = %s';
			$params[] = $args['protection'];
		}
		if ( ! empty( $args['s'] ) ) {
			$where[]  = '(bot_name LIKE %s OR request_uri LIKE %s OR user_agent LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['s'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_str = implode( ' AND ', $where );
		$query     = 'SELECT * FROM %i WHERE ' . $where_str . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params    = array_merge( array( $table ), $params, array( $args['limit'], $args['offset'] ) );

		return $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Counts logs matching filters.
	 *
	 * @param array $args Same args as get_logs.
	 * @return int Total number of logs.
	 */
	public static function count_logs( array $args = array() ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$where = array( '1=1' );
		$params = array();

		if ( ! empty( $args['bot_type'] ) ) {
			$where[]  = 'bot_type = %s';
			$params[] = $args['bot_type'];
		}
		if ( ! empty( $args['protection'] ) ) {
			$where[]  = 'protection = %s';
			$params[] = $args['protection'];
		}
		if ( ! empty( $args['s'] ) ) {
			$where[]  = '(bot_name LIKE %s OR request_uri LIKE %s OR user_agent LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['s'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_str = implode( ' AND ', $where );
		$query     = 'SELECT COUNT(*) FROM %i WHERE ' . $where_str;
		$params    = array_merge( array( $table ), $params );

		return (int) $wpdb->get_var( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

		$total = self::count_logs();

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
