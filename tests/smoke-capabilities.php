<?php
/**
 * Smoke checks for capability matrix resolver.
 *
 * Run:
 * php tests/smoke-capabilities.php
 */

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['dp_test_filters'] = array();

if (!function_exists('sanitize_key')) {
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
}
if (!function_exists('add_filter')) {
	function add_filter(string $tag, callable $callback, ...$rest): void {
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
if (!function_exists('current_user_can')) {
	function current_user_can(string $capability): bool {
		$granted = array('manage_options');
		return in_array($capability, $granted, true);
	}
}

require_once __DIR__ . '/../includes/Security/CapabilityManager.php';

$manager = new DonatePress\Security\CapabilityManager();

$okDefault = $manager->can('reports.view');
if ($okDefault !== true) {
	fwrite(STDERR, "[FAIL] default capability resolution failed.\n");
	exit(1);
}

add_filter('donatepress_capability_map', static function (array $map): array {
	$map['reports.view'] = 'edit_posts';
	return $map;
});

$okMapped = $manager->resolve_capability('reports.view');
if ($okMapped !== 'edit_posts') {
	fwrite(STDERR, "[FAIL] capability map filter did not override scope.\n");
	exit(1);
}

$adminScope = $manager->resolve_capability('admin.settings');
if ($adminScope !== 'manage_options') {
	fwrite(STDERR, "[FAIL] admin capability scope resolution failed.\n");
	exit(1);
}

add_filter('donatepress_user_can', static function (bool $allowed, string $scope): bool {
	if ($scope === 'reports.view') {
		return true;
	}
	return $allowed;
}, 10, 2);

$okDecision = $manager->can('reports.view');
if ($okDecision !== true) {
	fwrite(STDERR, "[FAIL] final permission filter did not apply.\n");
	exit(1);
}

fwrite(STDOUT, "[PASS] capability matrix resolver smoke succeeded.\n");
