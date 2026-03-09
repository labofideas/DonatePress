<?php

namespace DonatePress\API\Controllers;

use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Security\CapabilityManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscription management endpoints.
 */
class SubscriptionController {
	/**
	 * API namespace.
	 */
	private string $namespace;

	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/subscriptions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_subscriptions' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'limit'  => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_limit' ),
					),
					'status' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_status_or_empty' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_positive_int' ),
					),
					'status' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_status' ),
					),
				),
			)
		);
	}

	/**
	 * List subscriptions.
	 */
	public function list_subscriptions( WP_REST_Request $request ): WP_REST_Response {
		$limit  = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 20 ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );

		global $wpdb;
		$repository = new SubscriptionRepository( $wpdb );
		$rows       = $repository->list_recent( $limit, $status );

		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $rows,
				'total'   => $repository->count_all(),
			),
			200
		);
	}

	/**
	 * Update one subscription status.
	 */
	public function update_status( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );

		$allowed = array( 'pending', 'active', 'paused', 'cancelled', 'failed', 'completed', 'expired' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'invalid_status', __( 'Invalid subscription status.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository   = new SubscriptionRepository( $wpdb );
		$subscription = $repository->find( $id );
		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		$updated = $repository->update_status( $id, $status );
		if ( ! $updated ) {
			return new WP_Error( 'update_failed', __( 'Could not update subscription status.', 'donatepress' ), array( 'status' => 500 ) );
		}

		do_action( 'donatepress_subscription_status_updated', $id, $status, $subscription );

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'status'  => $status,
			),
			200
		);
	}

	/**
	 * Permissions.
	 */
	public function can_manage( WP_REST_Request $request ): bool {
		$manager = new CapabilityManager();
		$allowed = $manager->can( 'subscriptions.manage', $request );
		return (bool) apply_filters( 'donatepress_rest_can_manage', $allowed, $request );
	}

	/**
	 * Validate positive integer argument.
	 *
	 * @param mixed $value
	 */
	public function validate_positive_int( $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}

	/**
	 * Validate subscriptions list limit.
	 *
	 * @param mixed $value
	 */
	public function validate_limit( $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}

	/**
	 * Validate required subscription status.
	 *
	 * @param mixed $value
	 */
	public function validate_status( $value ): bool {
		$status = sanitize_key( (string) $value );
		$allowed = array( 'pending', 'active', 'paused', 'cancelled', 'failed', 'completed', 'expired' );
		return in_array( $status, $allowed, true );
	}

	/**
	 * Validate optional subscription status.
	 *
	 * @param mixed $value
	 */
	public function validate_status_or_empty( $value ): bool {
		$status = sanitize_key( (string) $value );
		if ( '' === $status ) {
			return true;
		}
		return $this->validate_status( $status );
	}
}
