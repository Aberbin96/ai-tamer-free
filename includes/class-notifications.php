<?php
/**
 * Notifications — flexible alerting system via Email, Slack, and Discord.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_option;
use function wp_mail;
use function wp_remote_post;
use function wp_json_encode;
use function get_bloginfo;
use function admin_url;
use function home_url;
use function esc_url_raw;
use function current_time;
use function time;
use function date;
use function hexdec;
use function ltrim;
use function sprintf;
use function in_array;
use function ucwords;
use function str_replace;

defined( 'ABSPATH' ) || exit;

/**
 * Notifications class.
 */
class Notifications {

	/**
	 * Available notification events.
	 */
	const EVENT_HIGH_INTENSITY = 'high_intensity';
	const EVENT_PAYMENT        = 'payment_received';
	const EVENT_SECURITY       = 'security_alert';
	const EVENT_NEW_BOT       = 'new_bot';

	/**
	 * Registers the notification handler.
	 */
	public function register(): void {
		add_action( 'aitamer_notification', array( $this, 'dispatch' ), 10, 2 );
	}

	/**
	 * Dispatches a notification for a specific event.
	 *
	 * @param string $event Event type (slug).
	 * @param array  $data  Event-specific data (e.g. bot name, amount, ip).
	 */
	public function dispatch( string $event, array $data = array() ): void {
		$settings = get_option( 'aitamer_settings', array() );
		
		// 1. Check if notifications are enabled globally.
		if ( empty( $settings['notifications_enabled'] ) ) {
			return;
		}

		// 2. Check if this specific event is enabled.
		$enabled_events = $settings['notification_events'] ?? array();
		if ( ! in_array( $event, (array) $enabled_events, true ) ) {
			return;
		}

		// 3. Dispatch to enabled channels.
		$channels = $settings['notification_channels'] ?? array( 'email' );

		foreach ( (array) $channels as $channel ) {
			switch ( $channel ) {
				case 'email':
					$this->send_email( $event, $data );
					break;
				case 'slack':
					$this->send_slack( $event, $data );
					break;
				case 'discord':
					$this->send_discord( $event, $data );
					break;
			}
		}
	}

	/**
	 * Sends an email notification.
	 */
	private function send_email( string $event, array $data ): void {
		$to      = get_option( 'admin_email' );
		$subject = sprintf( '[%s] AI Tamer Alert: %s', get_bloginfo( 'name' ), $this->get_event_label( $event ) );
		$message = $this->get_plain_text_message( $event, $data );

		wp_mail( $to, $subject, $message );
	}

	/**
	 * Sends a Slack notification via Webhook.
	 */
	private function send_slack( string $event, array $data ): void {
		$settings = get_option( 'aitamer_settings', array() );
		$url      = $settings['slack_webhook_url'] ?? '';

		if ( empty( $url ) ) {
			return;
		}

		$payload = array(
			'text'        => sprintf( '*AI Tamer Alert: %s*', $this->get_event_label( $event ) ),
			'attachments' => array(
				array(
					'color'  => $this->get_event_color( $event ),
					'fields' => $this->get_kv_fields( $event, $data ),
					'footer' => get_bloginfo( 'name' ),
					'ts'     => time(),
				),
			),
		);

		wp_remote_post(
			esc_url_raw( $url ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Sends a Discord notification via Webhook.
	 */
	private function send_discord( string $event, array $data ): void {
		$settings = get_option( 'aitamer_settings', array() );
		$url      = $settings['discord_webhook_url'] ?? '';

		if ( empty( $url ) ) {
			return;
		}

		$payload = array(
			'embeds' => array(
				array(
					'title'       => 'AI Tamer Alert: ' . $this->get_event_label( $event ),
					'description' => $this->get_plain_text_message( $event, $data ),
					'color'       => hexdec( ltrim( $this->get_event_color( $event ), '#' ) ),
					'footer'      => array( 'text' => get_bloginfo( 'name' ) ),
					'timestamp'   => date( 'c' ),
				),
			),
		);

		wp_remote_post(
			esc_url_raw( $url ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Returns a human-readable label for the event.
	 */
	private function get_event_label( string $event ): string {
		return match ( $event ) {
			self::EVENT_HIGH_INTENSITY => 'High Intensity Bot Activity',
			self::EVENT_PAYMENT        => 'New License Payment Received',
			self::EVENT_SECURITY       => 'Security Threat Intercepted',
			self::EVENT_NEW_BOT        => 'New AI Bot Detected',
			default                    => 'System Alert',
		};
	}

	/**
	 * Returns a color code for the event.
	 */
	private function get_event_color( string $event ): string {
		return match ( $event ) {
			self::EVENT_HIGH_INTENSITY => '#f0ad4e', // Warning
			self::EVENT_PAYMENT        => '#5cb85c', // Success
			self::EVENT_SECURITY       => '#d9534f', // Danger
			self::EVENT_NEW_BOT        => '#5bc0de', // Info
			default                    => '#777777',
		};
	}

	/**
	 * Formats data as a plain text block.
	 */
	private function get_plain_text_message( string $event, array $data ): string {
		$output = "Event: " . $this->get_event_label( $event ) . "\n";
		$output .= "Site: " . get_bloginfo( 'name' ) . " (" . home_url() . ")\n";
		$output .= "Time: " . current_time( 'mysql' ) . "\n\n";
		
		foreach ( $data as $key => $val ) {
			$label = ucwords( str_replace( '_', ' ', $key ) );
			$output .= "{$label}: {$val}\n";
		}

		$output .= "\nManage Settings: " . admin_url( 'admin.php?page=ai-tamer-settings' );
		
		return $output;
	}

	/**
	 * Formats data as key-value pairs for Slack fields.
	 */
	private function get_kv_fields( string $event, array $data ): array {
		$fields = array();
		foreach ( $data as $key => $val ) {
			$fields[] = array(
				'title' => ucwords( str_replace( '_', ' ', $key ) ),
				'value' => (string) $val,
				'short' => true,
			);
		}
		return $fields;
	}
}
