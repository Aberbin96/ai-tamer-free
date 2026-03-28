<?php

/**
 * ContentFilter — selectively hides content from AI training agents.
 *
 * Hooks into `the_content` to strip or replace blocks marked as `data-noai`
 * and respects per-post protection settings from the MetaBox.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_the_ID;
use function is_singular;
use function get_option;
use function get_transient;
use function set_transient;
use function wp_cache_get;
use function wp_cache_set;

defined('ABSPATH') || exit;

/**
 * ContentFilter class.
 */
class ContentFilter
{

	/** @var Detector */
	private Detector $detector;

	/**
	 * @param Detector $detector
	 */
	public function __construct(Detector $detector)
	{
		$this->detector = $detector;
	}

	/**
	 * Registers the content filter hook.
	 */
	public function register(): void
	{
		add_filter('the_content', array($this, 'filter_content'), 99);
	}

	/**
	 * Filters post content for training agents based on per-post and global settings.
	 *
	 * @param string $content Original post content.
	 * @return string Filtered content.
	 */
	public function filter_content(string $content): string
	{
		// Only filter on singular 'post' post type views.
		if (! is_singular('post')) {
			return $content;
		}

		// Allow Admin Preview.
		// Advanced defense preview (via Pro).
		$preview_content = apply_filters('aitamer_preview_defense', $content);
		if ($preview_content !== $content) {
			return $preview_content;
		}

		$agent   = $this->detector->classify();
		$post_id = (int) get_the_ID();

		// Determine effective protection level.
		$level = MetaBox::get_setting($post_id);

		// Resolve "inherit" to the actual outcome.
		if ('inherit' === $level) {
			if ($agent['type'] === 'training' || $agent['type'] === 'scraper') {
				$level = 'block_training';
			} else {
				return $content; // No filtering for search bots or humans.
			}
		}

		// If explicitly allow_all, skip all filtering.
		if ('allow_all' === $level) {
			return $content;
		}

		$should_filter = false;
		if ('block_all' === $level && $agent['matched']) {
			$should_filter = true; // Block any known bot.
		} elseif ('block_training' === $level && $this->detector->is_training_agent()) {
			$should_filter = true; // Block only training/scraper bots.
		}

		if (! $should_filter) {
			return $content;
		}

		// Active Defense: Poisoning.
		$settings = get_option('aitamer_settings', array());
		$defense  = $settings['active_defense'] ?? 'block';

		// Active Defense strategies (can be extended by Pro).
		$defended_content = apply_filters('aitamer_active_defense', $content, $defense, $post_id);
		if ($defended_content !== $content) {
			return $defended_content;
		}

		// Standard blocking or no filtering for researchers.
		// Strip elements marked with data-noai attribute.
		$content = $this->strip_noai_elements($content);

		return $this->apply_additional_pro_filters($content, $post_id, false);
	}

	/**
	 * Hook for Pro classes to apply additional filtering (like watermarking).
	 *
	 * @param string $content     Current content.
	 * @param int    $post_id     Current post ID.
	 * @param bool   $is_poisoned Whether the content was poisoned.
	 * @return string Modified content.
	 */
	protected function apply_additional_pro_filters(string $content, int $post_id, bool $is_poisoned): string
	{
		return $content;
	}

	/**
	 * Removes HTML elements that carry the `data-noai` attribute.
	 * These elements are specifically marked by the author as "not for AI".
	 *
	 * Usage: wrap content in <div data-noai> ... </div>
	 * The entire block will be invisible to training agents.
	 *
	 * @param string $content HTML content.
	 * @return string Filtered HTML.
	 */
	private function strip_noai_elements(string $content): string
	{
		// Use regex to remove block-level elements with data-noai attribute.
		// This handles divs, figures, sections, articles, asides.
		$tags = 'div|figure|section|article|aside|p|blockquote';
		$content = preg_replace(
			'/<(' . $tags . ')[^>]*\bdata-noai\b[^>]*>[\s\S]*?<\/\1>/i',
			'',
			$content
		);

		// Also strip <img> tags with data-noai.
		$content = preg_replace('/<img[^>]*\bdata-noai\b[^>]*\/?>/i', '', $content);

		return $content ?? '';
	}
}
