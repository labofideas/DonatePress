<?php
/**
 * Smoke checks for REST route argument sanitization/validation callbacks.
 *
 * Run:
 * php tests/smoke-rest-schema.php
 */

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['dp_test_routes'] = array();

if (!function_exists('register_rest_route')) {
	function register_rest_route(string $namespace, string $route, $args): void {
		$GLOBALS['dp_test_routes'][] = array(
			'namespace' => $namespace,
			'route' => $route,
			'args' => $args,
		);
	}
}
if (!function_exists('__return_true')) {
	function __return_true(): bool { return true; }
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
if (!function_exists('sanitize_title')) {
	function sanitize_title($value) { return strtolower(trim(preg_replace('/[^a-zA-Z0-9\-]+/', '-', (string) $value), '-')); }
}
if (!function_exists('is_email')) {
	function is_email($value) { return false !== filter_var((string) $value, FILTER_VALIDATE_EMAIL); }
}
if (!class_exists('WP_REST_Request')) {
	class WP_REST_Request {}
}

require_once __DIR__ . '/../includes/Security/CapabilityManager.php';
require_once __DIR__ . '/../includes/API/Controllers/DonorController.php';
require_once __DIR__ . '/../includes/API/Controllers/FormController.php';
require_once __DIR__ . '/../includes/API/Controllers/SubscriptionController.php';
require_once __DIR__ . '/../includes/API/Controllers/WebhookController.php';
require_once __DIR__ . '/../includes/API/Controllers/ReportsController.php';
require_once __DIR__ . '/../includes/API/Controllers/SettingsController.php';

$namespace = 'donatepress/v1';
(new DonatePress\API\Controllers\DonorController($namespace))->register_routes();
(new DonatePress\API\Controllers\FormController($namespace))->register_routes();
(new DonatePress\API\Controllers\SubscriptionController($namespace))->register_routes();
(new DonatePress\API\Controllers\WebhookController($namespace))->register_routes();
(new DonatePress\API\Controllers\ReportsController($namespace))->register_routes();
(new DonatePress\API\Controllers\SettingsController($namespace))->register_routes();

/**
 * @return array<string,mixed>|null
 */
function find_endpoint_args(string $route, string $method): ?array {
	foreach ($GLOBALS['dp_test_routes'] as $registered) {
		if ($registered['route'] !== $route) {
			continue;
		}

		$defs = $registered['args'];
		$defs = isset($defs[0]) ? $defs : array($defs);
		foreach ($defs as $def) {
			$defMethod = strtoupper((string) ($def['methods'] ?? ''));
			if ($defMethod === strtoupper($method)) {
				return (array) ($def['args'] ?? array());
			}
		}
	}
	return null;
}

function assert_arg_callbacks(string $route, string $method, string $arg, bool $expectValidate = true): void {
	$args = find_endpoint_args($route, $method);
	if (!is_array($args) || !isset($args[$arg])) {
		fwrite(STDERR, "[FAIL] Missing arg definition for {$method} {$route} :: {$arg}\n");
		exit(1);
	}

	$definition = (array) $args[$arg];
	if (empty($definition['sanitize_callback'])) {
		fwrite(STDERR, "[FAIL] Missing sanitize_callback for {$method} {$route} :: {$arg}\n");
		exit(1);
	}

	if ($expectValidate && empty($definition['validate_callback'])) {
		fwrite(STDERR, "[FAIL] Missing validate_callback for {$method} {$route} :: {$arg}\n");
		exit(1);
	}
}

assert_arg_callbacks('/manage/donors', 'POST', 'email');
assert_arg_callbacks('/manage/donors', 'POST', 'status');
assert_arg_callbacks('/manage/donors', 'POST', 'tags');

assert_arg_callbacks('/manage/forms', 'POST', 'title');
assert_arg_callbacks('/manage/forms', 'POST', 'status');
assert_arg_callbacks('/manage/forms', 'POST', 'default_amount');
assert_arg_callbacks('/manage/forms', 'POST', 'config');

assert_arg_callbacks('/subscriptions/(?P<id>\d+)/status', 'POST', 'id');
assert_arg_callbacks('/subscriptions/(?P<id>\d+)/status', 'POST', 'status');

assert_arg_callbacks('/webhooks/(?P<gateway>[a-z0-9_-]+)', 'POST', 'gateway');

assert_arg_callbacks('/donors', 'GET', 'limit');
assert_arg_callbacks('/forms', 'GET', 'limit');
assert_arg_callbacks('/campaigns', 'GET', 'limit');

assert_arg_callbacks('/settings', 'POST', 'settings');
assert_arg_callbacks('/setup-wizard/save', 'POST', 'settings');

fwrite(STDOUT, "[PASS] REST schema smoke checks succeeded.\n");
