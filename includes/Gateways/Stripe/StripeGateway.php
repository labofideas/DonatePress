<?php

namespace DonatePress\Gateways\Stripe;

use DonatePress\Gateways\GatewayInterface;
use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Services\SettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe payment and webhook implementation.
 */
class StripeGateway implements GatewayInterface {
	/**
	 * Settings.
	 */
	private SettingsService $settings;

	/**
	 * Constructor.
	 */
	public function __construct( SettingsService $settings ) {
		$this->settings = $settings;
	}

	public function id(): string {
		return 'stripe';
	}

	public function start_payment( array $donation, array $context ): array {
		$publishable = (string) $this->settings->get( 'stripe_publishable_key', '' );
		$secret      = (string) $this->settings->get( 'stripe_secret_key', '' );

		if ( '' === $publishable || '' === $secret ) {
			return array(
				'success' => false,
				'code'    => 'stripe_not_configured',
				'message' => __( 'Stripe is not configured yet.', 'donatepress' ),
			);
		}

		$amount_cents = (int) round( (float) ( $donation['amount'] ?? 0 ) * 100 );
		if ( $amount_cents < 50 ) {
			return array(
				'success' => false,
				'code'    => 'amount_too_small',
				'message' => __( 'Amount is too small for Stripe.', 'donatepress' ),
			);
		}

		$currency = strtolower( sanitize_text_field( (string) ( $donation['currency'] ?? 'usd' ) ) );
		$metadata = array(
			'donation_id'     => (string) ( $donation['id'] ?? '' ),
			'donation_number' => (string) ( $donation['donation_number'] ?? '' ),
			'form_id'         => (string) ( $donation['form_id'] ?? '' ),
		);

		$body = array(
			'amount'                    => (string) $amount_cents,
			'currency'                  => $currency,
			'automatic_payment_methods[enabled]' => 'true',
			'description'               => sprintf( 'DonatePress %s', (string) ( $donation['donation_number'] ?? '' ) ),
			'receipt_email'             => sanitize_email( (string) ( $donation['donor_email'] ?? '' ) ),
		);

		foreach ( $metadata as $meta_key => $meta_value ) {
			if ( '' !== $meta_value ) {
				$body[ 'metadata[' . $meta_key . ']' ] = $meta_value;
			}
		}

		$body = apply_filters( 'donatepress_stripe_payment_intent_body', $body, $donation, $context );

		$request_args = array(
			'timeout' => 20,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $secret,
				'Content-Type'   => 'application/x-www-form-urlencoded',
				'Idempotency-Key'=> 'dp_pi_' . sanitize_key( (string) ( $donation['donation_number'] ?? wp_generate_password( 12, false, false ) ) ),
			),
			'body'    => http_build_query( $body, '', '&' ),
		);

		$request_args = apply_filters( 'donatepress_stripe_payment_intent_request_args', $request_args, $donation, $context );

		$remote = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', $request_args );
		if ( is_wp_error( $remote ) ) {
			return array(
				'success' => false,
				'code'    => 'stripe_request_failed',
				'message' => $remote->get_error_message(),
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $remote );
		$raw_body    = (string) wp_remote_retrieve_body( $remote );
		$decoded     = json_decode( $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 || ! is_array( $decoded ) ) {
			$error_message = __( 'Stripe payment intent request failed.', 'donatepress' );
			if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
				$error_message = sanitize_text_field( (string) $decoded['error']['message'] );
			}

			return array(
				'success' => false,
				'code'    => 'stripe_api_error',
				'message' => $error_message,
				'http'    => $status_code,
			);
		}

		$response = array(
			'success'                => true,
			'provider'               => 'stripe',
			'flow'                   => 'payment_intent',
			'publishable_key'        => $publishable,
			'payment_intent_id'      => sanitize_text_field( (string) ( $decoded['id'] ?? '' ) ),
			'client_secret'          => sanitize_text_field( (string) ( $decoded['client_secret'] ?? '' ) ),
			'requires_confirmation'  => true,
			'webhook_expected_event' => 'payment_intent.succeeded',
		);

		return apply_filters( 'donatepress_stripe_start_payment', $response, $donation, $context );
	}

	public function verify_webhook( array $headers, string $payload ): bool {
		$normalized = array();
		foreach ( $headers as $key => $value ) {
			$normalized_key                = strtolower( str_replace( '_', '-', (string) $key ) );
			$normalized[ $normalized_key ] = (string) $value;
		}

		$signature_header = $normalized['stripe-signature'] ?? '';
		if ( '' === $signature_header && isset( $normalized['http-stripe-signature'] ) ) {
			$signature_header = $normalized['http-stripe-signature'];
		}

		$secret = (string) $this->settings->get( 'stripe_webhook_secret', '' );

		if ( '' === $signature_header || '' === $secret ) {
			return false;
		}

		$parts = array();
		foreach ( explode( ',', $signature_header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( count( $pair ) === 2 ) {
				$parts[ $pair[0] ] = $pair[1];
			}
		}

		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			return false;
		}

		$timestamp = (int) $parts['t'];
		$tolerance = (int) apply_filters( 'donatepress_stripe_webhook_tolerance', 300 );
		if ( abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $payload;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );
		if ( ! hash_equals( $expected, (string) $parts['v1'] ) ) {
			return false;
		}

		return true;
	}

	public function handle_webhook( array $headers, string $payload ): array {
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) ) {
			return array(
				'success' => false,
				'code'    => 'invalid_json',
				'message' => __( 'Invalid Stripe payload.', 'donatepress' ),
			);
		}

		$event_id   = sanitize_text_field( (string) ( $data['id'] ?? '' ) );
		$event_type = sanitize_text_field( (string) ( $data['type'] ?? '' ) );

		if ( '' !== $event_id ) {
			$event_lock = 'dp_wh_stripe_' . md5( $event_id );
			if ( get_transient( $event_lock ) ) {
				return array(
					'success'    => true,
					'provider'   => 'stripe',
					'event_id'   => $event_id,
					'event_type' => $event_type,
					'duplicate'  => true,
				);
			}
			set_transient( $event_lock, 1, DAY_IN_SECONDS );
		}

		$object         = $data['data']['object'] ?? array();
		$transaction_id = sanitize_text_field( (string) ( $object['id'] ?? '' ) );
		$metadata       = is_array( $object['metadata'] ?? null ) ? $object['metadata'] : array();
		$donation_id    = isset( $metadata['donation_id'] ) ? (int) $metadata['donation_id'] : 0;
		$subscription_ref = sanitize_text_field( (string) ( $object['subscription'] ?? '' ) );
		if ( '' === $subscription_ref && 'subscription' === (string) ( $object['object'] ?? '' ) ) {
			$subscription_ref = sanitize_text_field( (string) ( $object['id'] ?? '' ) );
		}

		global $wpdb;
		$repo     = new DonationRepository( $wpdb );
		$sub_repo = new SubscriptionRepository( $wpdb );
		$donation = null;

		if ( $donation_id > 0 ) {
			$donation = $repo->find( $donation_id );
		}
		if ( ! $donation && '' !== $transaction_id ) {
			$donation = $repo->find_by_gateway_transaction( $transaction_id );
		}

		if ( $donation && '' !== $transaction_id ) {
			$repo->update_gateway_transaction( (int) $donation['id'], $transaction_id );
		}

		if ( $donation ) {
			$status_map = array(
				'payment_intent.succeeded'      => 'completed',
				'payment_intent.payment_failed' => 'failed',
				'charge.refunded'               => 'refunded',
			);

			if ( isset( $status_map[ $event_type ] ) ) {
				$repo->update_status( (int) $donation['id'], $status_map[ $event_type ] );
				do_action( 'donatepress_donation_status_synced', (int) $donation['id'], $status_map[ $event_type ], 'stripe', $event_type );
			}
		}

		$subscription = null;
		if ( ! empty( $donation['subscription_id'] ) ) {
			$subscription = $sub_repo->find( (int) $donation['subscription_id'] );
		}
		if ( ! $subscription && '' !== $subscription_ref ) {
			$subscription = $sub_repo->find_by_gateway_subscription( 'stripe', $subscription_ref );
		}

		$subscription_status = $this->map_subscription_status( $event_type, is_array( $object ) ? $object : array() );
		if ( $subscription ) {
			$subscription_id = (int) $subscription['id'];

			if ( '' !== $subscription_ref && '' === (string) ( $subscription['gateway_subscription_id'] ?? '' ) ) {
				$sub_repo->update_gateway_subscription_id( $subscription_id, $subscription_ref );
			}

			if ( '' !== $subscription_status ) {
				$sub_repo->update_status( $subscription_id, $subscription_status );
				if ( in_array( $event_type, array( 'payment_intent.succeeded', 'invoice.payment_succeeded' ), true ) ) {
					$frequency = sanitize_key( (string) ( $subscription['frequency'] ?? 'monthly' ) );
					$next_at   = $this->next_cycle_datetime( $frequency );
					$sub_repo->mark_payment_success( $subscription_id, $next_at );
				} elseif ( in_array( $event_type, array( 'payment_intent.payment_failed', 'invoice.payment_failed' ), true ) ) {
					$sub_repo->increment_failure( $subscription_id );
					$current = $sub_repo->find( $subscription_id );
					if ( $current ) {
						$failure_count = (int) ( $current['failure_count'] ?? 0 );
						$max_retries   = max( 1, (int) ( $current['max_retries'] ?? 3 ) );
						if ( $failure_count >= $max_retries ) {
							$sub_repo->update_status( $subscription_id, 'expired' );
							$sub_repo->update_next_payment_at( $subscription_id, null );
						} else {
							$sub_repo->update_next_payment_at( $subscription_id, $this->next_retry_datetime( $failure_count ) );
						}
					}
				}
			}
		}

		do_action( 'donatepress_stripe_webhook_event', $event_type, $data );

		return array(
			'success'        => true,
			'event_id'       => $event_id,
			'event_type'     => $event_type,
			'provider'       => 'stripe',
			'transaction_id' => $transaction_id,
		);
	}

	/**
	 * Attempt recurring retry charge (integration hook point).
	 *
	 * @param array<string,mixed> $subscription Subscription payload.
	 * @return array<string,mixed>
	 */
	public function retry_subscription_charge( array $subscription ): array {
		$gateway_subscription_id = sanitize_text_field( (string) ( $subscription['gateway_subscription_id'] ?? '' ) );
		if ( '' === $gateway_subscription_id ) {
			return array(
				'success' => false,
				'code'    => 'missing_gateway_subscription',
				'message' => __( 'Stripe subscription reference is missing.', 'donatepress' ),
			);
		}

		$secret = (string) $this->settings->get( 'stripe_secret_key', '' );
		if ( '' === $secret ) {
			return array(
				'success' => false,
				'code'    => 'stripe_not_configured',
				'message' => __( 'Stripe secret key is missing.', 'donatepress' ),
			);
		}

		$subscription_remote = wp_remote_get(
			'https://api.stripe.com/v1/subscriptions/' . rawurlencode( $gateway_subscription_id ) . '?expand[]=latest_invoice',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
			)
		);

		if ( is_wp_error( $subscription_remote ) ) {
			return array(
				'success' => false,
				'code'    => 'stripe_retry_fetch_failed',
				'message' => $subscription_remote->get_error_message(),
			);
		}

		$subscription_code = (int) wp_remote_retrieve_response_code( $subscription_remote );
		$subscription_body = json_decode( (string) wp_remote_retrieve_body( $subscription_remote ), true );
		if ( $subscription_code < 200 || $subscription_code >= 300 || ! is_array( $subscription_body ) ) {
			$message = __( 'Could not fetch Stripe subscription.', 'donatepress' );
			if ( is_array( $subscription_body ) && ! empty( $subscription_body['error']['message'] ) ) {
				$message = sanitize_text_field( (string) $subscription_body['error']['message'] );
			}
			return array(
				'success' => false,
				'code'    => 'stripe_retry_fetch_failed',
				'message' => $message,
				'http'    => $subscription_code,
			);
		}

		$latest_invoice = $subscription_body['latest_invoice'] ?? '';
		if ( is_array( $latest_invoice ) ) {
			$latest_invoice = (string) ( $latest_invoice['id'] ?? '' );
		}
		$invoice_id = sanitize_text_field( (string) $latest_invoice );
		if ( '' === $invoice_id ) {
			return array(
				'success' => false,
				'code'    => 'stripe_retry_invoice_missing',
				'message' => __( 'No Stripe invoice available for retry.', 'donatepress' ),
			);
		}

		$pay_remote = wp_remote_post(
			'https://api.stripe.com/v1/invoices/' . rawurlencode( $invoice_id ) . '/pay',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization'   => 'Bearer ' . $secret,
					'Content-Type'    => 'application/x-www-form-urlencoded',
					'Idempotency-Key' => 'dp_retry_' . sanitize_key( $gateway_subscription_id ) . '_' . gmdate( 'YmdH' ),
				),
				'body' => '',
			)
		);

		if ( is_wp_error( $pay_remote ) ) {
			return array(
				'success' => false,
				'code'    => 'stripe_retry_charge_failed',
				'message' => $pay_remote->get_error_message(),
			);
		}

		$pay_code = (int) wp_remote_retrieve_response_code( $pay_remote );
		$pay_body = json_decode( (string) wp_remote_retrieve_body( $pay_remote ), true );
		if ( $pay_code < 200 || $pay_code >= 300 || ! is_array( $pay_body ) ) {
			$message = __( 'Stripe retry charge failed.', 'donatepress' );
			if ( is_array( $pay_body ) && ! empty( $pay_body['error']['message'] ) ) {
				$message = sanitize_text_field( (string) $pay_body['error']['message'] );
			}
			return array(
				'success' => false,
				'code'    => 'stripe_retry_charge_failed',
				'message' => $message,
				'http'    => $pay_code,
			);
		}

		$is_paid = ! empty( $pay_body['paid'] ) || 'paid' === sanitize_key( (string) ( $pay_body['status'] ?? '' ) );

		$default = array(
			'success' => $is_paid,
			'provider' => 'stripe',
			'transaction_id' => sanitize_text_field( (string) ( $pay_body['payment_intent'] ?? $pay_body['charge'] ?? $pay_body['id'] ?? '' ) ),
			'code' => $is_paid ? 'retry_paid' : 'stripe_retry_charge_failed',
			'message' => $is_paid ? __( 'Stripe retry charge succeeded.', 'donatepress' ) : __( 'Stripe retry charge was not paid.', 'donatepress' ),
		);

		/**
		 * Filter Stripe retry execution result.
		 *
		 * @param array<string,mixed> $default Default retry result.
		 * @param array<string,mixed> $subscription Subscription payload.
		 */
		$result = apply_filters( 'donatepress_stripe_retry_subscription_charge', $default, $subscription );
		return is_array( $result ) ? $result : $default;
	}

	/**
	 * Map Stripe event to internal subscription status.
	 *
	 * @param array<string,mixed> $object Stripe event object.
	 */
	private function map_subscription_status( string $event_type, array $object ): string {
		$status_map = array(
			'payment_intent.succeeded'      => 'active',
			'invoice.payment_succeeded'     => 'active',
			'payment_intent.payment_failed' => 'failed',
			'invoice.payment_failed'        => 'failed',
			'customer.subscription.deleted' => 'cancelled',
			'customer.subscription.paused'  => 'paused',
			'customer.subscription.resumed' => 'active',
		);

		if ( isset( $status_map[ $event_type ] ) ) {
			return $status_map[ $event_type ];
		}

		if ( 'customer.subscription.updated' === $event_type ) {
			$provider_status = sanitize_key( (string) ( $object['status'] ?? '' ) );
			$provider_map    = array(
				'active'             => 'active',
				'trialing'           => 'active',
				'past_due'           => 'failed',
				'unpaid'             => 'failed',
				'canceled'           => 'cancelled',
				'incomplete_expired' => 'expired',
				'paused'             => 'paused',
			);
			return $provider_map[ $provider_status ] ?? '';
		}

		return '';
	}

	/**
	 * Resolve next scheduled renewal based on frequency.
	 */
	private function next_cycle_datetime( string $frequency ): string {
		$base = current_time( 'timestamp', true );
		if ( 'annual' === $frequency ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $base ) );
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( '+1 month', $base ) );
	}

	/**
	 * Exponential retry backoff in hours (6, 12, 24... capped at 7d).
	 */
	private function next_retry_datetime( int $failure_count ): string {
		$base          = current_time( 'timestamp', true );
		$hours         = min( 24 * 7, max( 1, (int) pow( 2, $failure_count ) * 3 ) );
		return gmdate( 'Y-m-d H:i:s', strtotime( '+' . $hours . ' hours', $base ) );
	}
}
