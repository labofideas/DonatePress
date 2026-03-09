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
 * Campaign update endpoints.
 */
class CampaignUpdateController {
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
			'/campaigns/(?P<campaign_id>\d+)/updates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'public_list' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'campaign_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'page'        => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
					'limit'       => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/manage/campaigns/(?P<campaign_id>\d+)/updates',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'admin_list' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'campaign_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
						'page'        => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
						'limit'       => array( 'type' => 'integer', 'required' => false, 'sanitize_callback' => 'absint' ),
						'status'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'campaign_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
						'title'       => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'content'     => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
						'status'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'published_at'=> array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/manage/campaigns/(?P<campaign_id>\d+)/updates/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'campaign_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
						'id'          => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => 'PUT,PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'campaign_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
						'id'          => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
						'title'       => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'content'     => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
						'status'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'published_at'=> array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'campaign_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
						'id'          => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					),
				),
			)
		);
	}

	/**
	 * Public list of published updates.
	 */
	public function public_list( WP_REST_Request $request ) {
		$campaign_id = (int) $request->get_param( 'campaign_id' );
		if ( $campaign_id <= 0 ) {
			return new WP_Error( 'invalid_campaign', __( 'Invalid campaign id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		$page   = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$limit  = max( 1, min( 100, (int) $request->get_param( 'limit' ) ?: 10 ) );
		$offset = ( $page - 1 ) * $limit;

		global $wpdb;
		$campaign_repository = new CampaignRepository( $wpdb );
		if ( ! $campaign_repository->find( $campaign_id ) ) {
			return new WP_Error( 'not_found', __( 'Campaign not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		$repository = new CampaignUpdateRepository( $wpdb );
		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $repository->list_by_campaign( $campaign_id, $limit, $offset, 'published' ),
				'total'   => $repository->count_by_campaign( $campaign_id, 'published' ),
				'page'    => $page,
				'limit'   => $limit,
			),
			200
		);
	}

	/**
	 * Admin list (all statuses).
	 */
	public function admin_list( WP_REST_Request $request ): WP_REST_Response {
		$campaign_id = (int) $request->get_param( 'campaign_id' );
		$page        = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$limit       = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 20 ) );
		$offset      = ( $page - 1 ) * $limit;
		$status      = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' !== $status && ! in_array( $status, array( 'draft', 'published', 'archived' ), true ) ) {
			$status = '';
		}

		global $wpdb;
		$repository = new CampaignUpdateRepository( $wpdb );
		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $repository->list_by_campaign( $campaign_id, $limit, $offset, $status ),
				'total'   => $repository->count_by_campaign( $campaign_id, $status ),
				'page'    => $page,
				'limit'   => $limit,
			),
			200
		);
	}

	/**
	 * Create update.
	 */
	public function create( WP_REST_Request $request ) {
		$campaign_id = (int) $request->get_param( 'campaign_id' );
		if ( $campaign_id <= 0 ) {
			return new WP_Error( 'invalid_campaign', __( 'Invalid campaign id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		$payload = $this->payload( $request );

		$title = sanitize_text_field( (string) ( $payload['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'Update title is required.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$campaign_repository = new CampaignRepository( $wpdb );
		if ( ! $campaign_repository->find( $campaign_id ) ) {
			return new WP_Error( 'not_found', __( 'Campaign not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		$repository = new CampaignUpdateRepository( $wpdb );
		$id         = $repository->insert(
			array(
				'campaign_id'  => $campaign_id,
				'title'        => $title,
				'content'      => sanitize_textarea_field( (string) ( $payload['content'] ?? '' ) ),
				'status'       => $this->normalize_status( (string) ( $payload['status'] ?? 'published' ) ),
				'published_at' => ! empty( $payload['published_at'] ) ? sanitize_text_field( (string) $payload['published_at'] ) : null,
			)
		);
		if ( $id <= 0 ) {
			return new WP_Error( 'create_failed', __( 'Could not create campaign update.', 'donatepress' ), array( 'status' => 500 ) );
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
	 * Get update.
	 */
	public function get( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid update id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new CampaignUpdateRepository( $wpdb );
		$item       = $repository->find( $id );
		if ( ! $item ) {
			return new WP_Error( 'not_found', __( 'Campaign update not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'item' => $item ), 200 );
	}

	/**
	 * Update update.
	 */
	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid update id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		$payload = $this->payload( $request );

		$updates = array();
		if ( array_key_exists( 'title', $payload ) ) {
			$updates['title'] = sanitize_text_field( (string) $payload['title'] );
		}
		if ( array_key_exists( 'content', $payload ) ) {
			$updates['content'] = sanitize_textarea_field( (string) $payload['content'] );
		}
		if ( array_key_exists( 'status', $payload ) ) {
			$updates['status'] = $this->normalize_status( (string) $payload['status'] );
		}
		if ( array_key_exists( 'published_at', $payload ) ) {
			$updates['published_at'] = sanitize_text_field( (string) $payload['published_at'] );
		}

		global $wpdb;
		$repository = new CampaignUpdateRepository( $wpdb );
		if ( ! $repository->update( $id, $updates ) ) {
			return new WP_Error( 'update_failed', __( 'Could not update campaign update.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'item' => $repository->find( $id ) ), 200 );
	}

	/**
	 * Delete update.
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_id', __( 'Invalid update id.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new CampaignUpdateRepository( $wpdb );
		if ( ! $repository->delete( $id ) ) {
			return new WP_Error( 'delete_failed', __( 'Could not delete campaign update.', 'donatepress' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'deleted' => $id ), 200 );
	}

	/**
	 * Permission check.
	 */
	public function can_manage( WP_REST_Request $request ): bool {
		$manager = new CapabilityManager();
		$allowed = $manager->can( 'campaign_updates.manage', $request );
		return (bool) apply_filters( 'donatepress_rest_can_manage', $allowed, $request );
	}

	/**
	 * Normalize supported update status.
	 */
	private function normalize_status( string $status ): string {
		$status  = sanitize_key( $status );
		$allowed = array( 'draft', 'published', 'archived' );
		return in_array( $status, $allowed, true ) ? $status : 'published';
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
