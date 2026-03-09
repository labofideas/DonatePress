<?php
/**
 * Smoke checks for privacy exporter/eraser registration.
 *
 * Run:
 * php tests/smoke-privacy.php
 */

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('add_filter')) {
	$GLOBALS['dp_test_filters'] = array();
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
if (!function_exists('__')) {
	function __($text, $domain = null) { return (string) $text; }
}
if (!function_exists('sanitize_email')) {
	function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
}
if (!function_exists('is_email')) {
	function is_email($value) { return false !== filter_var((string) $value, FILTER_VALIDATE_EMAIL); }
}
require_once __DIR__ . '/../includes/Repositories/DonorRepository.php';
require_once __DIR__ . '/../includes/Repositories/DonationRepository.php';
require_once __DIR__ . '/../includes/Repositories/SubscriptionRepository.php';
require_once __DIR__ . '/../includes/Repositories/PortalTokenRepository.php';
require_once __DIR__ . '/../includes/Core/Privacy.php';

$privacy = new DonatePress\Core\Privacy();
$privacy->register();

$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

if ( ! isset( $exporters['donatepress'] ) || ! is_callable( $exporters['donatepress']['callback'] ?? null ) ) {
	fwrite( STDERR, "[FAIL] donatepress privacy exporter missing.\n" );
	exit( 1 );
}

if ( ! isset( $erasers['donatepress'] ) || ! is_callable( $erasers['donatepress']['callback'] ?? null ) ) {
	fwrite( STDERR, "[FAIL] donatepress privacy eraser missing.\n" );
	exit( 1 );
}

$export_result = call_user_func( $exporters['donatepress']['callback'], 'donor@example.com', 1 );
$erase_result  = call_user_func( $erasers['donatepress']['callback'], 'donor@example.com', 1 );

if ( ! is_array( $export_result ) || ! array_key_exists( 'data', $export_result ) || ! array_key_exists( 'done', $export_result ) ) {
	fwrite( STDERR, "[FAIL] exporter response shape invalid.\n" );
	exit( 1 );
}

if ( ! is_array( $erase_result ) || ! array_key_exists( 'items_removed', $erase_result ) || ! array_key_exists( 'done', $erase_result ) ) {
	fwrite( STDERR, "[FAIL] eraser response shape invalid.\n" );
	exit( 1 );
}

fwrite( STDOUT, "[PASS] privacy exporter/eraser registered and callable.\n" );
