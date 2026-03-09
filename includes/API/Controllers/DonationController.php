<?php

namespace DonatePress\API\Controllers;

use DonatePress\Repositories\DonationRepository;
use DonatePress\Security\NonceManager;
use DonatePress\Security\RateLimiter;
use DonatePress\Services\DonationService;
use DonatePress\Services\PaymentService;
use DonatePress\Services\SubscriptionService;
use DonatePress\Repositories\SubscriptionRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donation submission endpoints.
 */
class DonationController {
	/**
	 * API namespace.
	 */
	private string $namespace;

	/**
	 * Constructor.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/donations/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'form_id'    => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'amount'     => array( 'type' => 'number', 'required' => true ),
					'email'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => 'is_email',
					),
					'first_name' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'last_name'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'currency'   => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'gateway'    => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					'campaign_id'=> array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
					'comment'    => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					'is_recurring' => array( 'type' => 'boolean', 'required' => false ),
					'recurring_frequency' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					'hp_name'    => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'captcha_token' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'dp_nonce'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * Handle form submission securely.
	 */
	public function submit( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}

		$form_id = isset( $payload['form_id'] ) ? (int) $payload['form_id'] : 0;

		if ( ! empty( $payload['hp_name'] ) ) {
			return new WP_Error( 'spam_detected', __( 'Spam detected.', 'donatepress' ), array( 'status' => 400 ) );
		}

		$nonce_manager = new NonceManager();
		$nonce         = (string) ( $payload['dp_nonce'] ?? '' );
		if ( ! $nonce_manager->verify( $form_id, $nonce ) ) {
			return new WP_Error( 'invalid_nonce', __( 'Security check failed.', 'donatepress' ), array( 'status' => 403 ) );
		}
		if ( ! $this->verify_captcha( $request, $payload ) ) {
			return new WP_Error( 'invalid_captcha', __( 'Captcha verification failed.', 'donatepress' ), array( 'status' => 403 ) );
		}
		if ( ! $this->verify_origin( $request ) ) {
			return new WP_Error( 'invalid_origin', __( 'Origin check failed.', 'donatepress' ), array( 'status' => 403 ) );
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$rate_limiter = new RateLimiter();
		$limit        = (int) apply_filters( 'donatepress_submission_rate_limit', 8 );
		$window       = (int) apply_filters( 'donatepress_submission_rate_window', 60 );

		if ( ! $rate_limiter->allow( 'submit_' . $ip, $limit, $window ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Please retry shortly.', 'donatepress' ), array( 'status' => 429 ) );
		}

		$amount = isset( $payload['amount'] ) ? (float) $payload['amount'] : 0.0;
		$email  = sanitize_email( (string) ( $payload['email'] ?? '' ) );

		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Amount must be greater than zero.', 'donatepress' ), array( 'status' => 422 ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please provide a valid email address.', 'donatepress' ), array( 'status' => 422 ) );
		}

		if ( empty( $payload['first_name'] ) ) {
			return new WP_Error( 'invalid_name', __( 'First name is required.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new DonationRepository( $wpdb );
		$service    = new DonationService( $repository );
		$result     = $service->create_pending( $payload );

		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'save_failed', (string) $result['message'], array( 'status' => 500 ) );
		}

		$payment_service   = new PaymentService();
		$result['payment'] = $payment_service->start_payment(
			(array) ( $result['donation'] ?? array() ),
			$payload
		);

		$transaction_id = '';
		if ( ! empty( $result['payment']['transaction_id'] ) ) {
			$transaction_id = (string) $result['payment']['transaction_id'];
		} elseif ( ! empty( $result['payment']['payment_intent_id'] ) ) {
			$transaction_id = (string) $result['payment']['payment_intent_id'];
		} elseif ( ! empty( $result['payment']['order_id'] ) ) {
			$transaction_id = (string) $result['payment']['order_id'];
		}

		if ( ! empty( $result['payment']['success'] ) && '' !== $transaction_id ) {
			$repository->update_gateway_transaction(
				(int) $result['donation_id'],
				sanitize_text_field( $transaction_id )
			);
		}

		$is_recurring = ! empty( $result['donation']['is_recurring'] );
		if ( $is_recurring && ! empty( $result['payment']['success'] ) ) {
			$subscription_repository = new SubscriptionRepository( $wpdb );
			$subscription_service    = new SubscriptionService( $subscription_repository );
			$subscription_result     = $subscription_service->create_from_initial_donation(
				(array) ( $result['donation'] ?? array() ),
				(array) ( $result['payment'] ?? array() )
			);

			if ( ! empty( $subscription_result['success'] ) ) {
				$subscription_id = (int) $subscription_result['id'];
				$repository->update_subscription( (int) $result['donation_id'], $subscription_id );
				$result['subscription'] = array(
					'id' => $subscription_id,
				);
			} else {
				$result['subscription'] = $subscription_result;
			}
		}

		/**
		 * Filter submission response payload.
		 *
		 * @param array<string,mixed> $result Submission result.
		 * @param array<string,mixed> $payload Request payload.
		 */
		$result = apply_filters( 'donatepress_submit_response', $result, $payload );

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Verify browser origin/referer against current site.
	 */
	private function verify_origin( WP_REST_Request $request ): bool {
		$enforce = (bool) apply_filters( 'donatepress_enforce_origin_check', true, $request );
		if ( ! $enforce ) {
			return true;
		}

		$origin  = (string) $request->get_header( 'origin' );
		$referer = (string) $request->get_header( 'referer' );
		$target  = '';

		if ( '' !== $origin ) {
			$target = $origin;
		} elseif ( '' !== $referer ) {
			$target = $referer;
		} else {
			return false;
		}

		$target_host = wp_parse_url( $target, PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $target_host ) || '' === $target_host || ! is_string( $site_host ) || '' === $site_host ) {
			return false;
		}

		return hash_equals( strtolower( $site_host ), strtolower( $target_host ) );
	}

	/**
	 * Verify captcha token when enabled in settings.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function verify_captcha( WP_REST_Request $request, array $payload ): bool {
		$settings = get_option( 'donatepress_settings', array() );
		$enabled  = ! empty( $settings['enable_captcha'] );
		if ( ! $enabled ) {
			return true;
		}

		$token = sanitize_text_field( (string) ( $payload['captcha_token'] ?? '' ) );
		if ( '' === $token ) {
			return false;
		}

		$default = (bool) apply_filters( 'donatepress_captcha_default_result', false, $token, $request, $payload );
		$result  = apply_filters( 'donatepress_verify_captcha', $default, $token, $request, $payload );
		return (bool) $result;
	}
}
