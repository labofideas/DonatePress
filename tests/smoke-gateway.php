<?php

declare(strict_types=1);

namespace DonatePress\Services {
	function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function sanitize_textarea_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	function esc_url_raw($value) { return (string) $value; }
	function __($text, $domain = null) { return (string) $text; }
	function apply_filters($tag, $value) { return $value; }
	function get_option($key, $default = null) { return $GLOBALS['dp_test_options'][$key] ?? $default; }
	function update_option($key, $value, $autoload = null) { $GLOBALS['dp_test_options'][$key] = $value; return true; }
	function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
		return substr(bin2hex(random_bytes(32)), 0, (int) $length);
	}
	function wp_json_encode($value) { return json_encode($value); }
	function gmdate($format, $timestamp = null) { return \gmdate($format, $timestamp ?? time()); }
	function current_time($type, $gmt = 0) {
		if ('mysql' === $type) {
			return \gmdate('Y-m-d H:i:s');
		}
		return time();
	}
}

namespace DonatePress\Gateways\Offline {
	function __($text, $domain = null) { return (string) $text; }
	function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
		return substr(bin2hex(random_bytes(32)), 0, (int) $length);
	}
}

namespace DonatePress\Gateways {
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	function apply_filters($tag, $value, ...$args) {
		$filters = $GLOBALS['dp_test_filters'][$tag] ?? array();
		foreach ($filters as $cb) {
			$value = $cb($value, ...$args);
		}
		return $value;
	}
}

namespace {
	if (!defined('ABSPATH')) {
		define('ABSPATH', __DIR__ . '/');
	}
	if (!defined('DAY_IN_SECONDS')) {
		define('DAY_IN_SECONDS', 86400);
	}

	$GLOBALS['dp_test_options'] = array(
		'donatepress_settings' => array(
			'stripe_webhook_secret' => 'whsec_test',
		),
	);
	$GLOBALS['dp_test_filters']    = array();
	$GLOBALS['dp_test_transients'] = array();

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
	if (!function_exists('add_filter')) {
		function add_filter(string $tag, callable $callback): void {
			if (!isset($GLOBALS['dp_test_filters'][$tag])) {
				$GLOBALS['dp_test_filters'][$tag] = array();
			}
			$GLOBALS['dp_test_filters'][$tag][] = $callback;
		}
	}
	if (!function_exists('do_action')) {
		function do_action($tag, ...$args) {}
	}
	if (!function_exists('sanitize_text_field')) {
		function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	}
	if (!function_exists('sanitize_key')) {
		function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	}
	if (!function_exists('get_option')) {
		function get_option($key, $default = null) { return $GLOBALS['dp_test_options'][$key] ?? $default; }
	}
	if (!function_exists('get_transient')) {
		function get_transient($key) { return $GLOBALS['dp_test_transients'][$key] ?? false; }
	}
	if (!function_exists('set_transient')) {
		function set_transient($key, $value, $expiration = 0) { $GLOBALS['dp_test_transients'][$key] = $value; return true; }
	}
	if (!function_exists('wp_generate_password')) {
		function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
			return substr(bin2hex(random_bytes(32)), 0, (int) $length);
		}
	}

	require_once __DIR__ . '/../includes/Services/SettingsService.php';
	require_once __DIR__ . '/../includes/Gateways/GatewayInterface.php';
	require_once __DIR__ . '/../includes/Gateways/Stripe/StripeGateway.php';
	require_once __DIR__ . '/../includes/Gateways/PayPal/PayPalGateway.php';
	require_once __DIR__ . '/../includes/Gateways/Offline/OfflineGateway.php';
	require_once __DIR__ . '/../includes/Gateways/GatewayManager.php';

	use DonatePress\Gateways\GatewayInterface;
	use DonatePress\Gateways\GatewayManager;
	use DonatePress\Gateways\Offline\OfflineGateway;
	use DonatePress\Services\SettingsService;

	function assert_true(bool $condition, string $message): void {
		if (!$condition) {
			fwrite(STDERR, "[FAIL] {$message}\n");
			exit(1);
		}
	}

	$settings = new SettingsService();

	// 1) GatewayManager returns correct gateway by ID.
	$manager = new GatewayManager($settings);

	$stripe = $manager->get('stripe');
	assert_true($stripe !== null, 'GatewayManager should return stripe gateway.');
	assert_true($stripe->id() === 'stripe', 'Stripe gateway id() should be "stripe".');
	assert_true($stripe instanceof GatewayInterface, 'Stripe gateway should implement GatewayInterface.');

	$paypal = $manager->get('paypal');
	assert_true($paypal !== null, 'GatewayManager should return paypal gateway.');
	assert_true($paypal->id() === 'paypal', 'PayPal gateway id() should be "paypal".');

	$offline = $manager->get('offline');
	assert_true($offline !== null, 'GatewayManager should return offline gateway.');
	assert_true($offline->id() === 'offline', 'Offline gateway id() should be "offline".');

	// 2) GatewayManager returns null for unknown gateway.
	$unknown = $manager->get('nonexistent_gateway');
	assert_true($unknown === null, 'GatewayManager should return null for unknown gateway ID.');

	// 3) OfflineGateway start_payment returns success with manual flow.
	$offlineGw = new OfflineGateway();
	$payResult = $offlineGw->start_payment(
		array(
			'id'              => 1,
			'amount'          => 50.00,
			'currency'        => 'USD',
			'donation_number' => 'DN-001',
		),
		array()
	);

	assert_true($payResult['success'] === true, 'OfflineGateway start_payment should succeed.');
	assert_true($payResult['provider'] === 'offline', 'Provider should be "offline".');
	assert_true($payResult['flow'] === 'manual', 'Flow should be "manual".');
	assert_true(!empty($payResult['transaction_id']), 'Transaction ID should not be empty.');
	assert_true(str_starts_with($payResult['transaction_id'], 'off_'), 'Transaction ID should start with off_ prefix.');
	assert_true(!empty($payResult['message']), 'Payment result should include a message.');

	// 4) OfflineGateway verify_webhook returns false.
	$verifyResult = $offlineGw->verify_webhook(array(), '{}');
	assert_true($verifyResult === false, 'OfflineGateway verify_webhook should return false.');

	// 5) OfflineGateway handle_webhook returns unsupported.
	$webhookResult = $offlineGw->handle_webhook(array(), '{}');
	assert_true($webhookResult['success'] === false, 'OfflineGateway handle_webhook should return failure.');
	assert_true($webhookResult['code'] === 'webhook_not_supported', 'Error code should be webhook_not_supported.');

	// 6) OfflineGateway retry_subscription_charge returns manual required.
	$retryResult = $offlineGw->retry_subscription_charge(array('id' => 1));
	assert_true($retryResult['success'] === false, 'OfflineGateway retry should return failure.');
	assert_true($retryResult['code'] === 'manual_retry_required', 'Retry error code should be manual_retry_required.');

	// 7) OfflineGateway implements GatewayInterface.
	assert_true($offlineGw instanceof GatewayInterface, 'OfflineGateway should implement GatewayInterface.');
	assert_true($offlineGw->id() === 'offline', 'OfflineGateway id() should return "offline".');

	// 8) Custom gateway registration via donatepress_gateways filter.
	$customGateway = new class implements GatewayInterface {
		public function id(): string { return 'custom_test'; }
		public function start_payment(array $donation, array $context): array {
			return array('success' => true, 'provider' => 'custom_test', 'flow' => 'redirect');
		}
		public function verify_webhook(array $headers, string $payload): bool { return true; }
		public function handle_webhook(array $headers, string $payload): array {
			return array('success' => true, 'provider' => 'custom_test');
		}
	};

	add_filter('donatepress_gateways', static function ($gateways) use ($customGateway) {
		$gateways['custom_test'] = $customGateway;
		return $gateways;
	});

	$manager2 = new GatewayManager($settings);
	$custom   = $manager2->get('custom_test');
	assert_true($custom !== null, 'Custom gateway should be resolvable after filter registration.');
	assert_true($custom->id() === 'custom_test', 'Custom gateway id() should return "custom_test".');
	assert_true($custom instanceof GatewayInterface, 'Custom gateway should implement GatewayInterface.');

	$customPayment = $custom->start_payment(array('amount' => 100), array());
	assert_true($customPayment['success'] === true, 'Custom gateway start_payment should succeed.');
	assert_true($customPayment['provider'] === 'custom_test', 'Custom gateway provider should be "custom_test".');

	// 9) Built-in gateways still accessible after custom registration.
	$stillOffline = $manager2->get('offline');
	assert_true($stillOffline !== null, 'Offline gateway should still be accessible after custom gateway registration.');
	$stillStripe = $manager2->get('stripe');
	assert_true($stillStripe !== null, 'Stripe gateway should still be accessible after custom gateway registration.');

	fwrite(STDOUT, "[PASS] Gateway smoke tests succeeded.\n");
	exit(0);
}
