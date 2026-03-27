<?php
/**
 * LicensePolicy Enum.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Valid AI license policies (meta tags).
 */
enum LicensePolicy: string {
	case NO_TRAINING = 'no-training';
	case ALLOW       = 'allow';
	case ATTRIBUTION = 'allow-with-attribution';
}
