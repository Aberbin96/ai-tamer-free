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

		// Protected: clean structured content for a given post.
		register_rest_route(
			self::NAMESPACE,
			'/content/(?P<id>[a-zA-Z0-9\-_]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'handle_content'),
				'permission_callback' => array($this, 'check_token'),
				'args'                => array(
					'id' => array(
						'description'       => __('Post ID or slug.', 'ai-tamer'),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Stripe: Webhook handler for automated payments (Pro).
		register_rest_route(
			self::NAMESPACE,
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'handle_stripe_webhook'),
				'permission_callback' => '__return_true', // Stripe verifies sig internally or via plugin secret.
			)
		);

		// Discovery: Catalogo para RAG / Agentes MCP (Pro).
		register_rest_route(
			self::NAMESPACE,
			'/catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'handle_catalog'),
				'permission_callback' => '__return_true', // El catalogo es publico (muestra solo meta).
			)
		);
	}

	/**
	 * Validates the X-AI-License-Token header before granting content access.
	 *
	 * @return true|WP_Error
	 */
	public function check_token(): bool|WP_Error
	{
		if (LicenseVerifier::has_valid_token()) {
			return true;
		}
		return new WP_Error(
			'aitamer_unauthorized',
			'A valid X-AI-License-Token is required to access this endpoint. Visit ' . home_url('/wp-json/ai-tamer/v1/license') . ' to view usage terms.',
			array('status' => 401)
		);
	}

	/**
	 * GET /ai-tamer/v1/license
	 *
	 * Returns a JSON-LD document describing the site's AI content license terms.
	 * This endpoint is public — any agent can discover the terms without auth.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_license(): WP_REST_Response
	{
		$settings = wp_parse_args(
			get_option('aitamer_settings', array()),
			array('license_type' => 'all-rights-reserved')
		);

		$directive = $settings['license_type'] ?? 'all-rights-reserved';

		$license_url = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
		$notice      = 'AI training and scraping prohibited. You may request a commercial license token via the contact information below.';

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
				'email' => get_bloginfo('admin_email'),
			),
			'license'           => $license_url,
			'copyrightNotice'   => $notice,
			// How to request access.
			'accessRequirements' => array(
				'description' => 'To access structured content programmatically, present a valid X-AI-License-Token HTTP header issued by the site administrator.',
				'tokenEndpoint' => home_url('/wp-json/ai-tamer/v1/content/{post_id}'),
				'contactEmail'  => get_bloginfo('admin_email'),
			),
		);

		$response = new WP_REST_Response($body, 200);
		$response->header('Content-Type', 'application/ld+json; charset=utf-8');
		$response->header('Cache-Control', 'public, max-age=3600');

		return $response;
	}

	/**
	 * GET /ai-tamer/v1/content/{id}
	 *
	 * Returns sanitized post content as structured JSON for licensed AI agents.
	 * Requires a valid X-AI-License-Token header.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_content(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$identifier = $request->get_param('id');
		$post       = null;

		// Try by ID first if it's purely numeric.
		if (is_numeric($identifier)) {
			$post = get_post((int) $identifier);
		}

		// If not found by ID (or not numeric), try by slug.
		if (! $post) {
			$posts = get_posts(array(
				'name'           => $identifier,
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			));
			if (! empty($posts)) {
				$post = $posts[0];
			}
		}

		if (! $post || 'publish' !== $post->post_status) {
			return new WP_Error(
				'aitamer_not_found',
				'Post not found or not publicly available.',
				array('status' => 404)
			);
		}

		// Respect the per-post protection setting.
		$protection = MetaBox::get_setting((int) $post->ID);
		if ('block_all' === $protection) {
			return new WP_Error(
				'aitamer_forbidden',
				'The author has restricted AI access to this content.',
				array('status' => 403)
			);
		}

		// Dynamic media filtering based on granular settings.
		$block_images = get_post_meta((int) $post->ID, '_aitamer_block_images', true) === 'yes';
		$block_video  = get_post_meta((int) $post->ID, '_aitamer_block_video', true) === 'yes';
		$block_text   = get_post_meta((int) $post->ID, '_aitamer_block_text', true) === 'yes';

		// Internal Cache Check.
		$cache_key = 'aitamer_content_' . (int) $post->ID . '_' . ($block_images ? '1' : '0') . ($block_video ? '1' : '0') . ($block_text ? '1' : '0');
		$cached    = wp_cache_get($cache_key, 'ai-tamer');

		if (false !== $cached) {
			$content = $cached;
		} else {

		/**
		 * Advanced Gutenberg Block Filter.
		 * Temporarily hooks into render_block to suppress entire blocks if they contain restricted media.
		 */
		$filter_blocks = function($block_content, $block) use ($block_images, $block_video) {
			$block_name = $block['blockName'] ?? '';
			
			// If images are blocked, suppress only blocks that are primarily or exclusively images.
			if ($block_images) {
				$pure_image_blocks = array(
					'core/image',
					'core/gallery',
					'core/cover',
				);
				
				if (in_array($block_name, $pure_image_blocks, true)) {
					return '';
				}
			}

			// If video is blocked, suppress only core video and embed blocks.
			if ($block_video) {
				$pure_video_blocks = array(
					'core/video',
					'core/embed',
				);
				
				// Handle both generic core/embed and specific core-embed/youtube etc.
				if (in_array($block_name, $pure_video_blocks, true) || 0 === strpos($block_name, 'core-embed/')) {
					return '';
				}
			}

			// For any other block (including custom ones), we return the content.
			// Restricted tags (like <img>) will be stripped later by wp_kses or the extraction logic.
			return $block_content;
		};

		// Apply the filter only during this block rendering process.
		add_filter('render_block', $filter_blocks, 10, 2);
		$rendered_content = do_blocks($post->post_content);
		$rendered_content = do_shortcode($rendered_content);
		remove_filter('render_block', $filter_blocks);

		if ($block_text) {
			// If text is blocked, we only want to serve the allowed media.
			$media_tags = array();
			
			// Match all allowed media tags that haven't been stripped yet.
			$tags_to_extract = array();
			if (!$block_images) {
				$tags_to_extract[] = 'img';
				$tags_to_extract[] = 'figure';
			}
			if (!$block_video) {
				$tags_to_extract[] = 'video';
				$tags_to_extract[] = 'iframe';
				$tags_to_extract[] = 'embed';
				$tags_to_extract[] = 'object';
			}

			if (!empty($tags_to_extract)) {
				// Pattern to match full tags including inner content (for video/iframe/figure) or self-closing tags (img).
				$pattern = '/<(' . implode('|', $tags_to_extract) . ')[^>]*>.*?<\/\1>|<(' . implode('|', $tags_to_extract) . ')[^>]*\/>|<(' . implode('|', $tags_to_extract) . ')[^>]*>/is';
				if (preg_match_all($pattern, $rendered_content, $matches)) {
					$media_tags = $matches[0];
				}
			}

			$content = __('[Text content restricted by author]', 'ai-tamer') . "\n\n" . implode("\n", $media_tags);
		} else {
			// Define allowed tags for "clean" but complete-as-authorized content.
			$allowed_tags = array(
				'p'          => array('class' => array()),
				'br'         => array(),
				'strong'     => array(),
				'em'         => array(),
				'ul'         => array('class' => array()),
				'ol'         => array('class' => array()),
				'li'         => array('class' => array()),
				'blockquote' => array('class' => array()),
				'div'        => array('class' => array(), 'id' => array()),
				'span'       => array('class' => array()),
				'h1'         => array('class' => array()),
				'h2'         => array('class' => array()),
				'h3'         => array('class' => array()),
				'h4'         => array('class' => array()),
				'h5'         => array('class' => array()),
				'h6'         => array('class' => array()),
			);

			if (!$block_images) {
				$allowed_tags['img'] = array(
					'src'    => array(),
					'alt'    => array(),
					'title'  => array(),
					'width'  => array(),
					'height' => array(),
					'class'  => array(),
				);
				$allowed_tags['figure'] = array('class' => array());
				$allowed_tags['figcaption'] = array();
			}

			if (!$block_video) {
				$allowed_tags['video'] = array(
					'src'      => array(),
					'poster'   => array(),
					'controls' => array(),
					'width'    => array(),
					'height'   => array(),
				);
				$allowed_tags['iframe'] = array(
					'src'             => array(),
					'width'           => array(),
					'height'          => array(),
					'frameborder'     => array(),
					'allowfullscreen' => array(),
					'title'           => array(),
				);
				$allowed_tags['embed'] = array('src' => array(), 'type' => array(), 'width' => array(), 'height' => array());
			}

			// Process with the whitelist.
			$content = wp_kses($rendered_content, $allowed_tags);

			// Normalise excessive whitespace.
			$content = preg_replace('/\n{3,}/', "\n\n", trim($content));
		}

		// Save to cache for 1 hour.
		wp_cache_set($cache_key, $content, 'ai-tamer', 3600);
	}

		$author_name = get_the_author_meta('display_name', (int) $post->post_author);

		$body = array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'url'       => get_permalink($post),
			'author'    => $author_name,
			'published' => gmdate('c', strtotime($post->post_date_gmt)),
			'modified'  => gmdate('c', strtotime($post->post_modified_gmt)),
			'excerpt'   => wp_strip_all_tags($post->post_excerpt),
			'content'   => $content,
			'license'   => $protection ?: 'all-rights-reserved',
		);

		$response = new WP_REST_Response($body, 200);
		$response->header('Vary', 'Authorization, X-AI-License-Token');
		$response->header('Cache-Control', 'private, max-age=60');

		return $response;
	}

	/**
	 * POST /ai-tamer/v1/stripe/webhook
	 *
	 * Handles incoming Stripe notifications.
	 *
	 * @param WP_REST_Request $request The REST request object.
	 * @return WP_REST_Response
	 */
	public function handle_stripe_webhook(WP_REST_Request $request): WP_REST_Response
	{
		$payload = $request->get_body();
		$sig     = $request->get_header('Stripe-Signature') ?: '';

		// Delegate to StripeManager.
		$plugin = $GLOBALS['ai_tamer'] ?? null;
		if ($plugin && isset($plugin->stripe_manager)) {
			$plugin->stripe_manager->handle_webhook($payload, $sig);
		}

		return new WP_REST_Response(array('received' => true), 200);
	}

	/**
	 * GET /ai-tamer/v1/catalog
	 *
	 * Returns a list of recently published posts to help agents discover content.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_catalog(): WP_REST_Response
	{
		$posts_data = get_posts(array(
			'posts_per_page' => 50,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		));

		$catalog = array();

		foreach ($posts_data as $post) {
			$protection = MetaBox::get_setting((int) $post->ID);
			$catalog[] = array(
				'id'         => $post->ID,
				'slug'       => $post->post_name ?? '',
				'title'      => $post->post_title,
				'excerpt'    => wp_strip_all_tags($post->post_excerpt),
				'published'  => gmdate('c', strtotime($post->post_date_gmt)),
				'protection' => $protection ?: 'all-rights-reserved',
				'token_required' => ('allow_all' !== $protection),
				'full_content_url' => home_url('/wp-json/ai-tamer/v1/content/' . $post->ID),
			);
		}

		$body = array(
			'count'   => count($catalog),
			'items'   => $catalog,
			'license' => home_url('/wp-json/ai-tamer/v1/license'),
		);

		return new WP_REST_Response($body, 200);
	}
}
