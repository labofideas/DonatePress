<?php
declare(strict_types=1);

function dp_admin_json_exit(array $payload, int $code = 0): void {
	fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
	exit($code);
}

function dp_admin_fail(string $message, int $code = 1): void {
	fwrite(STDERR, $message . PHP_EOL);
	exit($code);
}

function dp_admin_bootstrap(): void {
	$wp_load = dirname(__DIR__, 6) . '/wp-load.php';
	if (!file_exists($wp_load)) {
		dp_admin_fail('wp-load.php not found.');
	}

	require_once $wp_load;
}

function dp_admin_action(): string {
	global $argv;
	return isset($argv[1]) ? sanitize_key((string) $argv[1]) : '';
}

/**
 * @return array<int,string>
 */
function dp_admin_args(): array {
	global $argv;
	return array_slice($argv, 2);
}

function dp_admin_backup(): void {
	$settings = get_option('donatepress_settings', array());
	if (!is_array($settings)) {
		$settings = array();
	}

	dp_admin_json_exit(
		array(
			'ok'      => true,
			'payload' => base64_encode(wp_json_encode(
				array(
					'donatepress_settings'        => $settings,
					'donatepress_setup_completed' => (int) get_option('donatepress_setup_completed', 0),
				)
			)),
		)
	);
}

function dp_admin_restore(string $encoded): void {
	$decoded = base64_decode($encoded, true);
	if (!is_string($decoded) || '' === $decoded) {
		dp_admin_fail('Invalid restore payload.');
	}

	$data = json_decode($decoded, true);
	if (!is_array($data)) {
		dp_admin_fail('Restore payload could not be decoded.');
	}

	update_option('donatepress_settings', is_array($data['donatepress_settings'] ?? null) ? $data['donatepress_settings'] : array(), false);
	update_option('donatepress_setup_completed', (int) ($data['donatepress_setup_completed'] ?? 0), false);

	dp_admin_json_exit(array('ok' => true, 'restored' => true));
}

dp_admin_bootstrap();
$action = dp_admin_action();
$args   = dp_admin_args();

switch ($action) {
	case 'backup':
		dp_admin_backup();
		break;
	case 'restore':
		dp_admin_restore((string) ($args[0] ?? ''));
		break;
	default:
		dp_admin_fail('Unknown action. Use backup or restore.');
}
