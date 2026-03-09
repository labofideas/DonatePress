<?php

namespace DonatePress\API\Controllers;

use DonatePress\Repositories\FormRepository;
use DonatePress\Security\CapabilityManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form management endpoints.
 */
class FormController {
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
			'/manage/forms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'page'   => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
						'limit'  => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
						'search' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'status' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'title'          => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_non_empty' ),
						),
						'slug'           => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_title' ),
						'status'         => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_status' ),
						),
						'default_amount' => array(
							'type'              => 'number',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_amount' ),
							'validate_callback' => array( $this, 'validate_amount' ),
						),
						'currency'       => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'gateway'        => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'config'         => array(
							'type'              => 'object',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_config' ),
							'validate_callback' => array( $this, 'validate_config' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/manage/forms/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => 'PUT,PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id'             => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
						'title'          => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'slug'           => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_title' ),
						'status'         => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_status' ),
						),
						'default_amount' => array(
							'type'              => 'number',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_amount' ),
							'validate_callback' => array( $this, 'validate_amount' ),
						),
						'currency'       => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'gateway'        => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'config'         => array(
							'type'              => 'object',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_config' ),
							'validate_callback' => array( $this, 'validate_config' ),
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);
	}

	/**
	 * List forms.
	 */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		$page   = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$limit  = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 25 ) );
		$offset = ( $page - 1 ) * $limit;
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' !== $status && ! in_array( $status, array( 'active', 'inactive', 'archived' ), true ) ) {
			$status = '';
		}

		global $wpdb;
		$repository = new FormRepository( $wpdb );

		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $repository->list( $limit, $offset, $search, $status ),
				'total'   => $repository->count_filtered( $search, $status ),
				'page'    => $page,
				'limit'   => $limit,
			),
			200
		);
	}

	/**
	 * Get form.
	 */
	public function get( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid form id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new FormRepository( $wpdb );
		$item       = $repository->find( $id );
		if ( ! $item ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'item' => $item ), 200 );
	}

	/**
	 * Create form.
	 */
	public function create( WP_REST_Request $request ) {
		$payload = $this->payload( $request );

		$title = sanitize_text_field( (string) ( $payload['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'Form title is required.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new FormRepository( $wpdb );
		$id         = $repository->insert(
			array(
				'title'          => $title,
				'slug'           => sanitize_title( (string) ( $payload['slug'] ?? $title ) ),
				'status'         => $this->normalize_status( (string) ( $payload['status'] ?? 'active' ) ),
				'default_amount' => (float) ( $payload['default_amount'] ?? 25 ),
				'currency'       => sanitize_text_field( (string) ( $payload['currency'] ?? 'USD' ) ),
				'gateway'        => sanitize_key( (string) ( $payload['gateway'] ?? 'stripe' ) ),
				'config'         => $payload['config'] ?? array(),
			)
		);
		if ( $id <= 0 ) {
			return new WP_Error( 'create_failed', __( 'Could not create form.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'item' => $repository->find( $id ) ), 201 );
	}

	/**
	 * Update form.
	 */
	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid form id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		$payload = $this->payload( $request );

		$updates = array();
		foreach ( array( 'title', 'slug', 'status', 'currency', 'gateway' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$updates[ $key ] = in_array( $key, array( 'status', 'gateway' ), true )
					? ( 'status' === $key ? $this->normalize_status( (string) $payload[ $key ] ) : sanitize_key( (string) $payload[ $key ] ) )
					: sanitize_text_field( (string) $payload[ $key ] );
			}
		}
		if ( array_key_exists( 'default_amount', $payload ) ) {
			$updates['default_amount'] = (float) $payload['default_amount'];
		}
		if ( array_key_exists( 'config', $payload ) ) {
			$updates['config'] = $payload['config'];
		}

		global $wpdb;
		$repository = new FormRepository( $wpdb );
		if ( ! $repository->update( $id, $updates ) ) {
			return new WP_Error( 'update_failed', __( 'Could not update form.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'item' => $repository->find( $id ) ), 200 );
	}

	/**
	 * Delete form.
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid form id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new FormRepository( $wpdb );
		if ( ! $repository->delete( $id ) ) {
			return new WP_Error( 'delete_failed', __( 'Could not delete form.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'deleted' => $id ), 200 );
	}

	/**
	 * Permissions.
	 */
	public function can_manage( WP_REST_Request $request ): bool {
		$manager = new CapabilityManager();
		$allowed = $manager->can( 'forms.manage', $request );
		return (bool) apply_filters( 'donatepress_rest_can_manage', $allowed, $request );
	}

	/**
	 * Normalize supported form status.
	 */
	private function normalize_status( string $status ): string {
		$status  = sanitize_key( $status );
		$allowed = array( 'active', 'inactive', 'archived' );
		return in_array( $status, $allowed, true ) ? $status : 'active';
	}

	/**
	 * Validate supported form status from REST args.
	 *
	 * @param mixed $value
	 */
	public function validate_status( $value ): bool {
		$status = sanitize_key( (string) $value );
		return in_array( $status, array( 'active', 'inactive', 'archived' ), true );
	}

	/**
	 * Validate non-empty string.
	 *
	 * @param mixed $value
	 */
	public function validate_non_empty( $value ): bool {
		return '' !== trim( (string) $value );
	}

	/**
	 * Sanitize numeric amount.
	 *
	 * @param mixed $value
	 */
	public function sanitize_amount( $value ): float {
		return (float) $value;
	}

	/**
	 * Validate amount constraints.
	 *
	 * @param mixed $value
	 */
	public function validate_amount( $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0;
	}

	/**
	 * Normalize config payload into array for repository JSON encoding.
	 *
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	public function sanitize_config( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return $value;
	}

	/**
	 * Validate config payload shape.
	 *
	 * @param mixed $value
	 */
	public function validate_config( $value ): bool {
		return is_array( $value );
	}

	/**
	 * Decode JSON/form payload.
	 *
	 * @return array<string,mixed>
	 */
	private function payload( WP_REST_Request $request ): array {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}
		return is_array( $payload ) ? $payload : array();
	}
}
