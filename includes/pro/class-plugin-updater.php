<?php

/**
 * PluginUpdater class - handles automatic Pro plugin updates securely.
 *
 * @package Ai_Tamer
 */

namespace AiTamer;

use function get_option;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function is_wp_error;

defined('ABSPATH') || exit;

/**
 * Updater for AI Tamer Pro.
 */
class PluginUpdater
{
	/** @var string The API endpoint for updates. */
	private $api_url = 'https://api.aitamer.com/v1/update';

	/** @var string Current plugin version. */
	private $version;

	/** @var string Plugin slug (e.g., ai-tamer-project/ai-tamer.php). */
	private $plugin_slug;

	/** @var string Plugin basename (e.g., ai-tamer). */
	private $plugin_basename;

	/**
	 * Constructor.
	 *
	 * @param string $version         Current version.
	 * @param string $plugin_slug     Plugin file relative to WP plugins dir.
	 * @param string $plugin_basename Plugin folder name.
	 */
	public function __construct(string $version, string $plugin_slug, string $plugin_basename)
	{
		$this->version         = $version;
		$this->plugin_slug     = $plugin_slug;
		$this->plugin_basename = $plugin_basename;

		add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
		add_filter('plugins_api', array($this, 'check_info'), 10, 3);
	}

	/**
	 * Makes a request to the licensing server.
	 *
	 * @param string $action 'check_update' or 'plugin_information'.
	 * @return object|false
	 */
	private function api_request(string $action)
	{
		$settings = get_option('aitamer_settings', array());
		$license  = $settings['plugin_license_key'] ?? '';

		$response = wp_remote_post($this->api_url, array(
			'timeout'   => 15,
			'headers'   => array('Accept' => 'application/json'),
			'body'      => array(
				'action'  => $action,
				'license' => $license,
				'slug'    => $this->plugin_basename,
				'domain'  => home_url(),
				'version' => $this->version,
			),
		));

		if (is_wp_error($response)) {
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		return json_decode($body);
	}

	/**
	 * Checks for plugin updates.
	 *
	 * @param object $transient Transient object.
	 * @return object
	 */
	public function check_update($transient)
	{
		if (empty($transient->checked)) {
			return $transient;
		}

		$remote = $this->api_request('check_update');

		if ($remote && isset($remote->new_version) && version_compare($this->version, $remote->new_version, '<')) {
			$res = new \stdClass();
			$res->slug        = $this->plugin_basename;
			$res->plugin      = $this->plugin_slug;
			$res->new_version = $remote->new_version;
			$res->tested      = $remote->tested;
			$res->package     = $remote->package;
			$res->url         = $remote->url;

			$transient->response[$res->plugin] = $res;
		}

		return $transient;
	}

	/**
	 * Fetches plugin information for the details modal.
	 *
	 * @param false|object|array $result Default return.
	 * @param string             $action Hook action.
	 * @param object             $args   Hook args.
	 * @return false|object|array
	 */
	public function check_info($result, string $action, $args)
	{
		if ('plugin_information' !== $action || $args->slug !== $this->plugin_basename) {
			return $result;
		}

		$remote = $this->api_request('plugin_information');

		if ($remote) {
			$res = new \stdClass();
			$res->name         = $remote->name ?? 'AI Tamer Pro';
			$res->slug         = $this->plugin_basename;
			$res->version      = $remote->new_version;
			$res->tested       = $remote->tested;
			$res->requires     = $remote->requires;
			$res->author       = $remote->author;
			$res->author_profile = $remote->author_profile;
			$res->download_link = $remote->package;
			$res->trunk        = $remote->url;
			$res->last_updated = $remote->last_updated;
			$res->sections     = array(
				'description'  => $remote->sections->description ?? '',
				'changelog'    => $remote->sections->changelog ?? '',
			);
			$res->banners      = array(
				'low'  => $remote->banners->low ?? '',
				'high' => $remote->banners->high ?? '',
			);

			return $res;
		}

		return $result;
	}
}
