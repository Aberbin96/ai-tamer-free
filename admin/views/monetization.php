<?php
/**
 * Monetization settings page view (Pro).
 *
 * @package Ai_Tamer
 */

use AiTamer\StripeManager;

defined( 'ABSPATH' ) || exit;

$settings = StripeManager::get_settings();
?>
<div class="wrap aitamer-wrap">

	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title"><?php esc_html_e( 'Monetization', 'ai-tamer' ); ?> <span class="aitamer-badge aitamer-badge-pro">PRO</span></h1>
			<p class="aitamer-page-desc"><?php esc_html_e( 'Set up automated licensing. Allow AI agents to purchase access tokens via Stripe.', 'ai-tamer' ); ?></p>
		</div>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields( 'aitamer_settings_group' ); ?>

		<div class="aitamer-grid">
			<div class="aitamer-card">
				<h2 class="aitamer-card-title"><?php esc_html_e( 'Stripe Configuration', 'ai-tamer' ); ?></h2>
				
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Monetization', 'ai-tamer' ); ?></th>
						<td>
							<input type="checkbox" name="aitamer_stripe_settings[enabled]" value="yes" <?php checked( $settings['enabled'], 'yes' ); ?> />
							<p class="description"><?php esc_html_e( 'If enabled, bots will see "Purchase" options in the license document.', 'ai-tamer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'ai-tamer' ); ?></th>
						<td>
							<select name="aitamer_stripe_settings[test_mode]">
								<option value="yes" <?php selected( $settings['test_mode'], 'yes' ); ?>><?php esc_html_e( 'Test Mode', 'ai-tamer' ); ?></option>
								<option value="no" <?php selected( $settings['test_mode'], 'no' ); ?>><?php esc_html_e( 'Live Mode', 'ai-tamer' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<hr />

				<h3><?php esc_html_e( 'Test API Keys', 'ai-tamer' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Test Publishable Key', 'ai-tamer' ); ?></th>
						<td><input type="text" name="aitamer_stripe_settings[test_publishable]" value="<?php echo esc_attr( $settings['test_publishable'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test Secret Key', 'ai-tamer' ); ?></th>
						<td><input type="password" name="aitamer_stripe_settings[test_secret]" value="<?php echo esc_attr( $settings['test_secret'] ); ?>" class="regular-text" /></td>
					</tr>
				</table>

				<hr />

				<h3><?php esc_html_e( 'Live API Keys', 'ai-tamer' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Live Publishable Key', 'ai-tamer' ); ?></th>
						<td><input type="text" name="aitamer_stripe_settings[live_publishable]" value="<?php echo esc_attr( $settings['live_publishable'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Live Secret Key', 'ai-tamer' ); ?></th>
						<td><input type="password" name="aitamer_stripe_settings[live_secret]" value="<?php echo esc_attr( $settings['live_secret'] ); ?>" class="regular-text" /></td>
					</tr>
				</table>
			</div>

			<div class="aitamer-card">
				<h2 class="aitamer-card-title"><?php esc_html_e( 'Product Settings', 'ai-tamer' ); ?></h2>
				<p><?php esc_html_e( 'Define the Stripe Price ID for a "Single Access Token" or a "Monthly Subscription".', 'ai-tamer' ); ?></p>
				
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Stripe Price ID', 'ai-tamer' ); ?></th>
						<td>
							<input type="text" name="aitamer_stripe_settings[price_id]" value="<?php echo esc_attr( $settings['price_id'] ); ?>" class="regular-text" placeholder="price_..." />
							<p class="description">
								<strong><?php esc_html_e( 'How to find this:', 'ai-tamer' ); ?></strong><br>
								<?php esc_html_e( 'Go to Stripe Dashboard > Products > [Your Product] > Price ID (starts with price_).', 'ai-tamer' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div class="aitamer-info-box" style="margin-top:20px; background: #fffcf0; border-left-color: #f1c40f;">
					<h3>💡 <?php esc_html_e( 'Pricing Recommendations', 'ai-tamer' ); ?></h3>
					<ul style="list-style:disc; margin-left: 20px;">
						<li><strong><?php esc_html_e( 'Micro-payments:', 'ai-tamer' ); ?></strong> <?php esc_html_e( 'Charge roughly $0.05 - $0.10 per full article ingestion to avoid price friction.', 'ai-tamer' ); ?></li>
						<li><strong><?php esc_html_e( 'Monthly Access:', 'ai-tamer' ); ?></strong> <?php esc_html_e( 'Standard SaaS rates ($19 - $49/mo) are recommended for high-authority niche sites.', 'ai-tamer' ); ?></li>
						<li><strong><?php esc_html_e( 'Note:', 'ai-tamer' ); ?></strong> <?php esc_html_e( 'Stripe supports fixed prices or usage-based billing. Ensure your Price ID matches your business model.', 'ai-tamer' ); ?></li>
					</ul>
				</div>

				<div class="aitamer-info-box" style="margin-top:20px;">
					<h3><?php esc_html_e( 'Webhook URL', 'ai-tamer' ); ?></h3>
					<p><?php esc_html_e( 'Configure your Stripe Webhook to point here:', 'ai-tamer' ); ?></p>
					<code><?php echo esc_url( home_url( '/wp-json/ai-tamer/v1/stripe/webhook' ) ); ?></code>
					<p class="description"><?php esc_html_e( 'Required events: checkout.session.completed', 'ai-tamer' ); ?></p>
				</div>
			</div>
		</div>

		<?php submit_button(); ?>
	</form>

</div>

<style>
.aitamer-info-box {
	background: #f0f6fb;
	padding: 15px;
	border-left: 4px solid #2271b1;
	border-radius: 4px;
}
.aitamer-info-box code { display: block; margin: 10px 0; background: #fff; padding: 5px; border: 1px solid #ccc; }
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
