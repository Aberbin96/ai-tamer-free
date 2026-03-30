<?php
/**
 * Web3 Toll — Handles Crypto Micropayment (L402) logic for Base network.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function __;
use function get_option;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function is_wp_error;
use function wp_hash;
use function set_transient;
use function get_transient;

defined( 'ABSPATH' ) || exit;

/**
 * Web3Toll class.
 */
class Web3Toll {

	/**
	 * USDC Contract Address on Base Network.
	 */
	const USDC_BASE_CONTRACT = '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';

	/**
	 * Keccak256 hash of "Transfer(address,address,uint256)".
	 */
	const TRANSFER_EVENT_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

	/**
	 * Validates a transaction hash via EVM JSON-RPC to ensure the payment is valid.
	 * 
	 * @param string $tx_hash The transaction root hash.
	 * @return bool|string True if valid, or a string describing the error.
	 */
	public static function verify_transaction( string $tx_hash ) {
		$settings = get_option( 'aitamer_settings', array() );
		
		$wallet = strtolower( $settings['base_wallet_address'] ?? '' );
		$price  = (float) ( $settings['usdc_price_per_request'] ?? 0.01 );
		$rpc    = $settings['base_rpc_node_url'] ?? 'https://mainnet.base.org';

		if ( empty( $wallet ) ) {
			return __( 'Wallet address not configured in AI Tamer settings.', 'ai-tamer' );
		}

		if ( ! preg_match( '/^0x[a-fA-F0-9]{40}$/', $wallet ) ) {
			return __( 'Invalid wallet address format.', 'ai-tamer' );
		}

		if ( ! preg_match( '/^0x[a-fA-F0-9]{64}$/', $tx_hash ) ) {
			return __( 'Invalid transaction hash format.', 'ai-tamer' );
		}

		// Prevent double-spending by checking if we have used this TxHash before.
		if ( get_transient( 'aitamer_tx_' . $tx_hash ) ) {
			return __( 'Transaction hash has already been used (Double Spend).', 'ai-tamer' );
		}

		// USDC uses 6 decimals.
		// Amount to verify = price * 1,000,000.
		$expected_raw_amount = dechex( (int) round( $price * 1000000 ) );

		$payload = array(
			'jsonrpc' => '2.0',
			'method'  => 'eth_getTransactionReceipt',
			'params'  => array( $tx_hash ),
			'id'      => 1,
		);

		$response = wp_remote_post(
			$rpc,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return __( 'Failed to connect to RPC node: ', 'ai-tamer' ) . $response->get_error_message(); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data['result'] ) ) {
			return __( 'Transaction not found or not confirmed on the blockchain.', 'ai-tamer' );
		}

		$receipt = $data['result'];

		if ( ! isset( $receipt['status'] ) || hexdec( $receipt['status'] ) !== 1 ) {
			return __( 'Transaction reverted or failed.', 'ai-tamer' );
		}

		$pad_wallet = '0x000000000000000000000000' . substr( $wallet, 2 );
		$found_valid_transfer = false;

		// Inspect all event logs for the expected USDC transfer.
		foreach ( $receipt['logs'] as $log ) {
			$address = strtolower( $log['address'] ?? '' );
			
			if ( $address !== strtolower( self::USDC_BASE_CONTRACT ) ) {
				continue;
			}

			$topics = $log['topics'] ?? array();

			if ( empty( $topics[0] ) || strtolower( $topics[0] ) !== self::TRANSFER_EVENT_TOPIC ) {
				continue;
			}

			// topics[1] = from, topics[2] = to
			$to_address = strtolower( $topics[2] ?? '' );
			
			if ( $to_address === $pad_wallet ) {
				// We found a transfer to our wallet! Check the amount.
				$data_amount = strtolower( $log['data'] ?? '' );
				$actual_amount = hexdec( $data_amount );
				$required_amount = hexdec( $expected_raw_amount );

				if ( $actual_amount >= $required_amount ) {
					$found_valid_transfer = true;
					break;
				}
			}
		}

		if ( ! $found_valid_transfer ) {
			return __( 'No valid USDC transfer matching the price was found to your wallet in this transaction.', 'ai-tamer' );
		}

		// Mark TxHash as used for 30 days to mitigate simple replay attacks over long term, 
		// and permanently in DB if we implement a ledger later.
		set_transient( 'aitamer_tx_' . $tx_hash, true, 30 * DAY_IN_SECONDS );

		return true;
	}

	/**
	 * Emits an HMAC-signed session token specifically for accessing a post.
	 *
	 * @param int $post_id The ID of the post to grant access to.
	 * @return string The signed token.
	 */
	public static function issue_post_token( int $post_id ): string {
		$expiration = time() + ( 24 * 3600 ); // Valid for 24 hours.
		$payload    = $post_id . '|' . $expiration;
		
		// wp_hash uses the site salts for cryptographic signature.
		$signature  = wp_hash( $payload, 'auth' ); 
		
		return base64_encode( $payload . '|' . $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Validates a given access token for a specific post.
	 *
	 * @param string $token   The token presented by the client.
	 * @param int    $post_id The post they are trying to access.
	 * @return bool True if valid and not expired.
	 */
	public static function validate_post_token( string $token, int $post_id ): bool {
		$decoded = base64_decode( $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( ! $decoded ) {
			return false;
		}

		$parts = explode( '|', $decoded );
		if ( count( $parts ) !== 3 ) {
			return false;
		}

		$token_post_id = (int) $parts[0];
		$token_expired = (int) $parts[1];
		$token_sig     = $parts[2];

		if ( $token_post_id !== $post_id ) {
			return false;
		}

		if ( time() > $token_expired ) {
			return false;
		}

		$expected_payload = $token_post_id . '|' . $token_expired;
		$expected_sig     = wp_hash( $expected_payload, 'auth' );

		return hash_equals( $expected_sig, $token_sig );
	}
}
