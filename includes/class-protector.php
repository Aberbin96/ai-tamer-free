<?php
/**
 * Protector — handles header injection, meta tags, and robots.txt.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_option;
use function wp_parse_args;
use function sanitize_text_field;
use function home_url;
use function esc_attr;
use function esc_url;
use function absint;

defined( 'ABSPATH' ) || exit;

/**
 * Protector class.
 */
class Protector {

	/** @var Detector */
	private Detector $detector;

	/**
	 * @param Detector $detector
	 */
	public function __construct( Detector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * Retrieves plugin settings with defaults merged in.
	 *
	 * @return array
	 */
	private function get_settings(): array {
		$defaults = array(
			'block_training_bots'  => true,
			'inject_meta_tags'     => true,
			'inject_http_headers'  => true,
			'crawl_delay_enabled'  => false,
			'crawl_delay'          => 10,
			'license_policy'       => 'no-training',
		);
		$saved = get_option( 'aitamer_settings', array() );
		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Injects X-Robots-Tag and other AI-control HTTP headers.
	 * Hooked on 'wp_headers'.
	 *
	 * @param array $headers Existing headers.
	 * @return array Modified headers.
	 */
	public function inject_headers( array $headers ): array {
		$settings = $this->get_settings();

		if ( ! $settings['inject_http_headers'] ) {
			return $headers;
		}

		// Standard emerging directives for AI crawlers.
		$headers['X-Robots-Tag'] = 'noai, noimageai';
		$headers['AI-License']   = 'no-training; no-storage';

		return $headers;
	}

	/**
	 * Outputs <meta> protection tags in <head>.
	 * Hooked on 'wp_head'.
	 */
	public function inject_meta_tags(): void {
		$settings = $this->get_settings();

		if ( ! $settings['inject_meta_tags'] ) {
			return;
		}

		$policy = sanitize_text_field( $settings['license_policy'] ?? 'no-training' );
		?>
		<!-- AI Tamer Protection -->
		<meta name="robots" content="noai, noimageai">
		<meta name="ai-license" content="<?php echo esc_attr( $policy ); ?>; source=<?php echo esc_url( home_url() ); ?>">
		<?php
	}

	/**
	 * Appends bot-specific Disallow rules to the virtual robots.txt.
	 * Hooked on 'robots_txt'.
	 *
	 * @param string $output  Existing robots.txt content.
	 * @param bool   $public  Whether the site is public.
	 * @return string Modified robots.txt.
	 */
	public function append_robots_txt( string $output, bool $public ): string {
		$settings = $this->get_settings();

		if ( ! $settings['block_training_bots'] ) {
			return $output;
		}

		$bots  = $this->detector->get_bots();
		$block = "\n# --- AI Tamer: Block AI Training & Scraper Bots ---\n";

		$has_bots = false;
		foreach ( $bots as $bot ) {
			// Only Disallow training and scraper bots, never search bots.
			if ( ! in_array( $bot['type'] ?? '', array( 'training', 'scraper' ), true ) ) {
				continue;
			}
			$ua     = sanitize_text_field( $bot['user_agent'] ?? '' );
			$block .= "User-agent: {$ua}\n";
			if ( ! empty( $settings['crawl_delay_enabled'] ) ) {
				$delay  = absint( $settings['crawl_delay'] ?? 10 ) ?: 10;
				$block .= "Crawl-delay: {$delay}\n";
			}
			$block .= "Disallow: /\n\n";
			$has_bots = true;
		}

		if ( ! $has_bots ) {
			return $output;
		}

		$block .= "# --- End AI Tamer Rules ---\n";

		return $output . $block;
	}
}
