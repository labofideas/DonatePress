<?php

namespace DonatePress\Gateways\PayPal;

use DonatePress\Gateways\GatewayInterface;
use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Services\SettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal payment and webhook implementation.
 */
class PayPalGateway implements GatewayInterface {
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
		return 'paypal';
	}

	public function start_payment( array $donation, array $context ): array {
		$client_id = (string) $this->settings->get( 'paypal_client_id', '' );
		$secret    = (string) $this->settings->get( 'paypal_secret', '' );

		if ( '' === $client_id || '' === $secret ) {
			return array(
				'success' => false,
				'code'    => 'paypal_not_configured',
				'message' => __( 'PayPal is not configured yet.', 'donatepress' ),
			);
		}

		$token = $this->get_access_token( $client_id, $secret );
		if ( empty( $token['success'] ) ) {
			return $token;
		}

		$currency = strtoupper( sanitize_text_field( (string) ( $donation['currency'] ?? 'USD' ) ) );
		$amount   = number_format( (float) ( $donation['amount'] ?? 0 ), 2, '.', '' );
		$donation_id = (int) ( $donation['id'] ?? 0 );

		$order_body = array(
			'intent' => 'CAPTURE',
			'purchase_units' => array(
				array(
					'custom_id' => (string) $donation_id,
					'invoice_id' => sanitize_text_field( (string) ( $donation['donation_number'] ?? '' ) ),
					'amount' => array(
						'currency_code' => $currency,
						'value'         => $amount,
					),
				),
			),
			'application_context' => array(
				'user_action' => 'PAY_NOW',
			),
		);

		$order_body = apply_filters( 'donatepress_paypal_order_body', $order_body, $donation, $context );

		$order_request = array(
			'timeout' => 20,
			'headers' => array(
				'Authorization'   => 'Bearer ' . $token['access_token'],
				'Content-Type'    => 'application/json',
				'PayPal-Request-Id' => 'dp_pp_' . sanitize_key( (string) ( $donation['donation_number'] ?? wp_generate_password( 12, false, false ) ) ),
			),
			'body' => wp_json_encode( $order_body ),
		);

		$order_request = apply_filters( 'donatepress_paypal_order_request_args', $order_request, $donation, $context );

		$remote = wp_remote_post( $this->api_base() . '/v2/checkout/orders', $order_request );
		if ( is_wp_error( $remote ) ) {
			return array(
				'success' => false,
				'code'    => 'paypal_request_failed',
				'message' => $remote->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $remote );
		$body = json_decode( (string) wp_remote_retrieve_body( $remote ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$message = __( 'PayPal order creation failed.', 'donatepress' );
			if ( is_array( $body ) && ! empty( $body['message'] ) ) {
				$message = sanitize_text_field( (string) $body['message'] );
			}
			return array(
				'success' => false,
				'code'    => 'paypal_api_error',
				'message' => $message,
				'http'    => $code,
			);
		}

		$approval_url = '';
		if ( ! empty( $body['links'] ) && is_array( $body['links'] ) ) {
			foreach ( $body['links'] as $link ) {
				if ( is_array( $link ) && 'approve' === ( $link['rel'] ?? '' ) ) {
					$approval_url = esc_url_raw( (string) ( $link['href'] ?? '' ) );
					break;
				}
			}
		}

		$response = array(
			'success'                => true,
			'provider'               => 'paypal',
			'flow'                   => 'order_approval',
			'client_id'              => $client_id,
			'order_id'               => sanitize_text_field( (string) ( $body['id'] ?? '' ) ),
			'transaction_id'         => sanitize_text_field( (string) ( $body['id'] ?? '' ) ),
			'approval_url'           => $approval_url,
			'requires_approval'      => true,
			'webhook_expected_event' => 'PAYMENT.CAPTURE.COMPLETED',
		);

		return apply_filters( 'donatepress_paypal_start_payment', $response, $donation, $context );
	}

	public function verify_webhook( array $headers, string $payload ): bool {
		$webhook_id = (string) $this->settings->get( 'paypal_webhook_id', '' );
		if ( '' === $webhook_id ) {
			$verified = false;
			return (bool) apply_filters( 'donatepress_paypal_verify_webhook', $verified, $headers, $payload, $this->settings->all() );
		}

		$client_id = (string) $this->settings->get( 'paypal_client_id', '' );
		$secret    = (string) $this->settings->get( 'paypal_secret', '' );
		if ( '' === $client_id || '' === $secret ) {
			return false;
		}

		$token = $this->get_access_token( $client_id, $secret );
		if ( empty( $token['success'] ) ) {
			return false;
		}

		$decoded = json_decode( $payload, true );
		if ( ! is_array( $decoded ) ) {
			return false;
		}

		$normalized = $this->normalize_headers( $headers );
		$verify_body = array(
			'auth_algo'         => (string) ( $normalized['paypal-auth-algo'] ?? '' ),
			'cert_url'          => (string) ( $normalized['paypal-cert-url'] ?? '' ),
			'transmission_id'   => (string) ( $normalized['paypal-transmission-id'] ?? '' ),
			'transmission_sig'  => (string) ( $normalized['paypal-transmission-sig'] ?? '' ),
			'transmission_time' => (string) ( $normalized['paypal-transmission-time'] ?? '' ),
			'webhook_id'        => $webhook_id,
			'webhook_event'     => $decoded,
		);

		foreach ( array( 'auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time' ) as $required_key ) {
			if ( '' === (string) $verify_body[ $required_key ] ) {
				return false;
			}
		}

		$transmission_ts = strtotime( (string) $verify_body['transmission_time'] );
		if ( false === $transmission_ts ) {
			return false;
		}
		$tolerance = (int) apply_filters( 'donatepress_paypal_webhook_tolerance', 300 );
		if ( abs( time() - $transmission_ts ) > $tolerance ) {
			return false;
		}

		$request_args = array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token['access_token'],
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $verify_body ),
		);

		$request_args = apply_filters( 'donatepress_paypal_verify_request_args', $request_args, $verify_body );
		$remote       = wp_remote_post( $this->api_base() . '/v1/notifications/verify-webhook-signature', $request_args );
		if ( is_wp_error( $remote ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $remote );
		$body = json_decode( (string) wp_remote_retrieve_body( $remote ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return false;
		}

		$status   = strtoupper( sanitize_text_field( (string) ( $body['verification_status'] ?? '' ) ) );
		$verified = 'SUCCESS' === $status;

		return (bool) apply_filters( 'donatepress_paypal_verify_webhook', $verified, $headers, $payload, $this->settings->all() );
	}

	public function handle_webhook( array $headers, string $payload ): array {
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) ) {
			return array(
				'success' => false,
				'code'    => 'invalid_json',
				'message' => __( 'Invalid PayPal payload.', 'donatepress' ),
			);
		}

		$event_id   = sanitize_text_field( (string) ( $data['id'] ?? '' ) );
		$event_type = sanitize_text_field( (string) ( $data['event_type'] ?? '' ) );

		if ( '' !== $event_id ) {
			$event_lock = 'dp_wh_paypal_' . md5( $event_id );
			if ( get_transient( $event_lock ) ) {
				return array(
					'success'    => true,
					'provider'   => 'paypal',
					'event_id'   => $event_id,
					'event_type' => $event_type,
					'duplicate'  => true,
				);
			}
			set_transient( $event_lock, 1, DAY_IN_SECONDS );
		}

		$resource = is_array( $data['resource'] ?? null ) ? $data['resource'] : array();
		$related  = is_array( $resource['supplementary_data']['related_ids'] ?? null ) ? $resource['supplementary_data']['related_ids'] : array();
		$candidates = array_filter(
			array(
				sanitize_text_field( (string) ( $resource['id'] ?? '' ) ),
				sanitize_text_field( (string) ( $related['order_id'] ?? '' ) ),
			)
		);

		$donation_id = 0;
		if ( isset( $resource['custom_id'] ) ) {
			$donation_id = (int) $resource['custom_id'];
		}
		$subscription_ref = sanitize_text_field( (string) ( $resource['id'] ?? '' ) );
		if ( 0 !== strpos( $event_type, 'BILLING.SUBSCRIPTION.' ) ) {
			$subscription_ref = sanitize_text_field( (string) ( $resource['billing_agreement_id'] ?? '' ) );
		}

		global $wpdb;
		$repo     = new DonationRepository( $wpdb );
		$sub_repo = new SubscriptionRepository( $wpdb );
		$donation = null;

		if ( $donation_id > 0 ) {
			$donation = $repo->find( $donation_id );
		}
		if ( ! $donation ) {
			foreach ( $candidates as $candidate ) {
				$found = $repo->find_by_gateway_transaction( (string) $candidate );
				if ( $found ) {
					$donation = $found;
					break;
				}
			}
		}

		if ( $donation ) {
			$transaction_ref = (string) ( $related['order_id'] ?? $resource['id'] ?? '' );
			if ( '' !== $transaction_ref ) {
				$repo->update_gateway_transaction( (int) $donation['id'], $transaction_ref );
			}

			$status_map = array(
				'PAYMENT.CAPTURE.COMPLETED' => 'completed',
				'PAYMENT.CAPTURE.DENIED'    => 'failed',
				'PAYMENT.CAPTURE.DECLINED'  => 'failed',
				'PAYMENT.CAPTURE.REFUNDED'  => 'refunded',
			);

			if ( isset( $status_map[ $event_type ] ) ) {
				$repo->update_status( (int) $donation['id'], $status_map[ $event_type ] );
				do_action( 'donatepress_donation_status_synced', (int) $donation['id'], $status_map[ $event_type ], 'paypal', $event_type );
			}
		}

		$subscription = null;
		if ( ! empty( $donation['subscription_id'] ) ) {
			$subscription = $sub_repo->find( (int) $donation['subscription_id'] );
		}
		if ( ! $subscription && '' !== $subscription_ref ) {
			$subscription = $sub_repo->find_by_gateway_subscription( 'paypal', $subscription_ref );
		}

		$subscription_status = $this->map_subscription_status( $event_type );
		if ( $subscription ) {
			$subscription_id = (int) $subscription['id'];

			if ( '' !== $subscription_ref && '' === (string) ( $subscription['gateway_subscription_id'] ?? '' ) ) {
				$sub_repo->update_gateway_subscription_id( $subscription_id, $subscription_ref );
			}

			if ( '' !== $subscription_status ) {
				$sub_repo->update_status( $subscription_id, $subscription_status );
				if ( 'PAYMENT.SALE.COMPLETED' === $event_type || 'PAYMENT.CAPTURE.COMPLETED' === $event_type ) {
					$frequency = sanitize_key( (string) ( $subscription['frequency'] ?? 'monthly' ) );
					$next_at   = $this->next_cycle_datetime( $frequency );
					$sub_repo->mark_payment_success( $subscription_id, $next_at );
				} elseif ( 'PAYMENT.SALE.DENIED' === $event_type || 'PAYMENT.CAPTURE.DENIED' === $event_type || 'PAYMENT.CAPTURE.DECLINED' === $event_type ) {
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

		do_action( 'donatepress_paypal_webhook_event', $event_type, $data );

		return array(
			'success'    => true,
			'event_id'   => $event_id,
			'event_type' => $event_type,
			'provider'   => 'paypal',
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
				'message' => __( 'PayPal subscription reference is missing.', 'donatepress' ),
			);
		}

		$client_id = (string) $this->settings->get( 'paypal_client_id', '' );
		$secret    = (string) $this->settings->get( 'paypal_secret', '' );
		if ( '' === $client_id || '' === $secret ) {
			return array(
				'success' => false,
				'code'    => 'paypal_not_configured',
				'message' => __( 'PayPal credentials are missing.', 'donatepress' ),
			);
		}

		$oauth = $this->get_access_token( $client_id, $secret );
		if ( empty( $oauth['success'] ) ) {
			return array(
				'success' => false,
				'code'    => sanitize_key( (string) ( $oauth['code'] ?? 'paypal_oauth_failed' ) ),
				'message' => (string) ( $oauth['message'] ?? __( 'Could not authenticate PayPal retry.', 'donatepress' ) ),
			);
		}

		$currency = strtoupper( sanitize_text_field( (string) ( $subscription['currency'] ?? 'USD' ) ) );
		$amount   = number_format( (float) ( $subscription['amount'] ?? 0 ), 2, '.', '' );
		$capture_body = array(
			'note'         => 'DonatePress recurring retry',
			'capture_type' => 'OUTSTANDING_BALANCE',
			'amount'       => array(
				'currency_code' => $currency,
				'value'         => $amount,
			),
		);

		$request = array(
			'timeout' => 20,
			'headers' => array(
				'Authorization'   => 'Bearer ' . sanitize_text_field( (string) $oauth['access_token'] ),
				'Content-Type'    => 'application/json',
				'PayPal-Request-Id' => 'dp_retry_' . sanitize_key( $gateway_subscription_id ) . '_' . gmdate( 'YmdH' ),
			),
			'body' => wp_json_encode( $capture_body ),
		);

		$request = apply_filters( 'donatepress_paypal_retry_request_args', $request, $subscription, $capture_body );
		$remote  = wp_remote_post(
			$this->api_base() . '/v1/billing/subscriptions/' . rawurlencode( $gateway_subscription_id ) . '/capture',
			$request
		);

		if ( is_wp_error( $remote ) ) {
			return array(
				'success' => false,
				'code'    => 'paypal_retry_charge_failed',
				'message' => $remote->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $remote );
		$body = json_decode( (string) wp_remote_retrieve_body( $remote ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$message = __( 'PayPal retry charge failed.', 'donatepress' );
			if ( is_array( $body ) && ! empty( $body['message'] ) ) {
				$message = sanitize_text_field( (string) $body['message'] );
			}
			return array(
				'success' => false,
				'code'    => 'paypal_retry_charge_failed',
				'message' => $message,
				'http'    => $code,
			);
		}

		$status = strtoupper( sanitize_text_field( (string) ( $body['status'] ?? 'COMPLETED' ) ) );
		$ok     = in_array( $status, array( 'COMPLETED', 'PENDING' ), true );

		$default = array(
			'success' => $ok,
			'provider' => 'paypal',
			'transaction_id' => sanitize_text_field( (string) ( $body['id'] ?? '' ) ),
			'code' => $ok ? 'retry_submitted' : 'paypal_retry_charge_failed',
			'message' => $ok ? __( 'PayPal retry submitted.', 'donatepress' ) : __( 'PayPal retry was not accepted.', 'donatepress' ),
			'status' => $status,
		);

		/**
		 * Filter PayPal retry execution result.
		 *
		 * @param array<string,mixed> $default Default retry result.
		 * @param array<string,mixed> $subscription Subscription payload.
		 */
		$result = apply_filters( 'donatepress_paypal_retry_subscription_charge', $default, $subscription );
		return is_array( $result ) ? $result : $default;
	}

	/**
	 * Map PayPal webhook event to internal subscription status.
	 */
	private function map_subscription_status( string $event_type ): string {
		$status_map = array(
			'PAYMENT.CAPTURE.COMPLETED'     => 'active',
			'PAYMENT.SALE.COMPLETED'        => 'active',
			'PAYMENT.CAPTURE.DENIED'        => 'failed',
			'PAYMENT.CAPTURE.DECLINED'      => 'failed',
			'PAYMENT.SALE.DENIED'           => 'failed',
			'BILLING.SUBSCRIPTION.ACTIVATED'=> 'active',
			'BILLING.SUBSCRIPTION.SUSPENDED'=> 'paused',
			'BILLING.SUBSCRIPTION.CANCELLED'=> 'cancelled',
			'BILLING.SUBSCRIPTION.EXPIRED'  => 'expired',
			'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'failed',
		);

		return $status_map[ $event_type ] ?? '';
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

	/**
	 * Generate and return PayPal OAuth access token.
	 *
	 * @return array<string,mixed>
	 */
	private function get_access_token( string $client_id, string $secret ): array {
		$auth  = base64_encode( $client_id . ':' . $secret );
		$args  = array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . $auth,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => 'grant_type=client_credentials',
		);

		$args = apply_filters( 'donatepress_paypal_oauth_request_args', $args );
		$resp = wp_remote_post( $this->api_base() . '/v1/oauth2/token', $args );
		if ( is_wp_error( $resp ) ) {
			return array(
				'success' => false,
				'code'    => 'paypal_oauth_failed',
				'message' => $resp->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return array(
				'success' => false,
				'code'    => 'paypal_oauth_failed',
				'message' => __( 'Could not authenticate PayPal API request.', 'donatepress' ),
			);
		}

		return array(
			'success'      => true,
			'access_token' => sanitize_text_field( (string) $body['access_token'] ),
		);
	}

	/**
	 * Resolve API base URL from mode.
	 */
	private function api_base(): string {
		$live = (int) $this->settings->get( 'live_mode_enabled', 0 ) === 1;
		$base = $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
		return (string) apply_filters( 'donatepress_paypal_api_base', $base, $live );
	}

	/**
	 * Normalize headers to predictable lowercase-hyphen map.
	 *
	 * @param array<string,string> $headers Raw map.
	 * @return array<string,string>
	 */
	private function normalize_headers( array $headers ): array {
		$normalized = array();
		foreach ( $headers as $key => $value ) {
			$k = strtolower( str_replace( '_', '-', (string) $key ) );
			$normalized[ $k ] = (string) $value;
		}

		$aliases = array(
			'http-paypal-auth-algo'         => 'paypal-auth-algo',
			'http-paypal-cert-url'          => 'paypal-cert-url',
			'http-paypal-transmission-id'   => 'paypal-transmission-id',
			'http-paypal-transmission-sig'  => 'paypal-transmission-sig',
			'http-paypal-transmission-time' => 'paypal-transmission-time',
		);

		foreach ( $aliases as $from => $to ) {
			if ( isset( $normalized[ $from ] ) && ! isset( $normalized[ $to ] ) ) {
				$normalized[ $to ] = $normalized[ $from ];
			}
		}

		return $normalized;
	}
}
