<?php

namespace AiTamer;

use function add_filter;
use function add_action;
use function __;
use function get_post_meta;
use function esc_attr__;
use function esc_html__;
use function esc_attr;
use function esc_html;
use function get_post_field;
use function update_post_meta;
use function wp_next_scheduled;
use function wp_schedule_single_event;
use function add_query_arg;
use function time;

/**
 * MediaPro class — handles media library UI extensions (columns, bulk actions).
 */
class MediaPro
{
	/**
	 * Registers hooks for the Media Library.
	 */
	public function register(): void
	{
		// List View Columns.
		add_filter('manage_media_columns', array($this, 'add_media_columns'));
		add_action('manage_media_custom_column', array($this, 'render_media_columns'), 10, 2);

		// Bulk Actions.
		add_filter('bulk_actions-upload', array($this, 'register_bulk_actions'));
		add_filter('handle_bulk_actions-upload', array($this, 'handle_bulk_actions'), 10, 3);
	}

	/**
	 * Adds a "Protection" column to the media library.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_media_columns(array $columns): array
	{
		$columns['aitamer_status'] = __('AI Protection', 'ai-tamer');
		return $columns;
	}

	/**
	 * Renders the protection status in the media library.
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Attachment ID.
	 */
	public function render_media_columns(string $column_name, int $post_id): void
	{
		if ('aitamer_status' !== $column_name) {
			return;
		}

		$status    = get_post_meta($post_id, '_aitamer_iptc_status', true);
		$certified = get_post_meta($post_id, '_aitamer_iptc_certified', true) === 'yes';

		if ($certified && 'done' === $status) {
			echo '<span class="dashicons dashicons-shield-alt" style="color:#16a34a;" title="' . esc_attr__('Protected: IPTC/C2PA injected', 'ai-tamer') . '"></span>';
			echo ' <small>' . esc_html__('Protected', 'ai-tamer') . '</small>';
		} elseif ($certified && 'pending' === $status) {
			echo '<span class="dashicons dashicons-clock" style="color:#dba617;" title="' . esc_attr__('In Queue: Waiting for processing', 'ai-tamer') . '"></span>';
			echo ' <small>' . esc_html__('Pending...', 'ai-tamer') . '</small>';
		} elseif ($certified && 'failed' === $status) {
			echo '<span class="dashicons dashicons-warning" style="color:#d63638;" title="' . esc_attr__('Processing failed', 'ai-tamer') . '"></span>';
			echo ' <small>' . esc_html__('Failed', 'ai-tamer') . '</small>';
		} else {
			echo '<span style="color:#94a3b8; font-style:italic;">' . esc_html__('Not Protected', 'ai-tamer') . '</span>';
		}
	}

	/**
	 * Registers bulk actions in the media library.
	 *
	 * @param array $bulk_actions Existing actions.
	 * @return array
	 */
	public function register_bulk_actions(array $bulk_actions): array
	{
		$bulk_actions['aitamer_bulk_protect'] = __('Certify Human Origin (AI Tamer)', 'ai-tamer');
		return $bulk_actions;
	}

	/**
	 * Handles the bulk protect action.
	 *
	 * @param string $redirect_to The redirect URL.
	 * @param string $doaction    The action name.
	 * @param array  $post_ids    Selected IDs.
	 * @return string
	 */
	public function handle_bulk_actions(string $redirect_to, string $doaction, array $post_ids): string
	{
		if ('aitamer_bulk_protect' !== $doaction) {
			return $redirect_to;
		}

		$count = 0;
		foreach ($post_ids as $post_id) {
			if (strpos((string)get_post_field('post_mime_type', $post_id), 'image/') === 0) {
				update_post_meta($post_id, '_aitamer_iptc_certified', 'yes');
				update_post_meta($post_id, '_aitamer_iptc_status', 'pending');
				
				// Queue processing.
				if (!wp_next_scheduled('aitamer_process_media', array($post_id))) {
					wp_schedule_single_event(time(), 'aitamer_process_media', array($post_id));
				}
				$count++;
			}
		}

		return add_query_arg('aitamer_bulk_processed', $count, $redirect_to);
	}
}
