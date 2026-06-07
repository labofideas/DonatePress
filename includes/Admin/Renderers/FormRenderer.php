<?php

namespace DonatePress\Admin\Renderers;

use DonatePress\Repositories\FormRepository;
use DonatePress\Security\CapabilityManager;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin Forms list and edit page.
 */
class FormRenderer {
	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Render forms list page.
	 */
	public function render(): void {
		if ( ! $this->can_access( 'admin.forms' ) ) {
			return;
		}

		$repository = new FormRepository( $this->wpdb );
		$filters    = $this->list_filters( 'forms' );
		$per_page   = 10;
		$rows       = $repository->list( $per_page, $filters['offset'], $filters['search'], $filters['status'] );
		$total_rows = $repository->count_filtered( $filters['search'], $filters['status'] );
		$editing_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
		$editing    = $editing_id > 0 ? $repository->find( $editing_id ) : null;
		?>
		<div class="wrap donatepress-admin">
			<div class="dp-card">
				<div class="dp-card-toolbar">
					<div class="dp-card-head">
						<h2><?php echo esc_html__( 'Forms', 'donatepress' ); ?></h2>
						<p><?php echo esc_html__( 'Create donation forms, adjust defaults, and copy the shortcode directly into any page or post.', 'donatepress' ); ?></p>
					</div>
					<?php if ( $editing ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=donatepress-forms' ) ); ?>"><?php echo esc_html__( 'Add New Form', 'donatepress' ); ?></a>
					<?php endif; ?>
				</div>

				<?php $this->render_action_notice( 'forms' ); ?>

				<div class="dp-stats-grid">
					<div class="dp-stat"><p class="dp-stat-label"><?php echo esc_html__( 'Total Forms', 'donatepress' ); ?></p><p class="dp-stat-value"><?php echo esc_html( (string) $repository->count_all() ); ?></p></div>
					<div class="dp-stat"><p class="dp-stat-label"><?php echo esc_html__( 'Active Forms', 'donatepress' ); ?></p><p class="dp-stat-value"><?php echo esc_html( (string) $repository->count_active() ); ?></p></div>
				</div>

				<?php $this->render_list_filters( 'donatepress-forms', 'forms', $filters['search'], $filters['status'], array( '' => __( 'All statuses', 'donatepress' ), 'active' => __( 'Active', 'donatepress' ), 'draft' => __( 'Draft', 'donatepress' ), 'archived' => __( 'Archived', 'donatepress' ) ) ); ?>

				<div class="dp-card dp-card-form">
					<div class="dp-card-head">
						<h3><?php echo esc_html( $editing ? __( 'Edit Form', 'donatepress' ) : __( 'Add Form', 'donatepress' ) ); ?></h3>
						<p><?php echo esc_html__( 'Titles are required. Default amount must be greater than zero.', 'donatepress' ); ?></p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dp-admin-inline-form">
						<input type="hidden" name="action" value="donatepress_save_form" />
						<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) (int) ( $editing['id'] ?? 0 ) ); ?>" />
						<?php wp_nonce_field( 'donatepress_save_form' ); ?>
						<div class="dp-grid two-col">
							<?php $this->render_admin_text_control( 'form_title', __( 'Title', 'donatepress' ), (string) ( $editing['title'] ?? '' ), true ); ?>
							<?php $this->render_admin_text_control( 'form_slug', __( 'Slug', 'donatepress' ), (string) ( $editing['slug'] ?? '' ) ); ?>
							<?php $this->render_admin_text_control( 'form_default_amount', __( 'Default Amount', 'donatepress' ), (string) ( $editing['default_amount'] ?? '25' ), true, 'number', '0.01' ); ?>
							<?php $this->render_admin_select_control( 'form_currency', __( 'Currency', 'donatepress' ), (string) ( $editing['currency'] ?? 'USD' ), array( 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP' ) ); ?>
							<?php $this->render_admin_select_control( 'form_gateway', __( 'Gateway', 'donatepress' ), (string) ( $editing['gateway'] ?? 'stripe' ), array( 'stripe' => 'Stripe', 'paypal' => 'PayPal' ) ); ?>
							<?php $this->render_admin_select_control( 'form_status', __( 'Status', 'donatepress' ), (string) ( $editing['status'] ?? 'active' ), array( 'active' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived' ) ); ?>
						</div>
						<?php submit_button( $editing ? __( 'Update Form', 'donatepress' ) : __( 'Create Form', 'donatepress' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

					<div class="dp-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Title', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Shortcode', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Slug', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Default Amount', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Gateway', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Status', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Created', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Actions', 'donatepress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr><td colspan="8"><?php echo esc_html__( 'No forms found yet.', 'donatepress' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $rows as $row ) : ?>
									<?php $shortcode = sprintf( '[donatepress_form id="%d"]', (int) $row['id'] ); ?>
									<?php $input_id = 'dp-form-shortcode-' . (int) $row['id']; ?>
									<tr>
										<td><?php echo esc_html( (string) $row['title'] ); ?></td>
										<td>
											<div class="dp-inline-code">
												<input type="text" id="<?php echo esc_attr( $input_id ); ?>" readonly value="<?php echo esc_attr( $shortcode ); ?>" />
												<button type="button" class="button button-secondary" data-copy-target="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html__( 'Copy', 'donatepress' ); ?></button>
											</div>
										</td>
										<td><?php echo esc_html( (string) $row['slug'] ); ?></td>
										<td><?php echo esc_html( $this->format_money( (float) $row['default_amount'], strtoupper( (string) $row['currency'] ) ) ); ?></td>
										<td><?php echo esc_html( ucfirst( sanitize_text_field( (string) $row['gateway'] ) ) ); ?></td>
										<td><?php echo esc_html( ucfirst( sanitize_text_field( (string) $row['status'] ) ) ); ?></td>
										<td><?php echo esc_html( $this->format_datetime( (string) $row['created_at'] ) ); ?></td>
										<td>
											<div class="dp-table-actions">
												<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'donatepress-forms', 'form_id' => (int) $row['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Edit', 'donatepress' ); ?></a>
												<a class="button-link button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'donatepress_delete_form', 'form_id' => (int) $row['id'] ), admin_url( 'admin-post.php' ) ), 'donatepress_delete_form_' . (int) $row['id'] ) ); ?>"><?php echo esc_html__( 'Delete', 'donatepress' ); ?></a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
					</div>
					<?php $this->render_pagination( 'donatepress-forms', 'forms', $filters['page'], $per_page, $total_rows, array( 'form_id' => $editing ? (int) $editing['id'] : null ) ); ?>
				</div>
			</div>
		<?php
	}

	/**
	 * Verify access for one admin scope.
	 */
	private function can_access( string $scope ): bool {
		return ( new CapabilityManager() )->can( $scope );
	}

	/**
	 * Format money for admin screens.
	 */
	private function format_money( float $amount, string $currency = 'USD' ): string {
		return strtoupper( sanitize_text_field( $currency ) ) . ' ' . number_format_i18n( $amount, 2 );
	}

	/**
	 * Format UTC datetime string for admin display.
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
	 * Render success/error notices for inline admin CRUD.
	 */
	private function render_action_notice( string $key ): void {
		$result = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
		if ( '' === $result ) {
			return;
		}

		$messages = array(
			'saved'   => array( 'success', __( 'Saved successfully.', 'donatepress' ) ),
			'deleted' => array( 'success', __( 'Deleted successfully.', 'donatepress' ) ),
			'invalid' => array( 'error', __( 'Please correct the required fields and numeric values, then try again.', 'donatepress' ) ),
			'failed'  => array( 'error', __( 'The requested action could not be completed.', 'donatepress' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		$type    = $messages[ $result ][0];
		$message = $messages[ $result ][1];
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Render a simple admin text field.
	 */
	private function render_admin_text_control( string $name, string $label, string $value, bool $required = false, string $type = 'text', string $step = '' ): void {
		echo '<div class="dp-field">';
		echo '<label for="' . esc_attr( $name ) . '">' . esc_html( $label );
		if ( $required ) {
			echo ' <span class="required">*</span>';
		}
		echo '</label>';
		echo '<input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . ( '' !== $step ? ' step="' . esc_attr( $step ) . '"' : '' ) . ' />';
		echo '</div>';
	}

	/**
	 * Render a simple admin select field.
	 *
	 * @param array<string,string> $options
	 */
	private function render_admin_select_control( string $name, string $label, string $value, array $options ): void {
		echo '<div class="dp-field">';
		echo '<label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $option_value => $option_label ) {
			echo '<option value="' . esc_attr( $option_value ) . '"' . selected( $value, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select>';
		echo '</div>';
	}

	/**
	 * Parse search/status/page filters for list screens.
	 *
	 * @return array{search:string,status:string,page:int,offset:int}
	 */
	private function list_filters( string $prefix ): array {
		$search = isset( $_GET[ $prefix . '_search' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $prefix . '_search' ] ) ) : '';
		$status = isset( $_GET[ $prefix . '_status' ] ) ? sanitize_key( wp_unslash( $_GET[ $prefix . '_status' ] ) ) : '';
		$page   = isset( $_GET[ $prefix . '_paged' ] ) ? max( 1, absint( wp_unslash( $_GET[ $prefix . '_paged' ] ) ) ) : 1;

		return array(
			'search' => $search,
			'status' => $status,
			'page'   => $page,
			'offset' => ( $page - 1 ) * 10,
		);
	}

	/**
	 * Render list search/filter controls.
	 *
	 * @param array<string,string> $status_options
	 */
	private function render_list_filters( string $page, string $prefix, string $search, string $status, array $status_options ): void {
		echo '<form method="get" class="dp-list-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '" />';
		echo '<input type="search" name="' . esc_attr( $prefix . '_search' ) . '" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search by title or slug', 'donatepress' ) . '" />';
		echo '<select name="' . esc_attr( $prefix . '_status' ) . '">';
		foreach ( $status_options as $option_value => $option_label ) {
			echo '<option value="' . esc_attr( $option_value ) . '"' . selected( $status, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'donatepress' ), 'secondary', '', false );
		if ( '' !== $search || '' !== $status ) {
			echo ' <a class="button button-link" href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html__( 'Reset', 'donatepress' ) . '</a>';
		}
		echo '</form>';
	}

	/**
	 * Render lightweight pagination.
	 *
	 * @param array<string,int|null> $extra_args
	 */
	private function render_pagination( string $page, string $prefix, int $current_page, int $per_page, int $total_rows, array $extra_args = array() ): void {
		$total_pages = max( 1, (int) ceil( $total_rows / $per_page ) );
		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<div class="dp-pagination">';
		for ( $index = 1; $index <= $total_pages; ++$index ) {
			$args = array(
				'page'                => $page,
				$prefix . '_paged'    => $index,
			);
			if ( isset( $_GET[ $prefix . '_search' ] ) ) {
				$args[ $prefix . '_search' ] = sanitize_text_field( wp_unslash( $_GET[ $prefix . '_search' ] ) );
			}
			if ( isset( $_GET[ $prefix . '_status' ] ) ) {
				$args[ $prefix . '_status' ] = sanitize_key( wp_unslash( $_GET[ $prefix . '_status' ] ) );
			}
			foreach ( $extra_args as $extra_key => $extra_value ) {
				if ( null !== $extra_value && 0 !== $extra_value ) {
					$args[ $extra_key ] = $extra_value;
				}
			}

			$class = $index === $current_page ? 'button button-primary' : 'button button-secondary';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( (string) $index ) . '</a>';
		}
		echo '</div>';
	}
}
