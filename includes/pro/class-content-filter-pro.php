<?php
/**
 * ContentFilterPro — adds watermarking and stylistic DNA for Pro users.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

defined('ABSPATH') || exit;

/**
 * ContentFilterPro class.
 */
class ContentFilterPro extends ContentFilter
{
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
		// Only watermark if we're not poisoning (poisoning already has markups).
		if (!$is_poisoned && class_exists('AiTamer\Watermarker')) {
			$content = Watermarker::apply($content, $post_id);
		}

		return $content;
	}
}
