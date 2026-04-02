<?php

namespace AiTamer;

use function add_filter;
use function absint;
use function sanitize_text_field;
use function current_time;
use function dbDelta;
use function update_option;
use function get_option;

/**
 * BillingManager - Core database operations for AI Tamer payments and wallets.
 * Decoupled from Stripe to support any payment provider (e.g. Lightning).
 *
 * @package Ai_Tamer
 */
class BillingManager {
	public function __construct() {
		add_filter( 'aitamer_monetization_earnings', array( $this, 'get_total_earnings' ) );
	}

	const TABLE         = 'aitamer_billing';

	/**
	 * Installs/Updates the billing and wallet database tables.
	 */
	public static function install_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$billing_table   = $wpdb->prefix . self::TABLE;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_billing = "CREATE TABLE $billing_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			agent_name varchar(255) DEFAULT '',
			amount float DEFAULT 0,
			currency varchar(10) DEFAULT 'USD',
			provider_id varchar(255) NOT NULL,
			status varchar(50) DEFAULT 'completed',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY provider_id (provider_id)
		) $charset_collate;";

		// Tolls Table: Tracks L402 invoices and access for specific IPs/Posts.
		// Updated to include P2P USDT Transaction Hashes.
		$tolls_table = $wpdb->prefix . 'aitamer_tolls';
		$sql_tolls = "CREATE TABLE $tolls_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			invoice_hash varchar(255) DEFAULT '',
			transaction_hash varchar(128) DEFAULT '',
			bolt11 text DEFAULT '',
			network varchar(50) DEFAULT '',
			amount_usdt float DEFAULT 0,
			post_id bigint(20) NOT NULL,
			bot_ip varchar(100) NOT NULL,
			status varchar(50) DEFAULT 'pending',
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY transaction_hash (transaction_hash),
			KEY bot_access (post_id, bot_ip)
		) $charset_collate;";

		dbDelta( array( $sql_billing, $sql_tolls ) );

		update_option( 'aitamer_billing_db_version', '1.4' );
	}

	/**
	 * Logs a new transaction in the billing table.
	 *
	 * @param array $data {
	 *     @type string $agent_name
	 *     @type float  $amount
	 *     @type string $currency
	 *     @type string $provider_id (e.g. stripe session ID or l402 hash)
	 *     @type string $status
	 * }
	 */
	public function log_transaction( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$wpdb->insert(
			$table,
			array(
				'agent_name'  => sanitize_text_field( $data['agent_name'] ?? 'Unknown Agent' ),
				'amount'      => (float) ( $data['amount'] ?? 0 ),
				'currency'    => sanitize_text_field( $data['currency'] ?? 'USD' ),
				'provider_id' => sanitize_text_field( $data['provider_id'] ),
				'status'      => sanitize_text_field( $data['status'] ?? 'completed' ),
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Retrieves transactions with optional filtering and pagination.
	 */
	public function get_transactions( array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$limit  = isset( $args['limit'] ) ? absint( $args['limit'] ) : 20;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
		$search = isset( $args['s'] ) ? sanitize_text_field( $args['s'] ) : '';

		$where = '1=1';
		if ( ! empty( $search ) ) {
			$where .= $wpdb->prepare( " AND (agent_name LIKE %s OR provider_id LIKE %s)", '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		return $wpdb->get_results(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT {$offset}, {$limit}",
			ARRAY_A
		);
	}

	/**
	 * Counts total transactions for pagination.
	 */
	public function count_transactions( array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$search = isset( $args['s'] ) ? sanitize_text_field( $args['s'] ) : '';
		$where = '1=1';
		if ( ! empty( $search ) ) {
			$where .= $wpdb->prepare( " AND (agent_name LIKE %s OR provider_id LIKE %s)", '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	/**
	 * Returns the total earnings from all completed transactions.
	 * Hooked to 'aitamer_monetization_earnings'.
	 */
	public function get_total_earnings(): float {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return (float) $wpdb->get_var( "SELECT SUM(amount) FROM {$table} WHERE status = 'completed'" );
	}
}
