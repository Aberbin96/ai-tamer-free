<?php

defined( 'ABSPATH' ) || exit;

/**
 * Audit Reports page view — Official Customs Logbook.
 *
 * @package Ai_Tamer
 */

use AiTamer\AuditReport;
use AiTamer\Logger;

global $wpdb;
$aitamer_table = $wpdb->prefix . Logger::TABLE;
$aitamer_total  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	"SELECT COUNT(*) FROM `{$aitamer_table}`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
?>
<div class="wrap aitamer-wrap">

	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title"><?php esc_html_e( 'Audit Reports', 'ai-tamer' ); ?></h1>
			<p class="aitamer-page-desc"><?php esc_html_e( 'Transparent AI bot forensics. Immutable evidence for security auditing.', 'ai-tamer' ); ?></p>
		</div>
		<div style="display:flex;gap:10px;align-items:center;flex-shrink:0;">
			<a href="<?php echo esc_url( AuditReport::get_download_url( 30 ) ); ?>" class="aitamer-btn-primary">
				&#8659; <?php esc_html_e( 'Generate Evidence (CSV)', 'ai-tamer' ); ?>
			</a>
		</div>
	</div>

	<?php if ( isset( $_GET['aitamer_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Could not generate the report. Please check file system permissions.', 'ai-tamer' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Metrics -->
	<?php
	$aitamer_oldest = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		"SELECT created_at FROM `{$aitamer_table}` ORDER BY id ASC LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	$aitamer_days = 0;
	if ( $aitamer_oldest ) {
		$aitamer_diff = time() - strtotime( $aitamer_oldest );
		$aitamer_days = min( 30, max( 1, ceil( $aitamer_diff / DAY_IN_SECONDS ) ) );
	}
	?>
	<div class="aitamer-metrics">
		<div class="aitamer-metric green">
			<div class="aitamer-metric-label"><?php esc_html_e( 'Secure Logs', 'ai-tamer' ); ?></div>
			<div class="aitamer-metric-value"><?php echo esc_html( number_format_i18n( $aitamer_total ) ); ?></div>
			<div class="aitamer-metric-sub"><?php esc_html_e( 'Protected access records', 'ai-tamer' ); ?></div>
		</div>
		<div class="aitamer-metric amber">
			<div class="aitamer-metric-label"><?php esc_html_e( 'Audit History', 'ai-tamer' ); ?></div>
			<div class="aitamer-metric-value"><?php echo esc_html( $aitamer_days ); ?> <?php echo esc_html( _n( 'Day', 'Days', $aitamer_days, 'ai-tamer' ) ); ?></div>
			<div class="aitamer-metric-sub"><?php esc_html_e( 'Activity period recorded', 'ai-tamer' ); ?></div>
		</div>
	</div>

	<!-- Download Options -->
	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e( 'Download Evidence Report', 'ai-tamer' ); ?></h2>
		</div>
		<p><?php esc_html_e( 'Select a time period to generate a downloadable CSV audit file.', 'ai-tamer' ); ?></p>
		<div style="display:flex;gap:10px;flex-wrap:wrap;">
			<a href="<?php echo esc_url( AuditReport::get_download_url( 7 ) ); ?>" class="aitamer-btn-ghost">
				<?php esc_html_e( 'Last 7 days', 'ai-tamer' ); ?>
			</a>
			<a href="<?php echo esc_url( AuditReport::get_download_url( 30 ) ); ?>" class="aitamer-btn-primary">
				<?php esc_html_e( 'Last 30 days', 'ai-tamer' ); ?>
			</a>
			<a href="<?php echo esc_url( AuditReport::get_download_url( 90 ) ); ?>" class="aitamer-btn-ghost">
				<?php esc_html_e( 'Last 90 days', 'ai-tamer' ); ?>
			</a>
		</div>
		<p style="margin-top:16px;font-size:11px;">
			<?php
			printf(
				/* translators: %s: total records */
				esc_html__( 'There are currently %s access records available in the log database.', 'ai-tamer' ),
				'<strong style="color:var(--at-green);">' . esc_html( number_format_i18n( $aitamer_total ) ) . '</strong>'
			);
			?>
		</p>
	</div>

	<!-- Log Preview Table -->
	<?php
	// Filtering and Pagination logic.
	$aitamer_paged      = absint( $_GET['paged'] ?? 1 );
	$aitamer_bot_type   = sanitize_text_field( $_GET['bot_type'] ?? '' );
	$aitamer_protection = sanitize_text_field( $_GET['protection'] ?? '' );
	$aitamer_search     = sanitize_text_field( $_GET['s'] ?? '' );
	$aitamer_per_page   = 25;
	$aitamer_offset     = ( $aitamer_paged - 1 ) * $aitamer_per_page;

	$aitamer_filter_args = array(
		'limit'      => $aitamer_per_page,
		'offset'     => $aitamer_offset,
		'bot_type'   => $aitamer_bot_type,
		'protection' => $aitamer_protection,
		's'          => $aitamer_search,
	);

	$aitamer_recent      = Logger::get_logs( $aitamer_filter_args );
	$aitamer_count       = Logger::count_logs( $aitamer_filter_args );
	$aitamer_total_pages = ceil( $aitamer_count / $aitamer_per_page );
	?>

	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e( 'Secure Activity Log', 'ai-tamer' ); ?></h2>
			<span style="font-size:11px;color:var(--at-muted);">
				<?php printf( esc_html__( 'Showing %d of %s records', 'ai-tamer' ), count( $aitamer_recent ), number_format_i18n( $aitamer_count ) ); ?>
			</span>
		</div>

		<!-- Filter Bar -->
		<form method="get" class="aitamer-filter-bar">
			<input type="hidden" name="page" value="ai-tamer-audit">
			
			<div class="aitamer-filter-group">
				<label><?php esc_html_e( 'Search', 'ai-tamer' ); ?></label>
				<input type="text" name="s" value="<?php echo esc_attr( $aitamer_search ); ?>" placeholder="<?php esc_attr_e( 'URI or Bot Name...', 'ai-tamer' ); ?>" style="width:200px;">
			</div>

			<div class="aitamer-filter-group">
				<label><?php esc_html_e( 'Bot Type', 'ai-tamer' ); ?></label>
				<select name="bot_type">
					<option value=""><?php esc_html_e( 'All Types', 'ai-tamer' ); ?></option>
					<option value="search" <?php selected( $aitamer_bot_type, 'search' ); ?>><?php esc_html_e( 'Search Engine', 'ai-tamer' ); ?></option>
					<option value="training" <?php selected( $aitamer_bot_type, 'training' ); ?>><?php esc_html_e( 'AI Training', 'ai-tamer' ); ?></option>
					<option value="scraper" <?php selected( $aitamer_bot_type, 'scraper' ); ?>><?php esc_html_e( 'Aggressive Scraper', 'ai-tamer' ); ?></option>
				</select>
			</div>

			<div class="aitamer-filter-group">
				<label><?php esc_html_e( 'Protection', 'ai-tamer' ); ?></label>
				<select name="protection">
					<option value=""><?php esc_html_e( 'All Actions', 'ai-tamer' ); ?></option>
					<option value="none" <?php selected( $aitamer_protection, 'none' ); ?>><?php esc_html_e( 'None (Allowed)', 'ai-tamer' ); ?></option>
					<option value="blocked" <?php selected( $aitamer_protection, 'blocked' ); ?>><?php esc_html_e( 'Blocked (401)', 'ai-tamer' ); ?></option>
					<option value="payment_required" <?php selected( $aitamer_protection, 'payment_required' ); ?>><?php esc_html_e( 'Payment Req (402)', 'ai-tamer' ); ?></option>
					<option value="obfuscated" <?php selected( $aitamer_protection, 'obfuscated' ); ?>><?php esc_html_e( 'Obfuscated', 'ai-tamer' ); ?></option>
				</select>
			</div>

			<button type="submit" class="aitamer-btn-ghost"><?php esc_html_e( 'Filter', 'ai-tamer' ); ?></button>
			<?php if ( ! empty( $aitamer_search ) || ! empty( $aitamer_bot_type ) || ! empty( $aitamer_protection ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-tamer-audit' ) ); ?>" class="aitamer-btn-danger" style="border:none;"><?php esc_html_e( 'Clear', 'ai-tamer' ); ?></a>
			<?php endif; ?>
		</form>

		<?php if ( empty( $aitamer_recent ) ) : ?>
			<div class="aitamer-empty">
				<p><?php esc_html_e( 'No matching records found for these filters.', 'ai-tamer' ); ?></p>
			</div>
		<?php else : ?>
			<div class="aitamer-table-responsive">
				<table class="aitamer-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Timestamp', 'ai-tamer' ); ?></th>
							<th><?php esc_html_e( 'Bot Identity', 'ai-tamer' ); ?></th>
							<th><?php esc_html_e( 'IP Hash', 'ai-tamer' ); ?></th>
							<th><?php esc_html_e( 'URI Accessed', 'ai-tamer' ); ?></th>
							<th><?php esc_html_e( 'Intent', 'ai-tamer' ); ?></th>
							<th><?php esc_html_e( 'Protection', 'ai-tamer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $aitamer_recent as $aitamer_entry ) :
							$aitamer_type = $aitamer_entry['bot_type'] ?? 'search';
						?>
						<tr>
							<td class="mono" style="font-size:10px;"><?php echo esc_html( $aitamer_entry['created_at'] ); ?></td>
							<td>
								<strong><?php echo esc_html( $aitamer_entry['bot_name'] ); ?></strong>
								<div style="font-size:9px;color:var(--at-muted);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr($aitamer_entry['user_agent']); ?>">
									<?php echo esc_html( $aitamer_entry['user_agent'] ); ?>
								</div>
							</td>
							<td class="mono" style="font-size:9px;"><?php echo esc_html( substr($aitamer_entry['ip_hash'], 0, 8) ); ?>...</td>
							<td class="mono"><?php echo esc_html( $aitamer_entry['request_uri'] ); ?></td>
							<td><span class="aitamer-badge-status <?php echo esc_attr( $aitamer_type ); ?>"><?php echo esc_html( $aitamer_type ); ?></span></td>
							<td>
								<?php
								$aitamer_prot_label = $aitamer_entry['protection'] ?? 'none';
								$aitamer_prot_class = $aitamer_prot_label === 'none' ? '' : ( strpos($aitamer_prot_label, 'block') !== false ? 'blocked' : 'limited' );
								?>
								<span class="aitamer-badge-status <?php echo esc_attr($aitamer_prot_class); ?>" style="<?php echo empty($aitamer_prot_class) ? 'background:var(--at-border);color:var(--at-text);' : ''; ?>">
									<?php echo esc_html( $aitamer_prot_label ); ?>
								</span>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Pagination -->
			<?php if ( $aitamer_total_pages > 1 ) : ?>
				<div class="aitamer-pagination">
					<?php
					echo paginate_links( array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '&laquo; ' . __( 'Prev', 'ai-tamer' ),
						'next_text' => __( 'Next', 'ai-tamer' ) . ' &raquo;',
						'total'     => $aitamer_total_pages,
						'current'   => $aitamer_paged,
					) );
					?>
				</div>
			<?php endif; ?>

		<?php endif; ?>
	</div>

</div>
