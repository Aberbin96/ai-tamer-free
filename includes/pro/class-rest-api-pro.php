<?php

namespace AiTamer;

use AiTamer\Enums\DefenseStrategy;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * RestApiPro - Pro endpoints for AI Tamer.
 */
class RestApiPro extends RestApi
{
	/**
	 * Registers Pro endpoints.
	 */
	public function register_routes(): void
	{
		// Parent registers base endpoints.
		parent::register_routes();

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

		// Stripe: Webhook handler for automated payments.
		register_rest_route(
			self::NAMESPACE,
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'handle_stripe_webhook'),
				'permission_callback' => '__return_true',
			)
		);

		// Discovery: Catalog for RAG / MCP Agents.
		register_rest_route(
			self::NAMESPACE,
			'/catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'handle_catalog'),
				'permission_callback' => '__return_true',
			)
		);

		// Admin: real-time AI detection for the editor.
		register_rest_route(
			self::NAMESPACE,
			'/detect',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'handle_detect'),
				'permission_callback' => function () {
					return current_user_can('edit_posts');
				},
				'args'                => array(
					'content' => array(
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
				),
			)
		);
	}

	/**
	 * Validates the X-AI-License-Token header.
	 */
	public function check_token(WP_REST_Request $request): bool|WP_Error
	{
		$agent = $this->detector ? $this->detector->classify() : array('matched' => false);
		
		$required_scope = '';
		$identifier     = $request->get_param('id');
		if ($identifier) {
			$post = null;
			if (is_numeric($identifier)) {
				$post = get_post((int) $identifier);
			} else {
				$posts = get_posts(array(
					'name'           => $identifier,
					'post_type'      => 'any',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
				));
				if (!empty($posts)) {
					$post = $posts[0];
				}
			}
			if ($post) {
				$required_scope = 'post:' . $post->ID;
			}
		}

		$is_valid = LicenseVerifier::has_valid_token($required_scope);
		$settings = get_option('aitamer_settings', array());
		$defense  = $settings['active_defense'] ?? 'block';

		if ($agent['matched'] && $this->logger) {
			$post_id = (int) $request->get_param('id');
			$protection = $is_valid ? 'api_content' : 'unauthorized';

			if (!$is_valid && DefenseStrategy::POISON->value === $defense) {
				$protection = 'unauthorized-poison';
			}

			$this->logger->log($agent, $protection, $post_id);
		}

		if ($is_valid) {
			return true;
		}

		if (!$is_valid && $agent['matched']) {
			$enable_micropayments = $settings['enable_micropayments'] ?? false;
			if ($enable_micropayments) {
				$stripe = Plugin::get_instance()->get_stripe_manager();
				$payment_url = $stripe->create_checkout_session($agent['name'] . ' (Auto)', (int) $request->get_param('id'));

				if ($payment_url) {
					header('X-Payment-Link: ' . $payment_url);
					return new WP_Error(
						'rest_payment_required',
						__('Payment Required: This content requires a valid license token. Purchase access via the payment link in headers.', 'ai-tamer'),
						array('status' => 402, 'payment_url' => $payment_url)
					);
				}
			}

			return new WP_Error(
				'rest_forbidden',
				__('Unauthorized: This content is protected by AI Tamer. Please provide a valid X-AI-License-Token.', 'ai-tamer'),
				array('status' => 401)
			);
		}

		if (DefenseStrategy::POISON->value === $defense && $agent['matched']) {
			$request->set_param('aitamer_poison', true);
			return true;
		}

		return new WP_Error(
			'aitamer_unauthorized',
			'A valid X-AI-License-Token is required to access this endpoint. Visit ' . home_url('/wp-json/ai-tamer/v1/license') . ' to view usage terms.',
			array('status' => 401)
		);
	}

	/**
	 * GET /content/{id}
	 */
	public function handle_content(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$identifier = $request->get_param('id');
		$post       = null;

		if (is_numeric($identifier)) {
			$post = get_post((int) $identifier);
		}

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
			return new WP_Error('aitamer_not_found', 'Post not found or not publicly available.', array('status' => 404));
		}

		$protection = MetaBox::get_setting((int) $post->ID);
		if ('block_all' === $protection) {
			return new WP_Error('aitamer_forbidden', 'The author has restricted AI access to this content.', array('status' => 403));
		}

		$block_images = get_post_meta((int) $post->ID, '_aitamer_block_images', true) === 'yes';
		$block_video  = get_post_meta((int) $post->ID, '_aitamer_block_video', true) === 'yes';
		$block_text   = get_post_meta((int) $post->ID, '_aitamer_block_text', true) === 'yes';

		$cache_key = 'ait_c_' . (int) $post->ID . '_' . ($block_images ? '1' : '0') . ($block_video ? '1' : '0') . ($block_text ? '1' : '0');
		$content = wp_cache_get($cache_key, 'ai-tamer');

		if (false === $content) {
			$content = get_transient($cache_key);
			if (false !== $content) {
				wp_cache_set($cache_key, $content, 'ai-tamer', 3600);
			}
		}

		if (false === $content) {
			$filter_blocks = function ($block_content, $block) use ($block_images, $block_video) {
				$block_name = $block['blockName'] ?? '';
				if ($block_images && in_array($block_name, array('core/image', 'core/gallery', 'core/cover'), true)) return '';
				if ($block_video && (in_array($block_name, array('core/video', 'core/embed'), true) || 0 === strpos($block_name, 'core-embed/'))) return '';
				return $block_content;
			};
			add_filter('render_block', $filter_blocks, 10, 2);
			$rendered_content = do_blocks($post->post_content);
			$rendered_content = do_shortcode($rendered_content);
			remove_filter('render_block', $filter_blocks);

			if ($block_text) {
				$media_tags = array();
				$tags_to_extract = array();
				if (!$block_images) { $tags_to_extract[] = 'img'; $tags_to_extract[] = 'figure'; }
				if (!$block_video) { $tags_to_extract[] = 'video'; $tags_to_extract[] = 'iframe'; $tags_to_extract[] = 'embed'; }
				if (!empty($tags_to_extract)) {
					$pattern = '/<(' . implode('|', $tags_to_extract) . ')[^>]*>.*?<\/\1>|<(' . implode('|', $tags_to_extract) . ')[^>]*\/>|<(' . implode('|', $tags_to_extract) . ')[^>]*>/is';
					if (preg_match_all($pattern, $rendered_content, $matches)) $media_tags = $matches[0];
				}
				$content = __('[Text content restricted by author]', 'ai-tamer') . "\n\n" . implode("\n", $media_tags);
			} else {
				$allowed_tags = array(
					'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(),
					'blockquote' => array(), 'div' => array(), 'span' => array(), 'h1' => array(), 'h2' => array(), 'h3' => array(),
					'h4' => array(), 'h5' => array(), 'h6' => array(),
				);
				if (!$block_images) {
					$allowed_tags['img'] = array('src' => array(), 'alt' => array(), 'title' => array(), 'width' => array(), 'height' => array());
					$allowed_tags['figure'] = array(); $allowed_tags['figcaption'] = array();
				}
				if (!$block_video) {
					$allowed_tags['video'] = array('src' => array(), 'poster' => array(), 'controls' => array());
					$allowed_tags['iframe'] = array('src' => array(), 'width' => array(), 'height' => array(), 'allowfullscreen' => array());
					$allowed_tags['embed'] = array('src' => array(), 'type' => array());
				}
				$content = wp_kses($rendered_content, $allowed_tags);
				$content = preg_replace('/\n{3,}/', "\n\n", trim($content));
			}
			wp_cache_set($cache_key, $content, 'ai-tamer', 3600);
			set_transient($cache_key, $content, 3600);
		}

		if ($request->get_param('aitamer_preview_poison') && current_user_can('manage_options')) $request->set_param('aitamer_poison', true);
		if ($request->get_param('aitamer_poison')) $content = Poisoner::poison($content);

		$body = array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'url'       => get_permalink($post),
			'author'    => get_the_author_meta('display_name', (int) $post->post_author),
			'published' => gmdate('c', strtotime($post->post_date_gmt)),
			'modified'  => gmdate('c', strtotime($post->post_modified_gmt)),
			'excerpt'   => wp_strip_all_tags($post->post_excerpt),
			'content'   => $content,
			'license'   => $protection ?: 'all-rights-reserved',
			'c2pa'      => array(
				'verified_human'  => (get_post_meta((int) $post->ID, '_aitamer_certify_human', true) === 'yes') || (HeuristicDetector::is_likely_human($content)),
				'heuristic_score' => HeuristicDetector::get_ai_score($content),
			),
		);

		$response = new WP_REST_Response($body, 200);
		$response->header('Vary', 'Authorization, X-AI-License-Token');
		return $response;
	}

	/**
	 * Stripe Webhook.
	 */
	public function handle_stripe_webhook(WP_REST_Request $request): WP_REST_Response
	{
		$plugin = $GLOBALS['ai_tamer'] ?? null;
		if ($plugin && isset($plugin->stripe_manager)) {
			$plugin->stripe_manager->handle_webhook($request->get_body(), $request->get_header('Stripe-Signature') ?: '');
		}
		return new WP_REST_Response(array('received' => true), 200);
	}

	/**
	 * GET /catalog
	 */
	public function handle_catalog(): WP_REST_Response
	{
		$posts_data = get_posts(array('posts_per_page' => 50, 'post_status' => 'publish'));
		$catalog = array();
		foreach ($posts_data as $post) {
			$protection = MetaBox::get_setting((int) $post->ID);
			$catalog[] = array(
				'id' => $post->ID, 'slug' => $post->post_name ?? '', 'title' => $post->post_title,
				'published' => gmdate('c', strtotime($post->post_date_gmt)), 'protection' => $protection ?: 'all-rights-reserved',
				'full_content_url' => home_url('/wp-json/ai-tamer/v1/content/' . $post->ID),
			);
		}
		return new WP_REST_Response(array('count' => count($catalog), 'items' => $catalog), 200);
	}

	/**
	 * POST /detect
	 */
	public function handle_detect(WP_REST_Request $request): WP_REST_Response
	{
		$content = $request->get_param('content') ?: '';
		$score   = HeuristicDetector::get_ai_score($content);
		$color   = ($score > 80) ? '#d63638' : (($score > 40) ? '#dba617' : '#2271b1');
		$label   = ($score > 90) ? __('Likely AI Generator', 'ai-tamer') : (($score > 40) ? __('AI-Assisted?', 'ai-tamer') : __('Likely Human', 'ai-tamer'));
		return new WP_REST_Response(array('score' => $score, 'label' => $label, 'color' => $color), 200);
	}
}
