<?php
/**
 * MetaBoxPro — adds advanced AI detection and certification fields.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

defined('ABSPATH') || exit;

/**
 * MetaBoxPro class.
 */
class MetaBoxPro extends MetaBox
{
	/**
	 * Renders Pro-specific fields: AI detection score and human certification.
	 *
	 * @param \WP_Post $post Post object.
	 */
	protected function render_pro_fields($post): void
	{
?>
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
			<?php
			$certified = get_post_meta($post->ID, '_aitamer_certified_human', true) === 'yes';
			?>
			<div id="aitamer-manual-note" style="margin-top:10px; font-size:10px; color:#16a34a; background:#f0fdf4; padding:6px 10px; border-radius:3px; border:1px solid #bbf7d0; <?php echo $certified ? '' : 'display:none;'; ?>">
				<strong>✅ <?php esc_html_e('Manual Override Active', 'ai-tamer'); ?></strong><br/>
				<?php esc_html_e('Content is certified as human-origin for the manifest.', 'ai-tamer'); ?>
			</div>
		</div>

		<p style="margin:10px 0;">
			<?php
			$certified = get_post_meta($post->ID, '_aitamer_certified_human', true) === 'yes';
			?>
			<label style="display:flex; align-items:flex-start; gap:8px; cursor:pointer;">
				<input type="checkbox" name="aitamer_certified_human" id="aitamer_certified_human" value="yes" <?php checked($certified); ?> style="margin-top:3px;" />
				<span>
					<strong><?php esc_html_e('Certify Human Origin (Manual Override)', 'ai-tamer'); ?></strong><br />
					<small style="color:#666; font-size:11px; display:block; line-height:1.2; margin-top:2px;">
						<?php esc_html_e('Experimental (English & Spanish): Declare this content as original human work. Recommended if automated detection is inaccurate.', 'ai-tamer'); ?>
					</small>
				</span>
			</label>
		</p>
<?php
	}

	/**
	 * Saves Pro-specific fields.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function save_pro_fields(int $post_id): void
	{
		update_post_meta($post_id, '_aitamer_certified_human', isset($_POST['aitamer_certified_human']) ? 'yes' : 'no');
	}
}
