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

defined( 'ABSPATH' ) || exit;

/**
 * LicenseVerifier class.
 */
class LicenseVerifier {

	/**
	 * The wp_options key where the HMAC secret is stored.
	 */
	const SECRET_OPTION = 'aitamer_license_secret';

	/**
	 * Returns the site-specific HMAC secret, generating one if not yet set.
	 *
	 * @return string
	 */
	public static function get_secret(): string {
		$secret = get_option( self::SECRET_OPTION, '' );
		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/**
	 * Checks the current request for a valid `X-AI-License-Token` header.
	 *
	 * @param string $required_scope Optional scope required (e.g. 'post:123').
	 * @return bool True if a valid, unexpired, and authorized token is present.
	 */
	public static function has_valid_token( string $required_scope = '' ): bool {
		// Retrieve the header value (Apache/Nginx/WP-specific lookup).
		$header = '';
		if ( isset( $_SERVER['HTTP_X_AI_LICENSE_TOKEN'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AI_LICENSE_TOKEN'] ) );
		}

		if ( empty( $header ) ) {
			return false;
		}

		// Decode base64url → JSON.
		$json    = base64_decode( strtr( $header, '-_', '+/' ) );
		$payload = json_decode( $json, true );

		if ( ! is_array( $payload ) ) {
			return false;
		}

		// Required fields.
		foreach ( array( 'sub', 'iss', 'exp', 'sig' ) as $field ) {
			if ( empty( $payload[ $field ] ) ) {
				return false;
			}
		}

		// Token expiry check.
		if ( (int) $payload['exp'] < time() ) {
			return false;
		}

		// HMAC verification.
		$expected = hash_hmac(
			'sha256',
			$payload['sub'] . '|' . $payload['iss'] . '|' . $payload['exp'],
			self::get_secret()
		);

		if ( ! hash_equals( $expected, $payload['sig'] ) ) {
			return false;
		}

		// Scope verification.
		if ( ! empty( $required_scope ) ) {
			$token_scope = $payload['scp'] ?? 'global';
			if ( ! self::is_scope_authorized( $token_scope, $required_scope ) ) {
				return false;
			}
		}

		// Real-time subscription check (if linked).
		if ( ! empty( $payload['sid'] ) ) {
			$stripe = new StripeManager();
			if ( ! $stripe->verify_subscription( $payload['sid'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generates a signed license token for a given agent.
	 *
	 * @param string $agent_name Name of the licensed agent (e.g. "GPTBot").
	 * @param int    $days       Number of days until expiry (default 365).
	 * @param string $sub_id     Optional Stripe subscription ID.
	 * @param string $scope      Optional access scope (default 'global').
	 * @return string base64url-encoded token string.
	 */
	public static function issue_token( string $agent_name, int $days = 365, string $sub_id = '', string $scope = 'global' ): string {
		if ( 'global' === $scope ) {
			$scope = LicenseScope::GLOBAL->value;
		}
		$exp = time() + ( $days * DAY_IN_SECONDS );
		$iss = home_url( '/' );
		$sig = hash_hmac( 'sha256', $agent_name . '|' . $iss . '|' . $exp, self::get_secret() );

		$payload = json_encode( array( // phpcs:ignore WordPress.WP.AlternativeFunctions
			'sub' => $agent_name,
			'iss' => $iss,
			'exp' => $exp,
			'sid' => $sub_id,
			'scp' => $scope,
			'sig' => $sig,
		) );

		// base64url encode.
		$token = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );

		// Persist the token in the registry.
		$tokens   = self::get_tokens();
		$tokens[] = array(
			'agent'     => $agent_name,
			'exp'       => $exp,
			'sub_id'    => $sub_id,
			'scope'     => $scope,
			'issued_at' => time(),
			'token'     => $token,
		);
		update_option( 'aitamer_license_tokens', $tokens, false );

		return $token;
	}

	/**
	 * Returns all stored tokens.
	 *
	 * @return array
	 */
	public static function get_tokens(): array {
		$tokens = get_option( 'aitamer_license_tokens', array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Revokes (deletes) a token by index.
	 *
	 * @param int $index Zero-based index in the stored tokens array.
	 */
	public static function revoke_token( int $index ): void {
		$tokens = self::get_tokens();
		if ( isset( $tokens[ $index ] ) ) {
			array_splice( $tokens, $index, 1 );
			update_option( 'aitamer_license_tokens', $tokens, false );
		}
	}

	/**
	 * Helper: checks if a token scope covers a required resource.
	 *
	 * @param string $token_scope    The scope from the token (e.g. 'global', 'post:12').
	 * @param string $required_scope The required resource (e.g. 'post:12').
	 * @return bool
	 */
	public static function is_scope_authorized( string $token_scope, string $required_scope ): bool {
		// Global tokens are always authorized.
		if ( LicenseScope::GLOBAL->value === $token_scope ) {
			return true;
		}

		// Exact match (e.g. 'post:123' == 'post:123').
		if ( $token_scope === $required_scope ) {
			return true;
		}

		// Category check: 'cat:10' should authorize 'post:5' if post 5 is in category 10.
		if ( 0 === strpos( $token_scope, 'cat:' ) && 0 === strpos( $required_scope, 'post:' ) ) {
			$cat_id  = (int) substr( $token_scope, 4 );
			$post_id = (int) substr( $required_scope, 5 );

			if ( $cat_id > 0 && $post_id > 0 ) {
				return has_category( $cat_id, $post_id );
			}
		}

		return false;
	}
}
