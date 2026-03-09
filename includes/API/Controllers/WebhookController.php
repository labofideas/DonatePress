<?php

namespace DonatePress\API\Controllers;

use DonatePress\Security\RateLimiter;
use DonatePress\Services\PaymentService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incoming gateway webhook endpoints.
 */
class WebhookController {
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
	 * Register webhook routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/webhooks/(?P<gateway>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'gateway' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_gateway' ),
					),
				),
			)
		);
	}

	/**
	 * Handle incoming webhook payload.
	 */
	public function receive( WP_REST_Request $request ) {
		$gateway = sanitize_key( (string) $request->get_param( 'gateway' ) );
		if ( '' === $gateway ) {
			return new WP_Error( 'invalid_gateway', __( 'Gateway is required.', 'donatepress' ), array( 'status' => 400 ) );
		}

		$ip_address   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$rate_limiter = new RateLimiter();
		$limit        = (int) apply_filters( 'donatepress_webhook_rate_limit', 180, $gateway );
		$window       = (int) apply_filters( 'donatepress_webhook_rate_window', 60, $gateway );
		$rate_key     = 'webhook_' . $gateway . '_' . md5( $ip_address );

		if ( ! $rate_limiter->allow( $rate_key, $limit, $window ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many webhook requests. Please retry shortly.', 'donatepress' ), array( 'status' => 429 ) );
		}

		$headers = array_change_key_case( $request->get_headers(), CASE_LOWER );
		$flat    = array();
		foreach ( $headers as $key => $value ) {
			$flat[ $key ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		$payload = (string) $request->get_body();

		$service = new PaymentService();
		$result  = $service->handle_webhook( $gateway, $flat, $payload );
		if ( empty( $result['success'] ) ) {
			$code    = sanitize_key( (string) ( $result['code'] ?? 'webhook_error' ) );
			$message = (string) ( $result['message'] ?? __( 'Webhook rejected.', 'donatepress' ) );
			$status  = 'invalid_signature' === $code ? 403 : 422;
			return new WP_Error( $code, $message, array( 'status' => $status ) );
		}

		do_action( 'donatepress_webhook_received', $gateway, $result );

		return new WP_REST_Response(
			array(
				'success' => true,
				'gateway' => $gateway,
				'event'   => $result['event_type'] ?? '',
			),
			200
		);
	}

	/**
	 * Validate webhook gateway path parameter.
	 *
	 * @param mixed $value
	 */
	public function validate_gateway( $value ): bool {
		return '' !== sanitize_key( (string) $value );
	}
}
