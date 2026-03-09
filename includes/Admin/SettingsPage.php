<?php

namespace DonatePress\Admin;

use DonatePress\Repositories\CampaignRepository;
use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\DonorRepository;
use DonatePress\Repositories\FormRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Security\CapabilityManager;
use DonatePress\Services\DemoImportService;
use DonatePress\Services\SetupWizardService;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page and Settings API registration.
 */
class SettingsPage {
	private const OPTION_KEY = 'donatepress_settings';
	private const MENU_SLUG  = 'donatepress';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_setup_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_donatepress_demo_import', array( $this, 'handle_demo_import' ) );
		add_action( 'admin_post_donatepress_save_form', array( $this, 'handle_save_form' ) );
		add_action( 'admin_post_donatepress_delete_form', array( $this, 'handle_delete_form' ) );
		add_action( 'admin_post_donatepress_save_campaign', array( $this, 'handle_save_campaign' ) );
		add_action( 'admin_post_donatepress_delete_campaign', array( $this, 'handle_delete_campaign' ) );
	}

	/**
	 * Add admin menu pages.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'DonatePress', 'donatepress' ),
			__( 'DonatePress', 'donatepress' ),
			$this->capability( 'admin.settings' ),
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-heart',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Setup Wizard', 'donatepress' ),
			__( 'Setup Wizard', 'donatepress' ),
			$this->capability( 'admin.setup' ),
			'donatepress-setup',
			array( $this, 'render_setup_wizard_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'donatepress' ),
			__( 'Settings', 'donatepress' ),
			$this->capability( 'admin.settings' ),
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Demo Import', 'donatepress' ),
			__( 'Demo Import', 'donatepress' ),
			$this->capability( 'admin.setup' ),
			'donatepress-demo-import',
			array( $this, 'render_demo_import_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Donations', 'donatepress' ),
			__( 'Donations', 'donatepress' ),
			$this->capability( 'admin.donations' ),
			'donatepress-donations',
			array( $this, 'render_donations_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Donors', 'donatepress' ),
			__( 'Donors', 'donatepress' ),
			$this->capability( 'admin.donors' ),
			'donatepress-donors',
			array( $this, 'render_donors_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Forms', 'donatepress' ),
			__( 'Forms', 'donatepress' ),
			$this->capability( 'admin.forms' ),
			'donatepress-forms',
			array( $this, 'render_forms_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Campaigns', 'donatepress' ),
			__( 'Campaigns', 'donatepress' ),
			$this->capability( 'admin.campaigns' ),
			'donatepress-campaigns',
			array( $this, 'render_campaigns_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Reports', 'donatepress' ),
			__( 'Reports', 'donatepress' ),
			$this->capability( 'admin.reports' ),
			'donatepress-reports',
			array( $this, 'render_reports_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Subscriptions', 'donatepress' ),
			__( 'Subscriptions', 'donatepress' ),
			$this->capability( 'admin.subscriptions' ),
			'donatepress-subscriptions',
			array( $this, 'render_subscriptions_page' )
		);
	}

	/**
	 * Load page-specific assets.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'donatepress' ) ) {
			return;
		}

		wp_enqueue_style(
			'donatepress-admin-settings',
			DONATEPRESS_URL . 'assets/admin/settings.css',
			array(),
			DONATEPRESS_VERSION
		);

		wp_enqueue_script(
			'donatepress-admin-settings',
			DONATEPRESS_URL . 'assets/admin/settings.js',
			array(),
			DONATEPRESS_VERSION,
			true
		);

		wp_enqueue_script(
			'donatepress-admin-shell',
			DONATEPRESS_URL . 'assets/admin/admin-shell.js',
			array(),
			DONATEPRESS_VERSION,
			true
		);

		if ( false !== strpos( $hook, 'donatepress_page_donatepress-setup' ) ) {
			wp_enqueue_script(
				'donatepress-setup-wizard',
				DONATEPRESS_URL . 'assets/admin/setup-wizard.js',
				array( 'wp-element' ),
				DONATEPRESS_VERSION,
				true
			);

			wp_localize_script(
				'donatepress-setup-wizard',
				'donatepressSetupWizard',
				array(
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'restBase'    => esc_url_raw( rest_url( 'donatepress/v1' ) ),
					'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
					'formsUrl'    => esc_url_raw( admin_url( 'admin.php?page=donatepress-forms' ) ),
					'demoImportUrl' => esc_url_raw( admin_url( 'admin.php?page=donatepress-demo-import' ) ),
					'status'      => $this->setup_wizard_status(),
				)
			);
		}
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		register_setting(
			'donatepress_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Render modern settings page.
	 */
	public function render_page(): void {
		if ( ! $this->can_access( 'admin.settings' ) ) {
			return;
		}

		$setup_complete  = (int) get_option( 'donatepress_setup_completed', 0 ) === 1;
		$demo_state      = ( new DemoImportService() )->status();
		$required_fields = $this->required_fields();
		$filled          = 0;
		foreach ( $required_fields as $field_key ) {
			if ( '' !== trim( (string) $this->get_setting( $field_key ) ) ) {
				++$filled;
			}
		}
		$progress = (int) round( ( $filled / count( $required_fields ) ) * 100 );
		?>
		<div class="wrap donatepress-admin">
			<div class="donatepress-hero">
				<div>
					<p class="donatepress-eyebrow"><?php echo esc_html__( 'DonatePress v1', 'donatepress' ); ?></p>
					<h1><?php echo esc_html__( 'Settings & Configuration', 'donatepress' ); ?></h1>
					<p><?php echo esc_html__( 'Configure compliance, payment credentials, and product defaults with a launch-ready setup flow.', 'donatepress' ); ?></p>
					<div class="dp-hero-actions">
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=donatepress-setup' ) ); ?>"><?php echo esc_html__( 'Open Setup Wizard', 'donatepress' ); ?></a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=donatepress-demo-import' ) ); ?>"><?php echo esc_html__( 'Import Demo Content', 'donatepress' ); ?></a>
					</div>
					<?php if ( ! empty( $demo_state['has_import'] ) ) : ?>
						<p class="dp-inline-note"><?php echo esc_html__( 'Demo pages are available on the frontend and can be reused as your starting point.', 'donatepress' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="donatepress-status-card <?php echo $setup_complete ? 'is-good' : 'is-warning'; ?>">
					<p class="label"><?php echo esc_html__( 'Compliance Readiness', 'donatepress' ); ?></p>
					<p class="value"><?php echo esc_html( $progress ); ?>%</p>
					<div class="bar"><span style="width: <?php echo esc_attr( (string) $progress ); ?>%"></span></div>
					<p class="hint">
						<?php
						echo $setup_complete
							? esc_html__( 'Required fields complete. You can enable live mode.', 'donatepress' )
							: esc_html__( 'Complete required fields to unlock live payments.', 'donatepress' );
						?>
					</p>
				</div>
			</div>

			<?php settings_errors( 'donatepress_settings' ); ?>
			<?php if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'donatepress' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php" class="donatepress-form">
				<?php settings_fields( 'donatepress_settings_group' ); ?>

				<div class="donatepress-tabs" role="tablist" aria-label="DonatePress settings sections">
					<button type="button" class="tab is-active" data-tab="org" role="tab" aria-selected="true"><?php echo esc_html__( 'Organization', 'donatepress' ); ?></button>
					<button type="button" class="tab" data-tab="payment" role="tab" aria-selected="false"><?php echo esc_html__( 'Payments', 'donatepress' ); ?></button>
					<button type="button" class="tab" data-tab="product" role="tab" aria-selected="false"><?php echo esc_html__( 'v1 Product', 'donatepress' ); ?></button>
				</div>

				<div class="donatepress-panel is-active" data-panel="org">
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php echo esc_html__( 'Organization & Compliance', 'donatepress' ); ?></h2>
							<p><?php echo esc_html__( 'These values are site-owner specific and appear on receipts and donor-facing legal surfaces.', 'donatepress' ); ?></p>
						</div>
						<div class="dp-grid two-col">
							<?php $this->render_text_input( 'organization_name', __( 'Organization Name', 'donatepress' ), true ); ?>
							<?php $this->render_text_input( 'organization_email', __( 'Organization Email', 'donatepress' ), true, 'email' ); ?>
							<?php $this->render_text_input( 'organization_phone', __( 'Organization Phone', 'donatepress' ) ); ?>
							<?php $this->render_text_input( 'organization_tax_id', __( 'Organization Tax ID', 'donatepress' ) ); ?>
							<?php $this->render_text_input( 'receipt_legal_entity_name', __( 'Receipt Legal Entity Name', 'donatepress' ), true ); ?>
							<?php $this->render_text_input( 'privacy_policy_url', __( 'Privacy Policy URL', 'donatepress' ), true, 'url' ); ?>
							<?php $this->render_text_input( 'terms_url', __( 'Terms URL', 'donatepress' ), true, 'url' ); ?>
						</div>
						<?php $this->render_textarea_input( 'organization_address', __( 'Organization Address', 'donatepress' ) ); ?>
						<?php $this->render_textarea_input( 'tax_disclaimer_text', __( 'Tax Disclaimer Text', 'donatepress' ), true ); ?>
						<?php $this->render_textarea_input( 'receipt_footer_text', __( 'Receipt Footer Text', 'donatepress' ) ); ?>
					</div>
				</div>

				<div class="donatepress-panel" data-panel="payment" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php echo esc_html__( 'Payment Credentials', 'donatepress' ); ?></h2>
							<p><?php echo esc_html__( 'Use test credentials while developing. Leave a secret field blank to keep the current stored value.', 'donatepress' ); ?></p>
						</div>
						<div class="dp-grid two-col">
							<?php $this->render_text_input( 'base_currency', __( 'Base Currency (ISO)', 'donatepress' ), true ); ?>
							<?php $this->render_text_input( 'supported_countries_csv', __( 'Supported Countries (CSV ISO2)', 'donatepress' ), true, 'text', 'US, CA, GB' ); ?>
							<?php $this->render_text_input( 'statement_descriptor', __( 'Statement Descriptor', 'donatepress' ) ); ?>
							<?php $this->render_text_input( 'stripe_publishable_key', __( 'Stripe Publishable Key', 'donatepress' ), true ); ?>
							<?php $this->render_password_input( 'stripe_secret_key', __( 'Stripe Secret Key', 'donatepress' ) ); ?>
							<?php $this->render_password_input( 'stripe_webhook_secret', __( 'Stripe Webhook Secret (whsec)', 'donatepress' ) ); ?>
							<?php $this->render_text_input( 'paypal_client_id', __( 'PayPal Client ID', 'donatepress' ), true ); ?>
							<?php $this->render_password_input( 'paypal_secret', __( 'PayPal Secret', 'donatepress' ) ); ?>
							<?php $this->render_text_input( 'paypal_webhook_id', __( 'PayPal Webhook ID', 'donatepress' ) ); ?>
						</div>
					</div>
				</div>

				<div class="donatepress-panel" data-panel="product" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php echo esc_html__( 'v1 Product Decisions', 'donatepress' ); ?></h2>
							<p><?php echo esc_html__( 'These values match your current v1 scope lock and can be adjusted later.', 'donatepress' ); ?></p>
						</div>
						<div class="dp-toggle-row">
							<?php $this->render_checkbox_input( 'wc_enabled', __( 'Enable WooCommerce in v1', 'donatepress' ), __( 'One-time donation flow via WooCommerce checkout.', 'donatepress' ) ); ?>
							<?php $this->render_checkbox_input( 'live_mode_enabled', __( 'Enable Live Payments', 'donatepress' ), __( 'Blocked until required compliance fields are complete.', 'donatepress' ) ); ?>
							<?php $this->render_checkbox_input( 'enable_captcha', __( 'Require Captcha on Donation Forms', 'donatepress' ), __( 'Enforce captcha token verification for donation submissions.', 'donatepress' ) ); ?>
						</div>
						<div class="dp-field">
							<label><?php echo esc_html__( 'Recurring Frequencies', 'donatepress' ); ?></label>
							<div class="dp-checkset">
								<?php
								$selected = $this->get_setting( 'recurring_frequencies' );
								if ( ! is_array( $selected ) ) {
									$selected = array();
								}
								$allowed_frequencies = $this->allowed_frequencies();
								?>
								<?php foreach ( $allowed_frequencies as $frequency ) : ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[recurring_frequencies][]" value="<?php echo esc_attr( $frequency ); ?>" <?php checked( in_array( $frequency, $selected, true ) ); ?> />
										<?php echo esc_html( ucwords( str_replace( '_', ' ', $frequency ) ) ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div>

				<?php do_action( 'donatepress_render_settings_panels', self::OPTION_KEY ); ?>

				<div class="donatepress-actions">
					<?php submit_button( __( 'Save Settings', 'donatepress' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render React setup wizard shell.
	 */
	public function render_setup_wizard_page(): void {
		if ( ! $this->can_access( 'admin.setup' ) ) {
			return;
		}
		?>
		<div class="wrap donatepress-admin">
			<div class="dp-card">
				<div class="dp-card-head">
					<h2><?php echo esc_html__( 'DonatePress Setup Wizard', 'donatepress' ); ?></h2>
					<p><?php echo esc_html__( 'Complete onboarding in order: organization profile, currency or gateways, and your first donation form. If you want a faster first look, import the demo content first.', 'donatepress' ); ?></p>
				</div>
				<div id="donatepress-setup-wizard-root"></div>
				<p class="dp-inline-note">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=donatepress-demo-import' ) ); ?>"><?php echo esc_html__( 'Open Demo Import', 'donatepress' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render demo import onboarding page.
	 */
	public function render_demo_import_page(): void {
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
								<a href="<?php echo esc_url( get_permalink( (int) $page_id ) ?: '#' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( (int) $page_id ) ); ?></a>
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
	 * Render donations list page.
	 */
	public function render_donations_page(): void {
		if ( ! $this->can_access( 'admin.donations' ) ) {
			return;
		}

		$repository = new DonationRepository( $this->db() );
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
	 * Render donors list page.
	 */
	public function render_donors_page(): void {
		if ( ! $this->can_access( 'admin.donors' ) ) {
			return;
		}

		$repository = new DonorRepository( $this->db() );
		$rows       = $repository->list_recent( 20 );

		$table_rows = array();
		foreach ( $rows as $row ) {
			$full_name = trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] );
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
			__( 'No donors found yet.', 'donatepress' )
		);
	}

	/**
	 * Render forms list page.
	 */
	public function render_forms_page(): void {
		if ( ! $this->can_access( 'admin.forms' ) ) {
			return;
		}

		$repository = new FormRepository( $this->db() );
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
	 * Render campaigns list page.
	 */
	public function render_campaigns_page(): void {
		if ( ! $this->can_access( 'admin.campaigns' ) ) {
			return;
		}

		$repository = new CampaignRepository( $this->db() );
		$filters    = $this->list_filters( 'campaigns' );
		$per_page   = 10;
		$rows       = $repository->list( $per_page, $filters['offset'], $filters['search'], $filters['status'] );
		$total_rows = $repository->count_filtered( $filters['search'], $filters['status'] );
		$form_repository = new FormRepository( $this->db() );
		$first_form      = $form_repository->find_first_active();
		$editing_id      = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;
		$editing         = $editing_id > 0 ? $repository->find( $editing_id ) : null;
		?>
		<div class="wrap donatepress-admin">
			<div class="dp-card">
				<div class="dp-card-toolbar">
					<div class="dp-card-head">
						<h2><?php echo esc_html__( 'Campaigns', 'donatepress' ); ?></h2>
						<p><?php echo esc_html__( 'Create focused fundraising campaigns with goals and a dedicated frontend shortcode.', 'donatepress' ); ?></p>
					</div>
					<?php if ( $editing ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=donatepress-campaigns' ) ); ?>"><?php echo esc_html__( 'Add New Campaign', 'donatepress' ); ?></a>
					<?php endif; ?>
				</div>

				<?php $this->render_action_notice( 'campaigns' ); ?>

				<div class="dp-stats-grid">
					<div class="dp-stat"><p class="dp-stat-label"><?php echo esc_html__( 'Total Campaigns', 'donatepress' ); ?></p><p class="dp-stat-value"><?php echo esc_html( (string) $repository->count_all() ); ?></p></div>
					<div class="dp-stat"><p class="dp-stat-label"><?php echo esc_html__( 'Active Campaigns', 'donatepress' ); ?></p><p class="dp-stat-value"><?php echo esc_html( (string) $repository->count_active() ); ?></p></div>
					<div class="dp-stat"><p class="dp-stat-label"><?php echo esc_html__( 'Raised Amount', 'donatepress' ); ?></p><p class="dp-stat-value"><?php echo esc_html( $this->format_money( $repository->sum_raised_amount() ) ); ?></p></div>
				</div>

				<?php $this->render_list_filters( 'donatepress-campaigns', 'campaigns', $filters['search'], $filters['status'], array( '' => __( 'All statuses', 'donatepress' ), 'active' => __( 'Active', 'donatepress' ), 'draft' => __( 'Draft', 'donatepress' ), 'completed' => __( 'Completed', 'donatepress' ), 'archived' => __( 'Archived', 'donatepress' ) ) ); ?>

				<div class="dp-card dp-card-form">
					<div class="dp-card-head">
						<h3><?php echo esc_html( $editing ? __( 'Edit Campaign', 'donatepress' ) : __( 'Add Campaign', 'donatepress' ) ); ?></h3>
						<p><?php echo esc_html__( 'Campaign title is required. Raised amount cannot exceed a negative number, and goal amount cannot be below zero.', 'donatepress' ); ?></p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dp-admin-inline-form">
						<input type="hidden" name="action" value="donatepress_save_campaign" />
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) ( $editing['id'] ?? 0 ) ); ?>" />
						<?php wp_nonce_field( 'donatepress_save_campaign' ); ?>
						<div class="dp-grid two-col">
							<?php $this->render_admin_text_control( 'campaign_title', __( 'Title', 'donatepress' ), (string) ( $editing['title'] ?? '' ), true ); ?>
							<?php $this->render_admin_text_control( 'campaign_slug', __( 'Slug', 'donatepress' ), (string) ( $editing['slug'] ?? '' ) ); ?>
							<?php $this->render_admin_text_control( 'campaign_goal_amount', __( 'Goal Amount', 'donatepress' ), (string) ( $editing['goal_amount'] ?? '' ), false, 'number', '0.01' ); ?>
							<?php $this->render_admin_text_control( 'campaign_raised_amount', __( 'Raised Amount', 'donatepress' ), (string) ( $editing['raised_amount'] ?? '0' ), false, 'number', '0.01' ); ?>
							<?php $this->render_admin_select_control( 'campaign_status', __( 'Status', 'donatepress' ), (string) ( $editing['status'] ?? 'draft' ), array( 'draft' => 'Draft', 'active' => 'Active', 'completed' => 'Completed', 'archived' => 'Archived' ) ); ?>
						</div>
						<div class="dp-field">
							<label for="campaign_description"><?php echo esc_html__( 'Description', 'donatepress' ); ?></label>
							<textarea id="campaign_description" name="campaign_description" rows="4"><?php echo esc_textarea( (string) ( $editing['description'] ?? '' ) ); ?></textarea>
						</div>
						<?php submit_button( $editing ? __( 'Update Campaign', 'donatepress' ) : __( 'Create Campaign', 'donatepress' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

					<div class="dp-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Title', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Slug', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Goal', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Raised', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Shortcode', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Status', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Created', 'donatepress' ); ?></th>
								<th><?php echo esc_html__( 'Actions', 'donatepress' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr><td colspan="8"><?php echo esc_html__( 'No campaigns found yet.', 'donatepress' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $rows as $row ) : ?>
									<?php $campaign_shortcode = $first_form ? sprintf( '[donatepress_campaign id="%d" form="%d"]', (int) $row['id'], (int) $first_form['id'] ) : sprintf( '[donatepress_campaign id="%d"]', (int) $row['id'] ); ?>
									<?php $input_id = 'dp-campaign-shortcode-' . (int) $row['id']; ?>
									<tr>
										<td><?php echo esc_html( (string) $row['title'] ); ?></td>
										<td><?php echo esc_html( (string) $row['slug'] ); ?></td>
										<td><?php echo esc_html( null !== $row['goal_amount'] ? $this->format_money( (float) $row['goal_amount'] ) : '-' ); ?></td>
										<td><?php echo esc_html( $this->format_money( (float) $row['raised_amount'] ) ); ?></td>
										<td>
											<div class="dp-inline-code">
												<input type="text" id="<?php echo esc_attr( $input_id ); ?>" readonly value="<?php echo esc_attr( $campaign_shortcode ); ?>" />
												<button type="button" class="button button-secondary" data-copy-target="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html__( 'Copy', 'donatepress' ); ?></button>
											</div>
										</td>
										<td><?php echo esc_html( ucfirst( sanitize_text_field( (string) $row['status'] ) ) ); ?></td>
										<td><?php echo esc_html( $this->format_datetime( (string) $row['created_at'] ) ); ?></td>
										<td>
											<div class="dp-table-actions">
												<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'donatepress-campaigns', 'campaign_id' => (int) $row['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Edit', 'donatepress' ); ?></a>
												<a class="button-link button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'donatepress_delete_campaign', 'campaign_id' => (int) $row['id'] ), admin_url( 'admin-post.php' ) ), 'donatepress_delete_campaign_' . (int) $row['id'] ) ); ?>"><?php echo esc_html__( 'Delete', 'donatepress' ); ?></a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
					</div>
					<?php $this->render_pagination( 'donatepress-campaigns', 'campaigns', $filters['page'], $per_page, $total_rows, array( 'campaign_id' => $editing ? (int) $editing['id'] : null ) ); ?>
				</div>
			</div>
		<?php
	}

	/**
	 * Render reports summary page.
	 */
	public function render_reports_page(): void {
		if ( ! $this->can_access( 'admin.reports' ) ) {
			return;
		}

		$donation_repository = new DonationRepository( $this->db() );
		$donor_repository    = new DonorRepository( $this->db() );
		$form_repository     = new FormRepository( $this->db() );
		$campaign_repository = new CampaignRepository( $this->db() );

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
	 * Render recurring subscriptions page.
	 */
	public function render_subscriptions_page(): void {
		if ( ! $this->can_access( 'admin.subscriptions' ) ) {
			return;
		}

		$repository = new SubscriptionRepository( $this->db() );
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
	 * Render common data page shell.
	 *
	 * @param array<int,array<string,string>> $metrics
	 * @param array<int,string>               $columns
	 * @param array<int,array<int,string>>    $rows
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

	/**
	 * Sanitize all plugin settings.
	 *
	 * @param array<string,mixed> $input Raw settings.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $input ): array {
		$current = get_option( self::OPTION_KEY, array() );
		$clean   = array();

		$clean['organization_name']         = sanitize_text_field( $input['organization_name'] ?? '' );
		$clean['organization_email']        = sanitize_email( $input['organization_email'] ?? '' );
		$clean['organization_phone']        = sanitize_text_field( $input['organization_phone'] ?? '' );
		$clean['organization_address']      = sanitize_textarea_field( $input['organization_address'] ?? '' );
		$clean['organization_tax_id']       = sanitize_text_field( $input['organization_tax_id'] ?? '' );
		$clean['receipt_legal_entity_name'] = sanitize_text_field( $input['receipt_legal_entity_name'] ?? '' );
		$clean['tax_disclaimer_text']       = sanitize_textarea_field( $input['tax_disclaimer_text'] ?? '' );
		$clean['privacy_policy_url']        = esc_url_raw( $input['privacy_policy_url'] ?? '' );
		$clean['terms_url']                 = esc_url_raw( $input['terms_url'] ?? '' );
		$clean['receipt_footer_text']       = sanitize_textarea_field( $input['receipt_footer_text'] ?? '' );
		$clean['base_currency']             = strtoupper( sanitize_text_field( $input['base_currency'] ?? 'USD' ) );
		$clean['statement_descriptor']      = sanitize_text_field( $input['statement_descriptor'] ?? '' );
		$clean['wc_enabled']                = ! empty( $input['wc_enabled'] ) ? 1 : 0;
		$clean['live_mode_enabled']         = ! empty( $input['live_mode_enabled'] ) ? 1 : 0;
		$clean['enable_captcha']            = ! empty( $input['enable_captcha'] ) ? 1 : 0;

		$freqs = $input['recurring_frequencies'] ?? array();
		if ( ! is_array( $freqs ) ) {
			$freqs = array();
		}
		$allowed_freqs                  = $this->allowed_frequencies();
		$clean['recurring_frequencies'] = array_values( array_intersect( $allowed_freqs, $freqs ) );
		if ( empty( $clean['recurring_frequencies'] ) ) {
			$clean['recurring_frequencies'] = $allowed_freqs;
		}

		$countries_csv = sanitize_text_field( $input['supported_countries_csv'] ?? '' );
		$countries     = array_filter( array_map( 'trim', explode( ',', strtoupper( $countries_csv ) ) ) );
		$clean['supported_countries'] = empty( $countries ) ? array( 'US' ) : array_values( $countries );
		$clean['supported_countries'] = apply_filters( 'donatepress_supported_countries', $clean['supported_countries'], $input );

		$clean['stripe_publishable_key'] = sanitize_text_field( $input['stripe_publishable_key'] ?? '' );
		$clean['paypal_client_id']       = sanitize_text_field( $input['paypal_client_id'] ?? '' );
		$clean['paypal_webhook_id']      = sanitize_text_field( $input['paypal_webhook_id'] ?? '' );

		$clean['stripe_secret_key']     = $this->resolve_secret_value( $input, $current, 'stripe_secret_key' );
		$clean['stripe_webhook_secret'] = $this->resolve_secret_value( $input, $current, 'stripe_webhook_secret' );
		$clean['paypal_secret']         = $this->resolve_secret_value( $input, $current, 'paypal_secret' );

		$required = $this->required_fields();
		$is_complete = true;
		foreach ( $required as $key ) {
			if ( empty( $clean[ $key ] ) ) {
				$is_complete = false;
				break;
			}
		}

		update_option( 'donatepress_setup_completed', $is_complete ? 1 : 0 );

		if ( ! $is_complete && ! empty( $clean['live_mode_enabled'] ) ) {
			$clean['live_mode_enabled'] = 0;
			add_settings_error(
				'donatepress_settings',
				'donatepress_live_mode_blocked',
				esc_html__( 'Live mode requires all required Organization & Compliance fields.', 'donatepress' ),
				'error'
			);
		}

		do_action( 'donatepress_settings_updated', $clean, $current, $input );

		return $clean;
	}

	/**
	 * Show setup reminder notice.
	 */
	public function render_setup_notice(): void {
		if ( ! $this->can_access( 'admin.settings' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::MENU_SLUG === $page ) {
			return;
		}

		if ( (int) get_option( 'donatepress_setup_completed', 0 ) === 1 ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$wizard_url   = admin_url( 'admin.php?page=donatepress-setup' );
		$demo_url     = admin_url( 'admin.php?page=donatepress-demo-import' );
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'DonatePress setup is incomplete. Complete required compliance fields before enabling live payments.', 'donatepress' ) . ' ';
		echo '<a href="' . esc_url( $wizard_url ) . '">' . esc_html__( 'Open setup wizard', 'donatepress' ) . '</a> · ';
		echo '<a href="' . esc_url( $demo_url ) . '">' . esc_html__( 'Import demo content', 'donatepress' ) . '</a> · ';
		echo '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open settings', 'donatepress' ) . '</a>';
		echo '</p></div>';
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
	 * Save or update a form.
	 */
	public function handle_save_form(): void {
		$this->assert_admin_access( 'admin.forms' );
		check_admin_referer( 'donatepress_save_form' );

		$repository = new FormRepository( $this->db() );
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
			$this->redirect_with_result( 'donatepress-forms', 'forms', 'invalid' );
		}

		$success = $form_id > 0 ? $repository->update( $form_id, $data ) : $repository->insert( $data ) > 0;
		$this->redirect_with_result( 'donatepress-forms', 'forms', $success ? 'saved' : 'failed' );
	}

	/**
	 * Delete a form.
	 */
	public function handle_delete_form(): void {
		$this->assert_admin_access( 'admin.forms' );
		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
		check_admin_referer( 'donatepress_delete_form_' . $form_id );
		$success = $form_id > 0 ? ( new FormRepository( $this->db() ) )->delete( $form_id ) : false;
		$this->redirect_with_result( 'donatepress-forms', 'forms', $success ? 'deleted' : 'failed' );
	}

	/**
	 * Save or update a campaign.
	 */
	public function handle_save_campaign(): void {
		$this->assert_admin_access( 'admin.campaigns' );
		check_admin_referer( 'donatepress_save_campaign' );

		$repository  = new CampaignRepository( $this->db() );
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
			$this->redirect_with_result( 'donatepress-campaigns', 'campaigns', 'invalid' );
		}

		$success = $campaign_id > 0 ? $repository->update( $campaign_id, $data ) : $repository->insert( $data ) > 0;
		$this->redirect_with_result( 'donatepress-campaigns', 'campaigns', $success ? 'saved' : 'failed' );
	}

	/**
	 * Delete a campaign.
	 */
	public function handle_delete_campaign(): void {
		$this->assert_admin_access( 'admin.campaigns' );
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( wp_unslash( $_GET['campaign_id'] ) ) : 0;
		check_admin_referer( 'donatepress_delete_campaign_' . $campaign_id );
		$success = $campaign_id > 0 ? ( new CampaignRepository( $this->db() ) )->delete( $campaign_id ) : false;
		$this->redirect_with_result( 'donatepress-campaigns', 'campaigns', $success ? 'deleted' : 'failed' );
	}

	private function required_fields(): array {
		$fields = array(
			'organization_name',
			'organization_email',
			'receipt_legal_entity_name',
			'tax_disclaimer_text',
			'privacy_policy_url',
			'terms_url',
		);
		return apply_filters( 'donatepress_required_settings_fields', $fields );
	}

	/**
	 * Allowed recurring frequencies in v1.
	 *
	 * @return array<int,string>
	 */
	private function allowed_frequencies(): array {
		$frequencies = array( 'monthly', 'annual' );
		$frequencies = apply_filters( 'donatepress_allowed_recurring_frequencies', $frequencies );
		if ( ! is_array( $frequencies ) || empty( $frequencies ) ) {
			return array( 'monthly', 'annual' );
		}
		return array_values( array_unique( array_map( 'sanitize_key', $frequencies ) ) );
	}

	/**
	 * Build setup wizard progress payload.
	 *
	 * @return array<string,mixed>
	 */
	private function setup_wizard_status(): array {
		$service = new SetupWizardService();
		return $service->status();
	}

	/**
	 * Resolve shared wpdb instance.
	 */
	private function db(): wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Resolve capability string for one admin scope.
	 */
	private function capability( string $scope ): string {
		$manager = new CapabilityManager();
		$cap     = $manager->resolve_capability( $scope );
		return '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Verify access for one admin scope.
	 */
	private function can_access( string $scope ): bool {
		$manager = new CapabilityManager();
		return $manager->can( $scope );
	}

	/**
	 * Stop execution when the current user lacks access.
	 */
	private function assert_admin_access( string $scope ): void {
		if ( ! $this->can_access( $scope ) ) {
			wp_die( esc_html__( 'You are not allowed to access this DonatePress screen.', 'donatepress' ) );
		}
	}

	/**
	 * Redirect back to an admin page with a compact result flag.
	 */
	private function redirect_with_result( string $page, string $key, string $result ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => $page,
					$key     => $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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

	/**
	 * Registered theme menu locations.
	 *
	 * @return array<string,string>
	 */
	private function available_menu_locations(): array {
		$locations = get_registered_nav_menus();
		return is_array( $locations ) ? $locations : array();
	}

	private function render_text_input( string $key, string $label, bool $required = false, string $type = 'text', string $placeholder = '' ): void {
		$value = (string) $this->get_setting( $key );
		echo '<div class="dp-field">';
		echo '<label for="dp-' . esc_attr( $key ) . '">' . esc_html( $label );
		if ( $required ) {
			echo ' <span class="required">*</span>';
		}
		echo '</label>';
		echo '<input id="dp-' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required' : '' ) . ' placeholder="' . esc_attr( $placeholder ) . '" />';
		echo '</div>';
	}

	private function render_textarea_input( string $key, string $label, bool $required = false ): void {
		$value = (string) $this->get_setting( $key );
		echo '<div class="dp-field">';
		echo '<label for="dp-' . esc_attr( $key ) . '">' . esc_html( $label );
		if ( $required ) {
			echo ' <span class="required">*</span>';
		}
		echo '</label>';
		echo '<textarea id="dp-' . esc_attr( $key ) . '" rows="4" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" ' . ( $required ? 'required' : '' ) . '>' . esc_textarea( $value ) . '</textarea>';
		echo '</div>';
	}

	private function render_password_input( string $key, string $label ): void {
		$value = (string) $this->get_setting( $key );
		$mask  = '' !== $value ? str_repeat( '*', 12 ) : '';
		echo '<div class="dp-field">';
		echo '<label for="dp-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<input id="dp-' . esc_attr( $key ) . '" type="password" autocomplete="new-password" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="" placeholder="' . esc_attr( $mask ) . '" />';
		echo '<p class="hint">' . esc_html__( 'Leave blank to keep existing value.', 'donatepress' ) . '</p>';
		echo '</div>';
	}

	private function render_checkbox_input( string $key, string $label, string $description ): void {
		$value = (int) $this->get_setting( $key );
		echo '<label class="dp-toggle">';
		echo '<input type="checkbox" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="1" ' . checked( $value, 1, false ) . ' />';
		echo '<span class="dp-toggle-body">';
		echo '<strong>' . esc_html( $label ) . '</strong>';
		echo '<small>' . esc_html( $description ) . '</small>';
		echo '</span>';
		echo '</label>';
	}

	/**
	 * Preserve existing secret if input value is empty.
	 */
	private function resolve_secret_value( array $input, array $current, string $key ): string {
		$incoming = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		if ( '' === $incoming ) {
			return isset( $current[ $key ] ) ? (string) $current[ $key ] : '';
		}
		return sanitize_text_field( $incoming );
	}

	/**
	 * Read a setting with safe default.
	 *
	 * @return mixed
	 */
	private function get_setting( string $key ) {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( 'supported_countries_csv' === $key ) {
			$countries = $settings['supported_countries'] ?? array();
			if ( is_array( $countries ) ) {
				return implode( ', ', $countries );
			}
			return '';
		}
		return $settings[ $key ] ?? '';
	}
}
