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
use function checked;

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
				<option value="custom" <?php selected($value, 'custom'); ?>>
					⚙️ <?php esc_html_e('Custom / Granular Control', 'ai-tamer'); ?>
				</option>
			</select>
		</p>

		<div id="aitamer-granular-options" style="margin-top:10px; padding:10px; background:#f0f0f1; border: 1px solid #ccd0d4; border-radius:4px; <?php echo ('custom' === $value) ? '' : 'display:none;'; ?>">
			<label style="display:block; margin-bottom:8px; font-weight:600; font-size:12px;">
				<?php esc_html_e('Granular Protections (Internal Targets)', 'ai-tamer'); ?>
			</label>

			<?php
			$block_text   = get_post_meta($post->ID, '_aitamer_block_text', true) === 'yes';
			$block_images = get_post_meta($post->ID, '_aitamer_block_images', true) === 'yes';
			$block_video  = get_post_meta($post->ID, '_aitamer_block_video', true) === 'yes';
			?>

			<p style="margin:5px 0;">
				<input type="checkbox" name="aitamer_block_text" id="aitamer_block_text" value="yes" <?php checked($block_text); ?> />
				<label for="aitamer_block_text"><?php esc_html_e('Block Text Training', 'ai-tamer'); ?></label>
			</p>
			<p style="margin:5px 0;">
				<input type="checkbox" name="aitamer_block_images" id="aitamer_block_images" value="yes" <?php checked($block_images); ?> />
				<label for="aitamer_block_images"><?php esc_html_e('Block Image Training', 'ai-tamer'); ?></label>
			</p>
			<p style="margin:5px 0;">
				<input type="checkbox" name="aitamer_block_video" id="aitamer_block_video" value="yes" <?php checked($block_video); ?> />
				<label for="aitamer_block_video"><?php esc_html_e('Block Video Training', 'ai-tamer'); ?></label>
			</p>
		</div>

		<hr style="border:0;border-top:1px solid #ccd0d4;margin:15px 0;" />

		<!-- AI Detection Feedback -->
		<?php
		$score = HeuristicDetector::get_ai_score($post->post_content);
		$color = ($score > 80) ? '#d63638' : (($score > 40) ? '#dba617' : '#2271b1');
		$label = ($score > 90) ? __('Likely AI Generator', 'ai-tamer') : (($score > 40) ? __('AI-Assisted?', 'ai-tamer') : __('Likely Human', 'ai-tamer'));
		?>
		<div id="aitamer-ai-status" style="margin-bottom:15px; padding:8px 12px; background:#fff; border-left:4px solid <?php echo $color; ?>; box-shadow:0 1px 1px rgba(0,0,0,0.04); transition: opacity 0.3s ease;">
			<span style="display:block; font-size:11px; color:#64748b; text-transform:uppercase; font-weight:600; letter-spacing:0.05em;">
				<?php esc_html_e('AI Detection Status', 'ai-tamer'); ?>
			</span>
			<div style="display:flex; align-items:baseline; gap:6px; margin-top:2px;">
				<strong class="aitamer-label-value" style="font-size:14px; color:<?php echo $color; ?>;"><?php echo esc_html($label); ?></strong>
				<span class="aitamer-score-value" style="font-size:12px; color:#94a3b8;"><?php echo (int)$score; ?>%</span>
			</div>
			<p style="margin:4px 0 0; font-size:10px; color:#94a3b8; line-height:1.3;">
				<?php esc_html_e('Based on pattern analysis (Updated on save).', 'ai-tamer'); ?>
			</p>
		</div>

		<p style="margin:10px 0;">
			<?php
			$certified = get_post_meta($post->ID, '_aitamer_certified_human', true) === 'yes';
			?>
			<label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer;">
				<input type="checkbox" name="aitamer_certified_human" value="yes" <?php checked($certified); ?> style="margin-top:3px;" />
				<span>
					<strong><?php esc_html_e('Certify Human Origin (Manual Override)', 'ai-tamer'); ?></strong><br />
					<small style="color:#666; font-size:11px; display:block; line-height:1.2; margin-top:2px;">
						<?php esc_html_e('Experimental (English & Spanish): Declare this content as original human work. Recommended if automated detection is inaccurate.', 'ai-tamer'); ?>
					</small>
				</span>
			</label>
		</p>

		<script>
			document.getElementById('aitamer_protection').addEventListener('change', function() {
				var granular = document.getElementById('aitamer-granular-options');
				granular.style.display = (this.value === 'custom') ? 'block' : 'none';
			});
		</script>
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
		$nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])) : '';
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

		$allowed = array('inherit', 'block_all', 'block_training', 'allow_all', 'custom');
		$value   = isset($_POST['aitamer_protection'])
			? sanitize_key(wp_unslash($_POST['aitamer_protection']))
			: 'inherit';

		if (! in_array($value, $allowed, true)) {
			$value = 'inherit';
		}

		update_post_meta($post_id, self::META_KEY, $value);

		// Save granular options.
		update_post_meta($post_id, '_aitamer_block_text', isset($_POST['aitamer_block_text']) ? 'yes' : 'no');
		update_post_meta($post_id, '_aitamer_block_images', isset($_POST['aitamer_block_images']) ? 'yes' : 'no');
		update_post_meta($post_id, '_aitamer_block_video', isset($_POST['aitamer_block_video']) ? 'yes' : 'no');

		// Save certification.
		update_post_meta($post_id, '_aitamer_certified_human', isset($_POST['aitamer_certified_human']) ? 'yes' : 'no');
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
		return in_array($value, array('inherit', 'block_all', 'block_training', 'allow_all', 'custom'), true) ? $value : 'inherit';
	}
}
