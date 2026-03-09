<?php

namespace DonatePress\API\Controllers;

use DonatePress\Repositories\CampaignRepository;
use DonatePress\Repositories\CampaignUpdateRepository;
use DonatePress\Security\CapabilityManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign management endpoints.
 */
class CampaignController {
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
			'/campaigns/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'public_get' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/campaigns/slug/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'public_get_by_slug' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slug' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_title' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/manage/campaigns',
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
						'title'         => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'slug'          => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_title' ),
						'description'   => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
						'goal_amount'   => array( 'type' => 'number', 'required' => false ),
						'raised_amount' => array( 'type' => 'number', 'required' => false ),
						'status'        => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'starts_at'     => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'ends_at'       => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/manage/campaigns/(?P<id>\d+)',
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
						'id'           => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
						'title'        => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'slug'         => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_title' ),
						'description'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
						'goal_amount'  => array( 'type' => 'number', 'required' => false ),
						'raised_amount'=> array( 'type' => 'number', 'required' => false ),
						'status'       => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'starts_at'    => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'ends_at'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
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
	 * Public campaign view by ID (active/completed only).
	 */
	public function public_get( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid campaign id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new CampaignRepository( $wpdb );
		$item       = $repository->find( $id );
		return $this->public_campaign_response( $item );
	}

	/**
	 * Public campaign view by slug.
	 */
	public function public_get_by_slug( WP_REST_Request $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Invalid campaign slug.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new CampaignRepository( $wpdb );
		$item       = $repository->find_by_slug( $slug );
		return $this->public_campaign_response( $item );
	}

	/**
	 * List campaigns.
	 */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		$page   = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$limit  = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 25 ) );
		$offset = ( $page - 1 ) * $limit;
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' !== $status && ! in_array( $status, array( 'draft', 'active', 'paused', 'completed', 'archived' ), true ) ) {
			$status = '';
		}

		global $wpdb;
		$repository = new CampaignRepository( $wpdb );

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
	 * Get campaign.
	 */
	public function get( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid campaign id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new CampaignRepository( $wpdb );
		$item       = $repository->find( $id );
		if ( ! $item ) {
			return new WP_Error( 'not_found', __( 'Campaign not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'item' => $item ), 200 );
	}

	/**
	 * Create campaign.
	 */
	public function create( WP_REST_Request $request ) {
		$payload = $this->payload( $request );

		$title = sanitize_text_field( (string) ( $payload['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'Campaign title is required.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new CampaignRepository( $wpdb );
		$id         = $repository->insert(
			array(
				'title'         => $title,
				'slug'          => sanitize_title( (string) ( $payload['slug'] ?? $title ) ),
				'description'   => sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) ),
				'goal_amount'   => isset( $payload['goal_amount'] ) ? (float) $payload['goal_amount'] : null,
				'raised_amount' => isset( $payload['raised_amount'] ) ? (float) $payload['raised_amount'] : 0,
				'status'        => $this->normalize_status( (string) ( $payload['status'] ?? 'draft' ) ),
				'starts_at'     => ! empty( $payload['starts_at'] ) ? sanitize_text_field( (string) $payload['starts_at'] ) : null,
				'ends_at'       => ! empty( $payload['ends_at'] ) ? sanitize_text_field( (string) $payload['ends_at'] ) : null,
			)
		);
		if ( $id <= 0 ) {
			return new WP_Error( 'create_failed', __( 'Could not create campaign.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'item' => $repository->find( $id ) ), 201 );
	}

	/**
	 * Update campaign.
	 */
	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid campaign id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		$payload = $this->payload( $request );

		$updates = array();
		foreach ( array( 'title', 'slug', 'status', 'starts_at', 'ends_at' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$updates[ $key ] = in_array( $key, array( 'status' ), true )
					? $this->normalize_status( (string) $payload[ $key ] )
					: sanitize_text_field( (string) $payload[ $key ] );
			}
		}
		if ( array_key_exists( 'description', $payload ) ) {
			$updates['description'] = sanitize_textarea_field( (string) $payload['description'] );
		}
		if ( array_key_exists( 'goal_amount', $payload ) ) {
			$updates['goal_amount'] = (float) $payload['goal_amount'];
		}
		if ( array_key_exists( 'raised_amount', $payload ) ) {
			$updates['raised_amount'] = (float) $payload['raised_amount'];
		}

		global $wpdb;
		$repository = new CampaignRepository( $wpdb );
		if ( ! $repository->update( $id, $updates ) ) {
			return new WP_Error( 'update_failed', __( 'Could not update campaign.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'item' => $repository->find( $id ) ), 200 );
	}

	/**
	 * Delete campaign.
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid campaign id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new CampaignRepository( $wpdb );
		if ( ! $repository->delete( $id ) ) {
			return new WP_Error( 'delete_failed', __( 'Could not delete campaign.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'deleted' => $id ), 200 );
	}

	/**
	 * Permissions.
	 */
	public function can_manage( WP_REST_Request $request ): bool {
		$manager = new CapabilityManager();
		$allowed = $manager->can( 'campaigns.manage', $request );
		return (bool) apply_filters( 'donatepress_rest_can_manage', $allowed, $request );
	}

	/**
	 * Normalize supported campaign status.
	 */
	private function normalize_status( string $status ): string {
		$status  = sanitize_key( $status );
		$allowed = array( 'draft', 'active', 'paused', 'completed', 'archived' );
		return in_array( $status, $allowed, true ) ? $status : 'draft';
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

	/**
	 * Build public campaign payload with published updates.
	 *
	 * @param array<string,mixed>|null $item
	 */
	private function public_campaign_response( ?array $item ) {
		if ( ! $item ) {
			return new WP_Error( 'not_found', __( 'Campaign not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		$status = sanitize_key( (string) ( $item['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'active', 'completed' ), true ) ) {
			return new WP_Error( 'not_available', __( 'Campaign is not publicly available.', 'donatepress' ), array( 'status' => 404 ) );
		}

		global $wpdb;
		$updates_repository = new CampaignUpdateRepository( $wpdb );
		$updates            = $updates_repository->list_by_campaign( (int) ( $item['id'] ?? 0 ), 10, 0, 'published' );

		return new WP_REST_Response(
			array(
				'success' => true,
				'item'    => $item,
				'updates' => $updates,
			),
			200
		);
	}
}
