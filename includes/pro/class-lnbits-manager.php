<?php
/**
 * LnbitsManager - Direct integration with LNbits REST API.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use WP_Error;
use function get_option;
use function wp_remote_post;
use function wp_remote_get;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function json_decode;
use function json_encode;
use function sanitize_text_field;

defined('ABSPATH') || exit;

/**
 * Class LnbitsManager
 */
class LnbitsManager {
	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'lnbits';
	}

	/**
	 * Check if the provider is enabled and configured.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$settings = get_option('aitamer_settings', array());
		return ! empty($settings['lnbits_enabled']) && ! empty($settings['lnbits_api_key']);
	}

	/**
	 * Create a Lightning Invoice.
	 *
	 * @param int    $amount_sats Amount in Satoshis.
	 * @param string $memo        Description for the invoice.
	 * @return array|WP_Error Array with payment_hash, payment_request or WP_Error.
	 */
	public function create_invoice( int $amount_sats, string $memo = 'Ai Tamer Content' ) {
		$settings = get_option('aitamer_settings', array());
		$url      = $settings['lnbits_url'] ?? 'https://legend.lnbits.com';
		$api_key  = $settings['lnbits_api_key'] ?? '';

		if ( empty($api_key) ) {
			return new WP_Error( 'lnbits_missing_config', __( 'LNbits API Key missing.', 'ai-tamer' ) );
		}

		$endpoint = rtrim($url, '/') . '/api/v1/payments';

		$body = array(
			'out'    => false,
			'amount' => $amount_sats,
			'memo'   => $memo,
		);

		$response = wp_remote_post( $endpoint, array(
			'headers' => array(
				'X-Api-Key'    => $api_key,
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $body ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 201 && $code !== 200 ) {
			return new WP_Error( 'lnbits_api_error', $data['message'] ?? __( 'Failed to create invoice.', 'ai-tamer' ) );
		}

		return array(
			'payment_hash'    => $data['payment_hash'] ?? '',
			'payment_request' => $data['payment_request'] ?? '',
		);
	}

	/**
	 * Verify if an invoice is settled.
	 *
	 * @param string $payment_hash The payment hash to check.
	 * @return bool|WP_Error True if paid, false if not, WP_Error on API failure.
	 */
	public function is_invoice_paid( string $payment_hash ) {
		$settings = get_option('aitamer_settings', array());
		$url      = $settings['lnbits_url'] ?? 'https://legend.lnbits.com';
		$api_key  = $settings['lnbits_api_key'] ?? '';

		if ( empty($api_key) || empty($payment_hash) ) {
			return false;
		}

		$endpoint = rtrim($url, '/') . '/api/v1/payments/' . $payment_hash;

		$response = wp_remote_get( $endpoint, array(
			'headers' => array(
				'X-Api-Key' => $api_key,
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		
		return ! empty($data['paid']);
	}

	/**
	 * Fetches the LNbits wallet balance and info.
	 *
	 * Calls GET /api/v1/wallet with the configured Invoice API key.
	 *
	 * @return array|WP_Error {
	 *     @type string $name         Wallet name.
	 *     @type int    $balance_msat Balance in millisatoshis.
	 *     @type int    $balance_sat  Balance in satoshis (rounded down).
	 *     @type string $id           Wallet ID.
	 * }
	 */
	public function get_wallet_balance() {
		$settings = get_option('aitamer_settings', array());
		$url      = $settings['lnbits_url'] ?? 'https://legend.lnbits.com';
		$api_key  = $settings['lnbits_api_key'] ?? '';

		if ( empty($api_key) ) {
			return new WP_Error( 'lnbits_missing_config', __( 'LNbits API Key missing.', 'ai-tamer' ) );
		}

		$endpoint = rtrim($url, '/') . '/api/v1/wallet';

		$response = wp_remote_get( $endpoint, array(
			'headers' => array(
				'X-Api-Key' => $api_key,
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'lnbits_wallet_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'LNbits wallet API returned HTTP %d.', 'ai-tamer' ), (int) $code )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty($data) || ! isset($data['balance']) ) {
			return new WP_Error( 'lnbits_wallet_parse_error', __( 'Could not parse LNbits wallet response.', 'ai-tamer' ) );
		}

		$balance_msat = (int) $data['balance'];

		return array(
			'name'         => sanitize_text_field( $data['name'] ?? '' ),
			'balance_msat' => $balance_msat,
			'balance_sat'  => (int) floor( $balance_msat / 1000 ),
			'id'           => sanitize_text_field( $data['id'] ?? '' ),
		);
	}
}
