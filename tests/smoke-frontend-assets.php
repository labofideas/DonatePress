<?php
/**
 * Smoke checks for frontend asset loading behavior.
 *
 * Run:
 * php tests/smoke-frontend-assets.php
 */

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
if (!defined('DONATEPRESS_URL')) {
	define('DONATEPRESS_URL', 'https://example.org/wp-content/plugins/donatepress/');
}
if (!defined('DONATEPRESS_VERSION')) {
	define('DONATEPRESS_VERSION', '0.1.0-test');
}
if (!defined('DAY_IN_SECONDS')) {
	define('DAY_IN_SECONDS', 86400);
}

$GLOBALS['dp_registered_styles'] = array();
$GLOBALS['dp_registered_scripts'] = array();
$GLOBALS['dp_enqueued_styles'] = array();
$GLOBALS['dp_enqueued_scripts'] = array();
$GLOBALS['dp_transients'] = array();

if (!function_exists('wp_register_style')) {
	function wp_register_style(string $handle, string $src, array $deps = array(), $ver = null): void {
		$GLOBALS['dp_registered_styles'][$handle] = compact('src', 'deps', 'ver');
	}
}
if (!function_exists('wp_register_script')) {
	function wp_register_script(string $handle, string $src, array $deps = array(), $ver = null, bool $in_footer = false): void {
		$GLOBALS['dp_registered_scripts'][$handle] = compact('src', 'deps', 'ver', 'in_footer');
	}
}
if (!function_exists('wp_enqueue_style')) {
	function wp_enqueue_style(string $handle): void {
		$GLOBALS['dp_enqueued_styles'][] = $handle;
	}
}
if (!function_exists('wp_enqueue_script')) {
	function wp_enqueue_script(string $handle): void {
		$GLOBALS['dp_enqueued_scripts'][] = $handle;
	}
}
if (!function_exists('add_shortcode')) {
	function add_shortcode(string $tag, callable $callback): void {}
}
if (!function_exists('add_action')) {
	function add_action(string $tag, callable $callback): void {}
}
if (!function_exists('shortcode_atts')) {
	function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array {
		return array_merge($pairs, $atts);
	}
}
if (!function_exists('esc_url_raw')) {
	function esc_url_raw($value) { return (string) $value; }
}
if (!function_exists('esc_url')) {
	function esc_url($value) { return (string) $value; }
}
if (!function_exists('esc_attr')) {
	function esc_attr($value) { return (string) $value; }
}
if (!function_exists('esc_html')) {
	function esc_html($value) { return (string) $value; }
}
if (!function_exists('esc_html__')) {
	function esc_html__($text, $domain = null) { return (string) $text; }
}
if (!function_exists('__')) {
	function __($text, $domain = null) { return (string) $text; }
}
if (!function_exists('checked')) {
	function checked($checked, $current = true, $display = true) {
		if ((bool) $checked === (bool) $current) {
			return $display ? 'checked="checked"' : 'checked="checked"';
		}
		return '';
	}
}
if (!function_exists('number_format_i18n')) {
	function number_format_i18n($number, $decimals = 0) {
		return number_format((float) $number, (int) $decimals, '.', ',');
	}
}
if (!function_exists('sanitize_key')) {
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
}
if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
}
if (!function_exists('wp_create_nonce')) {
	function wp_create_nonce($action): string { return 'nonce_' . (string) $action; }
}
if (!function_exists('rest_url')) {
	function rest_url($path = ''): string { return 'https://example.org/wp-json/' . ltrim((string) $path, '/'); }
}
if (!function_exists('get_transient')) {
	function get_transient($key) { return $GLOBALS['dp_transients'][$key] ?? false; }
}
if (!function_exists('set_transient')) {
	function set_transient($key, $value, $expiration = 0) { $GLOBALS['dp_transients'][$key] = $value; return true; }
}
if (!function_exists('wp_salt')) {
	function wp_salt($scheme = '') { return 'salt_for_asset_smoke'; }
}
if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value) { return $value; }
}

require_once __DIR__ . '/../includes/Security/NonceManager.php';
require_once __DIR__ . '/../includes/Frontend/FormShortcode.php';
require_once __DIR__ . '/../includes/Frontend/PortalShortcode.php';

use DonatePress\Frontend\FormShortcode;
use DonatePress\Frontend\PortalShortcode;

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, "[FAIL] {$message}\n");
		exit(1);
	}
}

$form = new FormShortcode();
$form->register_assets();
assert_true(isset($GLOBALS['dp_registered_styles']['donatepress-form']), 'Form style should be registered.');
assert_true(isset($GLOBALS['dp_registered_scripts']['donatepress-form']), 'Form script should be registered.');
assert_true(empty($GLOBALS['dp_enqueued_styles']) && empty($GLOBALS['dp_enqueued_scripts']), 'No form assets should enqueue on register only.');

$form->render(array('id' => 1));
assert_true(in_array('donatepress-form', $GLOBALS['dp_enqueued_styles'], true), 'Form style should enqueue on render.');
assert_true(in_array('donatepress-form', $GLOBALS['dp_enqueued_scripts'], true), 'Form script should enqueue on render.');

$GLOBALS['dp_enqueued_styles'] = array();
$GLOBALS['dp_enqueued_scripts'] = array();

$portal = new PortalShortcode();
$portal->register_assets();
assert_true(isset($GLOBALS['dp_registered_styles']['donatepress-portal']), 'Portal style should be registered.');
assert_true(isset($GLOBALS['dp_registered_scripts']['donatepress-portal']), 'Portal script should be registered.');
assert_true(empty($GLOBALS['dp_enqueued_styles']) && empty($GLOBALS['dp_enqueued_scripts']), 'No portal assets should enqueue on register only.');

$portal->render(array());
assert_true(in_array('donatepress-portal', $GLOBALS['dp_enqueued_styles'], true), 'Portal style should enqueue on render.');
assert_true(in_array('donatepress-portal', $GLOBALS['dp_enqueued_scripts'], true), 'Portal script should enqueue on render.');

fwrite(STDOUT, "[PASS] Frontend asset-loading smoke checks succeeded.\n");
