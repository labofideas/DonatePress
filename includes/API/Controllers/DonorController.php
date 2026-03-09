<?php

namespace DonatePress\API\Controllers;

use DonatePress\Repositories\DonorRepository;
use DonatePress\Security\CapabilityManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donor management endpoints.
 */
class DonorController {
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
			'/manage/donors',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'page'   => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'limit'  => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'search' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'email'      => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_email',
							'validate_callback' => 'is_email',
						),
						'first_name' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'last_name'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'phone'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'status'     => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_status' ),
						),
						'tags'       => array(
							'type'              => 'array',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_tags' ),
							'validate_callback' => array( $this, 'validate_tags' ),
						),
						'notes'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/manage/donors/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'PUT,PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id'         => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
						'first_name' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'last_name'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'phone'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'status'     => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => array( $this, 'validate_status' ),
						),
						'tags'       => array(
							'type'              => 'array',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_tags' ),
							'validate_callback' => array( $this, 'validate_tags' ),
						),
						'notes'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/manage/donors/(?P<id>\d+)/block',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'block' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/manage/donors/(?P<id>\d+)/anonymize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'anonymize' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/manage/donors/merge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'merge' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'source_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'target_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * List donors.
	 */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$repository = new DonorRepository( $wpdb );
		$page       = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$limit      = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 25 ) );
		$offset     = ( $page - 1 ) * $limit;
		$search     = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status     = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' !== $status && ! in_array( $status, array( 'active', 'blocked', 'anonymized', 'merged' ), true ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'items'   => array(),
					'total'   => 0,
					'page'    => $page,
					'limit'   => $limit,
				),
				200
			);
		}

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
	 * Create donor.
	 */
	public function create( WP_REST_Request $request ) {
		$payload = $this->payload( $request );

		$email = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Valid donor email is required.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new DonorRepository( $wpdb );
		$id         = $repository->insert(
			array(
				'email'      => $email,
				'first_name' => sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) ),
				'last_name'  => sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) ),
				'phone'      => sanitize_text_field( (string) ( $payload['phone'] ?? '' ) ),
				'status'     => $this->normalize_status( (string) ( $payload['status'] ?? 'active' ) ),
				'tags'       => is_array( $payload['tags'] ?? null ) ? $payload['tags'] : array(),
				'notes'      => sanitize_textarea_field( (string) ( $payload['notes'] ?? '' ) ),
			)
		);
		if ( $id <= 0 ) {
			return new WP_Error( 'create_failed', __( 'Could not create donor.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'item'    => $repository->find( $id ),
			),
			201
		);
	}

	/**
	 * Get one donor.
	 */
	public function get( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid donor id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new DonorRepository( $wpdb );
		$donor      = $repository->find( $id );
		if ( ! $donor ) {
			return new WP_Error( 'not_found', __( 'Donor not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'item'    => $donor,
			),
			200
		);
	}

	/**
	 * Update donor.
	 */
	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid donor id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		$payload = $this->payload( $request );

		$updates = array();
		foreach ( array( 'first_name', 'last_name', 'phone', 'status' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$updates[ $key ] = 'status' === $key
					? $this->normalize_status( (string) $payload[ $key ] )
					: sanitize_text_field( (string) $payload[ $key ] );
			}
		}
		if ( array_key_exists( 'notes', $payload ) ) {
			$updates['notes'] = sanitize_textarea_field( (string) $payload['notes'] );
		}
		if ( array_key_exists( 'tags', $payload ) && is_array( $payload['tags'] ) ) {
			$updates['tags'] = $payload['tags'];
		}

		global $wpdb;
		$repository = new DonorRepository( $wpdb );
		if ( ! $repository->update( $id, $updates ) ) {
			return new WP_Error( 'update_failed', __( 'Could not update donor.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'item'    => $repository->find( $id ),
			),
			200
		);
	}

	/**
	 * Block donor.
	 */
	public function block( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		global $wpdb;
		$repository = new DonorRepository( $wpdb );
		if ( $id <= 0 || ! $repository->block( $id ) ) {
			return new WP_Error( 'block_failed', __( 'Could not block donor.', 'donatepress' ), array( 'status' => 422 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'item'    => $repository->find( $id ),
			),
			200
		);
	}

	/**
	 * Anonymize donor.
	 */
	public function anonymize( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		global $wpdb;
		$repository = new DonorRepository( $wpdb );
		if ( $id <= 0 || ! $repository->anonymize( $id ) ) {
			return new WP_Error( 'anonymize_failed', __( 'Could not anonymize donor.', 'donatepress' ), array( 'status' => 422 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'item'    => $repository->find( $id ),
			),
			200
		);
	}

	/**
	 * Merge donor records.
	 */
	public function merge( WP_REST_Request $request ) {
		$payload = $this->payload( $request );

		$source_id = (int) ( $payload['source_id'] ?? 0 );
		$target_id = (int) ( $payload['target_id'] ?? 0 );
		if ( $source_id <= 0 || $target_id <= 0 || $source_id === $target_id ) {
			return new WP_Error( 'invalid_merge', __( 'Valid source and target donor IDs are required.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new DonorRepository( $wpdb );
		if ( ! $repository->merge( $source_id, $target_id ) ) {
			return new WP_Error( 'merge_failed', __( 'Could not merge donors.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'target'  => $repository->find( $target_id ),
				'source'  => $repository->find( $source_id ),
			),
			200
		);
	}

	/**
	 * Permissions.
	 */
	public function can_manage( WP_REST_Request $request ): bool {
		$manager = new CapabilityManager();
		$allowed = $manager->can( 'donors.manage', $request );
		return (bool) apply_filters( 'donatepress_rest_can_manage', $allowed, $request );
	}

	/**
	 * Normalize donor status to supported values.
	 */
	private function normalize_status( string $status ): string {
		$status  = sanitize_key( $status );
		$allowed = array( 'active', 'blocked', 'anonymized', 'merged' );
		return in_array( $status, $allowed, true ) ? $status : 'active';
	}

	/**
	 * Validate donor status from REST args.
	 *
	 * @param mixed $value
	 */
	public function validate_status( $value ): bool {
		$status = sanitize_key( (string) $value );
		return in_array( $status, array( 'active', 'blocked', 'anonymized', 'merged' ), true );
	}

	/**
	 * Normalize tags payload to a list of safe strings.
	 *
	 * @param mixed $value
	 * @return array<int,string>
	 */
	public function sanitize_tags( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$tags = array();
		foreach ( $value as $tag ) {
			$clean = sanitize_text_field( (string) $tag );
			if ( '' !== $clean ) {
				$tags[] = $clean;
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Validate tags payload shape.
	 *
	 * @param mixed $value
	 */
	public function validate_tags( $value ): bool {
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
