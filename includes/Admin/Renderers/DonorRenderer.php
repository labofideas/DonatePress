<?php

namespace DonatePress\Admin\Renderers;

use DonatePress\Repositories\DonorRepository;
use DonatePress\Security\CapabilityManager;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin Donors list page.
 */
class DonorRenderer {
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
	 * Render donors list page.
	 */
	public function render(): void {
		if ( ! $this->can_access( 'admin.donors' ) ) {
			return;
		}

		$repository = new DonorRepository( $this->wpdb );
		$rows       = $repository->list_recent( 20 );

		$table_rows = array();
		foreach ( $rows as $row ) {
			$full_name    = trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] );
			$table_rows[] = array(
				'' !== $full_name ? $full_name : __( 'Anonymous', 'donatepress' ),
				(string) $row['email'],
				(string) $row['donation_count'],
				$this->format_money( (float) $row['total_donated'] ),
				ucfirst( sanitize_text_field( (string) $row['status'] ) ),
				$this->format_datetime( (string) $row['created_at'] ),
			);
		}

		$this->render_data_page(
			__( 'Donors', 'donatepress' ),
			__( 'Donor records and contribution totals.', 'donatepress' ),
			array(
				array(
					'label' => __( 'Total Donors', 'donatepress' ),
					'value' => (string) $repository->count_all(),
				),
				array(
					'label' => __( 'Lifetime Volume', 'donatepress' ),
					'value' => $this->format_money( $repository->sum_total_donated() ),
				),
			),
			array(
				__( 'Name', 'donatepress' ),
				__( 'Email', 'donatepress' ),
				__( 'Donations', 'donatepress' ),
				__( 'Total Donated', 'donatepress' ),
				__( 'Status', 'donatepress' ),
				__( 'Joined', 'donatepress' ),
			),
			$table_rows,
			__( 'No donors found yet.', 'donatepress' ),
			array( 4 ),
			'donatepress_export_donors'
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
	 * @param string                          $title          Page title.
	 * @param string                          $message        Page description.
	 * @param array<int,array<string,string>> $metrics        Metric cards.
	 * @param array<int,string>               $columns        Table column headers.
	 * @param array<int,array<int,string>>    $rows           Table row data.
	 * @param string                          $empty_message  Shown when rows is empty.
	 * @param array<int,int>                  $badge_columns  Column indices to render as status badges.
	 * @param string                          $export_action  Admin-post action for CSV export.
	 */
	private function render_data_page( string $title, string $message, array $metrics, array $columns, array $rows, string $empty_message, array $badge_columns = array(), string $export_action = '' ): void {
		?>
		<div class="wrap donatepress-admin">
			<div class="dp-card">
				<div class="dp-card-toolbar">
					<div class="dp-card-head">
						<h2><?php echo esc_html( $title ); ?></h2>
						<p><?php echo esc_html( $message ); ?></p>
					</div>
					<?php if ( '' !== $export_action ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( $export_action ); ?>" />
							<?php wp_nonce_field( $export_action ); ?>
							<?php submit_button( __( 'Export CSV', 'donatepress' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
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
										<?php foreach ( $row as $col_idx => $value ) : ?>
											<?php if ( in_array( $col_idx, $badge_columns, true ) ) : ?>
												<td><span class="dp-badge dp-badge-<?php echo esc_attr( strtolower( $value ) ); ?>"><?php echo esc_html( $value ); ?></span></td>
											<?php else : ?>
												<td><?php echo esc_html( $value ); ?></td>
											<?php endif; ?>
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
