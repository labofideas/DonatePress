<?php

namespace DonatePress\Integrations;

use DonatePress\Repositories\DonationRepository;
use DonatePress\Services\AuditLogService;
use DonatePress\Services\SettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce one-time donation bridge and refund sync.
 */
class WooCommerceIntegration {
	private AuditLogService $audit_log;

	public function __construct() {
		$this->audit_log = new AuditLogService();
	}

	/**
	 * Register integration hooks.
	 */
	public function register(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'woocommerce_payment_complete', array( $this, 'sync_paid_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'sync_paid_order' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'sync_order_refund' ), 20, 2 );
	}

	/**
	 * Create DonatePress donation rows from paid WooCommerce order.
	 */
	public function sync_paid_order( int $order_id ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = sanitize_email( (string) $order->get_billing_email() );
		if ( ! is_email( $email ) ) {
			return;
		}

		global $wpdb;
		$repository = new DonationRepository( $wpdb );

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $this->is_donation_item( $item ) ) {
				continue;
			}

			$transaction_ref = 'wc_order_' . $order_id . '_item_' . (int) $item_id;
			$existing        = $repository->find_by_gateway_transaction( $transaction_ref );
			if ( $existing ) {
				continue;
			}

			$amount = $this->resolve_item_amount( $item );
			if ( $amount <= 0 ) {
				continue;
			}

			$number = 'DP-WC-' . $order_id . '-' . (int) $item_id . '-' . wp_rand( 100, 999 );
			$insert = $repository->insert_pending(
				array(
					'donation_number'        => $number,
					'donor_email'            => $email,
					'donor_first_name'       => sanitize_text_field( (string) $order->get_billing_first_name() ),
					'donor_last_name'        => sanitize_text_field( (string) $order->get_billing_last_name() ),
					'amount'                 => $amount,
					'currency'               => strtoupper( sanitize_text_field( (string) $order->get_currency() ) ),
					'gateway'                => 'woocommerce',
					'gateway_transaction_id' => $transaction_ref,
					'form_id'                => 1,
					'donor_comment'          => '',
					'is_recurring'           => false,
					'recurring_frequency'    => '',
				)
			);

			if ( $insert > 0 ) {
				$repository->update_status( $insert, 'completed' );
				$this->audit_log->log(
					'wc_order_synced',
					array(
						'order_id'     => $order_id,
						'donation_id'  => $insert,
						'item_id'      => (int) $item_id,
						'amount'       => $amount,
						'currency'     => strtoupper( sanitize_text_field( (string) $order->get_currency() ) ),
					)
				);
				do_action( 'donatepress_wc_order_synced', $order_id, $insert, $item_id, $amount );
			}
		}
	}

	/**
	 * Sync order refund to DonatePress donation statuses.
	 */
	public function sync_order_refund( int $order_id, int $refund_id ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		global $wpdb;
		$repository = new DonationRepository( $wpdb );
		$ids        = $repository->list_ids_by_gateway_prefix( 'wc_order_' . $order_id . '_item_' );
		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $donation_id ) {
			$repository->update_status( (int) $donation_id, 'refunded' );
		}
		$this->audit_log->log(
			'wc_refund_synced',
			array(
				'order_id'      => $order_id,
				'refund_id'     => $refund_id,
				'donation_ids'  => $ids,
			)
		);

		do_action( 'donatepress_wc_refund_synced', $order_id, $refund_id, $ids );
	}

	/**
	 * Whether WooCommerce integration should run.
	 */
	private function is_enabled(): bool {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$settings = new SettingsService();
		return (int) $settings->get( 'wc_enabled', 1 ) === 1;
	}

	/**
	 * Determine if order item should be treated as a donation line.
	 *
	 * @param \WC_Order_Item_Product $item WooCommerce order item.
	 */
	private function is_donation_item( $item ): bool {
		$flag_meta = (string) $item->get_meta( '_donatepress_is_donation', true );
		if ( in_array( strtolower( $flag_meta ), array( '1', 'true', 'yes' ), true ) ) {
			return true;
		}

		$product_id = (int) $item->get_product_id();
		if ( $product_id > 0 ) {
			$product_flag = (string) get_post_meta( $product_id, '_donatepress_is_donation', true );
			if ( in_array( strtolower( $product_flag ), array( '1', 'true', 'yes' ), true ) ) {
				return true;
			}
		}

		$explicit_amount = (float) $item->get_meta( '_donatepress_donation_amount', true );
		return $explicit_amount > 0;
	}

	/**
	 * Resolve donation amount for line item.
	 *
	 * @param \WC_Order_Item_Product $item WooCommerce order item.
	 */
	private function resolve_item_amount( $item ): float {
		$explicit_amount = (float) $item->get_meta( '_donatepress_donation_amount', true );
		if ( $explicit_amount > 0 ) {
			return $explicit_amount;
		}

		return (float) $item->get_total();
	}
}
