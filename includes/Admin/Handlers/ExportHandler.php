<?php

namespace DonatePress\Admin\Handlers;

use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\DonorRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Security\CapabilityManager;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin CSV export actions.
 */
class ExportHandler {

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
	 * Export all donations as CSV.
	 */
	public function export_donations(): void {
		$this->assert_access( 'admin.donations' );
		check_admin_referer( 'donatepress_export_donations' );

		$repository = new DonationRepository( $this->wpdb );
		$rows       = $repository->list_all();

		$this->send_csv(
			'donatepress-donations-' . gmdate( 'Y-m-d' ) . '.csv',
			array(
				__( 'Donation #', 'donatepress' ),
				__( 'Donor Email', 'donatepress' ),
				__( 'First Name', 'donatepress' ),
				__( 'Last Name', 'donatepress' ),
				__( 'Amount', 'donatepress' ),
				__( 'Currency', 'donatepress' ),
				__( 'Gateway', 'donatepress' ),
				__( 'Transaction ID', 'donatepress' ),
				__( 'Recurring', 'donatepress' ),
				__( 'Frequency', 'donatepress' ),
				__( 'Status', 'donatepress' ),
				__( 'Comment', 'donatepress' ),
				__( 'Date', 'donatepress' ),
			),
			$rows,
			array( 'donation_number', 'donor_email', 'donor_first_name', 'donor_last_name', 'amount', 'currency', 'gateway', 'gateway_transaction_id', 'is_recurring', 'recurring_frequency', 'status', 'donor_comment', 'donated_at' )
		);
	}

	/**
	 * Export all donors as CSV.
	 */
	public function export_donors(): void {
		$this->assert_access( 'admin.donors' );
		check_admin_referer( 'donatepress_export_donors' );

		$repository = new DonorRepository( $this->wpdb );
		$rows       = $repository->list_all();

		$this->send_csv(
			'donatepress-donors-' . gmdate( 'Y-m-d' ) . '.csv',
			array(
				__( 'Email', 'donatepress' ),
				__( 'First Name', 'donatepress' ),
				__( 'Last Name', 'donatepress' ),
				__( 'Phone', 'donatepress' ),
				__( 'Total Donated', 'donatepress' ),
				__( 'Donation Count', 'donatepress' ),
				__( 'Status', 'donatepress' ),
				__( 'Joined', 'donatepress' ),
			),
			$rows,
			array( 'email', 'first_name', 'last_name', 'phone', 'total_donated', 'donation_count', 'status', 'created_at' )
		);
	}

	/**
	 * Export all subscriptions as CSV.
	 */
	public function export_subscriptions(): void {
		$this->assert_access( 'admin.subscriptions' );
		check_admin_referer( 'donatepress_export_subscriptions' );

		$repository = new SubscriptionRepository( $this->wpdb );
		$rows       = $repository->list_all();

		$this->send_csv(
			'donatepress-subscriptions-' . gmdate( 'Y-m-d' ) . '.csv',
			array(
				__( 'Subscription #', 'donatepress' ),
				__( 'Gateway', 'donatepress' ),
				__( 'Gateway Sub ID', 'donatepress' ),
				__( 'Amount', 'donatepress' ),
				__( 'Currency', 'donatepress' ),
				__( 'Frequency', 'donatepress' ),
				__( 'Status', 'donatepress' ),
				__( 'Failures', 'donatepress' ),
				__( 'Max Retries', 'donatepress' ),
				__( 'Next Payment', 'donatepress' ),
				__( 'Last Payment', 'donatepress' ),
				__( 'Created', 'donatepress' ),
			),
			$rows,
			array( 'subscription_number', 'gateway', 'gateway_subscription_id', 'amount', 'currency', 'frequency', 'status', 'failure_count', 'max_retries', 'next_payment_at', 'last_payment_at', 'created_at' )
		);
	}

	/**
	 * Stream CSV file to browser.
	 *
	 * @param string                         $filename Download filename.
	 * @param array<int,string>              $headers  Column header labels.
	 * @param array<int,array<string,mixed>> $rows     Data rows from repository.
	 * @param array<int,string>              $fields   Column keys to extract from each row.
	 */
	private function send_csv( string $filename, array $headers, array $rows, array $fields ): void {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to php://output, not filesystem.
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Failed to open output stream.', 'donatepress' ) );
		}

		fputcsv( $output, $headers );

		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $fields as $field ) {
				$line[] = (string) ( $row[ $field ] ?? '' );
			}
			fputcsv( $output, $line );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing php://output stream.
		fclose( $output );
		exit;
	}

	/**
	 * Verify current user capability for a given admin scope.
	 *
	 * @param string $scope Admin scope identifier.
	 */
	private function assert_access( string $scope ): void {
		$manager = new CapabilityManager();
		if ( ! $manager->can( $scope ) ) {
			wp_die( esc_html__( 'You are not allowed to access this DonatePress screen.', 'donatepress' ) );
		}
	}
}
