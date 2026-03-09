<?php
/**
 * WooCommerce runtime fixture helper for Playwright.
 *
 * Usage:
 * php tests/e2e/helpers/woo-runtime-fixture.php seed
 * php tests/e2e/helpers/woo-runtime-fixture.php inspect <order_id>
 * php tests/e2e/helpers/woo-runtime-fixture.php cleanup [order_id]
 */

declare(strict_types=1);

const DP_E2E_WC_FIXTURE_OPTION = 'donatepress_e2e_wc_fixture';

/**
 * Print JSON and exit.
 *
 * @param array<string,mixed> $payload
 */
function dp_e2e_json_exit(array $payload, int $code = 0): void {
	fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
	exit($code);
}

function dp_e2e_fail(string $message, int $code = 1): void {
	fwrite(STDERR, $message . PHP_EOL);
	exit($code);
}

function dp_e2e_bootstrap_wordpress(): void {
	$wp_load = dirname(__DIR__, 6) . '/wp-load.php';
	if (!file_exists($wp_load)) {
		dp_e2e_fail('wp-load.php not found.');
	}

	require_once $wp_load;

	if (!class_exists('WooCommerce') || !function_exists('wc_get_cart_url') || !function_exists('wc_get_checkout_url')) {
		dp_e2e_fail('WooCommerce runtime is unavailable.');
	}

	if (!class_exists('DonatePress\\Integrations\\WooCommerceIntegration')) {
		dp_e2e_fail('DonatePress WooCommerce integration is unavailable.');
	}
}

function dp_e2e_action(): string {
	global $argv;
	return isset($argv[1]) ? sanitize_key((string) $argv[1]) : '';
}

/**
 * @return array<int,string>
 */
function dp_e2e_args(): array {
	global $argv;
	return array_slice($argv, 2);
}

/**
 * @param mixed $value
 */
function dp_e2e_get_option_raw(string $key, $value = null) {
	$sentinel = new stdClass();
	$result   = get_option($key, $sentinel);
	return $sentinel === $result ? $value : $result;
}

function dp_e2e_create_page(string $title, string $slug, string $content): int {
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'meta_input'   => array(
				'_donatepress_e2e_fixture' => '1',
			),
		),
		true
	);

	if ($page_id instanceof WP_Error) {
		dp_e2e_fail('Could not create fixture page: ' . $page_id->get_error_message());
	}

	return (int) $page_id;
}

function dp_e2e_restore_options(array $fixture): void {
	$originals = isset($fixture['original_options']) && is_array($fixture['original_options']) ? $fixture['original_options'] : array();
	foreach ($originals as $key => $value) {
		if (null === $value) {
			delete_option((string) $key);
			continue;
		}
		update_option((string) $key, $value, false);
	}
}

function dp_e2e_cleanup_order(int $order_id): void {
	if ($order_id <= 0) {
		return;
	}

	global $wpdb;
	if ($wpdb instanceof wpdb) {
		$prefix_like = $wpdb->esc_like('wc_order_' . $order_id . '_item_') . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}dp_donations WHERE gateway_transaction_id LIKE %s",
				$prefix_like
			)
		);
	}

	$refund_ids = wc_get_orders(
		array(
			'type'   => 'shop_order_refund',
			'parent' => $order_id,
			'limit'  => -1,
			'return' => 'ids',
		)
	);

	if (is_array($refund_ids)) {
		foreach ($refund_ids as $refund_id) {
			wp_delete_post((int) $refund_id, true);
		}
	}

	wp_delete_post($order_id, true);
}

function dp_e2e_cleanup_fixture(?int $order_id = null): void {
	$fixture = get_option(DP_E2E_WC_FIXTURE_OPTION, array());
	if (is_array($fixture) && !empty($fixture)) {
		dp_e2e_restore_options($fixture);

		$product_id = isset($fixture['product_id']) ? (int) $fixture['product_id'] : 0;
		if ($product_id > 0) {
			wp_delete_post($product_id, true);
		}

		$page_ids = isset($fixture['created_page_ids']) && is_array($fixture['created_page_ids']) ? $fixture['created_page_ids'] : array();
		foreach ($page_ids as $page_id) {
			wp_delete_post((int) $page_id, true);
		}
	}

	delete_option(DP_E2E_WC_FIXTURE_OPTION);

	if (null !== $order_id && $order_id > 0) {
		dp_e2e_cleanup_order($order_id);
	}
}

function dp_e2e_order_admin_url(int $order_id): string {
	if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
		return admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
	}

	return admin_url('post.php?post=' . $order_id . '&action=edit');
}

function dp_e2e_seed(): void {
	dp_e2e_cleanup_fixture();

	$cart_page_id     = dp_e2e_create_page('DonatePress E2E Cart', 'donatepress-e2e-cart', '[woocommerce_cart]');
	$checkout_page_id = dp_e2e_create_page('DonatePress E2E Checkout', 'donatepress-e2e-checkout', '[woocommerce_checkout]');
	$amount_value     = getenv('E2E_WC_DONATION_AMOUNT');
	$amount_number    = is_string($amount_value) && '' !== trim($amount_value) ? (float) $amount_value : 25.00;
	$amount           = number_format($amount_number, 2, '.', '');

	$product = new WC_Product_Simple();
	$product->set_name('DonatePress Woo UI E2E Donation');
	$product->set_status('publish');
	$product->set_catalog_visibility('hidden');
	$product->set_virtual(true);
	$product->set_regular_price($amount);
	$product->set_sold_individually(true);
	$product_id = (int) $product->save();

	if ($product_id <= 0) {
		dp_e2e_fail('Could not create WooCommerce fixture product.');
	}

	update_post_meta($product_id, '_donatepress_is_donation', '1');
	update_post_meta($product_id, '_visibility', 'hidden');

	$original_options = array(
		'woocommerce_cart_page_id'                     => dp_e2e_get_option_raw('woocommerce_cart_page_id', null),
		'woocommerce_checkout_page_id'                 => dp_e2e_get_option_raw('woocommerce_checkout_page_id', null),
		'woocommerce_enable_guest_checkout'            => dp_e2e_get_option_raw('woocommerce_enable_guest_checkout', null),
		'woocommerce_enable_signup_and_login_from_checkout' => dp_e2e_get_option_raw('woocommerce_enable_signup_and_login_from_checkout', null),
		'woocommerce_cod_settings'                     => dp_e2e_get_option_raw('woocommerce_cod_settings', null),
		'woocommerce_bacs_settings'                    => dp_e2e_get_option_raw('woocommerce_bacs_settings', null),
	);

	update_option('woocommerce_cart_page_id', $cart_page_id, false);
	update_option('woocommerce_checkout_page_id', $checkout_page_id, false);
	update_option('woocommerce_enable_guest_checkout', 'yes', false);
	update_option('woocommerce_enable_signup_and_login_from_checkout', 'no', false);

	$cod_settings = dp_e2e_get_option_raw('woocommerce_cod_settings', array());
	if (!is_array($cod_settings)) {
		$cod_settings = array();
	}
	$cod_settings['enabled']            = 'yes';
	$cod_settings['title']              = 'Cash on delivery';
	$cod_settings['enable_for_virtual'] = 'yes';
	update_option('woocommerce_cod_settings', $cod_settings, false);

	$bacs_settings = dp_e2e_get_option_raw('woocommerce_bacs_settings', array());
	if (!is_array($bacs_settings)) {
		$bacs_settings = array();
	}
	$bacs_settings['enabled'] = 'yes';
	$bacs_settings['title']   = 'Direct bank transfer';
	update_option('woocommerce_bacs_settings', $bacs_settings, false);

	$fixture = array(
		'product_id'        => $product_id,
		'created_page_ids'  => array($cart_page_id, $checkout_page_id),
		'original_options'  => $original_options,
		'amount'            => $amount,
	);
	update_option(DP_E2E_WC_FIXTURE_OPTION, $fixture, false);

	dp_e2e_json_exit(
		array(
			'ok'                    => true,
			'amount'                => $amount,
			'productId'             => $product_id,
			'cartPageId'            => $cart_page_id,
			'checkoutPageId'        => $checkout_page_id,
			'addToCartUrl'          => add_query_arg('add-to-cart', (string) $product_id, wc_get_cart_url()),
			'cartUrl'               => wc_get_cart_url(),
			'checkoutUrl'           => wc_get_checkout_url(),
			'loginUrl'              => wp_login_url(admin_url()),
			'adminOrderUrlTemplate' => str_replace('999999999', '%d', dp_e2e_order_admin_url(999999999)),
		)
	);
}

function dp_e2e_inspect(int $order_id): void {
	if ($order_id <= 0) {
		dp_e2e_fail('A valid order ID is required for inspect.');
	}

	$order = wc_get_order($order_id);
	if (!$order instanceof WC_Order) {
		dp_e2e_fail('Order not found.');
	}

	global $wpdb;
	$donations = array();
	if ($wpdb instanceof wpdb) {
		$donations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, donation_number, amount, currency, status, gateway_transaction_id
				FROM {$wpdb->prefix}dp_donations
				WHERE gateway_transaction_id LIKE %s
				ORDER BY id ASC",
				$wpdb->esc_like('wc_order_' . $order_id . '_item_') . '%'
			),
			ARRAY_A
		);
	}

	$refund_ids = wc_get_orders(
		array(
			'type'   => 'shop_order_refund',
			'parent' => $order_id,
			'limit'  => -1,
			'return' => 'ids',
		)
	);

	dp_e2e_json_exit(
		array(
			'ok'             => true,
			'orderId'        => $order_id,
			'orderStatus'    => $order->get_status(),
			'orderTotal'     => (float) $order->get_total(),
			'totalRefunded'  => (float) $order->get_total_refunded(),
			'refundIds'      => is_array($refund_ids) ? array_map('intval', $refund_ids) : array(),
			'donations'      => is_array($donations) ? $donations : array(),
			'donationCount'  => is_array($donations) ? count($donations) : 0,
			'adminOrderUrl'  => dp_e2e_order_admin_url($order_id),
		)
	);
}

function dp_e2e_cleanup_command(?int $order_id = null): void {
	dp_e2e_cleanup_fixture($order_id);
	dp_e2e_json_exit(
		array(
			'ok'      => true,
			'cleaned' => true,
			'orderId' => $order_id,
		)
	);
}

dp_e2e_bootstrap_wordpress();

$action = dp_e2e_action();
$args   = dp_e2e_args();

switch ($action) {
	case 'seed':
		dp_e2e_seed();
		break;

	case 'inspect':
		dp_e2e_inspect(isset($args[0]) ? (int) $args[0] : 0);
		break;

	case 'cleanup':
		dp_e2e_cleanup_command(isset($args[0]) ? (int) $args[0] : null);
		break;

	default:
		dp_e2e_fail('Unknown action. Use seed, inspect, or cleanup.');
}
