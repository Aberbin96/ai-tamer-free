<?php

/**
 * TransactionStatus enum.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Enums;

defined('ABSPATH') || exit;

/**
 * TransactionStatus class.
 */
enum TransactionStatus: string
{
	case PENDING   = 'pending';
	case COMPLETED = 'completed';
	case EXPIRED   = 'expired';
	case FAILED    = 'failed';
}
