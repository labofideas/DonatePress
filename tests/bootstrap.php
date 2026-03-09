<?php

declare(strict_types=1);

namespace {
	if (!defined('ABSPATH')) {
		define('ABSPATH', __DIR__ . '/');
	}
	if (!defined('DAY_IN_SECONDS')) {
		define('DAY_IN_SECONDS', 86400);
	}

	if (!class_exists('wpdb')) {
		class wpdb {}
	}

	if (!function_exists('sanitize_key')) {
		function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	}
}

namespace DonatePress\Services {
	function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	function sanitize_textarea_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function __($text, $domain = null) { return (string) $text; }
	function apply_filters($tag, $value) { return $value; }
	function do_action($tag, ...$args) {}
	function get_current_user_id() { return 0; }
	function wp_unslash($value) { return $value; }
	function wp_json_encode($value) { return json_encode($value); }
	function gmdate($format, $timestamp = null) { return \gmdate($format, $timestamp ?? time()); }
	function wp_rand($min = 0, $max = 0) { return random_int((int) $min, (int) $max); }
	function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) { return substr(bin2hex(random_bytes(32)), 0, (int) $length); }
	function current_time($type, $gmt = 0) {
		if ('mysql' === $type) {
			return \gmdate('Y-m-d H:i:s');
		}
		if ('timestamp' === $type) {
			return time();
		}
		return \gmdate('Y-m-d H:i:s');
	}
}

namespace DonatePress\Repositories {
	function current_time($type, $gmt = 0) {
		if ('mysql' === $type) {
			return \gmdate('Y-m-d H:i:s');
		}
		return time();
	}
}

namespace {
	require_once __DIR__ . '/../includes/Repositories/DonationRepository.php';
	require_once __DIR__ . '/../includes/Repositories/DonorRepository.php';
	require_once __DIR__ . '/../includes/Repositories/SubscriptionRepository.php';
	require_once __DIR__ . '/../includes/Services/PaymentService.php';
	require_once __DIR__ . '/../includes/Services/AuditLogService.php';
	require_once __DIR__ . '/../includes/Services/DonationService.php';
	require_once __DIR__ . '/../includes/Services/RecurringChargeService.php';
}
