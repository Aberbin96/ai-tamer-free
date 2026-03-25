<?php
/**
 * LicenseManager — machine-readable content licensing for AI agents.
 *
 * Implements two layers of machine-readable licensing:
 *
 * 1. HTTP headers — a custom `AI-Content-License` header with a structured
 *    value that AI crawlers can parse before indexing content.
 *
 * 2. JSON-LD schema — injects a CreativeWork schema block in <head> with
 *    `usageInfo`, `license`, and `copyrightNotice` properties, following the
 *    schema.org vocabulary understood by some AI training pipelines.
 *
 * Both layers respect the per-post protection setting from MetaBox.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function add_action;
use function add_filter;
use function get_bloginfo;
use function get_option;
use function get_permalink;
use function get_the_ID;
use function get_the_title;
use function gmdate;
use function home_url;
use function is_singular;
use function json_encode;
use function wp_json_encode;
use function wp_parse_args;

defined( 'ABSPATH' ) || exit;

/**
 * LicenseManager class.
 */
class LicenseManager {

	/**
	 * Registers all hooks.
	 */
	public function register(): void {
		// Inject the AI-Content-License HTTP header.
		add_filter( 'wp_headers', array( $this, 'inject_license_header' ) );

		// Inject JSON-LD CreativeWork schema in <head>.
		add_action( 'wp_head', array( $this, 'inject_jsonld' ), 5 );
	}

	/**
	 * Generates the effective license directive for the current request.
	 *
	 * @return string  'none' | 'training-prohibited' | 'all-rights-reserved' | 'permitted'
	 */
	private function get_license_directive(): string {
		$settings = wp_parse_args(
			get_option( 'aitamer_settings', array() ),
			array(
				'license_type' => 'all-rights-reserved',
			)
		);

		if ( is_singular() ) {
			$post_id = (int) get_the_ID();
			$level   = MetaBox::get_setting( $post_id );

			switch ( $level ) {
				case 'allow_all':
					return 'permitted';
				case 'block_all':
					return 'none';
				case 'block_training':
					return 'training-prohibited';
			}
		}

		return $settings['license_type'] ?? 'all-rights-reserved';
	}

	/**
	 * Adds the `AI-Content-License` HTTP header.
	 *
	 * Header value format (space-separated directives):
	 *   ai-content-license: all-rights-reserved; training=prohibited; scraping=prohibited
	 *
	 * @param array $headers Existing headers array.
	 * @return array Modified headers.
	 */
	public function inject_license_header( array $headers ): array {
		$directive = $this->get_license_directive();
		$site      = get_bloginfo( 'name' );
		$year      = gmdate( 'Y' );

		switch ( $directive ) {
			case 'none':
				$value = 'all-rights-reserved; training=prohibited; scraping=prohibited; indexing=prohibited';
				break;
			case 'training-prohibited':
				$value = 'all-rights-reserved; training=prohibited; scraping=prohibited';
				break;
			case 'permitted':
				$value = 'permitted';
				break;
			default: // all-rights-reserved
				$value = 'all-rights-reserved; training=prohibited';
				break;
		}

		$headers['AI-Content-License'] = $value;
		$headers['AI-Copyright']        = "Copyright {$year} {$site}. All rights reserved.";

		return $headers;
	}

	/**
	 * Injects a schema.org/CreativeWork JSON-LD block for AI crawlers.
	 */
	public function inject_jsonld(): void {
		$directive = $this->get_license_directive();

		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'CreativeWork',
			'url'              => is_singular() ? (string) get_permalink() : home_url( '/' ),
			'name'             => is_singular() ? get_the_title() : get_bloginfo( 'name' ),
			'copyrightYear'    => (int) gmdate( 'Y' ),
			'copyrightHolder'  => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
		);

		// Map the directive to schema.org usageInfo and license.
		switch ( $directive ) {
			case 'none':
				$schema['usageInfo']       = home_url( '/ai-usage-policy/' );
				$schema['license']         = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
				$schema['copyrightNotice'] = 'No AI training, scraping, or indexing permitted without a written licence.';
				break;

			case 'training-prohibited':
				$schema['usageInfo']       = home_url( '/ai-usage-policy/' );
				$schema['license']         = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
				$schema['copyrightNotice'] = 'AI training and scraping prohibited. Indexing by search bots permitted.';
				break;

			case 'permitted':
				$schema['license']         = 'https://creativecommons.org/licenses/by/4.0/';
				$schema['copyrightNotice'] = 'Licensed for AI use with attribution.';
				break;

			default: // all-rights-reserved
				$schema['usageInfo']       = home_url( '/ai-usage-policy/' );
				$schema['license']         = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
				$schema['copyrightNotice'] = 'AI training prohibited. All rights reserved.';
				break;
		}

		echo '<script type="application/ld+json">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		echo wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo "\n" . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
