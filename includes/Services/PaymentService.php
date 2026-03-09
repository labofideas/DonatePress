<?php

namespace DonatePress\Services;

use DonatePress\Gateways\GatewayManager;
use DonatePress\Repositories\WebhookEventRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes payment and webhook operations to configured gateways.
 */
class PaymentService {
	/**
	 * Gateway manager.
	 */
	private GatewayManager $gateway_manager;
	/**
	 * Audit logger.
	 */
	private ?AuditLogService $audit_log = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$settings             = new SettingsService();
		$this->gateway_manager = new GatewayManager( $settings );
	}

	/**
	 * Start gateway payment flow.
	 *
	 * @param array<string,mixed> $donation Donation snapshot.
	 * @param array<string,mixed> $context  Request context.
	 * @return array<string,mixed>
	 */
	public function start_payment( array $donation, array $context ): array {
		$gateway_id = sanitize_key( (string) ( $donation['gateway'] ?? 'stripe' ) );
		$gateway    = $this->gateway_manager->get( $gateway_id );

		if ( ! $gateway ) {
			return array(
				'success' => false,
				'code'    => 'gateway_not_found',
				'message' => __( 'Selected gateway is not available.', 'donatepress' ),
			);
		}

		$result = $gateway->start_payment( $donation, $context );

		/**
		 * Filter payment start result.
		 */
		return apply_filters( 'donatepress_start_payment_result', $result, $gateway_id, $donation, $context );
	}

	/**
	 * Handle webhook for a specific gateway.
	 *
	 * @param array<string,string> $headers Header map.
	 * @return array<string,mixed>
	 */
	public function handle_webhook( string $gateway_id, array $headers, string $payload ): array {
		$gateway = $this->gateway_manager->get( $gateway_id );
		if ( ! $gateway ) {
			$this->audit()->log(
				'webhook_gateway_not_found',
				array(
					'gateway' => $gateway_id,
				)
			);
			return array(
				'success' => false,
				'code'    => 'gateway_not_found',
				'message' => __( 'Webhook gateway not found.', 'donatepress' ),
			);
		}

		if ( ! $gateway->verify_webhook( $headers, $payload ) ) {
			$this->audit()->log(
				'webhook_signature_invalid',
				array(
					'gateway' => $gateway_id,
				)
			);
			return array(
				'success' => false,
				'code'    => 'invalid_signature',
				'message' => __( 'Invalid webhook signature.', 'donatepress' ),
			);
		}

		global $wpdb;
		$event_store = new WebhookEventRepository( $wpdb );
		$event_id    = $this->extract_webhook_event_id( $gateway_id, $payload );
		$event_hash  = hash( 'sha256', $payload );
		$event_key   = '' !== $event_id ? $event_id : 'payload_' . substr( $event_hash, 0, 32 );

		if ( ! $event_store->acquire( $gateway_id, $event_key, $event_hash ) ) {
			$this->audit()->log(
				'webhook_duplicate_ignored',
				array(
					'gateway'  => $gateway_id,
					'event_id' => $event_key,
				)
			);
			return array(
				'success'    => true,
				'provider'   => $gateway_id,
				'event_id'   => $event_key,
				'event_type' => '',
				'duplicate'  => true,
			);
		}

		$result = $gateway->handle_webhook( $headers, $payload );
		if ( empty( $result['success'] ) ) {
			$event_store->release( $gateway_id, $event_key );
			$this->audit()->log(
				'webhook_processing_failed',
				array(
					'gateway'  => $gateway_id,
					'event_id' => $event_key,
					'code'     => sanitize_key( (string) ( $result['code'] ?? '' ) ),
					'message'  => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
				)
			);
		} else {
			$message = sanitize_text_field( (string) ( $result['message'] ?? '' ) );
			$event_store->mark_processed( $gateway_id, $event_key, $message );
			$this->audit()->log(
				'webhook_processed',
				array(
					'gateway'    => $gateway_id,
					'event_id'   => $event_key,
					'event_type' => sanitize_text_field( (string) ( $result['event_type'] ?? '' ) ),
				)
			);
		}

		if ( ! isset( $result['event_id'] ) || '' === (string) $result['event_id'] ) {
			$result['event_id'] = $event_key;
		}

		/**
		 * Filter webhook handling result.
		 */
		return apply_filters( 'donatepress_webhook_result', $result, $gateway_id, $headers, $payload );
	}

	/**
	 * Resolve audit logger lazily to support test doubles.
	 */
	private function audit(): AuditLogService {
		if ( null === $this->audit_log ) {
			$this->audit_log = new AuditLogService();
		}
		return $this->audit_log;
	}

	/**
	 * Extract provider event id from webhook payload.
	 */
	private function extract_webhook_event_id( string $gateway_id, string $payload ): string {
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) ) {
			return '';
		}

		if ( 'stripe' === $gateway_id ) {
			return sanitize_text_field( (string) ( $data['id'] ?? '' ) );
		}
		if ( 'paypal' === $gateway_id ) {
			return sanitize_text_field( (string) ( $data['id'] ?? '' ) );
		}

		return sanitize_text_field( (string) ( $data['id'] ?? '' ) );
	}

	/**
	 * Attempt recurring charge retry for subscription.
	 *
	 * @param array<string,mixed> $subscription Subscription payload.
	 * @return array<string,mixed>
	 */
	public function retry_subscription_charge( string $gateway_id, array $subscription ): array {
		$gateway = $this->gateway_manager->get( $gateway_id );
		if ( ! $gateway ) {
			return array(
				'success' => false,
				'code'    => 'gateway_not_found',
				'message' => __( 'Retry gateway not found.', 'donatepress' ),
			);
		}

		if ( ! method_exists( $gateway, 'retry_subscription_charge' ) ) {
			return array(
				'success' => false,
				'code'    => 'retry_not_supported',
				'message' => __( 'Gateway does not support retry charge execution.', 'donatepress' ),
			);
		}

		/** @var callable $callable */
		$callable = array( $gateway, 'retry_subscription_charge' );
		$result   = call_user_func( $callable, $subscription );

		return apply_filters( 'donatepress_retry_subscription_result', $result, $gateway_id, $subscription );
	}
}
