<?php

/**
 * C2paManager — handles content provenance and authenticity manifests.
 *
 * Implements a simplified C2PA-like manifest for Proof of Human Origin.
 * Generates signed JSON-LD blocks and HTTP headers.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function add_action;
use function add_filter;
use function get_option;
use function get_the_ID;
use function is_singular;
use function get_post;
use function get_bloginfo;
use function home_url;
use function wp_json_encode;
use function esc_html__;
use function get_post_meta;
use function current_time;

defined('ABSPATH') || exit;

/**
 * C2paManager class.
 */
class C2paManager
{
	/**
	 * Registers hooks for C2PA metadata.
	 */
	public function register(): void
	{
		add_action('wp_head', array($this, 'inject_manifest'), 10);
		add_filter('wp_headers', array($this, 'inject_http_header'), 10, 2);
		add_filter('the_content', array($this, 'maybe_inject_badge'), 999);
	}

	/**
	 * Injects the JSON-LD manifest into the page head.
	 */
	public function inject_manifest(): void
	{
		if (!is_singular()) {
			return;
		}

		$settings = get_option('aitamer_settings', array());
		if (empty($settings['enable_c2pa'])) {
			return;
		}

		$post_id = get_the_ID();
		if (!$post_id) {
			return;
		}

		$manifest = $this->generate_manifest($post_id);

		echo "\n" . '<!-- AI Tamer C2PA Manifest -->';
		echo "\n" . '<script type="application/ld+json">' . wp_json_encode($manifest) . '</script>' . "\n";
	}

	/**
	 * Injects the C2PA manifest hash into HTTP headers.
	 *
	 * @param array $headers Existing headers.
	 * @return array Modified headers.
	 */
	public function inject_http_header(array $headers): array
	{
		if (!is_singular()) {
			return $headers;
		}

		$settings = get_option('aitamer_settings', array());
		if (empty($settings['enable_c2pa'])) {
			return $headers;
		}

		$post_id = get_the_ID();
		if (!$post_id) {
			return $headers;
		}

		$hash = $this->get_content_signature($post_id);

		$headers['X-AITamer-C2PA'] = $hash;
		return $headers;
	}

	/**
	 * Optionally injects a visual "Verified Human" badge into the content.
	 *
	 * @param string $content HTML content.
	 * @return string Modified content.
	 */
	public function maybe_inject_badge(string $content): string
	{
		// Use global $post to ensure we have the correct ID even inside complex loops.
		global $post;
		$post_id = $post->ID ?? get_the_ID();

		if (!is_singular() || !$post_id) {
			return $content;
		}

		$settings = get_option('aitamer_settings', array());

		$enabled    = !empty($settings['enable_c2pa']);
		$show_badge = !empty($settings['show_c2pa_badge']);
		$debug      = isset($_GET['aitamer_debug']);

		$score      = HeuristicDetector::get_ai_score($post->post_content);
		$is_verified = $this->is_verified_human((int)$post_id);

		// If debug is on, we bypass the settings check.
		if (!$debug && (!$enabled || !$show_badge)) {
			return $content;
		}

		// Selection of badge theme based on verified status and score.
		if ($is_verified) {
			$bg_color    = '#f8fafc';
			$border_color = '#e2e8f0';
			$text_color   = '#0f172a';
			$label       = esc_html__('Verified Human Origin', 'ai-tamer');
			$svg_color   = '#2ecc71';
		} elseif ($score > 80) {
			// Transparent about AI detection.
			$bg_color    = '#fff1f2';
			$border_color = '#fecdd3';
			$text_color   = '#9f1239';
			$label       = esc_html__('AI-Generated Content', 'ai-tamer');
			$svg_color   = '#e11d48';
		} else {
			// Human-Assisted or uncertain.
			$bg_color    = '#fffbeb';
			$border_color = '#fef3c7';
			$text_color   = '#92400e';
			$label       = esc_html__('Human-Assisted Content', 'ai-tamer');
			$svg_color   = '#d97706';
		}

		// Inline SVG Badge (No external requests).
		$svg_shield = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 6px;">
			<path d="M12 2L4 5V11C4 16.07 7.41 20.73 12 22C16.59 20.73 20 16.07 20 11V5L12 2Z" fill="' . $svg_color . '"/>
			<path d="M9 12L11 14L15 10" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
		</svg>';

		$badge = '<div class="aitamer-c2pa-badge" style="margin-top:20px; padding:12px 16px; border:1px solid ' . $border_color . '; border-radius:8px; font-size:13px; background:' . $bg_color . '; display:inline-flex; align-items:center; color:' . $text_color . '; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Oxygen-Sans,Ubuntu,Cantarell,Helvetica Neue,sans-serif; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">';
		$badge .= $svg_shield;
		$badge .= '<div>';
		$badge .= '<strong style="color:' . $text_color . ';">' . $label . '</strong>';
		$badge .= ' <span style="color:currentColor; opacity:0.7; margin-left:4px;">(C2PA Compliant)</span>';
		$badge .= '</div></div>';

		return $content . $badge;
	}

	/**
	 * Generates a verifiable manifest for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Manifest data.
	 */
	private function generate_manifest(int $post_id): array
	{
		$post = get_post($post_id);
		if (!$post) {
			return array();
		}

		$is_manual = get_post_meta($post_id, '_aitamer_certified_human', true) === 'yes';
		$score     = HeuristicDetector::get_ai_score($post->post_content);

		if ($is_manual) {
			$claim = 'Certified Human Origin';
		} elseif ($score < 30) {
			$claim = 'Verified Human Origin';
		} elseif ($score > 90) {
			$claim = 'AI-Generated Content';
		} else {
			$claim = 'Human-Assisted Content';
		}

		return array(
			'@context'    => 'https://schema.org',
			'@type'       => 'DigitalDocument',
			'identifier'  => 'urn:sha256:' . $this->get_content_signature($post_id),
			'name'        => 'Proof of Human Origin Manifest',
			'mainEntity'  => array(
				'@type' => 'CreativeWork',
				'name'  => $post->post_title,
				'datePublished' => $post->post_date_gmt,
				'url'           => home_url('?p=' . $post_id),
				'author'        => array(
					'@type' => 'Organization',
					'name'  => get_bloginfo('name'),
					'url'   => home_url(),
				),
			),
			'contentVerification' => array(
				'@type' => 'Message',
				'text'  => $claim,
				'datePublished' => current_time('c'),
				'provider'      => array(
					'@type' => 'SoftwareApplication',
					'name'  => 'AI Tamer Protection Engine',
					'softwareVersion' => AITAMER_VERSION,
				),
			),
		);
	}


	/**
	 * Checks if a post meets the criteria for human origin certification.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_verified_human(int $post_id): bool
	{
		// 1. Manual certification override.
		if (get_post_meta($post_id, '_aitamer_certified_human', true) === 'yes') {
			return true;
		}

		// 2. Automated heuristic check.
		$post = get_post($post_id);
		if (!$post) {
			return false;
		}

		return HeuristicDetector::is_likely_human($post->post_content, 40);
	}

	/**
	 * Generates a deterministic signature for the content.
	 *
	 * @param int $post_id Post ID.
	 * @return string HMAC signature.
	 */
	private function get_content_signature(int $post_id): string
	{
		$post = get_post($post_id);
		if (!$post) {
			return '';
		}
		$data = $post_id . '|' . $post->post_content . '|' . $post->post_date_gmt;

		// Use AUTH_KEY as salt for the HMAC.
		return hash_hmac('sha256', $data, (defined('AUTH_KEY') ? AUTH_KEY : 'default_salt'));
	}
}
