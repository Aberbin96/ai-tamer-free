<?php

/**
 * RestApi — exposes a structured content endpoint for licensed AI agents.
 *
 * Routes (namespace: ai-tamer/v1):
 *   GET /license           — public; returns machine-readable usage terms.
 *   GET /content/{post_id} — protected; returns clean post content as JSON.
 *
 * Authentication uses the existing HMAC token system from LicenseVerifier.
 * Agents must send the token in the `X-AI-License-Token` HTTP header.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use AiTamer\Enums\DefenseStrategy;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

use function add_action;
use function get_bloginfo;
use function get_option;
use function get_permalink;
use function get_post;
use function get_post_meta;
use function get_posts;
use function get_the_author_meta;
use function home_url;
use function is_wp_error;
use function register_rest_route;
use function wp_json_encode;
use function wp_strip_all_tags;
use function wp_parse_args;
use function do_shortcode;
use function do_blocks;
use function wp_cache_get;
use function wp_cache_set;
use function wp_cache_delete;
use function get_transient;
use function set_transient;
use function __;
use function _x;
use function is_user_logged_in;
use function current_user_can;
use function sanitize_text_field;
use function wp_unslash;
use function md5;

defined('ABSPATH') || exit;

/**
 * RestApi class.
 */
class RestApi
{

	/**
	 * REST namespace for all v1 endpoints.
	 */
	const NAMESPACE = 'ai-tamer/v1';

	/** @var Detector */
	protected $detector;

	/** @var Logger */
	protected $logger;

	/**
	 * Constructor.
	 * 
	 * @param Detector $detector Dependency.
	 * @param Logger   $logger   Dependency.
	 */
	public function __construct($detector = null, $logger = null)
	{
		$this->detector = $detector;
		$this->logger   = $logger;
	}

	/**
	 * Registers the REST API routes.
	 */
	public function register(): void
	{
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	/**
	 * Defines the endpoints.
	 */
	public function register_routes(): void
	{
		// Public: machine-readable license terms.
		register_rest_route(
			self::NAMESPACE,
			'/license',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'handle_license'),
				'permission_callback' => '__return_true',
			)
		);

		// Fingerprinting: Receive and analyze client-side signals.
		register_rest_route(
			self::NAMESPACE,
			'/fingerprint',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'handle_fingerprint'),
				'permission_callback' => '__return_true', // Public endpoint used for bot detection.
				'args'                => array(
					'webdriver'  => array(
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'chrome'     => array(
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'plugins'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'mimeTypes'  => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'innerWidth' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'outerWidth' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'webgl'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET /ai-tamer/v1/license
	 */
	public function handle_license(): WP_REST_Response
	{
		$settings = wp_parse_args(
			get_option('aitamer_settings', array()),
			array('license_type' => 'all-rights-reserved')
		);

		$directive = $settings['license_type'] ?? 'all-rights-reserved';
		$license_url = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
		$notice      = 'AI training and scraping prohibited.';

		if ('permitted' === $directive) {
			$license_url = 'https://creativecommons.org/licenses/by/4.0/';
			$notice      = 'Licensed for AI use with attribution.';
		}

		$body = array(
			'@context'          => 'https://schema.org',
			'@type'             => 'DigitalDocument',
			'name'              => get_bloginfo('name') . ' — AI Content License',
			'url'               => home_url('/wp-json/ai-tamer/v1/license'),
			'publisher'         => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo('name'),
				'url'   => home_url('/'),
			),
			'license'           => $license_url,
			'copyrightNotice'   => $notice,
		);

		$response = new WP_REST_Response($body, 200);
		$response->header('Content-Type', 'application/ld+json; charset=utf-8');
		return $response;
	}

	/**
	 * POST /fingerprint
	 */
	public function handle_fingerprint(WP_REST_Request $request): WP_REST_Response
	{
		$data = array(
			'webdriver'  => (bool) $request->get_param('webdriver'),
			'chrome'     => (bool) $request->get_param('chrome'),
			'plugins'    => (int) $request->get_param('plugins'),
			'mimeTypes'  => (int) $request->get_param('mimeTypes'),
			'innerWidth' => (int) $request->get_param('innerWidth'),
			'outerWidth' => (int) $request->get_param('outerWidth'),
			'webgl'      => sanitize_text_field((string) $request->get_param('webgl')),
			'ip'         => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
		);

		// Evaluate Fingerprint heuristic score based on client signals
		$risk_score = Detector::evaluate_fingerprint($data);

		if ($risk_score > 0 && $this->detector && $this->logger) {
			$action = ($risk_score >= 50) ? 'fingerprint_blocked' : 'fingerprint_suspicious';
			
			$dummy_agent = array(
				'name'    => 'Headless Browser (Detected via FP: ' . $risk_score . ')',
				'type'    => 'bot',
				'matched' => true,
				'ip'      => $data['ip']
			);
			
			$this->logger->log($dummy_agent, $action);
		}

		if ($risk_score >= 50 && !empty($data['ip'])) {
			// Add to Limiter actively (simulated ban for the IP via transients or similar)
			set_transient('aitamer_fp_block_' . md5($data['ip']), true, 3600); // 1 hour block
		}

		return new WP_REST_Response(array('received' => true, 'score' => $risk_score), 200);
	}
}
