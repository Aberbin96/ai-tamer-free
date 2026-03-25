<?php
/**
 * BotUpdater — fetches an updated bot list from a remote source.
 *
 * Checks for a newer version of the bots.json definition file
 * from a configurable remote URL (defaults to this plugin's GitHub repo).
 * Runs daily via WP-Cron and updates the local data/bots.json.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function add_action;
use function get_option;
use function update_option;
use function delete_transient;
use function wp_remote_get;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function is_wp_error;
use function sanitize_text_field;
use function json_decode;
use function json_encode;
use function time;

defined( 'ABSPATH' ) || exit;

/**
 * BotUpdater class.
 */
class BotUpdater {

	/**
	 * Default remote URL for the bot list.
	 * Points to the raw bots.json in the public GitHub repo.
	 */
	const DEFAULT_REMOTE_URL = 'https://raw.githubusercontent.com/Aberbin96/ai-tamer/main/data/bots.json';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'aitamer_update_bots', array( $this, 'fetch_and_update' ) );
	}

	/**
	 * Fetches the remote bot list and saves it locally.
	 * Called by WP-Cron.
	 */
	public function fetch_and_update(): void {
		$settings = get_option( 'aitamer_settings' );
		if ( empty( $settings['auto_update_bots'] ) ) {
			return;
		}

		$url      = self::DEFAULT_REMOTE_URL;
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return; // Silently skip on network error.
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data['bots'] ?? null ) ) {
			return; // Invalid format, skip.
		}

		// Write the new list to the local data file.
		$file = AITAMER_PLUGIN_DIR . 'data/bots.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $file, $body );

		// Update last-synced timestamp.
		update_option( 'aitamer_bots_last_updated', time() );
	}

	/**
	 * Returns the Unix timestamp of last bot list update, or false if never.
	 *
	 * @return int|false
	 */
	public static function last_updated() {
		return get_option( 'aitamer_bots_last_updated', false );
	}
}
