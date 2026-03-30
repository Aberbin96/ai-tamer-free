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
use function get_post_meta;
use function get_the_ID;
use function get_the_title;
use function get_the_author_meta;
use function get_avatar_url;
use function get_the_date;
use function get_the_modified_date;
use function get_post;
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
		$value     = '';

		if ( is_singular() ) {
			$post_id      = (int) get_the_ID();
			$block_text   = get_post_meta( $post_id, '_aitamer_block_text', true ) === 'yes';
			$block_images = get_post_meta( $post_id, '_aitamer_block_images', true ) === 'yes';
			$block_video  = get_post_meta( $post_id, '_aitamer_block_video', true ) === 'yes';

			if ( $block_text || $block_images || $block_video ) {
				$parts = array( 'all-rights-reserved' );
				$parts[] = 'text=' . ( $block_text ? 'prohibited' : 'permitted' );
				$parts[] = 'images=' . ( $block_images ? 'prohibited' : 'permitted' );
				$parts[] = 'video=' . ( $block_video ? 'prohibited' : 'permitted' );
				$value = implode( '; ', $parts );
			}
		}

		if ( empty( $value ) ) {
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
		}

		$headers['AI-Content-License'] = $value;
		$headers['AI-Copyright']        = "Copyright {$year} {$site}. All rights reserved.";

		return $headers;
	}

	/**
	 * Injects a schema.org JSON-LD block using the @graph structure for AEO.
	 */
	public function inject_jsonld(): void {
		$directive = $this->get_license_directive();
		$home_url  = home_url( '/' );
		$site_name = get_bloginfo( 'name' );

		// 1. Organization Node.
		$org_id = $home_url . '#organization';
		$graph  = array(
			array(
				'@type' => 'Organization',
				'@id'   => $org_id,
				'name'  => $site_name,
				'url'   => $home_url,
				'logo'  => array(
					'@type' => 'ImageObject',
					'url'   => $home_url . 'wp-content/uploads/logo.png', // Placeholder if no custom logo.
				),
			),
		);

		// 2. WebSite Node.
		$graph[] = array(
			'@type'     => 'WebSite',
			'@id'       => $home_url . '#website',
			'url'       => $home_url,
			'name'      => $site_name,
			'publisher' => array( '@id' => $org_id ),
		);

		// 3. Author and Article Nodes (if singular).
		if ( is_singular() ) {
			$post_id   = (int) get_the_ID();
			$post_url  = (string) get_permalink();
			$author_id = (int) get_the_author_meta( 'ID' );
			$author_url = get_the_author_meta( 'url' ) ?: $home_url . 'author/' . get_the_author_meta( 'user_nicename' );

			$author_node = array(
				'@type' => 'Person',
				'@id'   => $home_url . '#author/' . $author_id,
				'name'  => get_the_author_meta( 'display_name' ),
				'url'   => $author_url,
				'image' => array(
					'@type' => 'ImageObject',
					'url'   => get_avatar_url( $author_id ),
				),
				'description' => get_the_author_meta( 'description' ),
			);

			// Enrich with social links if available.
			$social_links = array();
			$platforms    = array( 'facebook', 'twitter', 'instagram', 'linkedin', 'youtube', 'github' );
			foreach ( $platforms as $platform ) {
				$link = get_the_author_meta( $platform, $author_id );
				if ( $link ) {
					$social_links[] = $link;
				}
			}
			if ( ! empty( $social_links ) ) {
				$author_node['sameAs'] = $social_links;
			}

			$graph[] = $author_node;

			$article = array(
				'@type'            => 'Article',
				'@id'              => $post_url . '#article',
				'url'              => $post_url,
				'headline'         => get_the_title(),
				'datePublished'    => get_the_date( 'c', $post_id ),
				'dateModified'     => get_the_modified_date( 'c', $post_id ),
				'author'           => array( '@id' => $home_url . '#author/' . $author_id ),
				'publisher'        => array( '@id' => $org_id ),
				'copyrightYear'    => (int) get_the_date( 'Y', $post_id ),
				'copyrightHolder'  => array( '@id' => $org_id ),
				'mainEntityOfPage' => array( '@id' => $post_url ),
			);

			// Add protection metadata.
			switch ( $directive ) {
				case 'none':
					$article['usageInfo']       = home_url( '/ai-usage-policy/' );
					$article['license']         = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
					$article['copyrightNotice'] = 'No AI training, scraping, or indexing permitted without a written licence.';
					break;
				case 'training-prohibited':
					$article['usageInfo']       = home_url( '/ai-usage-policy/' );
					$article['license']         = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
					$article['copyrightNotice'] = 'AI training and scraping prohibited. Indexing by search bots permitted.';
					break;
				case 'permitted':
					$article['license']         = 'https://creativecommons.org/licenses/by/4.0/';
					$article['copyrightNotice'] = 'Licensed for AI use with attribution.';
					break;
				default:
					$article['usageInfo']       = home_url( '/ai-usage-policy/' );
					$article['license']         = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
					$article['copyrightNotice'] = 'AI training prohibited. All rights reserved.';
					break;
			}

			// Add FAQ support if headers are detected (AEO requirement).
			$post_content = get_post( $post_id )->post_content ?? '';
			if ( preg_match_all( '/<h[2-3][^>]*>(.*?)<\/h[2-3]>.*?<p[^>]*>(.*?)<\/p>/is', $post_content, $matches, PREG_SET_ORDER ) ) {
				$questions = array();
				foreach ( $matches as $match ) {
					$q_text = trim( strip_tags( $match[1] ) );
					$a_text = trim( strip_tags( $match[2] ) );
					if ( strpos( $q_text, '?' ) !== false || strpos( $q_text, '¿' ) !== false ) {
						$questions[] = array(
							'@type'          => 'Question',
							'name'           => $q_text,
							'acceptedAnswer' => array(
								'@type' => 'Answer',
								'text'  => $a_text,
							),
						);
					}
				}
				if ( ! empty( $questions ) ) {
					$graph[] = array(
						'@type'      => 'FAQPage',
						'@id'        => $post_url . '#faq',
						'mainEntity' => $questions,
					);
					$article['hasPart'] = array( '@id' => $post_url . '#faq' );
				}
			}

			$graph[] = $article;
		}

		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode( array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		echo "\n" . '</script>' . "\n";
	}
}
