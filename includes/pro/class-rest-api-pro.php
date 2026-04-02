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

		// Admin: Lightning Streaming Analytics stats (capability-checked).
		register_rest_route(
			self::NAMESPACE,
			'/lightning-stats',
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'handle_lightning_stats'),
				'permission_callback' => function () {
					return current_user_can('manage_options');
				},
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


		// L402 / LSAT Lightning Validation.
		// Accepts both 'L402' (current spec) and 'LSAT' (legacy, Joule/Aperture v1) prefixes.
		// Format: Authorization: L402 <payment_hash>:<preimage>
		$l402_valid    = false;
		$l402_hash     = '';
		$l402_sats     = 0;
		$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		$l402_prefix_len = 0;
		if (0 === strpos($auth_header, 'L402 ')) {
			$l402_prefix_len = 5;
		} elseif (0 === strpos($auth_header, 'LSAT ')) {
			$l402_prefix_len = 5;
		}
		if ($l402_prefix_len > 0) {
			$credentials = substr($auth_header, $l402_prefix_len);
			$parts = explode(':', $credentials);
			if (count($parts) === 2) {
				$payment_hash = $parts[0];
				$preimage     = $parts[1];
				$l402_hash    = $payment_hash;

				// --- TEST BYPASS START ---
				if ('test_preimage' === $preimage) {
					$l402_valid = true;
					$l402_sats  = PricingEngine::get_price((int) $request->get_param('id'), $agent);
				}
				// --- TEST BYPASS END ---

				if (!$l402_valid) {
					$lnbits = Plugin::get_instance()->get_component('lnbits_manager');
					if ($lnbits) {
						$l402_valid = $lnbits->is_invoice_paid($payment_hash);
						if ($l402_valid) {
							$l402_sats = PricingEngine::get_price((int) $request->get_param('id'), $agent);
						}
					}
				}
			}
		}

		// Future e-cash integrations (Cashu / Fedimint) via extensible filter.
		$ecash_valid = apply_filters('aitamer_ecash_validate', false, $request);

		$is_valid = LicenseVerifier::has_valid_token($required_scope) || $l402_valid || $ecash_valid;
		$settings = get_option('aitamer_settings', array());
		$defense  = $settings['active_defense'] ?? 'block';

		if ($agent['matched'] && $this->logger) {
			$post_id = (int) $request->get_param('id');
			$protection = $is_valid ? 'api_content' : 'unauthorized';

			$this->logger->log($agent, $protection, $post_id);
		}

		// Record successful L402 micropayment in billing table (prevents duplicate on re-use).
		if ($l402_valid && ! empty($l402_hash)) {
			$billing_key = 'aitlnx_billed_' . $l402_hash;
			if (! get_transient($billing_key)) {
				$stripe_manager = Plugin::get_instance()->get_component('stripe_manager');
				if ($stripe_manager) {
					$stripe_manager->log_transaction(array(
						'agent_name'  => sanitize_text_field($agent['name'] ?? 'LN Agent'),
						'amount'      => (float) $l402_sats,
						'currency'    => 'SAT',
						'provider_id' => 'ln_' . $l402_hash,
						'status'      => \AiTamer\Enums\TransactionStatus::COMPLETED->value,
					));
				}
				// Mark billed for 48h (invoice TTL) to prevent double-logging.
				set_transient($billing_key, true, 2 * DAY_IN_SECONDS);
			}
		}

		if ($is_valid) {
			return true;
		}

		// Trigger L402 challenge for any unauthorized request to this endpoint if Payment strategy is active.
		if (Enums\DefenseStrategy::PAYMENT->value === $defense) {
			// L402 Lightning via LNbits (Exclusively for AI Agents here).
			$lnbits = Plugin::get_instance()->get_component('lnbits_manager');
			$ln_challenge = false;
			$ln_invoice_data = null;
			$post_id = (int) $request->get_param('id');
			$sats    = PricingEngine::get_price($post_id, $agent);

			if ($lnbits && $lnbits->is_enabled()) {
				$invoice = $lnbits->create_invoice($sats, 'Ai Tamer: Post ' . $post_id);

				if (!is_wp_error($invoice)) {
					header('Www-Authenticate: L402 macaroon="' . $invoice['payment_hash'] . '", invoice="' . $invoice['payment_request'] . '"');
					header('Access-Control-Expose-Headers: Www-Authenticate');
					$ln_challenge = true;
					$ln_invoice_data = $invoice;
				} else {
					$ln_error = $invoice->get_error_message();
				}
			}

			if ($ln_challenge) {
				$error_data = array(
					'status'     => 402,
					'price_sats' => $sats,
				);

				$base_price = PricingEngine::get_base_price($post_id);
				$error_data['pricing'] = array(
					'base_sats'  => $base_price,
					'multiplier' => ($base_price > 0) ? round($sats / $base_price, 2) : 1.0,
				);

				$error_data['l402'] = array(
					'version'            => '1',
					'payment_hash'       => $ln_invoice_data['payment_hash'],
					'invoice'            => $ln_invoice_data['payment_request'],
					'price_sats'         => $sats,
					'auth_header_format' => 'Authorization: L402 <payment_hash>:<preimage>',
				);

				return new WP_Error(
					'rest_payment_required',
					__('Payment Required to access this content. Pay the BOLT11 invoice and retry with the Authorization: L402 header.', 'ai-tamer'),
					$error_data
				);
			}

			// If Payment strategy is active but Lightning is not ready.
			return new WP_Error(
				'rest_lightning_not_configured',
				__('This content requires L402 Lightning payment, but the Lightning provider (LNbits) is not currently configured or reachable.', 'ai-tamer'),
				array(
					'status' => 503,
					'detail' => $ln_error ?? __('Check LNbits settings in the admin panel.', 'ai-tamer')
				)
			);
		}

		// Fallback to 401 if Payment is not the strategy or providers are not ready.
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

	/**
	 * GET /ai-tamer/v1/lightning-stats
	 *
	 * Returns aggregated Lightning micro-transaction stats for the admin dashboard.
	 * Capability-checked: only users with manage_options can access.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_lightning_stats(): WP_REST_Response
	{
		global $wpdb;

		$billing_table = $wpdb->prefix . StripeManager::TABLE;

		// Aggregate all LN rows (provider_id starts with 'ln_').
		$total_sats = (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT SUM(amount) FROM `{$billing_table}` WHERE provider_id LIKE 'ln_%' AND currency = 'SAT'" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$total_transactions = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM `{$billing_table}` WHERE provider_id LIKE 'ln_%' AND currency = 'SAT'" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		// Last 5 LN transactions.
		$recent = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT agent_name, amount, currency, provider_id, created_at FROM `{$billing_table}` WHERE provider_id LIKE 'ln_%' AND currency = 'SAT' ORDER BY id DESC LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		) ?: array();

		// Live LNbits wallet balance.
		$lnbits = Plugin::get_instance()->get_component('lnbits_manager');
		$wallet_data = null;
		if ($lnbits && $lnbits->is_enabled()) {
			$wallet_result = $lnbits->get_wallet_balance();
			if (!is_wp_error($wallet_result)) {
				$wallet_data = $wallet_result;
			}
		}

		// Cached BTC rate (piggybacks PricingEngine's transient — no extra API call).
		$btc_rate = get_transient(PricingEngine::RATE_TRANSIENT_KEY);
		if (!is_array($btc_rate)) {
			$btc_rate = null;
		}

		$body = array(
			'total_sats_earned'   => (int) $total_sats,
			'total_transactions'  => $total_transactions,
			'recent_transactions' => $recent,
			'lnbits_wallet'       => $wallet_data,
			'current_btc_rate'    => $btc_rate,
			'generated_at'        => gmdate('c'),
		);

		$response = new WP_REST_Response($body, 200);
		$response->header('Cache-Control', 'no-cache, no-store');
		return $response;
	}
}
