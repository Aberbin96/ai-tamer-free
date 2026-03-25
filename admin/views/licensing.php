<?php

/**
 * Licensing admin page view.
 *
 * @package Ai_Tamer
 */

use AiTamer\LicenseVerifier;

defined( 'ABSPATH' ) || exit;

// Handle token revocation.
if (
	isset( $_POST['aitamer_revoke_nonce'], $_POST['revoke_index'] )
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aitamer_revoke_nonce'] ) ), 'aitamer_revoke_token' )
	&& current_user_can( 'manage_options' )
) {
	LicenseVerifier::revoke_token( absint( $_POST['revoke_index'] ) );
	wp_safe_redirect( add_query_arg( 'aitamer_revoked', '1', admin_url( 'admin.php?page=ai-tamer-licensing' ) ) );
	exit;
}

// Handle token issuance.
$aitamer_issued_token = '';
if (
	isset( $_POST['aitamer_issue_token_nonce'] )
	&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aitamer_issue_token_nonce'] ) ), 'aitamer_issue_token' )
	&& current_user_can( 'manage_options' )
) {
	$aitamer_agent_name   = sanitize_text_field( wp_unslash( $_POST['agent_name'] ?? '' ) );
	$aitamer_days         = absint( $_POST['days'] ?? 365 ) ?: 365;
	$aitamer_issued_token = LicenseVerifier::issue_token( $aitamer_agent_name, $aitamer_days );
}

$aitamer_tokens = LicenseVerifier::get_tokens();
?>
<div class="wrap aitamer-wrap">

	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title"><?php esc_html_e( 'Licensing', 'ai-tamer' ); ?></h1>
			<p class="aitamer-page-desc"><?php esc_html_e( 'Issue HMAC-signed tokens to trusted AI agents for authorized access.', 'ai-tamer' ); ?></p>
		</div>
	</div>

	<?php if ( isset( $_GET['aitamer_revoked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Token revoked successfully.', 'ai-tamer' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $aitamer_issued_token ) : ?>
		<div class="notice notice-success">
			<p><strong><?php esc_html_e( 'Token issued. Share this securely with the licensee.', 'ai-tamer' ); ?></strong></p>
			<code class="aitamer-token-block"><?php echo esc_html( $aitamer_issued_token ); ?></code>
			<p style="font-size:11px;margin-top:8px;color:var(--at-muted);">
				<?php esc_html_e( 'The agent sends this as: X-AI-License-Token: [token]', 'ai-tamer' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Issued Tokens Table -->
	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e( 'Active License Tokens', 'ai-tamer' ); ?></h2>
		</div>
		<?php if ( empty( $aitamer_tokens ) ) : ?>
			<div class="aitamer-empty">
				<p><?php esc_html_e( 'No tokens issued yet. Use the form below to authorize a trusted AI agent.', 'ai-tamer' ); ?></p>
			</div>
		<?php else : ?>
			<div class="aitamer-table-responsive">
				<table class="aitamer-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Agent', 'ai-tamer' ); ?></th>
						<th><?php esc_html_e( 'Issued', 'ai-tamer' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'ai-tamer' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ai-tamer' ); ?></th>
						<th><?php esc_html_e( 'Token (preview)', 'ai-tamer' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ai-tamer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aitamer_tokens as $aitamer_i => $aitamer_t ) :
						$aitamer_is_expired = (int) $aitamer_t['exp'] < time();
					?>
					<tr>
						<td><strong><?php echo esc_html( $aitamer_t['agent'] ); ?></strong></td>
						<td class="mono"><?php echo esc_html( wp_date( 'Y-m-d', $aitamer_t['issued_at'] ) ); ?></td>
						<td class="mono"><?php echo esc_html( wp_date( 'Y-m-d', $aitamer_t['exp'] ) ); ?></td>
						<td>
							<span class="aitamer-badge-status <?php echo $aitamer_is_expired ? 'expired' : 'active'; ?>">
								<?php echo $aitamer_is_expired ? esc_html__( 'Expired', 'ai-tamer' ) : esc_html__( 'Active', 'ai-tamer' ); ?>
							</span>
						</td>
						<td><code><?php echo esc_html( substr( $aitamer_t['token'], 0, 28 ) . '…' ); ?></code></td>
						<td>
							<form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'Revoke this token?', 'ai-tamer' ); ?>');">
								<?php wp_nonce_field( 'aitamer_revoke_token', 'aitamer_revoke_nonce' ); ?>
								<input type="hidden" name="revoke_index" value="<?php echo esc_attr( $aitamer_i ); ?>">
								<button type="submit" class="aitamer-btn-danger"><?php esc_html_e( 'Revoke', 'ai-tamer' ); ?></button>
							</form>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<!-- Issue New Token -->
	<div class="aitamer-card">
		<div class="aitamer-card-header">
			<h2><?php esc_html_e( 'Issue New Token', 'ai-tamer' ); ?></h2>
		</div>
		<form method="post">
			<?php wp_nonce_field( 'aitamer_issue_token', 'aitamer_issue_token_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="agent_name"><?php esc_html_e( 'Agent Name', 'ai-tamer' ); ?></label>
					</th>
					<td>
						<input type="text" id="agent_name" name="agent_name" class="regular-text"
							placeholder="<?php esc_attr_e( 'e.g. GPTBot, MyResearchBot', 'ai-tamer' ); ?>" required>
						<p class="description"><?php esc_html_e( 'The User-Agent name of the licensed crawler.', 'ai-tamer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="days"><?php esc_html_e( 'Validity (days)', 'ai-tamer' ); ?></label>
					</th>
					<td>
						<input type="number" id="days" name="days" value="365" min="1" max="3650" class="small-text">
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Generate Token', 'ai-tamer' ) ); ?>
		</form>

		<h3 class="aitamer-section-title"><?php esc_html_e( 'How It Works', 'ai-tamer' ); ?></h3>
		<ol style="color:var(--at-muted);font-size:12px;padding-left:20px;line-height:1.8;">
			<li><?php esc_html_e( 'You issue a signed HMAC token to a trusted AI company.', 'ai-tamer' ); ?></li>
			<li><?php esc_html_e( 'They send it in every request as the X-AI-License-Token header.', 'ai-tamer' ); ?></li>
			<li><?php esc_html_e( 'AI Tamer verifies the signature and bypasses blocking for that agent.', 'ai-tamer' ); ?></li>
			<li><?php esc_html_e( 'Tokens expire automatically after the configured period.', 'ai-tamer' ); ?></li>
		</ol>
	</div>

</div>
