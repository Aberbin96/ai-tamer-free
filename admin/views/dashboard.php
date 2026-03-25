<?php
/**
 * Admin dashboard view — Command Center.
 *
 * @package Ai_Tamer
 */

use AiTamer\Logger;

defined( 'ABSPATH' ) || exit;

$stats    = Logger::get_stats( 10 );
$total    = (int) ( $stats['total'] ?? 0 );
$blocked  = 0;
$training = 0;
foreach ( ( $stats['top_bots'] ?? array() ) as $bot ) {
	if ( in_array( $bot['bot_type'], array( 'training', 'scraper' ), true ) ) {
		$training += (int) $bot['hits'];
	}
}
// Sovereignty Score: simple heuristic (higher blocked ratio = better)
$score = $total > 0 ? min( 100, max( 0, round( 100 - ( $training / $total * 50 ) ) ) ) : 100;
?>
<div class="wrap aitamer-wrap">

	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title">
				<?php esc_html_e( 'AI Tamer', 'ai-tamer' ); ?>
				<span class="aitamer-badge"><?php esc_html_e( 'Active', 'ai-tamer' ); ?></span>
			</h1>
			<p class="aitamer-page-desc"><?php esc_html_e( 'Your content sovereignty command center.', 'ai-tamer' ); ?></p>
		</div>
	</div>

	<!-- Metrics Row -->
	<div class="aitamer-metrics">
		<div class="aitamer-metric green">
			<div class="aitamer-metric-label"><?php esc_html_e( 'Total Detections', 'ai-tamer' ); ?></div>
			<div class="aitamer-metric-value"><?php echo esc_html( number_format_i18n( $total ) ); ?></div>
			<div class="aitamer-metric-sub"><?php esc_html_e( 'AI requests logged', 'ai-tamer' ); ?></div>
		</div>
		<div class="aitamer-metric red">
			<div class="aitamer-metric-label"><?php esc_html_e( 'Training Bots', 'ai-tamer' ); ?></div>
			<div class="aitamer-metric-value"><?php echo esc_html( number_format_i18n( $training ) ); ?></div>
			<div class="aitamer-metric-sub"><?php esc_html_e( 'Intercepted scraping hits', 'ai-tamer' ); ?></div>
		</div>
		<div class="aitamer-metric blue">
			<div class="aitamer-metric-label"><?php esc_html_e( 'Sovereignty Score', 'ai-tamer' ); ?></div>
			<div class="aitamer-metric-value"><?php echo esc_html( $score ); ?>%</div>
			<div class="aitamer-metric-sub"><?php esc_html_e( 'Protection health', 'ai-tamer' ); ?></div>
		</div>
		<div class="aitamer-metric amber">
			<div class="aitamer-metric-label"><?php esc_html_e( 'Status', 'ai-tamer' ); ?></div>
			<div class="aitamer-metric-value" style="font-size:16px;">
				<span class="aitamer-status-dot"></span><?php esc_html_e( 'Live', 'ai-tamer' ); ?>
			</div>
			<div class="aitamer-metric-sub"><?php esc_html_e( 'Headers, meta & robots.txt', 'ai-tamer' ); ?></div>
		</div>
	</div>

	<!-- Top Bots -->
	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e( 'Top AI Bots by Activity', 'ai-tamer' ); ?></h2>
		</div>
		<?php if ( empty( $stats['top_bots'] ) ) : ?>
			<div class="aitamer-empty">
				<p><?php esc_html_e( 'No AI bot activity recorded yet. Logs will appear after the first detected bot visit.', 'ai-tamer' ); ?></p>
			</div>
		<?php else : ?>
			<div class="aitamer-table-responsive">
				<table class="aitamer-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Bot Name', 'ai-tamer' ); ?></th>
							<th><?php esc_html_e( 'Intent', 'ai-tamer' ); ?></th>
							<th><?php esc_html_e( 'Requests', 'ai-tamer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stats['top_bots'] as $bot ) :
							$type = $bot['bot_type'] ?? 'search';
						?>
						<tr>
							<td><strong><?php echo esc_html( $bot['bot_name'] ); ?></strong></td>
							<td><span class="aitamer-badge-status <?php echo esc_attr( $type ); ?>"><?php echo esc_html( $type ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( (int) $bot['hits'] ) ); ?></td>
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
			<h2><?php esc_html_e( 'Most Targeted Content', 'ai-tamer' ); ?></h2>
		</div>
		<?php if ( empty( $stats['top_posts'] ) ) : ?>
			<div class="aitamer-empty">
				<p><?php esc_html_e( 'No content-specific activity recorded yet.', 'ai-tamer' ); ?></p>
			</div>
		<?php else : ?>
			<div class="aitamer-table-responsive">
				<table class="aitamer-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post / URL', 'ai-tamer' ); ?></th>
							<th><?php esc_html_e( 'Requests', 'ai-tamer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stats['top_posts'] as $row ) :
							$post_id    = (int) $row['post_id'];
							$post_title = get_the_title( $post_id );
							$edit_link  = get_edit_post_link( $post_id );
						?>
						<tr>
							<td>
								<?php if ( $edit_link ) : ?>
									<a href="<?php echo esc_url( $edit_link ); ?>" style="color:var(--at-blue);"><?php echo esc_html( $post_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $post_title ?: "Post #{$post_id}" ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

</div>
