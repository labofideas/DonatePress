<?php

namespace DonatePress\Admin\Handlers;

use DonatePress\Repositories\FormRepository;
use DonatePress\Security\CapabilityManager;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles form save and delete admin-post actions.
 */
class FormHandler {

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
	 * Save or update a form.
	 */
	public function save(): void {
		$this->assert_access( 'admin.forms' );
		check_admin_referer( 'donatepress_save_form' );

		$repository = new FormRepository( $this->wpdb );
		$form_id    = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$data       = array(
			'title'          => sanitize_text_field( wp_unslash( $_POST['form_title'] ?? '' ) ),
			'slug'           => sanitize_title( wp_unslash( $_POST['form_slug'] ?? '' ) ),
			'default_amount' => isset( $_POST['form_default_amount'] ) ? (float) wp_unslash( $_POST['form_default_amount'] ) : 25,
			'currency'       => sanitize_text_field( wp_unslash( $_POST['form_currency'] ?? 'USD' ) ),
			'gateway'        => sanitize_key( wp_unslash( $_POST['form_gateway'] ?? 'stripe' ) ),
			'status'         => sanitize_key( wp_unslash( $_POST['form_status'] ?? 'active' ) ),
		);

		if ( '' === $data['title'] || $data['default_amount'] <= 0 ) {
			$this->redirect( 'donatepress-forms', 'forms', 'invalid' );
		}

		$success = $form_id > 0 ? $repository->update( $form_id, $data ) : $repository->insert( $data ) > 0;
		$this->redirect( 'donatepress-forms', 'forms', $success ? 'saved' : 'failed' );
	}

	/**
	 * Delete a form.
	 */
	public function delete(): void {
		$this->assert_access( 'admin.forms' );
		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
		check_admin_referer( 'donatepress_delete_form_' . $form_id );
		$success = $form_id > 0 ? ( new FormRepository( $this->wpdb ) )->delete( $form_id ) : false;
		$this->redirect( 'donatepress-forms', 'forms', $success ? 'deleted' : 'failed' );
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
