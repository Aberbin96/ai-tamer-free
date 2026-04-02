<?php

namespace AiTamer\Traits;

/**
 * Trait MarkdownConverter
 * 
 * Provides simple HTML-to-Markdown conversion functionality for bot-friendly responses.
 */
trait MarkdownConverter {

	/**
	 * Converts basic HTML content to clean Markdown.
	 *
	 * @param string $html The HTML content to convert.
	 * @return string The converted Markdown text.
	 */
	public function html_to_markdown( $html ): string {
		if ( empty( $html ) ) {
			return '';
		}

		// 1. Remove script and style elements entirely.
		$markdown = preg_replace( '/<(script|style)[^>]*>[\s\S]*?<\/\1>/i', '', $html );

		// 2. Map structural elements to Markdown.
		
		// Headers
		$markdown = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/i', "\n# $1\n", $markdown );
		$markdown = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/i', "\n## $1\n", $markdown );
		$markdown = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/i', "\n### $1\n", $markdown );
		$markdown = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/i', "\n#### $1\n", $markdown );

		// Emphasis
		$markdown = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/i', '**$2**', $markdown );
		$markdown = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/i', '*$2*', $markdown );

		// Links
		$markdown = preg_replace( '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', '[$2]($1)', $markdown );

		// Lists
		$markdown = preg_replace( '/<li[^>]*>(.*?)<\/li>/i', "- $1\n", $markdown );
		$markdown = preg_replace( '/<(ul|ol)[^>]*>(.*?)<\/\1>/i', "\n$2\n", $markdown );

		// Paragraphs and Line Breaks
		$markdown = preg_replace( '/<p[^>]*>(.*?)<\/p>/i', "\n$1\n", $markdown );
		$markdown = preg_replace( '/<br\s*\/?>/i', "\n", $markdown );

		// 3. Strip all remaining HTML tags and decode entities.
		$markdown = wp_strip_all_tags( $markdown, false );
		$markdown = html_entity_decode( $markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 4. Cleanup excessive whitespace.
		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );
		
		return trim( $markdown );
	}

	/**
	 * Calculates the word count of a string.
	 *
	 * @param string $text The text to count.
	 * @return int Word count.
	 */
	public function get_word_count( string $text ): int {
		return str_word_count( wp_strip_all_tags( (string) $text ) );
	}

	/**
	 * Estimates reading time in minutes.
	 *
	 * @param string $text The text to analyze.
	 * @param int $wpm Words per minute average (default 200).
	 * @return int Estimated minutes.
	 */
	public function get_reading_time( string $text, int $wpm = 200 ): int {
		$words = $this->get_word_count( $text );
		return (int) ceil( $words / $wpm );
	}
}
