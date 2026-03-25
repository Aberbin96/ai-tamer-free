<?php

/**
 * MetaBox — per-post AI protection controls.
 *
 * Adds a "AI Tamer" meta box to posts/pages, allowing authors to:
 * - Block all AI bots for this post.
 * - Block only training bots.
 * - Allow all (inherit global settings).
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function add_action;
use function add_meta_box;
use function current_user_can;
use function get_post_meta;
use function get_post_types;
use function sanitize_key;
use function update_post_meta;
use function wp_nonce_field;
use function wp_verify_nonce;
use function wp_unslash;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function __;
use function selected;

defined('ABSPATH') || exit;

/**
 * MetaBox class.
 */
class MetaBox
{

	/** Meta key stored on each post. */
	const META_KEY = '_aitamer_protection';

	/** Nonce action. */
	const NONCE_ACTION = 'aitamer_meta_box_save';

	/** Nonce field name. */
	const NONCE_FIELD = 'aitamer_meta_box_nonce';

	/**
	 * Registers hooks.
	 */
	public function register(): void
	{
		add_action('add_meta_boxes', array($this, 'add_meta_box'));
		add_action('save_post', array($this, 'save'), 10, 2);
	}

	/**
	 * Registers the meta box on all public post types.
	 */
	public function add_meta_box(): void
	{
		$post_types = get_post_types(array('public' => true));
		foreach ($post_types as $post_type) {
			add_meta_box(
				'aitamer-protection',
				__('AI Tamer Protection', 'ai-tamer'),
				array($this, 'render'),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the meta box HTML.
	 *
	 * @param \WP_Post $post Current post object.
	 */
	public function render($post): void
	{
		wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
		$value = get_post_meta($post->ID, self::META_KEY, true) ?: 'inherit';
?>
		<p style="margin-top:0;">
			<label for="aitamer_protection" style="display:block;margin-bottom:5px;font-weight:600;">
				<?php esc_html_e('AI Bot Access', 'ai-tamer'); ?>
			</label>
			<select id="aitamer_protection" name="aitamer_protection" style="width:100%;box-sizing:border-box;">
				<option value="inherit" <?php selected($value, 'inherit'); ?>>
					<?php esc_html_e('— Inherit global settings —', 'ai-tamer'); ?>
				</option>
				<option value="block_all" <?php selected($value, 'block_all'); ?>>
					🔴 <?php esc_html_e('Block all AI bots', 'ai-tamer'); ?>
				</option>
				<option value="block_training" <?php selected($value, 'block_training'); ?>>
					🟡 <?php esc_html_e('Block training bots only', 'ai-tamer'); ?>
				</option>
				<option value="allow_all" <?php selected($value, 'allow_all'); ?>>
					🟢 <?php esc_html_e('Allow all AI bots', 'ai-tamer'); ?>
				</option>
			</select>
		</p>
		<p style="font-size:11px;color:#777;margin-bottom:0;">
			<?php esc_html_e('Override global AI protection for this specific post.', 'ai-tamer'); ?>
		</p>
<?php
	}

	/**
	 * Saves the meta box value on post save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save(int $post_id, $post): void
	{
		// Verify nonce.
		$nonce = isset($_POST[self::NONCE_FIELD]) ? wp_unslash($_POST[self::NONCE_FIELD]) : '';
		if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			return;
		}

		// Check autosave and capability.
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$allowed = array('inherit', 'block_all', 'block_training', 'allow_all');
		$value   = isset($_POST['aitamer_protection'])
			? sanitize_key(wp_unslash($_POST['aitamer_protection']))
			: 'inherit';

		if (! in_array($value, $allowed, true)) {
			$value = 'inherit';
		}

		update_post_meta($post_id, self::META_KEY, $value);
	}

	/**
	 * Returns the protection setting for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return string 'inherit' | 'block_all' | 'block_training' | 'allow_all'
	 */
	public static function get_setting(int $post_id): string
	{
		$value = get_post_meta($post_id, self::META_KEY, true);
		return in_array($value, array('inherit', 'block_all', 'block_training', 'allow_all'), true) ? $value : 'inherit';
	}
}
