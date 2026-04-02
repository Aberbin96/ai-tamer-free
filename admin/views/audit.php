<?php

defined('ABSPATH') || exit;

/**
 * Audit Reports page view — Official Customs Logbook.
 *
 * @package Ai_Tamer
 */

use AiTamer\AuditReport;
use AiTamer\Logger;
use AiTamer\Admin;

global $wpdb;

/**
 * Security: Nonce verification for filtering/pagination.
 */
if (isset($_GET['aitamer_audit_nonce']) && ! wp_verify_nonce(sanitize_key(wp_unslash($_GET['aitamer_audit_nonce'])), 'aitamer_audit_filter')) {
	wp_die(esc_html__('Security check failed.', 'ai-tamer'));
}

$aitamer_table = $wpdb->prefix . Logger::TABLE;
$aitamer_total  = (int) ($wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare('SELECT COUNT(*) FROM %i', $aitamer_table)
) ?: 0);
?>
<div class="wrap aitamer-wrap">

	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title"><?php esc_html_e('Audit Reports', 'ai-tamer'); ?></h1>
			<p class="aitamer-page-desc"><?php esc_html_e('Transparent AI bot forensics. Immutable evidence for security auditing.', 'ai-tamer'); ?></p>
		</div>
		<div style="display:flex;gap:10px;align-items:center;flex-shrink:0;">
			<a href="<?php echo esc_url(AuditReport::get_download_url(30)); ?>" class="aitamer-btn-primary">
				&#8659; <?php esc_html_e('Generate Evidence (CSV)', 'ai-tamer'); ?>
			</a>
		</div>
	</div>

	<?php
	Admin::get_instance()->render_navigation_tabs();
	?>

	<?php if (isset($_GET['aitamer_error'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended 
	?>
		<div class="notice notice-error">
			<p><?php esc_html_e('Could not generate the report. Please check file system permissions.', 'ai-tamer'); ?></p>
		</div>
	<?php endif; ?>

	<!-- Metrics -->
	<?php
	$aitamer_oldest = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare('SELECT created_at FROM %i ORDER BY id ASC LIMIT 1', $aitamer_table)
	);
	$aitamer_days = 0;
	if ($aitamer_oldest) {
		$aitamer_diff = time() - strtotime((string) $aitamer_oldest);
		$aitamer_days = (int) min(30, max(1, ceil($aitamer_diff / DAY_IN_SECONDS)));
	}
	?>
	<div class="aitamer-card">
		<div class="aitamer-metrics">
			<div class="aitamer-metric green">
				<div class="aitamer-metric-label"><?php esc_html_e('Secure Logs', 'ai-tamer'); ?></div>
				<div class="aitamer-metric-value"><?php echo esc_html(number_format_i18n($aitamer_total)); ?></div>
				<div class="aitamer-metric-sub"><?php esc_html_e('Protected access records', 'ai-tamer'); ?></div>
			</div>
			<div class="aitamer-metric amber">
				<div class="aitamer-metric-label"><?php esc_html_e('Audit History', 'ai-tamer'); ?></div>
				<div class="aitamer-metric-value"><?php echo esc_html($aitamer_days); ?> <?php echo esc_html(_n('Day', 'Days', $aitamer_days, 'ai-tamer')); ?></div>
				<div class="aitamer-metric-sub"><?php esc_html_e('Activity period recorded', 'ai-tamer'); ?></div>
			</div>
		</div>

		<div class="aitamer-card-header">
			<h2><?php esc_html_e('Download Evidence Report', 'ai-tamer'); ?></h2>
		</div>
		<p><?php esc_html_e('Select a time period to generate a downloadable CSV audit file.', 'ai-tamer'); ?></p>
		<div style="display:flex;gap:10px;flex-wrap:wrap;">
			<a href="<?php echo esc_url(AuditReport::get_download_url(7)); ?>" class="aitamer-btn-ghost">
				<?php esc_html_e('Last 7 days', 'ai-tamer'); ?>
			</a>
			<a href="<?php echo esc_url(AuditReport::get_download_url(30)); ?>" class="aitamer-btn-primary">
				<?php esc_html_e('Last 30 days', 'ai-tamer'); ?>
			</a>
			<a href="<?php echo esc_url(AuditReport::get_download_url(90)); ?>" class="aitamer-btn-ghost">
				<?php esc_html_e('Last 90 days', 'ai-tamer'); ?>
			</a>
		</div>
		<p style="margin-top:16px;font-size:11px;">
			<?php
			printf(
				/* translators: %s: total records */
				esc_html__('There are currently %s access records available in the log database.', 'ai-tamer'),
				'<strong style="color:var(--at-green);">' . esc_html(number_format_i18n($aitamer_total)) . '</strong>'
			);
			?>
		</p>

		<!-- Log Preview Table -->
		<?php
		// Filtering and Pagination logic.
		$aitamer_paged      = isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1;
		$aitamer_bot_type   = isset($_GET['bot_type']) ? sanitize_text_field(wp_unslash($_GET['bot_type'])) : '';
		$aitamer_protection = isset($_GET['protection']) ? sanitize_text_field(wp_unslash($_GET['protection'])) : '';
		$aitamer_search     = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$aitamer_per_page   = 25;
		$aitamer_offset     = ( $aitamer_paged - 1 ) * $aitamer_per_page;

		$aitamer_filter_args = array(
			'limit'      => $aitamer_per_page,
			'offset'     => $aitamer_offset,
			'bot_type'   => $aitamer_bot_type,
			'protection' => $aitamer_protection,
			's'          => $aitamer_search,
		);

		$aitamer_recent      = Logger::get_logs($aitamer_filter_args);
		$aitamer_count       = Logger::count_logs($aitamer_filter_args);
		$aitamer_total_pages = (int) ceil($aitamer_count / $aitamer_per_page);
		?>

		<div class="aitamer-card">
			<div class="aitamer-card-header">
				<h2><?php esc_html_e('Secure Activity Log', 'ai-tamer'); ?></h2>
				<span style="font-size:11px;color:var(--at-muted);">
					<?php
					/* translators: 1: number of records shown, 2: total number of records */
					printf( esc_html__( 'Showing %1$d of %2$s records', 'ai-tamer' ), (int) count( $aitamer_recent ), esc_html( number_format_i18n( $aitamer_count ) ) );
					?>
				</span>
			</div>

			<!-- Filter Bar -->
			<form method="get" class="aitamer-filter-bar">
				<input type="hidden" name="page" value="ai-tamer-audit">
				<?php wp_nonce_field( 'aitamer_audit_filter', 'aitamer_audit_nonce' ); ?>

				<div class="aitamer-filter-group">
					<label><?php esc_html_e('Search', 'ai-tamer'); ?></label>
					<input type="text" name="s" value="<?php echo esc_attr( (string) $aitamer_search ); ?>" placeholder="<?php esc_attr_e('URI or Bot Name...', 'ai-tamer'); ?>" style="width:200px;">
				</div>

				<div class="aitamer-filter-group">
					<label><?php esc_html_e('Bot Type', 'ai-tamer'); ?></label>
					<select name="bot_type">
						<option value=""><?php esc_html_e('All Types', 'ai-tamer'); ?></option>
						<option value="search" <?php selected($aitamer_bot_type, 'search'); ?>><?php esc_html_e('Search Engine', 'ai-tamer'); ?></option>
						<option value="training" <?php selected($aitamer_bot_type, 'training'); ?>><?php esc_html_e('AI Training', 'ai-tamer'); ?></option>
						<option value="scraper" <?php selected($aitamer_bot_type, 'scraper'); ?>><?php esc_html_e('Aggressive Scraper', 'ai-tamer'); ?></option>
					</select>
				</div>

				<div class="aitamer-filter-group">
					<label><?php esc_html_e('Protection', 'ai-tamer'); ?></label>
					<select name="protection">
						<option value=""><?php esc_html_e('All Actions', 'ai-tamer'); ?></option>
						<option value="none" <?php selected($aitamer_protection, 'none'); ?>><?php esc_html_e('None (Allowed)', 'ai-tamer'); ?></option>
						<option value="blocked" <?php selected($aitamer_protection, 'blocked'); ?>><?php esc_html_e('Blocked (401)', 'ai-tamer'); ?></option>
						<option value="payment_required" <?php selected($aitamer_protection, 'payment_required'); ?>><?php esc_html_e('Payment Req (402)', 'ai-tamer'); ?></option>
						<option value="obfuscated" <?php selected($aitamer_protection, 'obfuscated'); ?>><?php esc_html_e('Obfuscated', 'ai-tamer'); ?></option>
					</select>
				</div>

				<button type="submit" class="aitamer-btn-ghost"><?php esc_html_e('Filter', 'ai-tamer'); ?></button>
				<?php if (! empty($aitamer_search) || ! empty($aitamer_bot_type) || ! empty($aitamer_protection)) : ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=ai-tamer-audit')); ?>" class="aitamer-btn-danger" style="border:none;"><?php esc_html_e('Clear', 'ai-tamer'); ?></a>
				<?php endif; ?>
			</form>

			<?php if (empty($aitamer_recent)) : ?>
				<div class="aitamer-empty">
					<p><?php esc_html_e('No matching records found for these filters.', 'ai-tamer'); ?></p>
				</div>
			<?php else : ?>
				<div class="aitamer-table-responsive">
					<table class="aitamer-table">
						<thead>
							<tr>
								<th><?php esc_html_e('Timestamp', 'ai-tamer'); ?></th>
								<th><?php esc_html_e('Bot Identity', 'ai-tamer'); ?></th>
								<th><?php esc_html_e('IP Hash', 'ai-tamer'); ?></th>
								<th><?php esc_html_e('URI Accessed', 'ai-tamer'); ?></th>
								<th><?php esc_html_e('Intent', 'ai-tamer'); ?></th>
								<th><?php esc_html_e('Protection', 'ai-tamer'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($aitamer_recent as $aitamer_entry) :
								$aitamer_type = $aitamer_entry['bot_type'] ?? 'search';
							?>
								<tr>
									<td class="mono" style="font-size:10px;"><?php echo esc_html((string) ($aitamer_entry['created_at'] ?? '')); ?></td>
									<td>
										<strong><?php echo esc_html((string) ($aitamer_entry['bot_name'] ?? 'Unknown')); ?></strong>
										<div style="font-size:9px;color:var(--at-muted);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr((string) ($aitamer_entry['user_agent'] ?? '')); ?>">
											<?php echo esc_html((string) ($aitamer_entry['user_agent'] ?? '')); ?>
										</div>
									</td>
									<td class="mono" style="font-size:9px;"><?php echo esc_html(substr((string) ($aitamer_entry['ip_hash'] ?? ''), 0, 8)); ?>...</td>
									<td class="mono"><?php echo esc_html((string) ($aitamer_entry['request_uri'] ?? '/')); ?></td>
									<td><span class="aitamer-badge-status <?php echo esc_attr((string) $aitamer_type); ?>"><?php echo esc_html((string) $aitamer_type); ?></span></td>
									<td>
										<?php
										$aitamer_prot_label = (string) ($aitamer_entry['protection'] ?? 'none');
										$aitamer_prot_class = $aitamer_prot_label === 'none' ? '' : (strpos((string) $aitamer_prot_label, 'block') !== false ? 'blocked' : 'limited');
										?>
										<span class="aitamer-badge-status <?php echo esc_attr((string) $aitamer_prot_class); ?>" style="<?php echo empty($aitamer_prot_class) ? 'background:var(--at-border);color:var(--at-text);' : ''; ?>">
											<?php echo esc_html((string) $aitamer_prot_label); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- Pagination -->
				<?php if ($aitamer_total_pages > 1) : ?>
					<div class="aitamer-pagination">
						<?php
						echo wp_kses_post(paginate_links(array(
							'base'      => add_query_arg(array(
								'paged'                => '%#%',
								'aitamer_audit_nonce' => isset($_GET['aitamer_audit_nonce']) ? sanitize_key($_GET['aitamer_audit_nonce']) : wp_create_nonce('aitamer_audit_filter'),
							)),
							'format'    => '',
							'prev_text' => '&laquo; ' . __('Prev', 'ai-tamer'),
							'next_text' => __('Next', 'ai-tamer') . ' &raquo;',
							'total'     => $aitamer_total_pages,
							'current'   => $aitamer_paged,
						)));
						?>
					</div>
				<?php endif; ?>

			<?php endif; ?>
		</div>
	</div>
</div>
