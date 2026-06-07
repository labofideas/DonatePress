<?php

namespace DonatePress\Admin\Handlers;

use DonatePress\Repositories\CampaignRepository;
use DonatePress\Security\CapabilityManager;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles campaign save and delete admin-post actions.
 */
class CampaignHandler {

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb WordPress database instance.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Save or update a campaign.
	 */
	public function save(): void {
		$this->assert_access( 'admin.campaigns' );
		check_admin_referer( 'donatepress_save_campaign' );

		$repository  = new CampaignRepository( $this->wpdb );
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( $_POST['campaign_id'] ) ) : 0;
		$data        = array(
			'title'         => sanitize_text_field( wp_unslash( $_POST['campaign_title'] ?? '' ) ),
			'slug'          => sanitize_title( wp_unslash( $_POST['campaign_slug'] ?? '' ) ),
			'description'   => sanitize_textarea_field( wp_unslash( $_POST['campaign_description'] ?? '' ) ),
			'goal_amount'   => '' !== (string) ( $_POST['campaign_goal_amount'] ?? '' ) ? (float) wp_unslash( $_POST['campaign_goal_amount'] ) : null,
			'raised_amount' => isset( $_POST['campaign_raised_amount'] ) ? (float) wp_unslash( $_POST['campaign_raised_amount'] ) : 0,
			'status'        => sanitize_key( wp_unslash( $_POST['campaign_status'] ?? 'draft' ) ),
		);

		if ( '' === $data['title'] || ( null !== $data['goal_amount'] && $data['goal_amount'] < 0 ) || $data['raised_amount'] < 0 ) {
			$this->redirect( 'donatepress-campaigns', 'campaigns', 'invalid' );
		}

		$success = $campaign_id > 0 ? $repository->update( $campaign_id, $data ) : $repository->insert( $data ) > 0;
		$this->redirect( 'donatepress-campaigns', 'campaigns', $success ? 'saved' : 'failed' );
	}

	/**
	 * Delete a campaign.
	 */
	public function delete(): void {
		$this->assert_access( 'admin.campaigns' );
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;
		check_admin_referer( 'donatepress_delete_campaign_' . $campaign_id );
		$success = $campaign_id > 0 ? ( new CampaignRepository( $this->wpdb ) )->delete( $campaign_id ) : false;
		$this->redirect( 'donatepress-campaigns', 'campaigns', $success ? 'deleted' : 'failed' );
	}

	/**
	 * Stop execution when the current user lacks access.
	 *
	 * @param string $scope Capability scope.
	 */
	private function assert_access( string $scope ): void {
		$manager = new CapabilityManager();
		if ( ! $manager->can( $scope ) ) {
			wp_die( esc_html__( 'You are not allowed to access this DonatePress screen.', 'donatepress' ) );
		}
	}

	/**
	 * Redirect back to an admin page with a compact result flag.
	 *
	 * @param string $page Admin page slug.
	 * @param string $key  Query parameter key for the result flag.
	 * @param string $result Result value (saved, deleted, invalid, failed).
	 */
	private function redirect( string $page, string $key, string $result ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => $page,
					$key   => $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
