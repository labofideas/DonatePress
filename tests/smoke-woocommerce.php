<?php

declare(strict_types=1);

namespace {
	if (!defined('ABSPATH')) {
		define('ABSPATH', __DIR__ . '/');
	}
	class wpdb {}
	$GLOBALS['wpdb'] = new wpdb();
	$GLOBALS['dp_wc_repo'] = null;

	function sanitize_email($value) { return filter_var((string) $value, FILTER_SANITIZE_EMAIL); }
	function is_email($value) { return (bool) filter_var((string) $value, FILTER_VALIDATE_EMAIL); }
	function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
	function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
	function wp_rand($min = 0, $max = 0) { return random_int((int) $min, (int) $max); }
	function add_action($hook, $cb, $priority = 10, $accepted_args = 1) {}
	function do_action($hook, ...$args) {}
	function get_post_meta($id, $key, $single = false) { return ''; }
	function __($text, $domain = null) { return (string) $text; }
}

namespace DonatePress\Repositories {
	class DonationRepository {
		public static array $rows = array();
		public function __construct($db) {
			$GLOBALS['dp_wc_repo'] = $this;
		}
		public function find_by_gateway_transaction(string $tx): ?array {
			foreach (self::$rows as $row) {
				if (($row['gateway_transaction_id'] ?? '') === $tx) {
					return $row;
				}
			}
			return null;
		}
		public function insert_pending(array $data): int {
			$id = count(self::$rows) + 1;
			$data['id'] = $id;
			$data['status'] = 'pending';
			self::$rows[] = $data;
			return $id;
		}
		public function update_status(int $id, string $status): bool {
			foreach (self::$rows as &$row) {
				if ((int) ($row['id'] ?? 0) === $id) {
					$row['status'] = $status;
				}
			}
			return true;
		}
		public function list_ids_by_gateway_prefix(string $prefix, int $limit = 500): array {
			$ids = array();
			foreach (self::$rows as $row) {
				$tx = (string) ($row['gateway_transaction_id'] ?? '');
				if (strpos($tx, $prefix) === 0) {
					$ids[] = (int) $row['id'];
				}
			}
			return $ids;
		}
	}
}

namespace DonatePress\Services {
	class AuditLogService {
		public function log(string $event, array $context = array()): void {}
	}
	class SettingsService {
		public function get(string $key, $default = '') {
			if ($key === 'wc_enabled') {
				return 1;
			}
			return $default;
		}
	}
}

namespace DonatePress\Integrations {
	function class_exists($class) {
		if ($class === 'WooCommerce') {
			return true;
		}
		return \class_exists($class);
	}
	function function_exists($fn) {
		if ($fn === 'wc_get_order') {
			return true;
		}
		return \function_exists($fn);
	}
	class FakeOrderItem {
		private bool $donation;
		private float $amount;
		public function __construct(bool $donation = true, float $amount = 20) {
			$this->donation = $donation;
			$this->amount = $amount;
		}
		public function get_meta($key, $single = true) {
			if ($key === '_donatepress_is_donation') return $this->donation ? '1' : '0';
			if ($key === '_donatepress_donation_amount') return $this->amount;
			return '';
		}
		public function get_product_id() { return 0; }
		public function get_total() { return $this->amount; }
	}
	class FakeOrder {
		public function get_billing_email() { return 'donor@example.com'; }
		public function get_billing_first_name() { return 'Donor'; }
		public function get_billing_last_name() { return 'Example'; }
		public function get_currency() { return 'USD'; }
		public function get_items($type = 'line_item') {
			return array(
				1 => new FakeOrderItem(true, 20.00),
				2 => new FakeOrderItem(true, 35.50),
				3 => new FakeOrderItem(false, 0.00),
			);
		}
	}
	function wc_get_order($order_id) {
		return $order_id > 0 ? new FakeOrder() : null;
	}
}

namespace {
	require_once __DIR__ . '/../includes/Integrations/WooCommerceIntegration.php';

	use DonatePress\Integrations\WooCommerceIntegration;

	function assert_true(bool $condition, string $message): void {
		if (!$condition) {
			fwrite(STDERR, "[FAIL] {$message}\n");
			exit(1);
		}
	}

	$integration = new WooCommerceIntegration();
	$integration->sync_paid_order(123);
	$repo = $GLOBALS['dp_wc_repo'];
	assert_true($repo instanceof \DonatePress\Repositories\DonationRepository, 'Repository should be initialized.');
	assert_true(count(\DonatePress\Repositories\DonationRepository::$rows) === 2, 'Paid order sync should create donation rows for donation line items only.');
	assert_true((\DonatePress\Repositories\DonationRepository::$rows[0]['status'] ?? '') === 'completed', 'First paid order donation should be marked completed.');
	assert_true((\DonatePress\Repositories\DonationRepository::$rows[1]['status'] ?? '') === 'completed', 'Second paid order donation should be marked completed.');

	$integration->sync_paid_order(123);
	assert_true(count(\DonatePress\Repositories\DonationRepository::$rows) === 2, 'Paid order sync must be idempotent for previously-synced line items.');

	$integration->sync_order_refund(123, 999);
	assert_true((\DonatePress\Repositories\DonationRepository::$rows[0]['status'] ?? '') === 'refunded', 'Refund sync should mark donation as refunded.');
	assert_true((\DonatePress\Repositories\DonationRepository::$rows[1]['status'] ?? '') === 'refunded', 'Refund sync should mark all synced line donations as refunded.');

	fwrite(STDOUT, "[PASS] WooCommerce smoke tests succeeded.\n");
	exit(0);
}
