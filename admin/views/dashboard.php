<?php

/**
 * Admin dashboard view — Command Center.
 *
 * @package Ai_Tamer
 */

use AiTamer\Logger;

defined('ABSPATH') || exit;

$aitamer_stats    = Logger::get_stats(10);
$aitamer_total    = (int) ($aitamer_stats['total'] ?? 0);
$aitamer_blocked  = 0;
$aitamer_training = 0;
$aitamer_current_earnings = apply_filters('aitamer_monetization_earnings', 0.00);
$aitamer_potential_earnings = 0.0;

foreach (($aitamer_stats['top_bots'] ?? array()) as $aitamer_bot) {
	if (in_array($aitamer_bot['bot_type'], array('training', 'scraper'), true)) {
		$hits = (int) $aitamer_bot['hits'];
		$aitamer_training += $hits;

		$bot_val = apply_filters('aitamer_bot_monetization_value', 0.0, $aitamer_bot['bot_name']);
		if (empty($bot_val)) {
			$normalized = strtolower($aitamer_bot['bot_name']);
			if (strpos($normalized, 'gptbot') !== false || strpos($normalized, 'chatgpt') !== false) {
				$bot_val = 0.001;
			} elseif (strpos($normalized, 'claudebot') !== false || strpos($normalized, 'anthropic') !== false) {
				$bot_val = 0.0005;
			} elseif (strpos($normalized, 'google') !== false) {
				$bot_val = 0.0002;
			} else {
				$bot_val = 0.00;
			}
		}
		$aitamer_potential_earnings += $hits * $bot_val;
	}
}
// Shield Health: percentage of visits that are NOT training/scrapers (deprecated, always 100% for Audit Coverage).
$aitamer_score = 100;
?>
<div class="wrap aitamer-wrap">

	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title">
				<?php esc_html_e('AI Tamer', 'ai-tamer'); ?>
				<span class="aitamer-badge"><?php esc_html_e('Active', 'ai-tamer'); ?></span>
			</h1>
			<p class="aitamer-page-desc"><?php esc_html_e('Your content sovereignty command center.', 'ai-tamer'); ?></p>
		</div>
	</div>

	<!-- Metrics Row -->
	<div class="aitamer-metrics">
		<div class="aitamer-metric green">
			<div class="aitamer-metric-label"><?php esc_html_e('Total Detections', 'ai-tamer'); ?></div>
			<div class="aitamer-metric-value"><?php echo esc_html(number_format_i18n($aitamer_total)); ?></div>
			<div class="aitamer-metric-sub"><?php esc_html_e('AI requests logged', 'ai-tamer'); ?></div>
		</div>
		<div class="aitamer-metric red">
			<div class="aitamer-metric-label"><?php esc_html_e('Bots Intercepted', 'ai-tamer'); ?></div>
			<div class="aitamer-metric-value"><?php echo esc_html(number_format_i18n($aitamer_training)); ?></div>
			<div class="aitamer-metric-sub"><?php esc_html_e('Training bots deterred', 'ai-tamer'); ?></div>
		</div>
		<div class="aitamer-metric blue">
			<div class="aitamer-metric-label"><?php esc_html_e('Potential Earnings', 'ai-tamer'); ?></div>
			<div class="aitamer-metric-value">$<?php echo esc_html(number_format_i18n($aitamer_potential_earnings, 4)); ?></div>
			<div class="aitamer-metric-sub"><?php esc_html_e('Based on pessimistic bot valuation', 'ai-tamer'); ?></div>
		</div>
		<div class="aitamer-metric amber">
			<div class="aitamer-metric-label"><?php esc_html_e('Current Earnings', 'ai-tamer'); ?></div>
			<div class="aitamer-metric-value">$<?php echo esc_html(number_format_i18n($aitamer_current_earnings, 2)); ?></div>
			<div class="aitamer-metric-sub" style="margin-top:5px;">
				<?php if (! apply_filters('aitamer_is_pro_active', false)) : ?>
					<a href="#" style="color: inherit; text-decoration: underline; font-weight:600;"><?php esc_html_e('Upgrade to Pro to Monetize', 'ai-tamer'); ?></a>
				<?php else: ?>
					<?php esc_html_e('Actively monetizing', 'ai-tamer'); ?>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Top Bots -->
	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e('Top AI Bots by Activity', 'ai-tamer'); ?></h2>
		</div>
		<?php if (empty($aitamer_stats['top_bots'])) : ?>
			<div class="aitamer-empty">
				<p><?php esc_html_e('No AI bot activity recorded yet. Logs will appear after the first detected bot visit.', 'ai-tamer'); ?></p>
			</div>
		<?php else : ?>
			<div class="aitamer-table-responsive">
				<table class="aitamer-table">
					<thead>
						<tr>
							<th><?php esc_html_e('Bot Name', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Intent', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Requests', 'ai-tamer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($aitamer_stats['top_bots'] as $aitamer_bot) :
							$aitamer_type = $aitamer_bot['bot_type'] ?? 'search';
						?>
							<tr>
								<td><strong><?php echo esc_html($aitamer_bot['bot_name']); ?></strong></td>
								<td><span class="aitamer-badge-status <?php echo esc_attr($aitamer_type); ?>"><?php echo esc_html($aitamer_type); ?></span></td>
								<td><?php echo esc_html(number_format_i18n((int) $aitamer_bot['hits'])); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<!-- Most Targeted Content -->
	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e('Most Targeted Content', 'ai-tamer'); ?></h2>
		</div>
		<?php if (empty($aitamer_stats['top_posts'])) : ?>
			<div class="aitamer-empty">
				<p><?php esc_html_e('No content-specific activity recorded yet.', 'ai-tamer'); ?></p>
			</div>
		<?php else : ?>
			<div class="aitamer-table-responsive">
				<table class="aitamer-table">
					<thead>
						<tr>
							<th><?php esc_html_e('Post / URL', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Requests', 'ai-tamer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($aitamer_stats['top_posts'] as $aitamer_row) :
							$aitamer_post_id    = (int) $aitamer_row['post_id'];
							$aitamer_post_title = get_the_title($aitamer_post_id);
							$aitamer_edit_link  = get_edit_post_link($aitamer_post_id);
						?>
							<tr>
								<td>
									<?php if ($aitamer_edit_link) : ?>
										<a href="<?php echo esc_url($aitamer_edit_link); ?>" style="color:var(--at-blue);"><?php echo esc_html($aitamer_post_title); ?></a>
									<?php else : ?>
										<?php echo esc_html($aitamer_post_title ?: "Post #{$aitamer_post_id}"); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html(number_format_i18n((int) $aitamer_row['hits'])); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

</div>
