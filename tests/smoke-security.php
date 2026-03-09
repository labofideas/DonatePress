<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
if (!defined('DAY_IN_SECONDS')) {
	define('DAY_IN_SECONDS', 86400);
}

$GLOBALS['dp_test_options'] = array(
	'donatepress_settings' => array(
		'enable_captcha' => 1,
		'stripe_webhook_secret' => 'whsec_test',
	),
);
$GLOBALS['dp_test_filters'] = array();
$GLOBALS['dp_test_transients'] = array();
$GLOBALS['dp_test_nonce_valid'] = true;

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

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
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

		public function get_json_params() {
			return $this->params;
		}

		public function get_params() {
			return $this->params;
		}

		public function get_param(string $key) {
			return $this->params[$key] ?? null;
		}

		public function get_header(string $key): string {
			return (string) ($this->headers[strtolower($key)] ?? '');
		}

		public function get_headers(): array {
			return $this->headers;
		}

		public function get_body(): string {
			return $this->body;
		}
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
if (!function_exists('add_filter')) {
	function add_filter(string $tag, callable $callback): void {
		if (!isset($GLOBALS['dp_test_filters'][$tag])) {
			$GLOBALS['dp_test_filters'][$tag] = array();
		}
		$GLOBALS['dp_test_filters'][$tag][] = $callback;
	}
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
if (!function_exists('is_email')) {
	function is_email($value) { return false !== filter_var((string) $value, FILTER_VALIDATE_EMAIL); }
}
if (!function_exists('wp_unslash')) {
	function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_verify_nonce')) {
	function wp_verify_nonce($nonce, $action) { return !empty($GLOBALS['dp_test_nonce_valid']); }
}
if (!function_exists('is_user_logged_in')) {
	function is_user_logged_in() { return false; }
}
if (!function_exists('get_current_user_id')) {
	function get_current_user_id() { return 0; }
}
if (!function_exists('wp_set_current_user')) {
	function wp_set_current_user($id) {}
}
if (!function_exists('wp_salt')) {
	function wp_salt($scheme = '') { return 'salt_for_tests'; }
}
if (!function_exists('get_option')) {
	function get_option($key, $default = null) { return $GLOBALS['dp_test_options'][$key] ?? $default; }
}
if (!function_exists('update_option')) {
	function update_option($key, $value, $autoload = null) { $GLOBALS['dp_test_options'][$key] = $value; return true; }
}
if (!function_exists('home_url')) {
	function home_url($path = '') { return 'https://example.org' . (string) $path; }
}
if (!function_exists('wp_parse_url')) {
	function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}
if (!function_exists('get_transient')) {
	function get_transient($key) { return $GLOBALS['dp_test_transients'][$key] ?? false; }
}
if (!function_exists('set_transient')) {
	function set_transient($key, $value, $expiration = 0) { $GLOBALS['dp_test_transients'][$key] = $value; return true; }
}
if (!class_exists('DonatePress\\Services\\PaymentService')) {
	eval('namespace DonatePress\\Services; class PaymentService { public function handle_webhook(string $gateway_id, array $headers, string $payload): array { $signature = isset($headers["stripe-signature"]) ? (string) $headers["stripe-signature"] : ""; if ($signature === "" || strpos($signature, "deadbeef") !== false) { return array("success" => false, "code" => "invalid_signature", "message" => "Invalid webhook signature."); } return array("success" => true, "event_type" => "payment_intent.succeeded"); } }');
}

require_once __DIR__ . '/../includes/Security/NonceManager.php';
require_once __DIR__ . '/../includes/Security/RateLimiter.php';
require_once __DIR__ . '/../includes/API/Controllers/DonationController.php';
require_once __DIR__ . '/../includes/API/Controllers/WebhookController.php';
require_once __DIR__ . '/../includes/Services/SettingsService.php';
require_once __DIR__ . '/../includes/Gateways/GatewayInterface.php';
require_once __DIR__ . '/../includes/Gateways/Stripe/StripeGateway.php';

use DonatePress\API\Controllers\DonationController;
use DonatePress\API\Controllers\WebhookController;
use DonatePress\Gateways\Stripe\StripeGateway;
use DonatePress\Services\SettingsService;

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, "[FAIL] {$message}\n");
		exit(1);
	}
}

// 1) Captcha enforcement should reject empty token when enabled.
$donationController = new DonationController('donatepress/v1');
$captchaFailRequest = new WP_REST_Request(
	array(
		'form_id' => 1,
		'amount' => 10,
		'email' => 'a@example.org',
		'first_name' => 'A',
		'dp_nonce' => 'nonce',
		'captcha_token' => '',
	),
	array('origin' => 'https://example.org')
);
$captchaFail = $donationController->submit($captchaFailRequest);
assert_true($captchaFail instanceof WP_Error, 'Captcha failure should return WP_Error.');
assert_true($captchaFail->get_error_code() === 'invalid_captcha', 'Captcha failure should return invalid_captcha.');

// 2) Nonce enforcement should reject when nonce verification is disabled.
$GLOBALS['dp_test_nonce_valid'] = false;
$nonceFailRequest = new WP_REST_Request(
	array(
		'form_id' => 1,
		'amount' => 10,
		'email' => 'a@example.org',
		'first_name' => 'A',
		'dp_nonce' => 'nonce',
		'captcha_token' => 'ok_token',
	),
	array('origin' => 'https://example.org')
);
$nonceFail = $donationController->submit($nonceFailRequest);
assert_true($nonceFail instanceof WP_Error, 'Nonce failure should return WP_Error.');
assert_true($nonceFail->get_error_code() === 'invalid_nonce', 'Nonce failure should return invalid_nonce.');
$GLOBALS['dp_test_nonce_valid'] = true;

// 3) With valid captcha token filter, request should proceed past captcha check.
add_filter('donatepress_verify_captcha', static function ($default, $token) {
	return $token === 'ok_token';
});
$captchaPassRequest = new WP_REST_Request(
	array(
		'form_id' => 1,
		'amount' => 10,
		'email' => 'a@example.org',
		'first_name' => 'A',
		'dp_nonce' => 'nonce',
		'captcha_token' => 'ok_token',
	),
	array() // no origin on purpose: should fail on origin, not captcha
);
$captchaPass = $donationController->submit($captchaPassRequest);
assert_true($captchaPass instanceof WP_Error, 'Request should still fail before DB in smoke environment.');
assert_true($captchaPass->get_error_code() !== 'invalid_captcha', 'Captcha should be accepted when verifier returns true.');

// 4) Webhook rate limit enforcement should return 429 style error path.
add_filter('donatepress_webhook_rate_limit', static function ($limit) { return 0; });
$webhookController = new WebhookController('donatepress/v1');
$webhookRequest = new WP_REST_Request(array('gateway' => 'stripe'), array(), '{}');
$webhookResult  = $webhookController->receive($webhookRequest);
assert_true($webhookResult instanceof WP_Error, 'Webhook should be blocked by rate limiter.');
assert_true($webhookResult->get_error_code() === 'rate_limited', 'Webhook rate limit should return rate_limited.');

// 5) Invalid webhook signature should be rejected.
add_filter('donatepress_webhook_rate_limit', static function ($limit) { return 180; });
$badSignatureWebhook = new WP_REST_Request(
	array('gateway' => 'stripe'),
	array('stripe-signature' => 't=1,v1=deadbeef'),
	'{"id":"evt_invalid","type":"payment_intent.succeeded"}'
);
$badSignatureResult = $webhookController->receive($badSignatureWebhook);
assert_true($badSignatureResult instanceof WP_Error, 'Invalid webhook signature should return WP_Error.');
assert_true($badSignatureResult->get_error_code() === 'invalid_signature', 'Invalid webhook signature should return invalid_signature.');

// 6) Stripe replay tolerance should reject stale signed timestamp.
$settings = new SettingsService();
$stripe   = new StripeGateway($settings);
$oldTs    = time() - 3600;
$payload  = '{"id":"evt_old","type":"payment_intent.succeeded"}';
$sig      = hash_hmac('sha256', $oldTs . '.' . $payload, 'whsec_test');
$verified = $stripe->verify_webhook(array('stripe-signature' => "t={$oldTs},v1={$sig}"), $payload);
assert_true($verified === false, 'Stripe webhook verification should fail for stale timestamps.');

fwrite(STDOUT, "[PASS] Security smoke tests succeeded.\n");
exit(0);
