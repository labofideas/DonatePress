<?php

namespace DonatePress\Admin\Renderers;

use DonatePress\Security\CapabilityManager;
use DonatePress\Services\DemoImportService;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin Demo Import page and handles the import action.
 */
class DemoImportRenderer {
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
	 * Render demo import onboarding page.
	 */
	public function render(): void {
		if ( ! $this->can_access( 'admin.setup' ) ) {
			return;
		}

		$service = new DemoImportService();
		$status  = $service->status();
		?>
		<div class="wrap donatepress-admin">
			<div class="donatepress-hero">
				<div>
					<p class="donatepress-eyebrow"><?php echo esc_html__( 'First Run', 'donatepress' ); ?></p>
					<h1><?php echo esc_html__( 'Import Demo Content', 'donatepress' ); ?></h1>
					<p><?php echo esc_html__( 'Create a complete starter experience with frontend pages, a demo campaign, a donation form, and a navigation menu so new users can understand the plugin before they configure every setting.', 'donatepress' ); ?></p>
						<div class="dp-hero-actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="donatepress_demo_import" />
								<?php wp_nonce_field( 'donatepress_demo_import' ); ?>
								<select name="menu_location">
									<option value=""><?php echo esc_html__( 'Assign only if a location is empty', 'donatepress' ); ?></option>
									<?php foreach ( $this->available_menu_locations() as $location_key => $label ) : ?>
										<option value="<?php echo esc_attr( $location_key ); ?>"><?php echo esc_html( $label . ' (' . $location_key . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php submit_button( empty( $status['has_import'] ) ? __( 'Run Demo Import', 'donatepress' ) : __( 'Re-run Demo Import', 'donatepress' ), 'primary', 'submit', false ); ?>
							</form>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=donatepress-setup' ) ); ?>"><?php echo esc_html__( 'Open Setup Wizard', 'donatepress' ); ?></a>
					</div>
				</div>
				<div class="donatepress-status-card <?php echo ! empty( $status['has_import'] ) ? 'is-good' : 'is-warning'; ?>">
					<p class="label"><?php echo esc_html__( 'Demo Status', 'donatepress' ); ?></p>
					<p class="value"><?php echo ! empty( $status['has_import'] ) ? esc_html__( 'Ready', 'donatepress' ) : esc_html__( 'Pending', 'donatepress' ); ?></p>
					<p class="hint">
						<?php
						echo ! empty( $status['has_import'] )
							? esc_html__( 'Starter pages and menu links are available.', 'donatepress' )
							: esc_html__( 'Import once to create a realistic starter site structure.', 'donatepress' );
						?>
					</p>
				</div>
			</div>

			<?php if ( isset( $_GET['demo-import'] ) ) : ?>
				<?php $result = sanitize_key( wp_unslash( $_GET['demo-import'] ) ); ?>
				<?php $error_message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : __( 'Demo import failed.', 'donatepress' ); ?>
				<?php if ( 'success' === $result ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Demo content imported successfully.', 'donatepress' ); ?></p></div>
				<?php elseif ( 'failed' === $result ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $error_message ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="dp-grid two-col dp-stack-top">
				<div class="dp-card">
					<div class="dp-card-head">
						<h2><?php echo esc_html__( 'What gets created', 'donatepress' ); ?></h2>
						<p><?php echo esc_html__( 'The import is safe to re-run. Existing DonatePress demo pages are updated in place instead of duplicated.', 'donatepress' ); ?></p>
					</div>
					<ul class="dp-check-list">
						<li><?php echo esc_html__( 'A ready-to-use donation form', 'donatepress' ); ?></li>
						<li><?php echo esc_html__( 'A sample campaign with goal and progress', 'donatepress' ); ?></li>
						<li><?php echo esc_html__( 'Frontend pages for Donate, Campaign, Donor Portal, and Start Here', 'donatepress' ); ?></li>
						<li><?php echo esc_html__( 'A DonatePress Demo navigation menu', 'donatepress' ); ?></li>
						<li><?php echo esc_html__( 'Quick links back to your setup and settings screens', 'donatepress' ); ?></li>
					</ul>
				</div>
				<div class="dp-card">
					<div class="dp-card-head">
						<h2><?php echo esc_html__( 'After import', 'donatepress' ); ?></h2>
						<p><?php echo esc_html__( 'Use the demo as your working reference, then replace the sample copy and gateway configuration with your real data.', 'donatepress' ); ?></p>
					</div>
					<div class="dp-link-list">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=donatepress' ) ); ?>"><?php echo esc_html__( 'Open Settings', 'donatepress' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=donatepress-forms' ) ); ?>"><?php echo esc_html__( 'Review Forms', 'donatepress' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=donatepress-campaigns' ) ); ?>"><?php echo esc_html__( 'Review Campaigns', 'donatepress' ); ?></a>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $status['has_import'] ) ) : ?>
				<div class="dp-card dp-stack-top">
					<div class="dp-card-head">
						<h2><?php echo esc_html__( 'Demo Links', 'donatepress' ); ?></h2>
						<p><?php echo esc_html__( 'Open the generated pages to verify the frontend experience.', 'donatepress' ); ?></p>
					</div>
					<div class="dp-link-list">
						<?php foreach ( (array) ( $status['pages'] ?? array() ) as $page_id ) : ?>
							<?php if ( get_post_status( (int) $page_id ) ) : ?>
								<a href="<?php echo esc_url( get_permalink( (int) $page_id ) ? get_permalink( (int) $page_id ) : '#' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( (int) $page_id ) ); ?></a>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
					<p class="dp-inline-note">
						<?php
						if ( ! empty( $status['assigned_location'] ) ) {
							printf(
								/* translators: %s theme menu location slug */
								esc_html__( 'The demo menu is assigned to the "%s" theme location.', 'donatepress' ),
								esc_html( (string) $status['assigned_location'] )
							);
						} else {
							echo esc_html__( 'The demo menu was created but not auto-assigned because this theme already uses all menu locations.', 'donatepress' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle demo import action.
	 */
	public function handle_demo_import(): void {
		if ( ! $this->can_access( 'admin.setup' ) ) {
			wp_die( esc_html__( 'You are not allowed to import demo content.', 'donatepress' ) );
		}

		check_admin_referer( 'donatepress_demo_import' );

		$service = new DemoImportService();
		$result  = $service->import( isset( $_POST['menu_location'] ) ? sanitize_key( wp_unslash( $_POST['menu_location'] ) ) : '' );
		$args    = array(
			'page'        => 'donatepress-demo-import',
			'demo-import' => ! empty( $result['success'] ) ? 'success' : 'failed',
		);

		if ( empty( $result['success'] ) && ! empty( $result['message'] ) ) {
			$args['message'] = (string) $result['message'];
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
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
	 * Registered theme menu locations.
	 *
	 * @return array<string,string>
	 */
	private function available_menu_locations(): array {
		$locations = get_registered_nav_menus();
		return is_array( $locations ) ? $locations : array();
	}
}
