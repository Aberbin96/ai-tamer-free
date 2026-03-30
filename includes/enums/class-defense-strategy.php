<?php
/**
 * DefenseStrategy Enum.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Valid defense strategies for unauthorized AI agents.
 */
enum DefenseStrategy: string {
	case BLOCK     = 'block';
	case PAYMENT   = 'payment';
}
