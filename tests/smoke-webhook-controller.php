<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
$GLOBALS['dp_test_filters'] = array();
$GLOBALS['dp_test_transients'] = array();

if (!class_exists('WP_Error')) {
	class WP_Error {
		private string $code;
		private string $message;
		private array $data;
		public function __construct(string $code, string $message = '', array $data = array()) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

if (!class_exists('WP_REST_Request')) {
	class WP_REST_Request {
		private array $params;
		private array $headers;
		private string $body;
		public function __construct(array $params = array(), array $headers = array(), string $body = '') {
			$this->params = $params;
			$this->headers = array_change_key_case($headers, CASE_LOWER);
			$this->body = $body;
		}
		public function get_param(string $key) { return $this->params[$key] ?? null; }
		public function get_headers(): array { return $this->headers; }
		public function get_body(): string { return $this->body; }
	}
}

if (!class_exists('WP_REST_Response')) {
	class WP_REST_Response {
		public array $data;
		public int $status;
		public function __construct(array $data = array(), int $status = 200) {
			$this->data = $data;
			$this->status = $status;
		}
	}
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
if (!function_exists('wp_unslash')) {
	function wp_unslash($value) { return $value; }
}
if (!function_exists('add_filter')) {
	function add_filter(string $tag, callable $callback): void {
		if (!isset($GLOBALS['dp_test_filters'][$tag])) {
			$GLOBALS['dp_test_filters'][$tag] = array();
		}
		$GLOBALS['dp_test_filters'][$tag][] = $callback;
	}
}
if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value, ...$args) {
		$filters = $GLOBALS['dp_test_filters'][$tag] ?? array();
		foreach ($filters as $cb) {
			$value = $cb($value, ...$args);
		}
		return $value;
	}
}
if (!function_exists('do_action')) {
	function do_action($tag, ...$args) {}
}
if (!function_exists('get_transient')) {
	function get_transient($key) { return $GLOBALS['dp_test_transients'][$key] ?? false; }
}
if (!function_exists('set_transient')) {
	function set_transient($key, $value, $expiration = 0) { $GLOBALS['dp_test_transients'][$key] = $value; return true; }
}

if (!class_exists('DonatePress\\Services\\PaymentService')) {
	eval(
		'namespace DonatePress\\Services; class PaymentService { public static array $seen = array(); public function handle_webhook(string $gateway_id, array $headers, string $payload): array { if (!empty($headers["x-invalid-signature"])) { return array("success" => false, "code" => "invalid_signature", "message" => "Invalid webhook signature."); } $key = $gateway_id . "|" . md5($payload); if (isset(self::$seen[$key])) { return array("success" => true, "event_type" => "payment_intent.succeeded", "duplicate" => true); } self::$seen[$key] = true; return array("success" => true, "event_type" => "payment_intent.succeeded", "duplicate" => false); } }'
	);
}

require_once __DIR__ . '/../includes/Security/RateLimiter.php';
require_once __DIR__ . '/../includes/API/Controllers/WebhookController.php';

use DonatePress\API\Controllers\WebhookController;

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, "[FAIL] {$message}\n");
		exit(1);
	}
}

// 1) Invalid signature should return WP_Error with 403 status.
$controller = new WebhookController('donatepress/v1');
$invalidSignature = $controller->receive(
	new WP_REST_Request(
		array('gateway' => 'stripe'),
		array('x-invalid-signature' => '1'),
		'{"id":"evt_invalid"}'
	)
);
assert_true($invalidSignature instanceof WP_Error, 'Invalid signature should return WP_Error.');
assert_true($invalidSignature->get_error_code() === 'invalid_signature', 'Invalid signature should map to invalid_signature code.');
assert_true(((int) (($invalidSignature->get_error_data()['status'] ?? 0))) === 403, 'Invalid signature should map to 403 status.');

// 2) Replay payload should be no-op success after first processing.
$payload = '{"id":"evt_replay","type":"payment_intent.succeeded"}';
$first = $controller->receive(new WP_REST_Request(array('gateway' => 'stripe'), array(), $payload));
$second = $controller->receive(new WP_REST_Request(array('gateway' => 'stripe'), array(), $payload));
assert_true($first instanceof WP_REST_Response, 'First webhook should return WP_REST_Response.');
assert_true($second instanceof WP_REST_Response, 'Replay webhook should still return WP_REST_Response.');
assert_true(($first->data['success'] ?? false) === true, 'First webhook should be success.');
assert_true(($second->data['success'] ?? false) === true, 'Replay webhook should be success no-op.');

// 3) Rate-limited webhook should return 429 path.
add_filter('donatepress_webhook_rate_limit', static function ($limit) { return 0; });
$rateLimited = $controller->receive(new WP_REST_Request(array('gateway' => 'stripe'), array(), '{"id":"evt_rate"}'));
assert_true($rateLimited instanceof WP_Error, 'Rate-limited webhook should return WP_Error.');
assert_true($rateLimited->get_error_code() === 'rate_limited', 'Rate-limited webhook should return rate_limited code.');
assert_true(((int) (($rateLimited->get_error_data()['status'] ?? 0))) === 429, 'Rate-limited webhook should map to 429 status.');

fwrite(STDOUT, "[PASS] Webhook controller smoke tests succeeded.\n");
exit(0);
