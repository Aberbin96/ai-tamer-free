<?php

/**
 * Settings page view.
 *
 * @package Ai_Tamer
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap aitamer-wrap">

	<div class="aitamer-page-header">
		<div>
			<h1 class="aitamer-page-title"><?php esc_html_e( 'Settings', 'ai-tamer' ); ?></h1>
			<p class="aitamer-page-desc"><?php esc_html_e( 'Control how AI agents interact with your content. Changes take effect immediately.', 'ai-tamer' ); ?></p>
		</div>
	</div>

	<div class="aitamer-card">
		<form method="post" action="options.php">
			<?php
			settings_fields( 'aitamer_settings_group' );
			do_settings_sections( 'ai-tamer-settings' );
			submit_button( __( 'Save Settings', 'ai-tamer' ) );
			?>
		</form>
	</div>

</div>
