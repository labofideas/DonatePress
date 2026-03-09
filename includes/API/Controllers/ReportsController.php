<?php

namespace DonatePress\API\Controllers;

use DonatePress\Repositories\CampaignRepository;
use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\DonorRepository;
use DonatePress\Repositories\FormRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Security\CapabilityManager;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports and entity listing endpoints for admin clients.
 */
class ReportsController {
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
			'/reports/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/donors',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'donors' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_limit' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/forms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'forms' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_limit' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/campaigns',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'campaigns' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_limit' ),
					),
				),
			)
		);
	}

	/**
	 * Return summary metrics snapshot.
	 */
	public function summary(): WP_REST_Response {
		global $wpdb;
		$donations     = new DonationRepository( $wpdb );
		$donors        = new DonorRepository( $wpdb );
		$forms         = new FormRepository( $wpdb );
		$campaigns     = new CampaignRepository( $wpdb );
		$subscriptions = new SubscriptionRepository( $wpdb );

		$data = array(
			'donations' => array(
				'total'            => $donations->count_all(),
				'completed_count'  => $donations->count_by_status( 'completed' ),
				'pending_count'    => $donations->count_by_status( 'pending' ),
				'completed_volume' => $donations->sum_by_status( 'completed' ),
			),
			'donors' => array(
				'total'           => $donors->count_all(),
				'lifetime_volume' => $donors->sum_total_donated(),
			),
			'forms' => array(
				'total'  => $forms->count_all(),
				'active' => $forms->count_active(),
			),
			'campaigns' => array(
				'total'  => $campaigns->count_all(),
				'active' => $campaigns->count_active(),
				'raised' => $campaigns->sum_raised_amount(),
			),
			'subscriptions' => array(
				'total'     => $subscriptions->count_all(),
				'active'    => $subscriptions->count_by_status( 'active' ),
				'failed'    => $subscriptions->count_by_status( 'failed' ),
				'paused'    => $subscriptions->count_by_status( 'paused' ),
				'expired'   => $subscriptions->count_by_status( 'expired' ),
				'cancelled' => $subscriptions->count_by_status( 'cancelled' ),
			),
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * List donors.
	 */
	public function donors( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$repo  = new DonorRepository( $wpdb );
		$limit = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 25 ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $repo->list_recent( $limit ),
				'total'   => $repo->count_all(),
			),
			200
		);
	}

	/**
	 * List forms.
	 */
	public function forms( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$repo  = new FormRepository( $wpdb );
		$limit = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 25 ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $repo->list_recent( $limit ),
				'total'   => $repo->count_all(),
			),
			200
		);
	}

	/**
	 * List campaigns.
	 */
	public function campaigns( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$repo  = new CampaignRepository( $wpdb );
		$limit = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 25 ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $repo->list_recent( $limit ),
				'total'   => $repo->count_all(),
			),
			200
		);
	}

	/**
	 * Permissions.
	 */
	public function can_manage( WP_REST_Request $request ): bool {
		$manager = new CapabilityManager();
		$allowed = $manager->can( 'reports.view', $request );
		return (bool) apply_filters( 'donatepress_rest_can_manage', $allowed, $request );
	}

	/**
	 * Validate optional list limit arg.
	 *
	 * @param mixed $value
	 */
	public function validate_limit( $value ): bool {
		return is_numeric( $value ) && (int) $value > 0;
	}
}
