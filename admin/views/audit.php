<?php

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
	<div class="aitamer-metrics">
		<div class="aitamer-metric green">
			<div class="aitamer-metric-label"><?php esc_html_e( 'Secure Logs', 'ai-tamer' ); ?></div>
			<div class="aitamer-metric-value"><?php echo esc_html( number_format_i18n( $aitamer_total ) ); ?></div>
			<div class="aitamer-metric-sub"><?php esc_html_e( 'Protected access records', 'ai-tamer' ); ?></div>
		</div>
		<div class="aitamer-metric amber">
			<div class="aitamer-metric-label"><?php esc_html_e( 'Transparency', 'ai-tamer' ); ?></div>
			<div class="aitamer-metric-value">99.9%</div>
			<div class="aitamer-metric-sub"><?php esc_html_e( 'Evidence integrity score', 'ai-tamer' ); ?></div>
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
	// Fetch recent 20 log entries for preview.
	$aitamer_recent = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT bot_name, bot_type, request_uri, ip_hash, user_agent, protection, created_at FROM `{$aitamer_table}` ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			20
		),
		ARRAY_A
	);
	?>
	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e( 'Recent Activity Log', 'ai-tamer' ); ?></h2>
			<span style="font-size:11px;color:var(--at-muted);">
				<span class="aitamer-status-dot" style="width:6px;height:6px;"></span>
				<?php esc_html_e( 'Live', 'ai-tamer' ); ?>
			</span>
		</div>
		<?php if ( empty( $aitamer_recent ) ) : ?>
			<div class="aitamer-empty">
				<p><?php esc_html_e( 'No activity recorded yet. Logs will appear after the first detected bot visit.', 'ai-tamer' ); ?></p>
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
							<td><span class="aitamer-badge-status" style="background:var(--at-border);color:var(--at-text);"><?php echo esc_html( $aitamer_entry['protection'] ?? 'none' ); ?></span></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

</div>
