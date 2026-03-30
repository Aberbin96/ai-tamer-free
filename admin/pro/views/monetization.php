<?php

/**
 * Monetization settings page view (Pro).
 *
 * @package Ai_Tamer
 */

use AiTamer\StripeManager;

defined('ABSPATH') || exit;

$settings = StripeManager::get_settings();
?>
<div class="wrap aitamer-wrap">

	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title"><?php esc_html_e('Monetization', 'ai-tamer'); ?> <span class="aitamer-badge aitamer-badge-pro">PRO</span></h1>
			<p class="aitamer-page-desc"><?php esc_html_e('Set up automated licensing. Allow AI agents to purchase access tokens via Stripe.', 'ai-tamer'); ?></p>
		</div>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields('aitamer_settings_group'); ?>

		<!-- Strategic Recommendation Box -->
		<div class="aitamer-info-box" style="margin-bottom: 30px; background: #f0f6fb; border-left: 4px solid #2271b1;">
			<h2 style="margin-top: 0; color: #2271b1;"><span class="dashicons dashicons-lightbulb" style="vertical-align: middle; margin-right: 5px;"></span> <?php esc_html_e('Strategic Recommendation (2026)', 'ai-tamer'); ?></h2>
			<p><?php esc_html_e('To maximize revenue while minimizing fee loss, we recommend a phased approach:', 'ai-tamer'); ?></p>
			<div style="display: flex; gap: 20px; margin-top: 15px;">
				<div style="flex: 1; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
					<h3 style="margin-top: 0;">V1: Blocking & Logs</h3>
					<p class="description"><?php esc_html_e('Baseline control. Understand bot behavior before charging.', 'ai-tamer'); ?></p>
				</div>
				<div style="flex: 1; padding: 15px; background: #e7f5fe; border: 1px solid #2271b1; border-radius: 4px; position: relative;">
					<span style="position: absolute; top: -10px; right: 10px; background: #2271b1; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: bold;"><?php esc_html_e('RECOMMENDED', 'ai-tamer'); ?></span>
					<h3 style="margin-top: 0;">V2: Reading Vouchers</h3>
					<p class="description"><?php esc_html_e('Best for cash flow. Avoids the Stripe 0.50€ minimum by selling credits in bulk.', 'ai-tamer'); ?></p>
				</div>
				<div style="flex: 1; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; opacity: 0.6;">
					<h3 style="margin-top: 0;">V3: Money Streaming</h3>
					<p class="description"><?php esc_html_e('The future of autonomous agents. Real-time payments (Lightning/L402).', 'ai-tamer'); ?></p>
				</div>
			</div>
		</div>

		<div class="aitamer-grid">
			<div class="aitamer-card">
				<h2 class="aitamer-card-title"><?php esc_html_e('Stripe Configuration', 'ai-tamer'); ?></h2>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Enable Monetization', 'ai-tamer'); ?></th>
						<td>
							<input type="checkbox" name="aitamer_stripe_settings[enabled]" value="yes" <?php checked($settings['enabled'], 'yes'); ?> />
							<p class="description"><?php esc_html_e('If enabled, bots will see "Purchase" options in the license document.', 'ai-tamer'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Mode', 'ai-tamer'); ?></th>
						<td>
							<select name="aitamer_stripe_settings[test_mode]">
								<option value="yes" <?php selected($settings['test_mode'], 'yes'); ?>><?php esc_html_e('Test Mode', 'ai-tamer'); ?></option>
								<option value="no" <?php selected($settings['test_mode'], 'no'); ?>><?php esc_html_e('Live Mode', 'ai-tamer'); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<hr />

				<h3><?php esc_html_e('Test API Keys', 'ai-tamer'); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Test Publishable Key', 'ai-tamer'); ?></th>
						<td><input type="text" name="aitamer_stripe_settings[test_publishable]" value="<?php echo esc_attr($settings['test_publishable']); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Test Secret Key', 'ai-tamer'); ?></th>
						<td><input type="password" name="aitamer_stripe_settings[test_secret]" value="<?php echo esc_attr($settings['test_secret']); ?>" class="regular-text" /></td>
					</tr>
				</table>

				<hr />

				<h3><?php esc_html_e('Live API Keys', 'ai-tamer'); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('Live Publishable Key', 'ai-tamer'); ?></th>
						<td><input type="text" name="aitamer_stripe_settings[live_publishable]" value="<?php echo esc_attr($settings['live_publishable']); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Live Secret Key', 'ai-tamer'); ?></th>
						<td><input type="password" name="aitamer_stripe_settings[live_secret]" value="<?php echo esc_attr($settings['live_secret']); ?>" class="regular-text" /></td>
					</tr>
				</table>
			</div>

			<div class="aitamer-card">
				<h2 class="aitamer-card-title"><?php esc_html_e('Licensing Models & Products', 'ai-tamer'); ?></h2>
				<p><?php esc_html_e('Define the Stripe Price IDs for your different access tiers.', 'ai-tamer'); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e('V2: Reading Voucher (Prepaid)', 'ai-tamer'); ?></th>
						<td>
							<input type="text" name="aitamer_stripe_settings[price_id_voucher]" value="<?php echo esc_attr($settings['price_id_voucher'] ?? ''); ?>" class="regular-text" placeholder="price_..." />
							<p class="description">
								<strong><?php esc_html_e('Recommended Path:', 'ai-tamer'); ?></strong> <?php esc_html_e('Sells a pack of readings (credits) at once. Prevents transaction fees from consuming small payments.', 'ai-tamer'); ?>
							</p>
							<div style="margin-top:10px;">
								<label style="display:inline-block; width:180px;"><?php esc_html_e('Initial Credits per Voucher:', 'ai-tamer'); ?></label>
								<input type="number" name="aitamer_stripe_settings[voucher_credits]" value="<?php echo esc_attr($settings['voucher_credits'] ?? 1000); ?>" class="small-text" />
								<span class="description"><?php esc_html_e('(0 = Unlimited)', 'ai-tamer'); ?></span>
							</div>
							<div style="margin-top:10px;">
								<label style="display:inline-block; width:180px;"><?php esc_html_e('Validity (Days):', 'ai-tamer'); ?></label>
								<input type="number" name="aitamer_stripe_settings[voucher_validity_days]" value="<?php echo esc_attr($settings['voucher_validity_days'] ?? 365); ?>" class="small-text" />
								<span class="description"><?php esc_html_e('(0 = Forever)', 'ai-tamer'); ?></span>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('V2: Enterprise Monthly Access', 'ai-tamer'); ?></th>
						<td>
							<input type="text" name="aitamer_stripe_settings[price_id]" value="<?php echo esc_attr($settings['price_id']); ?>" class="regular-text" placeholder="price_..." />
							<p class="description">
								<?php esc_html_e('Monthly recurring subscription for high-volume corporate bots.', 'ai-tamer'); ?>
							</p>
						</td>
					</tr>
					<!-- V1: Single Article removed as per request -->
				</table>

				<div class="aitamer-info-box" style="margin-top:20px; background: #fffcf0; border-left-color: #f1c40f;">
					<h3>💡 <?php esc_html_e('Pricing Recommendations', 'ai-tamer'); ?></h3>
					<ul style="list-style:disc; margin-left: 20px;">
						<li><strong><?php esc_html_e('Reading Vouchers (V2):', 'ai-tamer'); ?></strong> <?php esc_html_e('Recommended for most sites. Prevents transaction fee loss by selling bulk credits (e.g., $10 for 1,000 readings).', 'ai-tamer'); ?></li>
						<li><strong><?php esc_html_e('Monthly Access:', 'ai-tamer'); ?></strong> <?php esc_html_e('Standard SaaS rates ($19 - $49/mo) are recommended for high-authority niche sites or enterprise LLM crawlers.', 'ai-tamer'); ?></li>
						<li><strong><?php esc_html_e('Note:', 'ai-tamer'); ?></strong> <?php esc_html_e('Stripe supports fixed prices or usage-based billing. Ensure your Price ID matches your business model in the Stripe Dashboard.', 'ai-tamer'); ?></li>
					</ul>
				</div>

				<div class="aitamer-info-box" style="margin-top:20px;">
					<h3><?php esc_html_e('Webhook URL', 'ai-tamer'); ?></h3>
					<p><?php esc_html_e('Configure your Stripe Webhook to point here:', 'ai-tamer'); ?></p>
					<code><?php echo esc_url(home_url('/wp-json/ai-tamer/v1/stripe/webhook')); ?></code>
					<p class="description"><?php esc_html_e('Required events: checkout.session.completed', 'ai-tamer'); ?></p>
				</div>
			</div>
		</div>

		<?php
		// Render Web3 Data Toll and other sections registered to this page.
		do_settings_sections('ai-tamer-monetization');
		?>

		<?php submit_button(); ?>
	</form>

	<hr />

	<div class="aitamer-card">
		<div class="aitamer-card-header">
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

		$stripe_manager = \AiTamer\Plugin::get_instance()->get_component('stripe_manager');
		$transactions   = $stripe_manager ? $stripe_manager->get_transactions($aitamer_billing_args) : array();
		$aitamer_billing_total = $stripe_manager ? $stripe_manager->count_transactions($aitamer_billing_args) : 0;
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
								<td class="mono"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($tx['created_at']))); ?></td>
								<td><strong><?php echo esc_html($tx['agent_name']); ?></strong></td>
								<td><?php echo esc_html(strtoupper($tx['currency']) . ' ' . number_format_i18n($tx['amount'], 2)); ?></td>
								<td class="mono"><?php echo esc_html($tx['provider_id']); ?></td>
								<td>
									<span class="aitamer-badge-status <?php echo ('completed' === $tx['status']) ? 'allowed' : 'expired'; ?>">
										<?php echo esc_html($tx['status']); ?>
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
						'base'      => add_query_arg('billing_paged', '%#%'),
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

</div>

<style>
	/* ... existing styles ... */
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
