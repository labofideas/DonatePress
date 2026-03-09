<?php
/**
 * Smoke checks for setup wizard service.
 *
 * Run:
 * php tests/smoke-setup-wizard.php
 */

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

if (!class_exists('wpdb')) {
	class wpdb {}
}

$GLOBALS['dp_test_options'] = array(
	'donatepress_settings' => array(
		'organization_name' => '',
		'organization_email' => '',
		'receipt_legal_entity_name' => '',
		'tax_disclaimer_text' => '',
		'privacy_policy_url' => '',
		'terms_url' => '',
		'base_currency' => 'USD',
		'stripe_publishable_key' => '',
		'paypal_client_id' => '',
	),
	'donatepress_setup_completed' => 0,
);

if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value, ...$args) { return $value; }
}
if (!function_exists('do_action')) {
	function do_action($tag, ...$args) {}
}
if (!function_exists('__')) {
	function __($text, $domain = null) { return (string) $text; }
}
if (!function_exists('sanitize_key')) {
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
}
if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
}
if (!function_exists('sanitize_textarea_field')) {
	function sanitize_textarea_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
}
if (!function_exists('sanitize_email')) {
	function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
}
if (!function_exists('esc_url_raw')) {
	function esc_url_raw($value) { return (string) $value; }
}
if (!function_exists('sanitize_title')) {
	function sanitize_title($value) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $value), '-')); }
}
if (!function_exists('current_time')) {
	function current_time($type, $gmt = 0) { return gmdate('Y-m-d H:i:s'); }
}
if (!function_exists('wp_json_encode')) {
	function wp_json_encode($value) { return json_encode($value); }
}
if (!function_exists('get_option')) {
	function get_option($key, $default = null) { return $GLOBALS['dp_test_options'][$key] ?? $default; }
}
if (!function_exists('update_option')) {
	function update_option($key, $value, $autoload = null) { $GLOBALS['dp_test_options'][$key] = $value; return true; }
}
if (!function_exists('add_settings_error')) {
	function add_settings_error($setting, $code, $message, $type = 'error') {}
}

require_once __DIR__ . '/../includes/Admin/SettingsPage.php';
require_once __DIR__ . '/../includes/Repositories/FormRepository.php';
require_once __DIR__ . '/../includes/Services/SetupWizardService.php';

$service = new DonatePress\Services\SetupWizardService();
$status  = $service->status();

if (!is_array($status) || !array_key_exists('required_total', $status) || !array_key_exists('required_set', $status)) {
	fwrite(STDERR, "[FAIL] setup wizard status shape invalid.\n");
	exit(1);
}

$saved = $service->save_settings(array(
	'organization_name' => 'KindCart',
	'organization_email' => 'hello@example.com',
	'receipt_legal_entity_name' => 'KindCart Foundation',
	'tax_disclaimer_text' => 'Tax text',
	'privacy_policy_url' => 'https://example.com/privacy',
	'terms_url' => 'https://example.com/terms',
	'stripe_publishable_key' => 'pk_test_123',
));

if ((int) ($saved['required_set'] ?? 0) <= 0) {
	fwrite(STDERR, "[FAIL] setup wizard save did not update required progress.\n");
	exit(1);
}

$complete = $service->complete();
if (!is_array($complete) || !array_key_exists('success', $complete)) {
	fwrite(STDERR, "[FAIL] setup wizard complete response invalid.\n");
	exit(1);
}

fwrite(STDOUT, "[PASS] setup wizard service smoke succeeded.\n");
