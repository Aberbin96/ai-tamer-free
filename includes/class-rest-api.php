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
}
