<?php

/**
 * Watermarker — advanced content attribution and tracking.
 *
 * Implements dual-layer watermarking:
 * 1. Invisible Steganography (Zero-Width Characters)
 * 2. Grammatical DNA (Stylistic Synonym Substitution)
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_option;
use function get_bloginfo;
use function get_locale;
use function get_post_meta;
use function get_attached_file;
use function update_post_meta;
use function delete_post_meta;
use function wp_next_scheduled;
use function wp_schedule_single_event;

defined('ABSPATH') || exit;

/**
 * Watermarker class.
 */
class Watermarker
{
	/**
	 * Invisible characters used for encoding.
	 */
	private const ZWSP  = "\xe2\x80\x8b"; // Zero-Width Space
	private const ZWNJ  = "\xe2\x80\x8c"; // Zero-Width Non-Joiner

	/**
	 * Synonym map for Grammatical DNA.
	 */
	private static array $synonyms = array(
		'es' => array(
			'quizás'      => array('tal vez', 'posiblemente'),
			'importante'  => array('relevante', 'esencial'),
			'siempre'     => array('constantemente', 'en todo momento'),
			'ahora'       => array('actualmente', 'en este momento'),
			'rápidamente' => array('velozmente', 'con prontitud'),
		),
		'en' => array(
			'perhaps'   => array('maybe', 'possibly'),
			'important' => array('relevant', 'essential'),
			'always'    => array('constantly', 'at all times'),
			'now'       => array('currently', 'at present'),
			'quickly'   => array('fast', 'swiftly'),
		),
	);

	/**
	 * Apply watermarking to content.
	 *
	 * @param string $content Original HTML content.
	 * @param int    $post_id Post ID to encode.
	 * @return string Watermarked content.
	 */
	public static function apply(string $content, int $post_id): string
	{
		$settings = get_option('aitamer_settings', array());
		$enabled  = !empty($settings['enable_watermarking']) && 'no' !== $settings['enable_watermarking'];

		if (!$enabled) {
			return $content;
		}

		// 1. apply Stylistic DNA (Grammatical layer) - OPTIONAL.
		if (!empty($settings['active_stylistic_dna']) && 'no' !== $settings['active_stylistic_dna']) {
			$content = self::apply_stylistic_dna($content, $post_id);
		}

		// 2. apply Invisible signature (Steganography) - DEFAULT if enabled.
		$content = self::inject_invisible_tag($content, $post_id);

		return $content;
	}

	/**
	 * Encodes a signature using Zero-Width characters.
	 *
	 * @param string $content HTML content.
	 * @param int    $post_id ID to encode.
	 * @return string Content with invisible signature.
	 */
	private static function inject_invisible_tag(string $content, int $post_id): string
	{
		// Encode Site name hash + Post ID.
		$site_hash = substr(md5(get_bloginfo('name')), 0, 4);
		$payload   = $site_hash . ':' . $post_id;
		$signature = self::encode_string($payload);
		
		// Inject at the end of the FIRST paragraph to ensure visibility in snippets.
		if (preg_match('/<\/p>/i', $content)) {
			return preg_replace('/<\/p>/i', $signature . '</p>', $content, 1);
		}

		return $signature . $content;
	}

	/**
	 * Encodes a string into a series of ZWSP/ZWNJ characters.
	 *
	 * @param string $str The string to encode.
	 * @return string The encoded invisible string.
	 */
	private static function encode_string(string $str): string
	{
		$encoded = '';
		$chars   = str_split($str);
		
		foreach ($chars as $char) {
			$binary = sprintf('%08b', ord($char));
			for ($i = 0; $i < 8; $i++) {
				$encoded .= ($binary[$i] === '0') ? self::ZWSP : self::ZWNJ;
			}
		}

		return $encoded;
	}

	/**
	 * Switches words for synonyms based on a determininstic key for this post.
	 *
	 * @param string $content HTML content.
	 * @param int    $post_id Post ID.
	 * @return string Content with stylistic DNA applied.
	 */
	private static function apply_stylistic_dna(string $content, int $post_id): string
	{
		$lang = substr(get_locale(), 0, 2);
		if (!isset(self::$synonyms[$lang])) {
			$lang = 'en'; // Fallback
		}

		$map = self::$synonyms[$lang];
		$key = hash('crc32', \AUTH_KEY . $post_id);

		foreach ($map as $word => $replacements) {
			$pattern = '/\b' . preg_quote($word, '/') . '\b/iu';
			
			if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
				$idx = hexdec(substr($key, 0, 2)) % count($replacements);
				$replacement = $replacements[$idx];
				
				// Subtle swap: every 3rd occurrence.
				$count = 0;
				$content = preg_replace_callback(
					$pattern,
					function ($m) use (&$count, $replacement) {
						$count++;
						return ($count % 3 === 0) ? $replacement : $m[0];
					},
					$content
				);
			}
		}

		return $content;
	}

	/**
	 * Injects IPTC Digital Source Type into a JPEG image.
	 *
	 * @param string $file_path Path to the local image file.
	 * @param string $source_type IPTC source type (e.g., 'trainedAlgorithmicMedia').
	 * @return bool True on success.
	 */
	public static function apply_iptc_metadata(string $file_path, string $source_type = 'trainedAlgorithmicMedia'): bool
	{
		if (!file_exists($file_path) || !is_writable($file_path)) {
			return false;
		}

		$size = getimagesize($file_path, $info);
		if ($size['mime'] !== 'image/jpeg') {
			return false; // Currently only JPEG supported via iptcembed.
		}

		// IPTC Record 2, Tag 228 (Digital Source Type).
		$iptc_tag = '2#228';
		$data = array(
			$iptc_tag => $source_type,
		);

		// Build binary IPTC block.
		$iptc_new = '';
		foreach ($data as $tag => $string) {
			$tag = str_replace('2#', '', $tag);
			$iptc_new .= self::iptc_make_tag(2, $tag, $string);
		}

		// Embed into image.
		$content = iptcembed($iptc_new, $file_path);
		if ($content === false) {
			return false;
		}

		$fp = fopen($file_path, 'wb');
		if (!$fp) {
			return false;
		}

		fwrite($fp, $content);
		fclose($fp);

		return true;
	}

	/**
	 * Processes a single media file asynchronously (IPTC injection).
	 *
	 * @param int $post_id Attachment ID.
	 */
	public static function process_media_async(int $post_id): void
	{
		$certified = get_post_meta($post_id, '_aitamer_iptc_certified', true) === 'yes';
		if (!$certified) {
			return;
		}

		$file = get_attached_file($post_id);
		if (!$file || !file_exists($file)) {
			update_post_meta($post_id, '_aitamer_iptc_status', 'failed');
			return;
		}

		$success = self::apply_iptc_metadata($file, 'originalMediaDigitalSource');
		update_post_meta($post_id, '_aitamer_iptc_status', $success ? 'done' : 'failed');
	}

	/**
	 * Helper to create binary IPTC tags.
	 *
	 * @param int    $rec Record type.
	 * @param int    $dat Data type.
	 * @param string $val Value.
	 * @return string Binary tag.
	 */
	private static function iptc_make_tag($rec, $dat, $val): string
	{
		$len = strlen($val);
		if ($len < 0x8000) {
			return chr(0x1c) . chr($rec) . chr($dat) .
				chr($len >> 8) .
				chr($len & 0xff) .
				$val;
		} else {
			return chr(0x1c) . chr($rec) . chr($dat) .
				chr(0x80) . chr(0x04) .
				chr(($len >> 24) & 0xff) .
				chr(($len >> 16) & 0xff) .
				chr(($len >> 8) & 0xff) .
				chr($len & 0xff) .
				$val;
		}
	}
}

