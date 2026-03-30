<?php
/**
 * LicenseVerifierTest — Unit tests for the HMAC license token logic.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use AiTamer\LicenseVerifier;

class LicenseVerifierTest extends TestCase {

	/**
	 * Setup BrainMonkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock standard WP functions used in LicenseVerifier.
		Monkey\Functions\expect( 'get_option' )
			->andReturn( 'test-secret-123' );
		Monkey\Functions\expect( 'home_url' )
			->andReturn( 'https://example.com' );
		Monkey\Functions\expect( 'sanitize_text_field' )
			->andReturnFirstArg();
		Monkey\Functions\expect( 'wp_unslash' )
			->andReturnFirstArg();
		Monkey\Functions\expect( 'update_option' )
			->andReturn( true );
		Monkey\Functions\expect( 'wp_generate_password' )
			->andReturn( 'mock-password-123' );
	}

	/**
	 * Teardown BrainMonkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that a valid token is correctly verified.
	 */
	public function test_it_verifies_valid_tokens(): void {
		// 1. Generate a token.
		$token = LicenseVerifier::issue_token( 'GPTBot', 30 );

		// 2. Simulate the header.
		$_SERVER['HTTP_X_AI_LICENSE_TOKEN'] = $token;

		// 3. Verify.
		$this->assertTrue( LicenseVerifier::has_valid_token() );
	}

	/**
	 * Tests that an expired token is rejected.
	 */
	public function test_it_rejects_expired_tokens(): void {
		// Mock time to be in the past for verification, then in the future for generation?
		// Better: just generate a token that expires at T and test at T+1.
		
		// This is tricky without mocking time() directly.
		// Let's assume the token generation and verification logic is sound and focus on HMAC failure.
		
		$token = LicenseVerifier::issue_token( 'GPTBot', -1 ); // Expired yesterday.
		$_SERVER['HTTP_X_AI_LICENSE_TOKEN'] = $token;

		$this->assertFalse( LicenseVerifier::has_valid_token() );
	}

	/**
	 * Tests that a tampered token is rejected.
	 */
	public function test_it_rejects_tampered_tokens(): void {
		$token = LicenseVerifier::issue_token( 'GPTBot', 30 );
		
		// Tamper with the token (it's base64url encoded JSON).
		$decoded = base64_decode( strtr( $token, '-_', '+/' ) );
		$payload = json_decode( $decoded, true );
		$payload['sub'] = 'MaliciousBot'; // Change the subject.
		
		$tampered = rtrim( strtr( base64_encode( json_encode( $payload ) ), '+/', '-_' ), '=' );
		$_SERVER['HTTP_X_AI_LICENSE_TOKEN'] = $tampered;

		$this->assertFalse( LicenseVerifier::has_valid_token() );
	}
}
