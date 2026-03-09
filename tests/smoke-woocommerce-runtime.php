<?php
/**
 * Real WooCommerce runtime smoke (optional).
 *
 * Run:
 * DONATEPRESS_WC_RUNTIME=1 php tests/smoke-woocommerce-runtime.php
 */

declare(strict_types=1);

if (getenv('DONATEPRESS_WC_RUNTIME') !== '1') {
	fwrite(STDOUT, "[SKIP] Set DONATEPRESS_WC_RUNTIME=1 to run WooCommerce runtime smoke.\n");
	exit(0);
}

$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (!file_exists($wp_load)) {
	fwrite(STDOUT, "[SKIP] wp-load.php not found.\n");
	exit(0);
}

require_once $wp_load;

if (!class_exists('WooCommerce') || !function_exists('wc_create_order')) {
	fwrite(STDOUT, "[SKIP] WooCommerce runtime is unavailable.\n");
	exit(0);
}

if (!class_exists('DonatePress\\Integrations\\WooCommerceIntegration')) {
	$integrationPath = __DIR__ . '/../includes/Integrations/WooCommerceIntegration.php';
	if (file_exists($integrationPath)) {
		require_once $integrationPath;
	}
}

if (!class_exists('DonatePress\\Integrations\\WooCommerceIntegration')) {
	fwrite(STDOUT, "[SKIP] DonatePress WooCommerce integration class is unavailable.\n");
	exit(0);
}

global $wpdb;
if (!isset($wpdb) || empty($wpdb->dbh)) {
	fwrite(STDOUT, "[SKIP] Database connection unavailable.\n");
	exit(0);
}

use DonatePress\Integrations\WooCommerceIntegration;

function dp_wc_assert(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, "[FAIL] {$message}\n");
		exit(1);
	}
}

$productId = 0;
$orderId = 0;
$refundId = 0;

try {
	$product = new WC_Product_Simple();
	$product->set_name('DonatePress E2E Donation Product');
	$product->set_status('publish');
	$product->set_catalog_visibility('hidden');
	$product->set_virtual(true);
	$product->set_regular_price('25.00');
	$productId = (int) $product->save();
	dp_wc_assert($productId > 0, 'Could not create Woo test product.');
	update_post_meta($productId, '_donatepress_is_donation', '1');

	$order = wc_create_order();
	dp_wc_assert($order instanceof WC_Order, 'Could not create Woo test order.');
	$orderId = (int) $order->get_id();
	dp_wc_assert($orderId > 0, 'Woo test order ID missing.');

	$order->add_product(wc_get_product($productId), 1);
	$order->set_address(
		array(
			'first_name' => 'Runtime',
			'last_name'  => 'Tester',
			'email'      => 'runtime-woo@example.com',
			'address_1'  => '123 Test Street',
			'city'       => 'Test City',
			'state'      => 'CA',
			'postcode'   => '90001',
			'country'    => 'US',
		),
		'billing'
	);
	$order->calculate_totals();
	$order->save();

	$integration = new WooCommerceIntegration();
	$integration->sync_paid_order($orderId);

	$donationsTable = $wpdb->prefix . 'dp_donations';
	$statusRows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, status, gateway_transaction_id
			 FROM {$donationsTable}
			 WHERE gateway_transaction_id LIKE %s
			 ORDER BY id ASC",
			$wpdb->esc_like('wc_order_' . $orderId . '_item_') . '%'
		),
		ARRAY_A
	);

	dp_wc_assert(is_array($statusRows) && count($statusRows) > 0, 'No DonatePress donation rows were synced from Woo order.');
	foreach ($statusRows as $row) {
		dp_wc_assert(($row['status'] ?? '') === 'completed', 'Synced Woo donation should be completed.');
	}

	$refund = wc_create_refund(
		array(
			'order_id' => $orderId,
			'amount'   => (float) $order->get_total(),
			'reason'   => 'DonatePress runtime smoke refund',
		)
	);
	if ($refund instanceof WP_Error) {
		throw new RuntimeException('Could not create Woo refund: ' . $refund->get_error_message());
	}
	$refundId = (int) $refund->get_id();
	dp_wc_assert($refundId > 0, 'Woo refund creation failed.');

	$integration->sync_order_refund($orderId, $refundId);

	$refundedCount = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$donationsTable}
			 WHERE gateway_transaction_id LIKE %s
			 AND status = %s",
			$wpdb->esc_like('wc_order_' . $orderId . '_item_') . '%',
			'refunded'
		)
	);

	dp_wc_assert($refundedCount === count($statusRows), 'Refund sync did not mark all synced donations as refunded.');

	fwrite(STDOUT, "[PASS] WooCommerce runtime smoke succeeded.\n");
} catch (Throwable $e) {
	fwrite(STDERR, "[FAIL] " . $e->getMessage() . "\n");
	exit(1);
} finally {
	if ($orderId > 0) {
		$prefixLike = $wpdb->esc_like('wc_order_' . $orderId . '_item_') . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}dp_donations WHERE gateway_transaction_id LIKE %s",
				$prefixLike
			)
		);
	}
	if ($refundId > 0) {
		wp_delete_post($refundId, true);
	}
	if ($orderId > 0) {
		wp_delete_post($orderId, true);
	}
	if ($productId > 0) {
		wp_delete_post($productId, true);
	}
}
