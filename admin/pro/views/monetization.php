<?php

/**
 * Monetization settings page view (Pro).
 *
 * @package Ai_Tamer
 */

use AiTamer\BillingManager;
use AiTamer\Enums\TransactionStatus;

defined('ABSPATH') || exit;

$settings = get_option('aitamer_settings', array());
?>
<div class="wrap aitamer-wrap">
	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title"><?php esc_html_e('Monetization', 'ai-tamer'); ?></h1>
			<p class="aitamer-page-desc"><?php esc_html_e('Set up automated licensing and Lightning micro-payments.', 'ai-tamer'); ?></p>
		</div>
	</div>

	<?php
	\AiTamer\Admin::get_instance()->render_navigation_tabs();
	?>

	<?php
	// ── USDT P2P Analytics Widget ──────────────────────────────────────────────
	$usdt_address  = $settings['usdt_address'] ?? '';
	$usdt_network  = $settings['usdt_network'] ?? 'polygon';
	$usdt_enabled  = ! empty($usdt_address);

	// Load initial data from DB.
	global $wpdb;
	$billing_table = $wpdb->prefix . BillingManager::TABLE;
	$tolls_table   = $wpdb->prefix . 'aitamer_tolls';
	
	$total_usdt = (float) ($wpdb->get_var(
		$wpdb->prepare("SELECT SUM(amount_usdt) FROM `{$tolls_table}` WHERE status = %s", 'paid')
	) ?: 0);
	
	$total_tx = (int) ($wpdb->get_var(
		$wpdb->prepare("SELECT COUNT(*) FROM `{$tolls_table}` WHERE status = %s", 'paid')
	) ?: 0);

	$recent_tx = $wpdb->get_results(
		$wpdb->prepare("SELECT * FROM `{$tolls_table}` WHERE status = %s ORDER BY id DESC LIMIT 5", 'paid'),
		ARRAY_A
	) ?: array();
	?>

	<div class="aitamer-card" id="aitusdt-widget" style="margin-bottom: 30px;">
		<div class="aitamer-card-header" style="display:flex; align-items:center; gap:12px;">
			<h2 class="aitamer-card-title" style="margin:0; flex:1;">
				<span style="color:#26a17b;">₮</span> <?php esc_html_e('USDT P2P Analytics', 'ai-tamer'); ?>
				<span class="aitamer-badge" style="background:#26a17b;">NON-CUSTODIAL</span>
			</h2>
			<small style="color:#888; font-style:italic;"><?php esc_html_e('Direct Blockchain Payments', 'ai-tamer'); ?></small>
		</div>

		<?php if (! $usdt_enabled) : ?>
			<div class="aitamer-info-box" style="background:#fff8e5; border-left-color:#26a17b; margin-top:15px;">
				<p><?php esc_html_e('Configure your USDT Wallet Address in the settings below to start receiving direct payments from AI agents.', 'ai-tamer'); ?></p>
			</div>
		<?php else : ?>
			<div style="background:#f0fbf8; padding:10px; border-radius:4px; margin-top:15px; font-family:monospace; font-size:12px; color:#266353;">
				<strong><?php esc_html_e('Receiving Address:', 'ai-tamer'); ?></strong> <?php echo esc_html($usdt_address); ?> 
				<span class="aitamer-badge" style="background:#26a17b; font-size:9px; vertical-align:middle;"><?php echo esc_html(strtoupper($usdt_network)); ?></span>
			</div>
		<?php endif; ?>

		<!-- Metric row -->
		<div class="aitamer-metrics" style="margin-top:20px;">
			<div class="aitamer-metric" style="border-top:3px solid #26a17b;">
				<div class="aitamer-metric-label"><?php esc_html_e('Total USDT Earned', 'ai-tamer'); ?></div>
				<div class="aitamer-metric-value" style="color:#26a17b;"><?php echo esc_html(number_format_i18n($total_usdt, 4)); ?> <small style="font-size:14px;">USDT</small></div>
				<div class="aitamer-metric-sub"><?php esc_html_e('Direct P2P Revenue', 'ai-tamer'); ?></div>
			</div>
			<div class="aitamer-metric" style="border-top:3px solid #2271b1;">
				<div class="aitamer-metric-label"><?php esc_html_e('Verified Transactions', 'ai-tamer'); ?></div>
				<div class="aitamer-metric-value"><?php echo esc_html(number_format_i18n($total_tx)); ?></div>
				<div class="aitamer-metric-sub"><?php esc_html_e('Confirmed via Verifier API', 'ai-tamer'); ?></div>
			</div>
			<div class="aitamer-metric" style="border-top:3px solid #888;">
				<div class="aitamer-metric-label"><?php esc_html_e('Toll Precision', 'ai-tamer'); ?></div>
				<div class="aitamer-metric-value" style="font-size:18px;">6 Decimals</div>
				<div class="aitamer-metric-sub"><?php esc_html_e('Embedded Post-ID tracking', 'ai-tamer'); ?></div>
			</div>
		</div>

		<!-- Recent Transactions -->
		<div style="margin-top:25px;">
			<h3 style="margin-bottom:10px;"><?php esc_html_e('Recent USDT Payments', 'ai-tamer'); ?></h3>
			<div class="aitamer-table-responsive">
				<table class="aitamer-table">
					<thead>
						<tr>
							<th><?php esc_html_e('Date', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Network', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Amount', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Transaction Hash', 'ai-tamer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($recent_tx)) : ?>
							<tr>
								<td colspan="4" style="text-align:center; color:#999;">
									<?php esc_html_e('No USDT transactions found yet.', 'ai-tamer'); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ($recent_tx as $tx) : ?>
								<tr>
									<td class="mono"><?php echo esc_html(date_i18n((string) get_option('date_format') . ' ' . (string) get_option('time_format'), strtotime((string) ($tx['created_at'] ?? 'now')))); ?></td>
									<td><span class="aitamer-badge" style="background:#eee; color:#666;"><?php echo esc_html(strtoupper((string) ($tx['network'] ?? ''))); ?></span></td>
									<td style="font-weight:600; color:#26a17b;"><?php echo esc_html($tx['amount_usdt']); ?> <small>USDT</small></td>
									<td class="mono" title="<?php echo esc_attr((string) ($tx['transaction_hash'] ?? '')); ?>">
										<?php echo esc_html(substr((string) ($tx['transaction_hash'] ?? ''), 0, 10) . '…'); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="aitamer-card-header" style="margin-top:25px;">
			<h2 class="aitamer-card-title"><?php esc_html_e('Billing History', 'ai-tamer'); ?></h2>
			<span class="aitamer-badge"><?php esc_html_e('Recent Transactions', 'ai-tamer'); ?></span>
		</div>

		<?php
		$aitamer_billing_paged    = absint($_GET['billing_paged'] ?? 1);
		$aitamer_billing_search   = sanitize_text_field($_GET['billing_s'] ?? '');
		$aitamer_billing_per_page = 20;
		$aitamer_billing_offset   = ($aitamer_billing_paged - 1) * $aitamer_billing_per_page;

		$aitamer_billing_args = array(
			'limit'  => $aitamer_billing_per_page,
			'offset' => $aitamer_billing_offset,
			's'      => $aitamer_billing_search,
		);

		$billing_manager = \AiTamer\Plugin::get_instance()->get_component('billing_manager');
		$transactions    = $billing_manager ? $billing_manager->get_transactions($aitamer_billing_args) : array();
		$aitamer_billing_total = $billing_manager ? $billing_manager->count_transactions($aitamer_billing_args) : 0;
		$aitamer_billing_pages = ceil($aitamer_billing_total / $aitamer_billing_per_page);
		?>

		<!-- Billing Filter Bar -->
		<form method="get" class="aitamer-filter-bar" style="margin-top:20px;">
			<input type="hidden" name="page" value="ai-tamer-monetization">

			<div class="aitamer-filter-group">
				<label><?php esc_html_e('Search Agent', 'ai-tamer'); ?></label>
				<input type="text" name="billing_s" value="<?php echo esc_attr($aitamer_billing_search); ?>" placeholder="<?php esc_attr_e('Agent name...', 'ai-tamer'); ?>" style="width:200px;">
			</div>

			<button type="submit" class="aitamer-btn-ghost"><?php esc_html_e('Filter', 'ai-tamer'); ?></button>
			<?php if (! empty($aitamer_billing_search)) : ?>
				<a href="<?php echo esc_url(admin_url('admin.php?page=ai-tamer-monetization')); ?>" class="aitamer-btn-danger" style="border:none;"><?php esc_html_e('Clear', 'ai-tamer'); ?></a>
			<?php endif; ?>
		</form>
		<?php if (! empty($transactions)) : ?>
			<div class="aitamer-table-responsive">
				<table class="aitamer-table">
					<thead>
						<tr>
							<th><?php esc_html_e('Date', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Agent / Customer', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Amount', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Reference', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Status', 'ai-tamer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($transactions as $tx) : ?>
							<tr>
								<td class="mono"><?php echo esc_html(date_i18n((string) get_option('date_format') . ' ' . (string) get_option('time_format'), strtotime((string) ($tx['created_at'] ?? 'now')))); ?></td>
								<td><strong><?php echo esc_html((string) ($tx['agent_name'] ?? 'Unknown')); ?></strong></td>
								<td><?php echo esc_html(strtoupper((string) ($tx['currency'] ?? 'SAT')) . ' ' . number_format_i18n((float) ($tx['amount'] ?? 0), 2)); ?></td>
								<td class="mono"><?php echo esc_html((string) ($tx['provider_id'] ?? '—')); ?></td>
								<td>
									<span class="aitamer-badge-status <?php echo (TransactionStatus::COMPLETED->value === $tx['status']) ? 'allowed' : 'expired'; ?>">
										<?php
										$status_label = (string) ($tx['status'] ?? 'pending');
										if (TransactionStatus::COMPLETED->value === $status_label) {
											$status_label = __('Completed', 'ai-tamer');
										} elseif (TransactionStatus::PENDING->value === $status_label) {
											$status_label = __('Pending', 'ai-tamer');
										} elseif (TransactionStatus::EXPIRED->value === $status_label) {
											$status_label = __('Expired', 'ai-tamer');
										} elseif (TransactionStatus::FAILED->value === $status_label) {
											$status_label = __('Failed', 'ai-tamer');
										}
										echo esc_html((string) $status_label);
										?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ($aitamer_billing_pages > 1) : ?>
				<div class="aitamer-pagination">
					<?php
					echo paginate_links(array(
						'base'      => add_query_arg('billing_paged', '%#%', admin_url('admin.php?page=ai-tamer-monetization')),
						'format'    => '',
						'prev_text' => '&laquo; ' . __('Prev', 'ai-tamer'),
						'next_text' => __('Next', 'ai-tamer') . ' &raquo;',
						'total'     => $aitamer_billing_pages,
						'current'   => $aitamer_billing_paged,
					));
					?>
				</div>
			<?php endif; ?>

		<?php else : ?>
			<div class="aitamer-empty">
				<p><?php esc_html_e('No transactions found yet. Once a bot purchases a license, it will appear here.', 'ai-tamer'); ?></p>
			</div>
		<?php endif; ?>
	</div>

	<style>
		@keyframes aitlnx-pulse {

			0%,
			100% {
				opacity: 1;
				transform: scale(1);
			}

			50% {
				opacity: .4;
				transform: scale(1.25);
			}
		}

		#aitlnx-live-dot.aitlnx-pulsing {
			animation: aitlnx-pulse 1.2s ease-in-out infinite;
		}
	</style>
</div>

<style>
	.aitamer-info-box {
		background: #f0f6fb;
		padding: 15px;
		border-left: 4px solid #2271b1;
		border-radius: 4px;
	}

	.aitamer-info-box code {
		display: block;
		margin: 10px 0;
		background: #fff;
		padding: 5px;
		border: 1px solid #ccc;
	}

	.aitamer-badge-pro {
		background: #d63638;
		color: #fff;
		font-size: 10px;
		padding: 2px 6px;
		border-radius: 4px;
		vertical-align: middle;
		margin-left: 10px;
	}
</style>
