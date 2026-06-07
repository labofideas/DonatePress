<?php

declare(strict_types=1);

namespace DonatePress\Services {
	function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function sanitize_textarea_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	function esc_url_raw($value) { return (string) $value; }
	function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
	function __($text, $domain = null) { return (string) $text; }
	function apply_filters($tag, $value) { return $value; }
	function get_option($key, $default = null) { return $GLOBALS['dp_test_options'][$key] ?? $default; }
	function update_option($key, $value, $autoload = null) { $GLOBALS['dp_test_options'][$key] = $value; return true; }
	function get_bloginfo($show = '') {
		if ('name' === $show) {
			return 'Test Blog';
		}
		return '';
	}
	function number_format_i18n($number, $decimals = 0) {
		return number_format((float) $number, $decimals, '.', ',');
	}
	function wp_strip_all_tags($string, $remove_breaks = false) {
		$string = strip_tags((string) $string);
		if ($remove_breaks) {
			$string = preg_replace('/[\r\n\t ]+/', ' ', $string);
		}
		return trim($string);
	}
	function current_time($type, $gmt = 0) {
		if ('mysql' === $type) {
			return \gmdate('Y-m-d H:i:s');
		}
		return time();
	}
}

namespace {
	if (!defined('ABSPATH')) {
		define('ABSPATH', __DIR__ . '/');
	}

	$GLOBALS['dp_test_options'] = array(
		'donatepress_settings' => array(
			'receipt_legal_entity_name' => 'Test Charity Inc',
			'tax_disclaimer_text'       => 'This donation may be tax-deductible.',
			'receipt_footer_text'       => 'Thank you for your generosity.',
		),
	);

	require_once __DIR__ . '/../includes/Services/SettingsService.php';
	require_once __DIR__ . '/../includes/Services/ReceiptService.php';

	use DonatePress\Services\ReceiptService;

	function assert_true(bool $condition, string $message): void {
		if (!$condition) {
			fwrite(STDERR, "[FAIL] {$message}\n");
			exit(1);
		}
	}

	$donation = array(
		'donation_number'  => 'DN-20260607-001',
		'donor_email'      => 'jane@example.com',
		'donor_first_name' => 'Jane',
		'donor_last_name'  => 'Doe',
		'currency'         => 'USD',
		'amount'           => 150.00,
		'donated_at'       => '2026-06-07 12:00:00',
	);

	// 1) render_html() returns valid HTML with donation details.
	$receipt = new ReceiptService();
	$html    = $receipt->render_html($donation);

	assert_true(is_string($html) && strlen($html) > 0, 'render_html() should return a non-empty string.');
	assert_true(str_contains($html, 'Test Charity Inc'), 'HTML should contain org name from settings.');
	assert_true(str_contains($html, 'DN-20260607-001'), 'HTML should contain donation number.');
	assert_true(str_contains($html, 'Jane Doe'), 'HTML should contain donor name.');
	assert_true(str_contains($html, 'jane@example.com'), 'HTML should contain donor email.');
	assert_true(str_contains($html, 'USD'), 'HTML should contain currency code.');
	assert_true(str_contains($html, '150.00'), 'HTML should contain formatted amount.');
	assert_true(str_contains($html, '2026-06-07 12:00:00'), 'HTML should contain donation date.');
	assert_true(str_contains($html, 'tax-deductible'), 'HTML should contain tax disclaimer text.');
	assert_true(str_contains($html, 'Thank you'), 'HTML should contain footer text.');
	assert_true(str_contains($html, '<h1>'), 'HTML should contain h1 tag for org name.');
	assert_true(str_contains($html, '<strong>'), 'HTML should contain strong tags for labels.');

	// 2) render_text() returns plain text without HTML tags.
	$text = $receipt->render_text($donation);

	assert_true(is_string($text) && strlen($text) > 0, 'render_text() should return a non-empty string.');
	assert_true(!str_contains($text, '<h1>'), 'Text receipt should not contain HTML tags.');
	assert_true(!str_contains($text, '<p>'), 'Text receipt should not contain p tags.');
	assert_true(!str_contains($text, '<strong>'), 'Text receipt should not contain strong tags.');
	assert_true(str_contains($text, 'DN-20260607-001'), 'Text should contain donation number.');
	assert_true(str_contains($text, '150.00'), 'Text should contain amount.');
	assert_true(str_contains($text, 'Jane Doe'), 'Text should contain donor name.');
	assert_true(str_contains($text, '-----'), 'Text should contain separator from hr replacement.');

	// 3) render_html() uses blog name as fallback when receipt_legal_entity_name is empty.
	$GLOBALS['dp_test_options'] = array(
		'donatepress_settings' => array(),
	);
	$receipt3 = new ReceiptService();
	$html3    = $receipt3->render_html($donation);
	assert_true(str_contains($html3, 'Test Blog'), 'HTML should fallback to blog name when entity name is not set.');

	// 4) render_html() handles missing donor fields gracefully.
	$minimalDonation = array(
		'amount' => 25.00,
	);
	$receipt4 = new ReceiptService();
	$html4    = $receipt4->render_html($minimalDonation);
	assert_true(is_string($html4) && strlen($html4) > 0, 'render_html() should handle minimal donation data.');
	assert_true(str_contains($html4, '25.00'), 'HTML should contain the amount even with minimal data.');

	// 5) render_text() handles minimal donation data.
	$text4 = $receipt4->render_text($minimalDonation);
	assert_true(is_string($text4) && strlen($text4) > 0, 'render_text() should handle minimal donation data.');
	assert_true(str_contains($text4, '25.00'), 'Text should contain amount with minimal data.');

	fwrite(STDOUT, "[PASS] Receipt smoke tests succeeded.\n");
	exit(0);
}
