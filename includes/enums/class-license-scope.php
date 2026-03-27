<?php
/**
 * LicenseScope Enum.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Valid license scopes for tokens.
 */
enum LicenseScope: string {
	case GLOBAL   = 'global';
	case POST     = 'post';
	case CATEGORY = 'cat';
}
