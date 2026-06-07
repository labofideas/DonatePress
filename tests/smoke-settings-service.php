<?php

declare(strict_types=1);

namespace DonatePress\Services {
	function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function sanitize_textarea_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	function esc_url_raw($value) {
		$url = filter_var((string) $value, FILTER_SANITIZE_URL);
		return false === $url ? '' : $url;
	}
	function get_option($key, $default = null) { return $GLOBALS['dp_test_options'][$key] ?? $default; }
	function update_option($key, $value, $autoload = null) { $GLOBALS['dp_test_options'][$key] = $value; return true; }
	function __($text, $domain = null) { return (string) $text; }
	function apply_filters($tag, $value) { return $value; }
}

namespace {
	if (!defined('ABSPATH')) {
		define('ABSPATH', __DIR__ . '/');
	}

	$GLOBALS['dp_test_options'] = array();

	require_once __DIR__ . '/../includes/Services/SettingsService.php';

	use DonatePress\Services\SettingsService;

	function assert_true(bool $condition, string $message): void {
		if (!$condition) {
			fwrite(STDERR, "[FAIL] {$message}\n");
			exit(1);
		}
	}

	// 1) get() returns default when key is missing.
	$GLOBALS['dp_test_options'] = array();
	$service = new SettingsService();
	$value   = $service->get('nonexistent_key', 'fallback');
	assert_true($value === 'fallback', 'get() should return default when key is missing.');

	// 2) get() returns stored value when key exists.
	$GLOBALS['dp_test_options'] = array(
		'donatepress_settings' => array('base_currency' => 'EUR'),
	);
	$service2 = new SettingsService();
	$currency = $service2->get('base_currency', 'USD');
	assert_true($currency === 'EUR', 'get() should return stored value when present.');

	// 3) all() returns full array.
	$GLOBALS['dp_test_options'] = array(
		'donatepress_settings' => array('base_currency' => 'GBP', 'enable_captcha' => 1),
	);
	$service3 = new SettingsService();
	$all      = $service3->all();
	assert_true(is_array($all), 'all() should return an array.');
	assert_true($all['base_currency'] === 'GBP', 'all() should contain stored settings.');
	assert_true($all['enable_captcha'] === 1, 'all() should contain all stored keys.');

	// 4) all() returns empty array when option is not set.
	$GLOBALS['dp_test_options'] = array();
	$service4 = new SettingsService();
	$empty    = $service4->all();
	assert_true($empty === array(), 'all() should return empty array when option is unset.');

	// 5) update() sanitizes text fields (trims whitespace).
	$GLOBALS['dp_test_options'] = array('donatepress_settings' => array());
	$service5 = new SettingsService();
	$service5->update(array('organization_name' => '  My Org  '));
	$stored = $GLOBALS['dp_test_options']['donatepress_settings'];
	assert_true($stored['organization_name'] === 'My Org', 'update() should trim text fields.');

	// 6) update() sanitizes email.
	$GLOBALS['dp_test_options'] = array('donatepress_settings' => array());
	$service6 = new SettingsService();
	$service6->update(array('organization_email' => 'admin@example.com'));
	$stored6 = $GLOBALS['dp_test_options']['donatepress_settings'];
	assert_true($stored6['organization_email'] === 'admin@example.com', 'update() should sanitize email.');

	// 7) update() sanitizes URL fields.
	$GLOBALS['dp_test_options'] = array('donatepress_settings' => array());
	$service7 = new SettingsService();
	$service7->update(array('privacy_policy_url' => 'https://example.com/privacy'));
	$stored7 = $GLOBALS['dp_test_options']['donatepress_settings'];
	assert_true($stored7['privacy_policy_url'] === 'https://example.com/privacy', 'update() should preserve valid URLs.');

	// 8) update() sanitizes boolean fields.
	$GLOBALS['dp_test_options'] = array('donatepress_settings' => array());
	$service8 = new SettingsService();
	$service8->update(array('wc_enabled' => 'yes', 'live_mode_enabled' => '', 'enable_captcha' => 1));
	$stored8 = $GLOBALS['dp_test_options']['donatepress_settings'];
	assert_true($stored8['wc_enabled'] === 1, 'update() should coerce truthy values to 1.');
	assert_true($stored8['live_mode_enabled'] === 0, 'update() should coerce empty values to 0.');
	assert_true($stored8['enable_captcha'] === 1, 'update() should keep 1 as 1 for booleans.');

	// 9) update() preserves existing secrets when empty string passed.
	$GLOBALS['dp_test_options'] = array(
		'donatepress_settings' => array(
			'stripe_secret_key'     => 'sk_live_existing_key',
			'stripe_webhook_secret' => 'whsec_existing',
			'paypal_secret'         => 'paypal_secret_existing',
		),
	);
	$service9 = new SettingsService();
	$service9->update(array(
		'stripe_secret_key'     => '',
		'stripe_webhook_secret' => '',
		'paypal_secret'         => '',
	));
	$stored9 = $GLOBALS['dp_test_options']['donatepress_settings'];
	assert_true($stored9['stripe_secret_key'] === 'sk_live_existing_key', 'Empty stripe_secret_key should preserve existing value.');
	assert_true($stored9['stripe_webhook_secret'] === 'whsec_existing', 'Empty stripe_webhook_secret should preserve existing value.');
	assert_true($stored9['paypal_secret'] === 'paypal_secret_existing', 'Empty paypal_secret should preserve existing value.');

	// 10) update() replaces secrets when new non-empty value provided.
	$service9->update(array(
		'stripe_secret_key' => 'sk_live_new_key',
	));
	$stored10 = $GLOBALS['dp_test_options']['donatepress_settings'];
	assert_true($stored10['stripe_secret_key'] === 'sk_live_new_key', 'Non-empty secret should replace existing value.');

	// 11) update() sanitizes base_currency to uppercase.
	$GLOBALS['dp_test_options'] = array('donatepress_settings' => array());
	$service11 = new SettingsService();
	$service11->update(array('base_currency' => 'eur'));
	$stored11 = $GLOBALS['dp_test_options']['donatepress_settings'];
	assert_true($stored11['base_currency'] === 'EUR', 'base_currency should be uppercased.');

	// 12) update() merges with existing settings, not replaces.
	$GLOBALS['dp_test_options'] = array(
		'donatepress_settings' => array('base_currency' => 'USD', 'enable_captcha' => 1),
	);
	$service12 = new SettingsService();
	$service12->update(array('organization_name' => 'Charity'));
	$stored12 = $GLOBALS['dp_test_options']['donatepress_settings'];
	assert_true($stored12['base_currency'] === 'USD', 'Existing settings should be preserved after partial update.');
	assert_true($stored12['enable_captcha'] === 1, 'Existing boolean setting should be preserved.');
	assert_true($stored12['organization_name'] === 'Charity', 'New setting should be added.');

	// 13) update() handles textarea fields.
	$GLOBALS['dp_test_options'] = array('donatepress_settings' => array());
	$service13 = new SettingsService();
	$service13->update(array('tax_disclaimer_text' => '  Tax-deductible donation.  '));
	$stored13 = $GLOBALS['dp_test_options']['donatepress_settings'];
	assert_true($stored13['tax_disclaimer_text'] === 'Tax-deductible donation.', 'Textarea fields should be trimmed.');

	fwrite(STDOUT, "[PASS] Settings service smoke tests succeeded.\n");
	exit(0);
}
