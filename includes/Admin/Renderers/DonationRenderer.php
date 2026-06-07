<?php

namespace DonatePress\Admin\Renderers;

use DonatePress\Repositories\DonationRepository;
use DonatePress\Security\CapabilityManager;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin Donations list page.
 */
class DonationRenderer {
	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Render donations list page.
	 */
	public function render(): void {
		if ( ! $this->can_access( 'admin.donations' ) ) {
			return;
		}

		$repository = new DonationRepository( $this->wpdb );
		$rows       = $repository->list_recent( 20 );

		$table_rows = array();
		foreach ( $rows as $row ) {
			$donor_name = trim( (string) $row['donor_first_name'] . ' ' . (string) $row['donor_last_name'] );
			if ( '' === $donor_name ) {
				$donor_name = (string) $row['donor_email'];
			}

			$table_rows[] = array(
				(string) $row['donation_number'],
				$donor_name,
				strtoupper( (string) $row['currency'] ) . ' ' . number_format_i18n( (float) $row['amount'], 2 ),
				ucfirst( sanitize_text_field( (string) $row['gateway'] ) ),
				ucfirst( sanitize_text_field( (string) $row['status'] ) ),
				$this->format_datetime( (string) $row['donated_at'] ),
			);
		}

		$this->render_data_page(
			__( 'Donations', 'donatepress' ),
			__( 'Recent donations and payment state overview.', 'donatepress' ),
			array(
				array(
					'label' => __( 'Total Donations', 'donatepress' ),
					'value' => (string) $repository->count_all(),
				),
				array(
					'label' => __( 'Completed', 'donatepress' ),
					'value' => (string) $repository->count_by_status( 'completed' ),
				),
				array(
					'label' => __( 'Pending', 'donatepress' ),
					'value' => (string) $repository->count_by_status( 'pending' ),
				),
				array(
					'label' => __( 'Completed Volume', 'donatepress' ),
					'value' => $this->format_money( $repository->sum_by_status( 'completed' ) ),
				),
			),
			array(
				__( 'Donation #', 'donatepress' ),
				__( 'Donor', 'donatepress' ),
				__( 'Amount', 'donatepress' ),
				__( 'Gateway', 'donatepress' ),
				__( 'Status', 'donatepress' ),
				__( 'Date', 'donatepress' ),
			),
			$table_rows,
			__( 'No donations found yet.', 'donatepress' )
		);
	}

	/**
	 * Verify access for one admin scope.
	 *
	 * @param string $scope Admin scope identifier.
	 */
	private function can_access( string $scope ): bool {
		return ( new CapabilityManager() )->can( $scope );
	}

	/**
	 * Format money for admin screens.
	 *
	 * @param float  $amount   Numeric amount.
	 * @param string $currency ISO currency code.
	 */
	private function format_money( float $amount, string $currency = 'USD' ): string {
		return strtoupper( sanitize_text_field( $currency ) ) . ' ' . number_format_i18n( $amount, 2 );
	}

	/**
	 * Format UTC datetime string for admin display.
	 *
	 * @param string $datetime UTC datetime string.
	 */
	private function format_datetime( string $datetime ): string {
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '-';
		}

		$timestamp = strtotime( $datetime . ' UTC' );
		if ( false === $timestamp ) {
			return $datetime;
		}

		return wp_date( 'Y-m-d H:i', $timestamp );
	}

	/**
	 * Render common data page shell.
	 *
	 * @param string                          $title         Page title.
	 * @param string                          $message       Page description.
	 * @param array<int,array<string,string>> $metrics       Metric cards.
	 * @param array<int,string>               $columns       Table column headers.
	 * @param array<int,array<int,string>>    $rows          Table row data.
	 * @param string                          $empty_message Shown when rows is empty.
	 */
	private function render_data_page( string $title, string $message, array $metrics, array $columns, array $rows, string $empty_message ): void {
		?>
		<div class="wrap donatepress-admin">
			<div class="dp-card">
				<div class="dp-card-head">
					<h2><?php echo esc_html( $title ); ?></h2>
					<p><?php echo esc_html( $message ); ?></p>
				</div>

				<?php if ( ! empty( $metrics ) ) : ?>
					<div class="dp-stats-grid">
						<?php foreach ( $metrics as $metric ) : ?>
							<div class="dp-stat">
								<p class="dp-stat-label"><?php echo esc_html( $metric['label'] ?? '' ); ?></p>
								<p class="dp-stat-value"><?php echo esc_html( $metric['value'] ?? '' ); ?></p>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="dp-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<?php foreach ( $columns as $column ) : ?>
									<th scope="col"><?php echo esc_html( $column ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr>
									<td colspan="<?php echo esc_attr( (string) count( $columns ) ); ?>"><?php echo esc_html( $empty_message ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<?php foreach ( $row as $value ) : ?>
											<td><?php echo esc_html( $value ); ?></td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}
