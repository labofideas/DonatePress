<?php

namespace DonatePress\Admin\Renderers;

use DonatePress\Repositories\CampaignRepository;
use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\DonorRepository;
use DonatePress\Repositories\FormRepository;
use DonatePress\Security\CapabilityManager;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin Reports summary page.
 */
class ReportRenderer {
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
	 * Render reports summary page.
	 */
	public function render(): void {
		if ( ! $this->can_access( 'admin.reports' ) ) {
			return;
		}

		$donation_repository = new DonationRepository( $this->wpdb );
		$donor_repository    = new DonorRepository( $this->wpdb );
		$form_repository     = new FormRepository( $this->wpdb );
		$campaign_repository = new CampaignRepository( $this->wpdb );

		$rows = array(
			array( __( 'Completed Donation Volume', 'donatepress' ), $this->format_money( $donation_repository->sum_by_status( 'completed' ) ) ),
			array( __( 'Completed Donations', 'donatepress' ), (string) $donation_repository->count_by_status( 'completed' ) ),
			array( __( 'Pending Donations', 'donatepress' ), (string) $donation_repository->count_by_status( 'pending' ) ),
			array( __( 'Total Donors', 'donatepress' ), (string) $donor_repository->count_all() ),
			array( __( 'Donor Lifetime Volume', 'donatepress' ), $this->format_money( $donor_repository->sum_total_donated() ) ),
			array( __( 'Active Forms', 'donatepress' ), (string) $form_repository->count_active() ),
			array( __( 'Active Campaigns', 'donatepress' ), (string) $campaign_repository->count_active() ),
			array( __( 'Campaign Raised Amount', 'donatepress' ), $this->format_money( $campaign_repository->sum_raised_amount() ) ),
		);

		$this->render_data_page(
			__( 'Reports', 'donatepress' ),
			__( 'Operational summary for donations, donors, forms, and campaigns.', 'donatepress' ),
			array(
				array(
					'label' => __( 'Data Sources', 'donatepress' ),
					'value' => '4',
				),
				array(
					'label' => __( 'Snapshot Time', 'donatepress' ),
					'value' => $this->format_datetime( current_time( 'mysql', true ) ),
				),
			),
			array(
				__( 'Metric', 'donatepress' ),
				__( 'Value', 'donatepress' ),
			),
			$rows,
			__( 'No report data is available yet.', 'donatepress' )
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
