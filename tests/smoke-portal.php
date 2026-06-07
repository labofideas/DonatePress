<?php

declare(strict_types=1);

namespace {
	if (!defined('ABSPATH')) {
		define('ABSPATH', __DIR__ . '/');
	}
	if (!defined('DAY_IN_SECONDS')) {
		define('DAY_IN_SECONDS', 86400);
	}
	if (!defined('MINUTE_IN_SECONDS')) {
		define('MINUTE_IN_SECONDS', 60);
	}

	$GLOBALS['dp_test_options']    = array();
	$GLOBALS['dp_test_transients'] = array();

	if (!class_exists('wpdb')) {
		class wpdb {
			public string $prefix = 'wp_';
		}
	}

	if (!function_exists('__')) {
		function __($text, $domain = null) { return (string) $text; }
	}
	if (!function_exists('apply_filters')) {
		function apply_filters($tag, $value, ...$args) { return $value; }
	}
	if (!function_exists('do_action')) {
		function do_action($tag, ...$args) {}
	}
	if (!function_exists('sanitize_email')) {
		function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
	}
	if (!function_exists('sanitize_text_field')) {
		function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	}
	if (!function_exists('sanitize_key')) {
		function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	}
	if (!function_exists('wp_generate_password')) {
		function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
			return substr(bin2hex(random_bytes(32)), 0, (int) $length);
		}
	}
	if (!function_exists('get_transient')) {
		function get_transient($key) { return $GLOBALS['dp_test_transients'][$key] ?? false; }
	}
	if (!function_exists('set_transient')) {
		function set_transient($key, $value, $expiration = 0) { $GLOBALS['dp_test_transients'][$key] = $value; return true; }
	}
	if (!function_exists('delete_transient')) {
		function delete_transient($key) { unset($GLOBALS['dp_test_transients'][$key]); return true; }
	}
	if (!function_exists('get_option')) {
		function get_option($key, $default = null) { return $GLOBALS['dp_test_options'][$key] ?? $default; }
	}
	if (!function_exists('update_option')) {
		function update_option($key, $value, $autoload = null) { $GLOBALS['dp_test_options'][$key] = $value; return true; }
	}
	if (!function_exists('current_time')) {
		function current_time($type, $gmt = 0) {
			if ('mysql' === $type) {
				return gmdate('Y-m-d H:i:s');
			}
			return time();
		}
	}
}

namespace DonatePress\Services {
	function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	function __($text, $domain = null) { return (string) $text; }
	function apply_filters($tag, $value) { return $value; }
	function do_action($tag, ...$args) {}
	function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
		return substr(bin2hex(random_bytes(32)), 0, (int) $length);
	}
	function gmdate($format, $timestamp = null) { return \gmdate($format, $timestamp ?? time()); }
	function current_time($type, $gmt = 0) {
		if ('mysql' === $type) {
			return \gmdate('Y-m-d H:i:s');
		}
		return time();
	}
	function get_transient($key) { return $GLOBALS['dp_test_transients'][$key] ?? false; }
	function set_transient($key, $value, $expiration = 0) { $GLOBALS['dp_test_transients'][$key] = $value; return true; }
	function delete_transient($key) { unset($GLOBALS['dp_test_transients'][$key]); return true; }
}

namespace DonatePress\Repositories {
	function current_time($type, $gmt = 0) {
		if ('mysql' === $type) {
			return \gmdate('Y-m-d H:i:s');
		}
		return time();
	}
}

namespace {
	require_once __DIR__ . '/../includes/Repositories/PortalTokenRepository.php';
	require_once __DIR__ . '/../includes/Services/PortalService.php';

	use DonatePress\Repositories\PortalTokenRepository;
	use DonatePress\Services\PortalService;

	function assert_true(bool $condition, string $message): void {
		if (!$condition) {
			fwrite(STDERR, "[FAIL] {$message}\n");
			exit(1);
		}
	}

	// --- 1) Magic link creation returns a token. ---
	$mockTokenRepo = new class extends PortalTokenRepository {
		public array $last_insert = array();
		public function __construct() {}
		public function insert(string $email, string $token_hash, string $ip_address, string $user_agent, string $expires_at): int {
			$this->last_insert = array(
				'email'      => $email,
				'token_hash' => $token_hash,
				'ip'         => $ip_address,
				'ua'         => $user_agent,
				'expires_at' => $expires_at,
			);
			return 42;
		}
		public function find_active_by_hash(string $token_hash): ?array { return null; }
		public function mark_used(int $id): bool { return true; }
	};

	$portalService = new PortalService($mockTokenRepo);
	$result = $portalService->create_magic_link('donor@example.com', '127.0.0.1', 'TestAgent/1.0');

	assert_true($result['success'] === true, 'Magic link creation should succeed.');
	assert_true(!empty($result['token']), 'Magic link should contain a token.');
	assert_true(strlen($result['token']) === 48, 'Token should be 48 characters long.');
	assert_true(!empty($result['expires_at']), 'Magic link should have an expiration.');
	assert_true($result['ttl'] > 0, 'Magic link TTL should be positive.');
	assert_true($mockTokenRepo->last_insert['email'] === 'donor@example.com', 'Repository should receive the donor email.');
	assert_true(!empty($mockTokenRepo->last_insert['token_hash']), 'Repository should receive a hashed token.');
	assert_true($mockTokenRepo->last_insert['token_hash'] !== $result['token'], 'Stored hash must differ from raw token.');

	// --- 2) Creation failure when repository returns 0. ---
	$failRepo = new class extends PortalTokenRepository {
		public function __construct() {}
		public function insert(string $email, string $token_hash, string $ip_address, string $user_agent, string $expires_at): int {
			return 0;
		}
		public function find_active_by_hash(string $token_hash): ?array { return null; }
		public function mark_used(int $id): bool { return false; }
	};

	$failService = new PortalService($failRepo);
	$failResult = $failService->create_magic_link('bad@example.com', '127.0.0.1', 'TestAgent');
	assert_true($failResult['success'] === false, 'Magic link creation should fail when repo returns 0.');
	assert_true($failResult['code'] === 'token_store_failed', 'Failure code should be token_store_failed.');

	// --- 3) Token consumption marks the token as used and returns session. ---
	$consumeToken = 'test_raw_token_for_consumption_1234567890abcde';
	$consumeHash  = hash('sha256', $consumeToken);

	$consumeRepo = new class($consumeHash) extends PortalTokenRepository {
		private string $expected_hash;
		public bool $mark_used_called = false;
		public function __construct(string $expected_hash) { $this->expected_hash = $expected_hash; }
		public function insert(string $email, string $token_hash, string $ip_address, string $user_agent, string $expires_at): int { return 1; }
		public function find_active_by_hash(string $token_hash): ?array {
			if ($token_hash === $this->expected_hash) {
				return array(
					'id'          => 99,
					'donor_email' => 'portal@example.com',
					'token_hash'  => $token_hash,
					'expires_at'  => gmdate('Y-m-d H:i:s', time() + 900),
					'used_at'     => null,
				);
			}
			return null;
		}
		public function mark_used(int $id): bool {
			$this->mark_used_called = true;
			return true;
		}
	};

	$consumeService = new PortalService($consumeRepo);
	$consumeResult  = $consumeService->consume_magic_link($consumeToken);

	assert_true($consumeResult['success'] === true, 'Token consumption should succeed.');
	assert_true(!empty($consumeResult['session_token']), 'Consumed token should yield a session token.');
	assert_true(str_starts_with($consumeResult['session_token'], 'dps_'), 'Session token should start with dps_ prefix.');
	assert_true($consumeResult['email'] === 'portal@example.com', 'Session should contain donor email.');
	assert_true($consumeResult['expires_in'] > 0, 'Session should have a positive expiry.');
	assert_true($consumeRepo->mark_used_called, 'mark_used should have been called on the repository.');

	// --- 4) Expired/invalid tokens are rejected. ---
	$expiredRepo = new class extends PortalTokenRepository {
		public function __construct() {}
		public function insert(string $email, string $token_hash, string $ip_address, string $user_agent, string $expires_at): int { return 1; }
		public function find_active_by_hash(string $token_hash): ?array {
			return null;
		}
		public function mark_used(int $id): bool { return true; }
	};

	$expiredService = new PortalService($expiredRepo);
	$expiredResult  = $expiredService->consume_magic_link('some_expired_or_invalid_token_value_here_12345');

	assert_true($expiredResult['success'] === false, 'Expired token consumption should fail.');
	assert_true($expiredResult['code'] === 'invalid_or_expired', 'Expired token should return invalid_or_expired code.');

	// --- 5) Session retrieval after consumption. ---
	$sessionToken = $consumeResult['session_token'];
	$session      = $consumeService->get_session($sessionToken);

	assert_true(is_array($session), 'get_session should return array for valid session.');
	assert_true($session['email'] === 'portal@example.com', 'Session should contain the correct donor email.');

	// --- 6) Session retrieval for unknown token returns null. ---
	$noSession = $consumeService->get_session('dps_nonexistent_session_token_value_here_12345678');
	assert_true($noSession === null, 'get_session should return null for unknown session.');

	// --- 7) Session revocation. ---
	$consumeService->revoke_session($sessionToken);
	$revokedSession = $consumeService->get_session($sessionToken);
	assert_true($revokedSession === null, 'Session should be null after revocation.');

	fwrite(STDOUT, "[PASS] Portal smoke tests succeeded.\n");
	exit(0);
}
