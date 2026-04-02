<?php

/**
 * Protector — handles header injection, meta tags, and robots.txt.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_option;
use function wp_parse_args;
use function sanitize_text_field;
use function home_url;
use function esc_attr;
use function esc_url;
use function absint;
use function get_bloginfo;
use function get_the_title;
use function current_time;

use AiTamer\Traits\MarkdownConverter;

defined('ABSPATH') || exit;

/**
 * Protector class.
 */
class Protector
{
	use MarkdownConverter;

	/** @var Detector */
	private Detector $detector;

	/**
	 * @param Detector $detector
	 */
	public function __construct(Detector $detector)
	{
		$this->detector = $detector;
	}

	/**
	 * Retrieves plugin settings with defaults merged in.
	 *
	 * @return array
	 */
	private function get_settings(): array
	{
		$defaults = array(
			'block_training_bots'  => true,
			'inject_meta_tags'     => true,
			'inject_http_headers'  => true,
			'crawl_delay_enabled'  => false,
			'crawl_delay'          => 10,
			'license_policy'       => 'no-training',
			'active_defense'       => 'block',
			'enable_llms_txt'      => true,
			'protected_post_types' => array('post'),
		);
		$saved = get_option('aitamer_settings', array());
		return wp_parse_args($saved, $defaults);
	}

	/**
	 * Handles Active Defense (Blocking/402) on the frontend.
	 * Hooked on 'template_redirect'.
	 */
	public function handle_active_defense(): void
	{
		$settings = $this->get_settings();
		$protected_types = $settings['protected_post_types'] ?? array('post');

		if (! is_singular($protected_types)) {
			return;
		}

		$defense  = $settings['active_defense'] ?? Enums\DefenseStrategy::BLOCK->value;

		// Only handle BLOCK and PAYMENT here.
		if (! in_array($defense, array(Enums\DefenseStrategy::BLOCK->value, Enums\DefenseStrategy::PAYMENT->value), true)) {
			return;
		}

		$agent = $this->detector->classify();
		if (! $agent['matched'] || ! $this->detector->is_training_agent()) {
			return;
		}

		$post_id = (int) get_the_ID();
		$required_scope = 'post:' . $post_id;


		// Skip if bot has a valid standard license token.
		if (LicenseVerifier::has_valid_token($required_scope)) {
			// Deduction logic for Reading Vouchers (V2).
			$payload = LicenseVerifier::get_last_payload();
			if (! empty($payload['vch']) && ! empty($payload['uid'])) {
				LicenseVerifier::deduct_credit($payload['uid']);
			}
			return;
		}

		// Handle PAYMENT strategy (402).
		if (Enums\DefenseStrategy::PAYMENT->value === $defense) {
			global $wpdb;
			$bot_ip      = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			$tolls_table = $wpdb->prefix . 'aitamer_tolls';

			// 1. Check for X-Payment-Hash header.
			$tx_hash = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAYMENT_HASH'] ?? '' ) );

			// Define the unique amount for this post.
			$usdt_verifier = new USDTVerifier();
			$base_price    = (float) ($settings['usdt_price_usd'] ?? 0.10);
			$unique_amount = $usdt_verifier->get_unique_amount($base_price, $post_id);
			$recipient     = $settings['usdt_address'] ?? '';
			$network       = $settings['usdt_network'] ?? 'polygon';

			if (! empty($tx_hash) && ! empty($recipient)) {
				// Check if already verified in DB.
				$paid = $wpdb->get_var($wpdb->prepare(
					"SELECT id FROM %i WHERE transaction_hash = %s AND status = 'paid' LIMIT 1",
					$tolls_table,
					sanitize_text_field($tx_hash)
				));

				if (! $paid) {
					$is_verified = $usdt_verifier->verify($tx_hash, $unique_amount, $recipient, $network);
					if (true === $is_verified) {
						$wpdb->insert($tolls_table, array(
							'transaction_hash' => sanitize_text_field($tx_hash),
							'amount_usdt'      => $unique_amount,
							'network'          => $network,
							'post_id'          => $post_id,
							'bot_ip'           => $bot_ip,
							'status'           => 'paid',
							'created_at'       => current_time('mysql'),
						));
						$paid = true;
					}
				}

				if ($paid) {
					// Serve clean structured JSON Markdown to verified bots.
					if ($agent['matched']) {
						$this->serve_paid_content_json($post_id);
					}
					return;
				}
			}

			// 2. Challenge: 402 Payment Required.
			header('HTTP/1.1 402 Payment Required');
			header('W3C-Payment-Method: USDT');
			header('WWW-Authenticate: USDT address="' . esc_attr($recipient) . '", amount="' . esc_attr((string) $unique_amount) . '", network="' . esc_attr($network) . '"');
			header('Access-Control-Expose-Headers: WWW-Authenticate, W3C-Payment-Method');

			$error_data = array(
				'status'  => 402,
				'message' => esc_html__('Payment Required to access this content.', 'ai-tamer'),
				'payment' => array(
					'method'      => 'USDT',
					'address'     => $recipient,
					'amount'      => $unique_amount,
					'network'     => $network,
					'instruction' => esc_html__('Send the exact amount of USDT to the address above, then retry with the "X-Payment-Hash" header.', 'ai-tamer'),
				),
			);

			wp_die( wp_json_encode($error_data), esc_html__('Payment Required', 'ai-tamer'), array('response' => 402));
		}

		// Fallback to BLOCK strategy (401).
		wp_die(
			esc_html__('No valid license token found for this content. Use the header X-AI-License-Token: <token>. This content is protected against AI training agents.', 'ai-tamer'),
			esc_html__('Unauthorized', 'ai-tamer'),
			array('response' => 401)
		);
	}

	/**
	 * Serves the paid content as a clean JSON object with Markdown.
	 *
	 * @param int $post_id The post ID to serve.
	 */
	private function serve_paid_content_json(int $post_id): void
	{
		$post = get_post($post_id);
		if (! $post) {
			wp_send_json_error('Post not found', 404);
		}

		// Process the content (expand shortcodes, etc.)
		$content = apply_filters('the_content', $post->post_content);

		// Convert to Markdown
		$markdown = $this->html_to_markdown($content);

		$response = array(
			'id'           => $post_id,
			'title'        => get_the_title($post_id),
			'content'      => $markdown,
			'word_count'   => $this->get_word_count($markdown),
			'reading_time' => $this->get_reading_time($markdown),
			'url'          => get_permalink($post_id),
			'license'      => get_option('aitamer_settings')['license_policy'] ?? 'no-training',
			'format'       => 'markdown',
			'generated_at' => current_time('mysql'),
		);

		wp_send_json($response);
	}

	/**
	 * Injects X-Robots-Tag and other AI-control HTTP headers.
	 * Hooked on 'wp_headers'.
	 *
	 * @param array $headers Existing headers.
	 * @return array Modified headers.
	 */
	public function inject_headers(array $headers): array
	{
		$settings = $this->get_settings();

		if (! $settings['inject_http_headers']) {
			return $headers;
		}

		// Standard emerging directives for AI crawlers.
		$headers['X-Robots-Tag'] = 'noai, noimageai';
		$headers['AI-License']   = 'no-training; no-storage';

		return $headers;
	}

	/**
	 * Outputs <meta> protection tags in <head>.
	 * Hooked on 'wp_head'.
	 */
	public function inject_meta_tags(): void
	{
		$settings = $this->get_settings();

		if (! $settings['inject_meta_tags']) {
			return;
		}

		$policy = sanitize_text_field($settings['license_policy'] ?? 'no-training');

		// AI Tamer Protection
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<meta name="robots" content="noai, noimageai">' . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<meta name="ai-license" content="' . esc_attr($policy) . '; source=' . esc_url(home_url()) . '">' . "\n";
	}

	/**
	 * Appends bot-specific Disallow rules to the virtual robots.txt.
	 * Hooked on 'robots_txt'.
	 *
	 * @param string $output  Existing robots.txt content.
	 * @param bool   $public  Whether the site is public.
	 * @return string Modified robots.txt.
	 */
	public function append_robots_txt(string $output, bool $public): string
	{
		$settings = $this->get_settings();

		if (! $settings['block_training_bots']) {
			return $output;
		}

		$bots  = $this->detector->get_bots();
		$block = "\n# --- AI Tamer: Block AI Training & Scraper Bots ---\n";

		$has_bots = false;
		foreach ($bots as $bot) {
			// Only Disallow training and scraper bots. 
			// 'aeo' and 'search' bots are allowed for discovery.
			if (! in_array($bot['type'] ?? '', array('training', 'scraper'), true)) {
				continue;
			}
			$ua     = sanitize_text_field($bot['user_agent'] ?? '');
			$block .= "User-agent: {$ua}\n";
			if (! empty($settings['crawl_delay_enabled'])) {
				$delay  = absint($settings['crawl_delay'] ?? 10) ?: 10;
				$block .= "Crawl-delay: {$delay}\n";
			}
			$block .= "Disallow: /\n\n";
			$has_bots = true;
		}

		if (! $has_bots) {
			return $output;
		}

		$block .= "# --- End AI Tamer Rules ---\n";

		return $output . $block;
	}

	/**
	 * Outputs the dynamic llms.txt content.
	 * Hooked on 'template_redirect'.
	 */
	public function handle_llms_txt(): void
	{
		$settings = $this->get_settings();
		if (empty($settings['enable_llms_txt'])) {
			return;
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( false === strpos( (string) $request_uri, '/llms.txt' ) ) {
			return;
		}

		header('Content-Type: text/plain; charset=utf-8');

		echo "# llms.txt - AI Tamer Protection for " . esc_html(get_bloginfo('name')) . "\n";
		echo "Description: " . esc_html(get_bloginfo('description')) . "\n\n";

		echo "## Machine-Readable Endpoints\n";
		echo "- AI License & Terms: " . esc_url(home_url('/wp-json/ai-tamer/v1/license')) . "\n";
		echo "- Content Catalog (RAG/MCP): " . esc_url(home_url('/wp-json/ai-tamer/v1/catalog')) . " (?post_type=xxx&page=1)\n\n";

		echo "## Instructions for AI Agents\n";
		echo "1. This site uses AI Tamer to protect its content.\n";
		echo "2. To access full-text content via the REST API, you may need a valid 'X-AI-License-Token' header.\n";
		echo "3. Visit the License endpoint above to discover how to obtain a token or purchase access.\n\n";

		echo "## Protection Status\n";
		echo "Active Defense: " . esc_html($settings['active_defense'] ?? 'block') . "\n";
		echo "License Policy: " . esc_html($settings['license_policy'] ?? 'no-training') . "\n";

		exit;
	}
}
