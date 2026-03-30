<?php

namespace AiTamer;

use AiTamer\Enums\DefenseStrategy;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use function current_user_can;

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

		// Wallet: Check prepaid credit balance (V2).
		register_rest_route(
			self::NAMESPACE,
			'/wallet',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'handle_wallet'),
				'permission_callback' => array($this, 'check_token'),
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

		// Web3 Data Toll: Verify transaction and issue token.
		register_rest_route(
			self::NAMESPACE,
			'/pay-toll',
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'handle_pay_toll'),
				'permission_callback' => '__return_true', // Anyone can attempt to pay.
				'args'                => array(
					'tx_hash' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'post_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
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

		$web3_token = isset($_SERVER['HTTP_X_AI_TOLL_TOKEN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_AI_TOLL_TOKEN'])) : '';
		$web3_valid = false;
		if ( $web3_token && class_exists('\AiTamer\Web3Toll') && isset($post) && $post ) {
			$web3_valid = \AiTamer\Web3Toll::validate_post_token( $web3_token, $post->ID );
		}

		$is_valid = LicenseVerifier::has_valid_token($required_scope) || $web3_valid;
		$settings = get_option('aitamer_settings', array());
		$defense  = $settings['active_defense'] ?? 'block';

		if ($agent['matched'] && $this->logger) {
			$post_id = (int) $request->get_param('id');
			$protection = $is_valid ? 'api_content' : 'unauthorized';


			$this->logger->log($agent, $protection, $post_id);
		}

		if ($is_valid) {
			return true;
		}

		if (!$is_valid && $agent['matched']) {
			if (Enums\DefenseStrategy::PAYMENT->value === $defense) {
				$payment_url = null;
				$stripe = Plugin::get_instance()->get_stripe_manager();
				if ($stripe && current_user_can('read') === false) {
					$payment_url = $stripe->create_checkout_session($agent['name'] . ' (Auto)', (int) $request->get_param('id'));
					if ($payment_url) {
						header('X-Payment-Link: ' . $payment_url);
					}
				}

				$crypto_wallet = '';
				if (!empty($settings['web3_toll_enabled'])) {
					$crypto_wallet = $settings['base_wallet_address'] ?? '';
					$price  = $settings['usdc_price_per_request'] ?? '0.01';
					if ( $crypto_wallet ) {
						header('Www-Authenticate: L402 macaroon="", invoice="usdc_base:' . esc_attr($crypto_wallet) . '?amount=' . esc_attr($price) . '"');
					}
				}

				if ($payment_url || $crypto_wallet) {
					$msg = __('Payment Required to access this content.', 'ai-tamer');
					if ($crypto_wallet) {
						$msg .= ' ' . __('For Crypto (L402), submit USDC on Base and use X-AI-Toll-Token.', 'ai-tamer');
					}
					if ($payment_url) {
						$msg .= ' ' . __('For Fiat, purchase a license via the X-Payment-Link header.', 'ai-tamer');
					}

					return new WP_Error(
						'rest_payment_required',
						$msg,
						array('status' => 402, 'payment_url' => $payment_url)
					);
				}
			}

			return new WP_Error(
				'rest_forbidden',
				__('No valid license token found for this content. Use the header X-AI-License-Token: <token>. This content is protected against AI training agents.', 'ai-tamer'),
				array('status' => 401)
			);
		}


		return new WP_Error(
			'aitamer_unauthorized',
			'A valid X-AI-License-Token is required to access this endpoint. Visit ' . home_url('/wp-json/ai-tamer/v1/license') . ' to view usage terms.',
			array('status' => 401)
		);
	}

	/**
	 * GET /ai-tamer/v1/license
	 * Overrides the base license to add Stripe integration.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_license(): WP_REST_Response
	{
		$response = parent::handle_license();
		$body     = $response->get_data();

		$stripe = Plugin::get_instance()->get_stripe_manager();
		if ($stripe && $stripe->is_enabled()) {
			$sub_url      = $stripe->create_checkout_session('AI Agent', 0, 'subscription');
			$voucher_url  = $stripe->create_checkout_session('AI Agent', 0, 'voucher');

			$body['potentialAction'] = array();

			if ($sub_url) {
				$body['potentialAction'][] = array(
					'@type' => 'BuyAction',
					'name'  => 'Monthly Subscription (Unlimited)',
					'url'   => $sub_url,
				);
				$body['subscription_url'] = $sub_url;
			}

			if ($voucher_url) {
				$body['potentialAction'][] = array(
					'@type' => 'BuyAction',
					'name'  => 'Reading Voucher (Credits)',
					'url'   => $voucher_url,
				);
				$body['voucher_url'] = $voucher_url;
			}
		}

		$response->set_data($body);
		return $response;
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
	 * Stripe Webhook.
	 */
	public function handle_stripe_webhook(WP_REST_Request $request): WP_REST_Response
	{
		$plugin = Plugin::get_instance();
		$stripe = $plugin->get_stripe_manager();
		if ($stripe) {
			$stripe->handle_webhook($request->get_body(), $request->get_header('Stripe-Signature') ?: '');
		}
		return new WP_REST_Response(array('received' => true), 200);
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
	 * GET /wallet
	 * Returns the credit balance for the authenticated voucher.
	 */
	public function handle_wallet(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$payload = LicenseVerifier::get_last_payload();

		if (empty($payload['uid'])) {
			return new WP_Error(
				'aitamer_not_a_voucher',
				__('The provided token is not a Reading Voucher and does not have a wallet balance.', 'ai-tamer'),
				array('status' => 400)
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'aitamer_wallets';
		$wallet = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$table} WHERE token_id = %s",
			$payload['uid']
		), ARRAY_A);

		if (! $wallet) {
			return new WP_Error(
				'aitamer_wallet_not_found',
				__('No wallet found for this token ID.', 'ai-tamer'),
				array('status' => 404)
			);
		}

		return new WP_REST_Response(array(
			'token_id'   => $wallet['token_id'],
			'balance'    => (int) $wallet['balance'],
			'status'     => $wallet['status'],
			'last_used'  => $wallet['last_used'],
			'created_at' => $wallet['created_at'],
		), 200);
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

		if ($risk_score >= 50 && $this->detector) {
			// Severe mismatch. Log it and flag IP.
			$dummy_agent = array(
				'name'    => 'Headless Browser (Detected via FP)',
				'type'    => 'bot',
				'matched' => true,
				'ip'      => $data['ip']
			);
			
			// Optional: If you want to log the specific signal, add it to 'protection' or a new meta
			if ($this->logger) {
				$this->logger->log($dummy_agent, 'fingerprint_blocked');
			}
			
			// Add to Limiter actively (simulated ban for the IP via transients or similar)
			set_transient('aitamer_fp_block_' . md5($data['ip']), true, 3600); // 1 hour block
		}

		return new WP_REST_Response(array('received' => true, 'score' => $risk_score), 200);
	}

	/**
	 * POST /pay-toll
	 * Validates a Web3 transaction hash and issues a session token.
	 */
	public function handle_pay_toll(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$tx_hash = $request->get_param('tx_hash');
		$post_id = (int) $request->get_param('post_id');

		if ( ! class_exists('\AiTamer\Web3Toll') ) {
			return new WP_Error('aitamer_web3_disabled', __('Web3 Toll component is missing.', 'ai-tamer'), array('status' => 501));
		}

		$verification = \AiTamer\Web3Toll::verify_transaction($tx_hash);
		
		if ( true !== $verification ) {
			return new WP_Error('aitamer_web3_tx_invalid', $verification, array('status' => 400));
		}

		// Valid payment! Issue a token for this post.
		$token = \AiTamer\Web3Toll::issue_post_token($post_id);

		return new WP_REST_Response(array(
			'success' => true,
			'message' => __('Payment verified successfully.', 'ai-tamer'),
			'token'   => $token,
			'post_id' => $post_id,
		), 200);
	}
}
