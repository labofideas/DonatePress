<?php

namespace DonatePress\Admin\Renderers;

use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Security\CapabilityManager;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin Subscriptions list page.
 */
class SubscriptionRenderer {
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
	 * Render recurring subscriptions page.
	 */
	public function render(): void {
		if ( ! $this->can_access( 'admin.subscriptions' ) ) {
			return;
		}

		$repository = new SubscriptionRepository( $this->wpdb );
		$rows       = $repository->list_recent( 25 );

		$table_rows = array();
		foreach ( $rows as $row ) {
			$table_rows[] = array(
				(string) $row['subscription_number'],
				ucfirst( sanitize_text_field( (string) $row['gateway'] ) ),
				$this->format_money( (float) $row['amount'], strtoupper( (string) $row['currency'] ) ),
				ucfirst( sanitize_text_field( (string) $row['frequency'] ) ),
				ucfirst( sanitize_text_field( (string) $row['status'] ) ),
				(string) (int) $row['failure_count'],
				$this->format_datetime( (string) $row['updated_at'] ),
			);
		}

		$this->render_data_page(
			__( 'Subscriptions', 'donatepress' ),
			__( 'Recurring donation subscription lifecycle and health snapshot.', 'donatepress' ),
			array(
				array(
					'label' => __( 'Total Subscriptions', 'donatepress' ),
					'value' => (string) $repository->count_all(),
				),
				array(
					'label' => __( 'Active', 'donatepress' ),
					'value' => (string) $repository->count_by_status( 'active' ),
				),
				array(
					'label' => __( 'Failed', 'donatepress' ),
					'value' => (string) $repository->count_by_status( 'failed' ),
				),
				array(
					'label' => __( 'Paused', 'donatepress' ),
					'value' => (string) $repository->count_by_status( 'paused' ),
				),
			),
			array(
				__( 'Subscription #', 'donatepress' ),
				__( 'Gateway', 'donatepress' ),
				__( 'Amount', 'donatepress' ),
				__( 'Frequency', 'donatepress' ),
				__( 'Status', 'donatepress' ),
				__( 'Failures', 'donatepress' ),
				__( 'Updated', 'donatepress' ),
			),
			$table_rows,
			__( 'No subscriptions found yet.', 'donatepress' )
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
