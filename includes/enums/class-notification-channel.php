<?php
/**
 * NotificationChannel Enum.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Enums;

defined('ABSPATH') || exit;

/**
 * Valid notification channels for alerts.
 */
enum NotificationChannel: string
{
	case EMAIL   = 'email';
	case SLACK   = 'slack';
	case DISCORD = 'discord';

	/**
	 * Returns human-readable labels for each channel.
	 *
	 * @return array<string, string>
	 */
	public static function get_labels(): array
	{
		return array(
			self::EMAIL->value   => __('Email', 'ai-tamer'),
			self::SLACK->value   => __('Slack (Webhook)', 'ai-tamer'),
			self::DISCORD->value => __('Discord (Webhook)', 'ai-tamer'),
		);
	}
}
