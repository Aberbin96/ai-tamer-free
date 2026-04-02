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

defined('ABSPATH') || exit;

/**
 * Protector class.
 */
class Protector
{

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
			$payment_url = null;
			$stripe = Plugin::get_instance()->get_stripe_manager();
			if ($stripe && current_user_can('read') === false) { // Don't charge humans reading
				$payment_url = $stripe->create_checkout_session($agent['name'] . ' (Frontend)', $post_id);
				if ($payment_url) {
					header('X-Payment-Link: ' . $payment_url);
				}
			}

			if ($payment_url) {
				$msg = __('Payment Required to access this content.', 'ai-tamer');
				$msg .= ' ' . __('For Fiat, purchase a license via the X-Payment-Link header.', 'ai-tamer');

				wp_die(
					$msg,
					__('Payment Required', 'ai-tamer'),
					array('response' => 402)
				);
			}
		}

		// Fallback to BLOCK strategy (401).
		wp_die(
			__('No valid license token found for this content. Use the header X-AI-License-Token: <token>. This content is protected against AI training agents.', 'ai-tamer'),
			__('Unauthorized', 'ai-tamer'),
			array('response' => 401)
		);
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
?>
		<!-- AI Tamer Protection -->
		<meta name="robots" content="noai, noimageai">
		<meta name="ai-license" content="<?php echo esc_attr($policy); ?>; source=<?php echo esc_url(home_url()); ?>">
<?php
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

		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		if (false === strpos($request_uri, '/llms.txt')) {
			return;
		}

		header('Content-Type: text/plain; charset=utf-8');

		echo "# llms.txt - AI Tamer Protection for " . get_bloginfo('name') . "\n";
		echo "Description: " . get_bloginfo('description') . "\n\n";

		echo "## Machine-Readable Endpoints\n";
		echo "- AI License & Terms: " . home_url('/wp-json/ai-tamer/v1/license') . "\n";
		echo "- Content Catalog (RAG/MCP): " . home_url('/wp-json/ai-tamer/v1/catalog') . " (?post_type=xxx&page=1)\n\n";

		echo "## Instructions for AI Agents\n";
		echo "1. This site uses AI Tamer to protect its content.\n";
		echo "2. To access full-text content via the REST API, you may need a valid 'X-AI-License-Token' header.\n";
		echo "3. Visit the License endpoint above to discover how to obtain a token or purchase access.\n\n";

		echo "## Protection Status\n";
		echo "Active Defense: " . ($settings['active_defense'] ?? 'block') . "\n";
		echo "License Policy: " . ($settings['license_policy'] ?? 'no-training') . "\n";

		exit;
	}
}
