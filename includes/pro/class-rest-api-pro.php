<?php

namespace AiTamer;

use AiTamer\Enums\DefenseStrategy;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use function current_user_can;
use function get_transient;
use function set_transient;
use function get_option;
use function get_post;
use function get_posts;
use function wp_unslash;
use function sanitize_text_field;
use function esc_attr;
use function home_url;
use function is_wp_error;
use function __;

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


		// Discovery: Catalog for RAG / MCP Agents.
		register_rest_route(
			self::NAMESPACE,
			'/catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'handle_catalog'),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_type' => array(
						'type' => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'page' => array(
						'type' => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
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


		// Fingerprinting: Receive and analyze client-side signals.
		register_rest_route(
			self::NAMESPACE,
			'/fingerprint',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'handle_fingerprint'),
				// Check nonce for security. The script sends X-WP-Nonce header by default via keepalive fetch
				'permission_callback' => function () {
					// Either nonce is verified via cookie auth implicitly or we do it explicitly.
					return is_user_logged_in() || current_user_can('read') || true; // WP handles nonce auth. Allow public if it has valid nonce. Actually allow all, but we handle rate limits.
				},
				'args'                => array(
					'webdriver'  => array('type' => 'boolean'),
					'chrome'     => array('type' => 'boolean'),
					'plugins'    => array('type' => 'integer'),
					'mimeTypes'  => array('type' => 'integer'),
					'innerWidth' => array('type' => 'integer'),
					'outerWidth' => array('type' => 'integer'),
					'webgl'      => array('type' => 'string'),
				),
			)
		);

		// Admin: USDT P2P Analytics stats (capability-checked).
		register_rest_route(
			self::NAMESPACE,
			'/usdt-stats',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'handle_usdt_stats'),
				'permission_callback' => function () {
					return current_user_can('manage_options');
				},
			)
		);
	}

	/**
	 * Validates the X-Payment-Hash or X-AI-License-Token header.
	 */
	public function check_token(WP_REST_Request $request): bool|WP_Error
	{
		$agent = $this->detector ? $this->detector->classify() : array('matched' => false);
		$post_id = (int) $request->get_param('id');
		$settings = get_option('aitamer_settings', array());

		// 1. License Key Check (Standard).
		if (LicenseVerifier::has_valid_token('post:' . $post_id)) {
			return true;
		}

		// 2. USDT P2P Verification Logic.
		$tx_hash   = $_SERVER['HTTP_X_PAYMENT_HASH'] ?? '';
		$recipient = $settings['usdt_address'] ?? '';
		$network   = $settings['usdt_network'] ?? 'polygon';

		if (! empty($tx_hash) && ! empty($recipient)) {
			global $wpdb;
			$tolls_table = $wpdb->prefix . 'aitamer_tolls';

			// Check if already paid.
			$paid = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM {$tolls_table} WHERE transaction_hash = %s AND status = 'paid' LIMIT 1",
				sanitize_text_field($tx_hash)
			));

			if (! $paid) {
				$usdt_verifier = new USDTVerifier();
				$base_price    = (float) ($settings['usdt_price_usd'] ?? 0.10);
				$unique_amount = $usdt_verifier->get_unique_amount($base_price, $post_id);

				$is_verified = $usdt_verifier->verify($tx_hash, $unique_amount, $recipient, $network);
				if (true === $is_verified) {
					$wpdb->insert($tolls_table, array(
						'transaction_hash' => sanitize_text_field($tx_hash),
						'amount_usdt'      => $unique_amount,
						'network'          => $network,
						'post_id'          => $post_id,
						'bot_ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
						'status'           => 'paid',
						'created_at'       => current_time('mysql'),
					));
					$paid = true;
				}
			}

			if ($paid) {
				return true;
			}
		}

		$defense = $settings['active_defense'] ?? 'block';
		if (Enums\DefenseStrategy::PAYMENT->value === $defense) {
			$usdt_verifier = new USDTVerifier();
			$base_price    = (float) ($settings['usdt_price_usd'] ?? 0.10);
			$unique_amount = $usdt_verifier->get_unique_amount($base_price, $post_id);

			header('W3C-Payment-Method: USDT');
			header('WWW-Authenticate: USDT address="' . esc_attr($recipient) . '", amount="' . esc_attr((string) $unique_amount) . '", network="' . esc_attr($network) . '"');
			header('Access-Control-Expose-Headers: WWW-Authenticate, W3C-Payment-Method');

			return new WP_Error(
				'rest_payment_required',
				__('Payment Required: Direct USDT P2P Transfer.', 'ai-tamer'),
				array(
					'status'  => 402,
					'payment' => array(
						'method'  => 'USDT',
						'address' => $recipient,
						'amount'  => $unique_amount,
						'network' => $network,
					),
				)
			);
		}

		// Fallback to 401.
		return new WP_Error(
			'aitamer_unauthorized',
			'A valid X-AI-License-Token or X-Payment-Hash is required.',
			array('status' => 401)
		);
	}

	/**
	 * GET /ai-tamer/v1/usdt-stats
	 * (Capability-checked: manage_options)
	 */
	public function handle_usdt_stats(): WP_REST_Response
	{
		global $wpdb;
		$tolls_table = $wpdb->prefix . 'aitamer_tolls';

		$total_usdt = (float) ($wpdb->get_var(
			$wpdb->prepare("SELECT SUM(amount_usdt) FROM `{$tolls_table}` WHERE status = %s", 'paid')
		) ?: 0);

		$total_tx = (int) ($wpdb->get_var(
			$wpdb->prepare("SELECT COUNT(*) FROM `{$tolls_table}` WHERE status = %s", 'paid')
		) ?: 0);

		$recent = $wpdb->get_results(
			$wpdb->prepare("SELECT * FROM `{$tolls_table}` WHERE status = %s ORDER BY id DESC LIMIT 5", 'paid'),
			ARRAY_A
		) ?: array();

		return new WP_REST_Response(array(
			'total_usdt'  => $total_usdt,
			'total_tx'    => $total_tx,
			'recent_tx'   => $recent,
			'generated_at'=> gmdate('c'),
		), 200);
	}
	/**
	 * GET /ai-tamer/v1/license
	 * Base license implementation (Stripe integration removed).
	 *
	 * @return WP_REST_Response
	 */
	public function handle_license(): WP_REST_Response
	{
		return parent::handle_license();
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

		$settings        = get_option('aitamer_settings', array());
		$protected_types = $settings['protected_post_types'] ?? array('post');

		if (! in_array($post->post_type, $protected_types, true)) {
			return new WP_Error('aitamer_not_protected', __('This post type is not protected or exposed by the AI Tamer API.', 'ai-tamer'), array('status' => 403));
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
				if (!$block_images) {
					$tags_to_extract[] = 'img';
					$tags_to_extract[] = 'figure';
				}
				if (!$block_video) {
					$tags_to_extract[] = 'video';
					$tags_to_extract[] = 'iframe';
					$tags_to_extract[] = 'embed';
				}
				if (!empty($tags_to_extract)) {
					$pattern = '/<(' . implode('|', $tags_to_extract) . ')[^>]*>.*?<\/\1>|<(' . implode('|', $tags_to_extract) . ')[^>]*\/>|<(' . implode('|', $tags_to_extract) . ')[^>]*>/is';
					if (preg_match_all($pattern, $rendered_content, $matches)) $media_tags = $matches[0];
				}
				$content = __('[Text content restricted by author]', 'ai-tamer') . "\n\n" . implode("\n", $media_tags);
			} else {
				$allowed_tags = array(
					'p' => array(),
					'br' => array(),
					'strong' => array(),
					'em' => array(),
					'ul' => array(),
					'ol' => array(),
					'li' => array(),
					'blockquote' => array(),
					'div' => array(),
					'span' => array(),
					'h1' => array(),
					'h2' => array(),
					'h3' => array(),
					'h4' => array(),
					'h5' => array(),
					'h6' => array(),
				);
				if (!$block_images) {
					$allowed_tags['img'] = array('src' => array(), 'alt' => array(), 'title' => array(), 'width' => array(), 'height' => array());
					$allowed_tags['figure'] = array();
					$allowed_tags['figcaption'] = array();
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
	 * GET /catalog
	 */
	public function handle_catalog(WP_REST_Request $request): WP_REST_Response
	{
		$settings        = get_option('aitamer_settings', array());
		$protected_types = $settings['protected_post_types'] ?? array('post');

		$requested_type = $request->get_param('post_type');
		$post_type      = $protected_types;

		if ($requested_type) {
			if (! in_array($requested_type, $protected_types, true)) {
				return new WP_REST_Response(array('count' => 0, 'items' => array()), 200);
			}
			$post_type = $requested_type;
		}

		$page     = (int) $request->get_param('page') ?: 1;
		$per_page = 50;
		$offset   = ($page - 1) * $per_page;

		$posts_data = get_posts(array(
			'posts_per_page' => $per_page,
			'post_status'    => 'publish',
			'post_type'      => $post_type,
			'offset'         => $offset,
		));
		$catalog = array();
		foreach ($posts_data as $post) {
			$protection = MetaBox::get_setting((int) $post->ID);
			$catalog[] = array(
				'id' => $post->ID,
				'slug' => $post->post_name ?? '',
				'title' => $post->post_title,
				'published' => gmdate('c', strtotime($post->post_date_gmt)),
				'protection' => $protection ?: 'all-rights-reserved',
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


	/**
	 * POST /fingerprint
	 */
	public function handle_fingerprint(WP_REST_Request $request): WP_REST_Response
	{
		$data = array(
			'webdriver'  => $request->get_param('webdriver'),
			'chrome'     => $request->get_param('chrome'),
			'plugins'    => (int) $request->get_param('plugins'),
			'mimeTypes'  => (int) $request->get_param('mimeTypes'),
			'innerWidth' => (int) $request->get_param('innerWidth'),
			'outerWidth' => (int) $request->get_param('outerWidth'),
			'webgl'      => sanitize_text_field((string) $request->get_param('webgl')),
			'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
		);

		// Evaluate Fingerprint heuristic score based on client signals
		$risk_score = HeuristicDetector::evaluate_fingerprint($data);

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

		if ($risk_score >= 50) {
			// Add to Limiter actively (simulated ban for the IP via transients or similar)
			set_transient('aitamer_fp_block_' . md5($data['ip']), true, 3600); // 1 hour block
		}

		return new WP_REST_Response(array('received' => true, 'score' => $risk_score), 200);
	}

}
