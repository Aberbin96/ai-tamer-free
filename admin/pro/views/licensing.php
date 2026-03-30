<?php

/**
 * Licensing admin page view.
 *
 * @package Ai_Tamer
 */

use AiTamer\LicenseVerifier;
use AiTamer\Enums\LicenseScope;

defined('ABSPATH') || exit;

// Get success data from redirects.
$aitamer_issued_token = get_transient('aitamer_last_issued_token');
if ($aitamer_issued_token) {
	delete_transient('aitamer_last_issued_token');
}

// Filtering and Pagination logic.
$aitamer_paged    = absint( $_GET['paged'] ?? 1 );
$aitamer_search   = sanitize_text_field( $_GET['s'] ?? '' );
$aitamer_scope    = sanitize_text_field( $_GET['scope'] ?? '' );
$aitamer_per_page = 15;
$aitamer_offset   = ( $aitamer_paged - 1 ) * $aitamer_per_page;

$aitamer_filter_args = array(
	'limit'  => $aitamer_per_page,
	'offset' => $aitamer_offset,
	's'      => $aitamer_search,
	'scope'  => $aitamer_scope,
);

$aitamer_tokens      = LicenseVerifier::get_tokens( $aitamer_filter_args );
$aitamer_count       = LicenseVerifier::count_tokens( $aitamer_filter_args );
$aitamer_total_pages = ceil( $aitamer_count / $aitamer_per_page );

// API Documentation data.
$aitamer_rest_url    = get_rest_url(null, 'ai-tamer/v1');
$aitamer_license_url = $aitamer_rest_url . '/license';
$aitamer_content_url = $aitamer_rest_url . '/content/{post_id}';
$aitamer_sample_token = 'your-hmac-token-here';
$aitamer_scope_type  = \AiTamer\Enums\LicenseScope::GLOBAL->value;
$aitamer_scope_id    = '';
?>
<div class="wrap aitamer-wrap">

	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title"><?php esc_html_e('Licensing & API', 'ai-tamer'); ?></h1>
			<p class="aitamer-page-desc"><?php esc_html_e('Issue HMAC-signed tokens to trusted AI agents for authorized access and programmatic control.', 'ai-tamer'); ?></p>
		</div>
	</div>

	<?php if (isset($_GET['aitamer_revoked'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e('Token revoked successfully.', 'ai-tamer'); ?></p>
		</div>
	<?php endif; ?>

	<?php if (isset($_GET['aitamer_issued']) && $aitamer_issued_token) : ?>
		<div class="notice notice-success">
			<p><strong><?php esc_html_e('Token issued. Share this securely with the licensee.', 'ai-tamer'); ?></strong></p>
			<code class="aitamer-token-block"><?php echo esc_html($aitamer_issued_token); ?></code>
			<p style="font-size:11px;margin-top:8px;color:var(--at-muted);">
				<?php esc_html_e('The agent sends this as: X-AI-License-Token: [token]', 'ai-tamer'); ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Issued Tokens Table -->
	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e( 'Active License Tokens', 'ai-tamer' ); ?></h2>
			<span style="font-size:11px;color:var(--at-muted);">
				<?php printf( esc_html__( 'Showing %d of %s records', 'ai-tamer' ), count( $aitamer_tokens ), number_format_i18n( $aitamer_count ) ); ?>
			</span>
		</div>

		<!-- Filter Bar -->
		<form method="get" class="aitamer-filter-bar">
			<input type="hidden" name="page" value="ai-tamer-licensing">
			
			<div class="aitamer-filter-group">
				<label><?php esc_html_e( 'Search', 'ai-tamer' ); ?></label>
				<input type="text" name="s" value="<?php echo esc_attr( $aitamer_search ); ?>" placeholder="<?php esc_attr_e( 'Agent or Token...', 'ai-tamer' ); ?>" style="width:200px;">
			</div>

			<div class="aitamer-filter-group">
				<label><?php esc_html_e( 'Scope', 'ai-tamer' ); ?></label>
				<select name="scope">
					<option value=""><?php esc_html_e( 'All Scopes', 'ai-tamer' ); ?></option>
					<option value="global" <?php selected( $aitamer_scope, 'global' ); ?>><?php esc_html_e( 'Global', 'ai-tamer' ); ?></option>
					<?php 
						// Optionally allow filtering by specific post/cat if they exist in tokens? 
						// For now, let's keep it simple: Global or Other.
					?>
				</select>
			</div>

			<button type="submit" class="aitamer-btn-ghost"><?php esc_html_e( 'Filter', 'ai-tamer' ); ?></button>
			<?php if ( ! empty( $aitamer_search ) || ! empty( $aitamer_scope ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-tamer-licensing' ) ); ?>" class="aitamer-btn-danger" style="border:none;"><?php esc_html_e( 'Clear', 'ai-tamer' ); ?></a>
			<?php endif; ?>
		</form>
		<?php if (empty($aitamer_tokens)) : ?>
			<div class="aitamer-empty">
				<p><?php esc_html_e('No tokens issued yet. Use the form below to authorize a trusted AI agent.', 'ai-tamer'); ?></p>
			</div>
		<?php else : ?>
			<div class="aitamer-table-responsive">
				<table class="aitamer-table">
					<thead>
						<tr>
							<th><?php esc_html_e('Agent', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Issued', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Expires', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Scope', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Subscription', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Credits', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Status', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Token (preview)', 'ai-tamer'); ?></th>
							<th><?php esc_html_e('Actions', 'ai-tamer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($aitamer_tokens as $aitamer_i => $aitamer_t) :
							$aitamer_is_expired = (int) $aitamer_t['exp'] < time();
						?>
							<tr>
								<td><strong><?php echo esc_html($aitamer_t['agent']); ?></strong></td>
								<td class="mono"><?php echo esc_html(wp_date('Y-m-d', $aitamer_t['issued_at'])); ?></td>
								<td class="mono"><?php echo esc_html(wp_date('Y-m-d', $aitamer_t['exp'])); ?></td>
								<td>
									<?php 
										$aitamer_scope = $aitamer_t['scope'] ?? LicenseScope::GLOBAL->value;
										if (LicenseScope::GLOBAL->value === $aitamer_scope) {
											echo '<span class="aitamer-badge-pro" style="background:var(--at-surface-2);color:var(--at-text);border:1px solid var(--at-border);">' . esc_html__('Global', 'ai-tamer') . '</span>';
										} else {
											echo '<code>' . esc_html($aitamer_scope) . '</code>';
										}
									?>
								</td>
								<td class="mono">
									<?php if (!empty($aitamer_t['sub_id'])) : ?>
										<span title="<?php echo esc_attr($aitamer_t['sub_id']); ?>">
											<?php echo esc_html(substr($aitamer_t['sub_id'], 0, 8) . '…'); ?>
										</span>
									<?php else : ?>
										<span style="color:var(--at-muted);"><?php esc_html_e('Direct', 'ai-tamer'); ?></span>
									<?php endif; ?>
								</td>
								<td class="mono">
									<?php 
										if (! empty($aitamer_t['is_voucher']) && ! empty($aitamer_t['uid'])) {
											global $wpdb;
											$table = $wpdb->prefix . 'aitamer_wallets';
											$balance = $wpdb->get_var($wpdb->prepare(
												"SELECT balance FROM {$table} WHERE token_id = %s",
												$aitamer_t['uid']
											));
											if (null !== $balance) {
												echo '<strong>' . (int)$balance . '</strong>';
											} else {
												echo '<span style="color:var(--at-muted);">–</span>';
											}
										} else {
											echo '<span style="color:var(--at-muted);">Unlimited</span>';
										}
									?>
								</td>
								<td>
									<span class="aitamer-badge-status <?php echo $aitamer_is_expired ? 'expired' : 'active'; ?>">
										<?php echo $aitamer_is_expired ? esc_html__('Expired', 'ai-tamer') : esc_html__('Active', 'ai-tamer'); ?>
									</span>
								</td>
								<td><code><?php echo esc_html(substr($aitamer_t['token'], 0, 28) . '…'); ?></code></td>
								<td>
									<form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e('Revoke this token?', 'ai-tamer'); ?>');">
										<?php wp_nonce_field('aitamer_revoke_token', 'aitamer_revoke_nonce'); ?>
										<input type="hidden" name="revoke_index" value="<?php echo esc_attr($aitamer_i); ?>">
										<button type="submit" class="aitamer-btn-danger"><?php esc_html_e('Revoke', 'ai-tamer'); ?></button>
									</form>
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

	<!-- Issue New Token -->
	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e('Issue New Token', 'ai-tamer'); ?></h2>
		</div>
		<form method="post">
			<?php wp_nonce_field('aitamer_issue_token', 'aitamer_issue_token_nonce'); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="agent_name"><?php esc_html_e('Agent Name', 'ai-tamer'); ?></label>
					</th>
					<td>
						<input type="text" id="agent_name" name="agent_name" class="regular-text"
							placeholder="<?php esc_attr_e('e.g. GPTBot, MyResearchBot', 'ai-tamer'); ?>" required>
						<p class="description"><?php esc_html_e('The User-Agent name of the licensed crawler.', 'ai-tamer'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="days"><?php esc_html_e('Validity (days)', 'ai-tamer'); ?></label>
					</th>
					<td>
						<input type="number" id="days" name="days" value="365" min="0" max="3650" class="small-text">
						<span class="description"><?php esc_html_e('(0 = Forever)', 'ai-tamer'); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="sub_id"><?php esc_html_e('Stripe Subscription ID', 'ai-tamer'); ?></label>
					</th>
					<td>
						<input type="text" id="sub_id" name="sub_id" class="regular-text" placeholder="sub_1...">
						<p class="description"><?php esc_html_e('Optional. If provided, the token will be invalidated automatically if the subscription is canceled.', 'ai-tamer'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="credits"><?php esc_html_e('Reading Voucher (Credits)', 'ai-tamer'); ?></label>
					</th>
					<td>
						<input type="number" id="credits" name="credits" value="0" min="0" class="small-text">
						<p class="description"><?php esc_html_e('Setting this above 0 turns the token into a "Reading Voucher". For unlimited access, leave at 0.', 'ai-tamer'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="scope_type"><?php esc_html_e('Access Scope', 'ai-tamer'); ?></label>
					</th>
					<td>
						<select id="scope_type" name="scope_type" onchange="document.getElementById('scope_id_row').style.display = (this.value === '<?php echo esc_attr(LicenseScope::GLOBAL->value); ?>') ? 'none' : 'table-row';">
							<?php
								foreach (LicenseScope::cases() as $case) {
									$label = match($case) {
										LicenseScope::GLOBAL   => __('Global (All Content)', 'ai-tamer'),
										LicenseScope::POST     => __('Single Post/Page', 'ai-tamer'),
										LicenseScope::CATEGORY => __('Category', 'ai-tamer'),
									};
									printf('<option value="%s" %s>%s</option>', esc_attr($case->value), selected($aitamer_scope_type, $case->value, false), esc_html($label));
								}
							?>
						</select>
					</td>
				</tr>
				<tr id="scope_id_row" style="display:none;">
					<th scope="row">
						<label for="scope_id"><?php esc_html_e('Scope ID (Post or Category ID)', 'ai-tamer'); ?></label>
					</th>
					<td>
						<input type="number" id="scope_id" name="scope_id" class="small-text">
						<p class="description"><?php esc_html_e('Enter the numeric ID of the post or category you want to authorize.', 'ai-tamer'); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(__('Generate Token', 'ai-tamer')); ?>
		</form>

		<h3 class="aitamer-section-title"><?php esc_html_e('How It Works', 'ai-tamer'); ?></h3>
		<ol style="color:var(--at-muted);font-size:12px;padding-left:20px;line-height:1.8;">
			<li><?php esc_html_e('You issue a signed HMAC token to a trusted AI company.', 'ai-tamer'); ?></li>
			<li><?php esc_html_e('They send it in every request as the X-AI-License-Token header.', 'ai-tamer'); ?></li>
			<li><?php esc_html_e('AI Tamer verifies the signature and bypasses blocking for that agent.', 'ai-tamer'); ?></li>
			<li><?php esc_html_e('Tokens expire automatically after the configured period.', 'ai-tamer'); ?></li>
		</ol>
	</div>

	<!-- API Documentation (Pro) -->
	<div class="aitamer-page-header" style="margin-top: 48px; border-top: 1px solid var(--at-border); padding-top: 24px;">
		<div>
			<h2 class="aitamer-page-title"><?php esc_html_e('API Access Documentation', 'ai-tamer'); ?> <span class="aitamer-badge-pro">PRO</span></h2>
			<p class="aitamer-page-desc"><?php esc_html_e('Programmatic access for licensed AI agents. Serve clean, structured content directly.', 'ai-tamer'); ?></p>
		</div>
	</div>

	<div class="aitamer-grid">
		<div class="aitamer-card">
			<h2 class="aitamer-card-title"><?php esc_html_e('Endpoints', 'ai-tamer'); ?></h2>

			<div class="aitamer-endpoint-group">
				<h3><?php esc_html_e('Machine-Readable License', 'ai-tamer'); ?></h3>
				<code>GET <?php echo esc_url($aitamer_license_url); ?></code>
				<p class="description"><?php esc_html_e('Public endpoint describing usage terms and how to request access.', 'ai-tamer'); ?></p>
			</div>

			<hr style="border:0; border-top: 1px solid var(--at-border); margin: 20px 0;" />

			<div class="aitamer-endpoint-group">
				<h3><?php esc_html_e('Structured Content', 'ai-tamer'); ?></h3>
				<code>GET <?php echo esc_url($aitamer_content_url); ?></code>
				<p class="description"><?php esc_html_e('Authenticated endpoint serving clean post content in JSON format.', 'ai-tamer'); ?></p>
			</div>
		</div>

		<div class="aitamer-card">
			<h2 class="aitamer-card-title"><?php esc_html_e('How to Authenticate', 'ai-tamer'); ?></h2>
			<p><?php esc_html_e('AI agents must present a valid HMAC-signed token in the headers of each request.', 'ai-tamer'); ?></p>

			<div class="aitamer-code-block">
				<pre>X-AI-License-Token: <?php echo esc_html($aitamer_sample_token); ?></pre>
			</div>

			<p class="description" style="margin-top: 10px;">
				<?php esc_html_e('Use the "Active License Tokens" table above to manage your HMAC keys.', 'ai-tamer'); ?>
			</p>
		</div>
	</div>

	<div class="aitamer-card" style="margin-top: 24px;">
		<p><?php esc_html_e('AI agents must present a valid HMAC-signed token in the headers of each request. You can use either the numeric Post ID or the post slug in the URL.', 'ai-tamer'); ?></p>

		<div class="aitamer-code-block">
			<pre>curl -X GET "<?php echo esc_url(str_replace('{post_id}', '12', $aitamer_content_url)); ?>" \
     -H "X-AI-License-Token: <?php echo esc_html($aitamer_sample_token); ?>"</pre>
		</div>

		<p><em><?php esc_html_e('Or using the post slug:', 'ai-tamer'); ?></em></p>
		<div class="aitamer-code-block">
			<pre>curl -X GET "<?php echo esc_url(str_replace('{post_id}', 'mi-post-slug', $aitamer_content_url)); ?>" \
     -H "X-AI-License-Token: <?php echo esc_html($aitamer_sample_token); ?>"</pre>
		</div>
	</div>

</div>
