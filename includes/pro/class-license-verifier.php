<?php

/**
 * LicenseVerifier — validates an AI agent's stated license claim.
 *
 * When an AI agent sends an `X-AI-License-Token` header, this class
 * verifies the token using a SHA-256 HMAC against the site's secret key.
 * Agents that hold a valid token may be granted expanded access even when
 * global protection is enabled.
 *
 * This provides the foundation for a future paid/contractual licensing
 * flow: the site owner issues a token to a licensed party, who presents
 * it with each request.
 *
 * Token format (base64url-encoded JSON):
 *   {
 *     "sub": "agent-name",
 *     "iss": "https://yoursite.com",
 *     "exp": 1234567890,       // Unix timestamp
 *     "sig": "sha256-hmac"    // HMAC of sub+iss+exp with site secret
 *   }
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use AiTamer\Enums\LicenseScope;
use function base64_decode;
use function base64_encode;
use function get_option;
use function hash_hmac;
use function hash_equals;
use function home_url;
use function json_decode;
use function json_encode;
use function sanitize_text_field;
use function time;
use function update_option;
use function wp_generate_password;
use function wp_unslash;
use function has_category;

defined('ABSPATH') || exit;

/**
 * LicenseVerifier class.
 */
class LicenseVerifier
{

	/**
	 * The wp_options key where the HMAC secret is stored.
	 */
	const SECRET_OPTION = 'aitamer_license_secret';

	/**
	 * Stores the last validated token payload.
	 * 
	 * @var array|null
	 */
	private static ?array $last_validated_payload = null;

	/**
	 * Returns the site-specific HMAC secret, generating one if not yet set.
	 *
	 * @return string
	 */
	public static function get_secret(): string
	{
		$secret = get_option(self::SECRET_OPTION, '');
		if (empty($secret)) {
			$secret = wp_generate_password(64, true, true);
			update_option(self::SECRET_OPTION, $secret, false);
		}
		return $secret;
	}

	public static function has_valid_token(string $required_scope = ''): bool
	{
		self::$last_validated_payload = null;

		// Retrieve the header value (Apache/Nginx/WP-specific lookup).
		$header = '';
		if (isset($_SERVER['HTTP_X_AI_LICENSE_TOKEN'])) {
			$header = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_AI_LICENSE_TOKEN']));
		}

		if (empty($header)) {
			return false;
		}

		// Decode base64url → JSON.
		$json    = base64_decode(strtr($header, '-_', '+/'));
		$payload = json_decode($json, true);

		if (! is_array($payload)) {
			return false;
		}

		// Required fields.
		foreach (array('sub', 'iss', 'exp', 'sig') as $field) {
			if (empty($payload[$field])) {
				return false;
			}
		}

		// Token expiry check.
		if ((int) $payload['exp'] < time()) {
			return false;
		}

		// HMAC verification.
		// Compatibility: check for old tokens (no UID) or new ones.
		$uid = $payload['uid'] ?? '';
		$hmac_msg = $payload['sub'] . '|' . $payload['iss'] . '|' . $payload['exp'];
		if (! empty($uid)) {
			$hmac_msg .= '|' . $uid;
		}

		$expected = hash_hmac('sha256', $hmac_msg, self::get_secret());

		if (! hash_equals($expected, $payload['sig'])) {
			return false;
		}

		// Scope verification.
		if (! empty($required_scope)) {
			$token_scope = $payload['scp'] ?? 'global';
			if (! self::is_scope_authorized($token_scope, $required_scope)) {
				return false;
			}
		}

		// Reading Voucher check (V2).
		if (! empty($payload['vch']) && ! empty($uid)) {
			if (! self::check_wallet_balance($uid)) {
				return false;
			}
		}

		// Real-time subscription check (if linked).
		if (! empty($payload['sid'])) {
			$stripe = new StripeManager();
			if (! $stripe->verify_subscription($payload['sid'])) {
				return false;
			}
		}

		self::$last_validated_payload = $payload;
		return true;
	}

	/**
	 * Returns the last successfully validated payload.
	 */
	public static function get_last_payload(): ?array
	{
		return self::$last_validated_payload;
	}

	/**
	 * Generates a signed license token for a given agent.
	 *
	 * @param string $agent_name Name of the licensed agent (e.g. "GPTBot").
	 * @param int    $days       Number of days until expiry (default 365).
	 * @param string $sub_id     Optional Stripe subscription ID.
	 * @param string $scope      Optional access scope (default 'global').
	 * @param int    $credits    Optional initial credits for Reading Vouchers.
	 * @return string base64url-encoded token string.
	 */
	public static function issue_token(string $agent_name, int $days = 365, string $sub_id = '', string $scope = 'global', int $credits = 0): string
	{
		Plugin::log("AI Tamer: issue_token called for {$agent_name}, scope: {$scope}, credits: {$credits}");

		if ('global' === $scope) {
			$scope = LicenseScope::GLOBAL->value;
		}

		$uid = wp_generate_password(16, false);
		$exp = time() + ($days * DAY_IN_SECONDS);
		$iss = home_url('/');
		$sig = hash_hmac('sha256', $agent_name . '|' . $iss . '|' . $exp . '|' . $uid, self::get_secret());

		$payload_data = array(
			'sub' => $agent_name,
			'iss' => $iss,
			'exp' => $exp,
			'sid' => $sub_id,
			'scp' => $scope,
			'uid' => $uid,
			'sig' => $sig,
		);

		if ($credits > 0) {
			$payload_data['vch'] = 1;
		}

		$payload = json_encode($payload_data); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// base64url encode.
		$token = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

		// Persist the token in the registry.
		$tokens   = self::get_tokens();
		$tokens[] = array(
			'uid'       => $uid,
			'agent'     => $agent_name,
			'exp'       => $exp,
			'sub_id'    => $sub_id,
			'scope'     => $scope,
			'issued_at' => time(),
			'token'     => $token,
			'is_voucher' => ($credits > 0),
		);
		$updated = update_option('aitamer_license_tokens', $tokens, false);

		if ($updated) {
			Plugin::log("AI Tamer: Token saved successfully for {$agent_name} (UID: {$uid}).");

			// Initialize wallet if credits are provided.
			if ($credits > 0) {
				self::init_wallet($uid, $credits);
			}
		} else {
			Plugin::log("AI Tamer: ERROR - Failed to save token for {$agent_name} in update_option.");
		}

		return $token;
	}

	/**
	 * Initializes a wallet for a Reading Voucher.
	 *
	 * @param string $token_id The unique token UID.
	 * @param int    $credits  Initial balance.
	 */
	public static function init_wallet(string $token_id, int $credits): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'aitamer_wallets';

		$wpdb->insert(
			$table,
			array(
				'token_id'   => $token_id,
				'balance'    => $credits,
				'status'     => 'active',
				'created_at' => current_time('mysql'),
			),
			array('%s', '%d', '%s', '%s')
		);

		Plugin::log("AI Tamer: Initialized wallet for token {$token_id} with {$credits} credits.");
	}

	/**
	 * Checks if a voucher has remaining credits.
	 *
	 * @param string $token_id The unique token UID.
	 * @return bool
	 */
	public static function check_wallet_balance(string $token_id): bool
	{
		global $wpdb;
		$table = $wpdb->prefix . 'aitamer_wallets';

		$balance = $wpdb->get_var($wpdb->prepare(
			"SELECT balance FROM {$table} WHERE token_id = %s AND status = 'active'",
			$token_id
		));

		return null !== $balance && (int) $balance > 0;
	}

	/**
	 * Deducts one credit from a voucher's wallet.
	 *
	 * @param string $token_id The unique token UID.
	 */
	public static function deduct_credit(string $token_id): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'aitamer_wallets';

		$wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET balance = balance - 1, last_used = %s WHERE token_id = %s AND balance > 0",
			current_time('mysql'),
			$token_id
		));

		// Check if exhausted.
		$balance = $wpdb->get_var($wpdb->prepare(
			"SELECT balance FROM {$table} WHERE token_id = %s",
			$token_id
		));

		if (0 === (int) $balance) {
			$wpdb->update($table, array('status' => 'exhausted'), array('token_id' => $token_id));
			Plugin::log("AI Tamer: Wallet {$token_id} exhausted.");
		}
	}

	/**
	 * Returns stored tokens with optional filtering and pagination.
	 *
	 * @param array $args Filtering and pagination arguments.
	 * @return array
	 */
	public static function get_tokens( array $args = array() ): array {
		$tokens = get_option( 'aitamer_license_tokens', array() );
		if ( ! is_array( $tokens ) ) {
			return array();
		}

		// Filtering.
		if ( ! empty( $args['s'] ) ) {
			$s      = strtolower( $args['s'] );
			$tokens = array_filter(
				$tokens,
				function( $t ) use ( $s ) {
					return strpos( strtolower( $t['agent'] ), $s ) !== false || strpos( strtolower( $t['token'] ), $s ) !== false;
				}
			);
		}

		if ( ! empty( $args['scope'] ) ) {
			$scope  = $args['scope'];
			$tokens = array_filter(
				$tokens,
				function( $t ) use ( $scope ) {
					return ( $t['scope'] ?? 'global' ) === $scope;
				}
			);
		}

		// Pagination.
		if ( isset( $args['limit'] ) ) {
			$limit  = (int) $args['limit'];
			$offset = (int) ( $args['offset'] ?? 0 );
			$tokens = array_slice( $tokens, $offset, $limit, true ); // Preserve keys for index-based actions.
		}

		return $tokens;
	}

	/**
	 * Counts stored tokens with optional filtering.
	 *
	 * @param array $args Filtering arguments.
	 * @return int
	 */
	public static function count_tokens( array $args = array() ): int {
		$args_no_pagination = $args;
		unset( $args_no_pagination['limit'], $args_no_pagination['offset'] );
		return count( self::get_tokens( $args_no_pagination ) );
	}

	/**
	 * Revokes (deletes) a token by index.
	 *
	 * @param int $index Zero-based index in the stored tokens array.
	 */
	public static function revoke_token(int $index): void
	{
		$tokens = self::get_tokens();
		if (isset($tokens[$index])) {
			array_splice($tokens, $index, 1);
			update_option('aitamer_license_tokens', $tokens, false);
		}
	}

	/**
	 * Helper: checks if a token scope covers a required resource.
	 *
	 * @param string $token_scope    The scope from the token (e.g. 'global', 'post:12').
	 * @param string $required_scope The required resource (e.g. 'post:12').
	 * @return bool
	 */
	public static function is_scope_authorized(string $token_scope, string $required_scope): bool
	{
		// Global tokens are always authorized.
		if (LicenseScope::GLOBAL->value === $token_scope) {
			return true;
		}

		// Exact match (e.g. 'post:123' == 'post:123').
		if ($token_scope === $required_scope) {
			return true;
		}

		// Category check: 'cat:10' should authorize 'post:5' if post 5 is in category 10.
		if (0 === strpos($token_scope, 'cat:') && 0 === strpos($required_scope, 'post:')) {
			$cat_id  = (int) substr($token_scope, 4);
			$post_id = (int) substr($required_scope, 5);

			if ($cat_id > 0 && $post_id > 0) {
				return has_category($cat_id, $post_id);
			}
		}

		return false;
	}
}
