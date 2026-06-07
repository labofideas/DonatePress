<?php

namespace DonatePress\Admin;

use DonatePress\Admin\Renderers\CampaignRenderer;
use DonatePress\Admin\Renderers\DemoImportRenderer;
use DonatePress\Admin\Renderers\DonationRenderer;
use DonatePress\Admin\Renderers\DonorRenderer;
use DonatePress\Admin\Renderers\FormRenderer;
use DonatePress\Admin\Renderers\ReportRenderer;
use DonatePress\Admin\Renderers\SubscriptionRenderer;
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
		$demo_renderer = new DemoImportRenderer( $this->db() );
		add_action( 'admin_post_donatepress_demo_import', array( $demo_renderer, 'handle_demo_import' ) );

		$form_handler = new Handlers\FormHandler( $this->db() );
		add_action( 'admin_post_donatepress_save_form', array( $form_handler, 'save' ) );
		add_action( 'admin_post_donatepress_delete_form', array( $form_handler, 'delete' ) );

		$campaign_handler = new Handlers\CampaignHandler( $this->db() );
		add_action( 'admin_post_donatepress_save_campaign', array( $campaign_handler, 'save' ) );
		add_action( 'admin_post_donatepress_delete_campaign', array( $campaign_handler, 'delete' ) );

		$export_handler = new Handlers\ExportHandler( $this->db() );
		add_action( 'admin_post_donatepress_export_donations', array( $export_handler, 'export_donations' ) );
		add_action( 'admin_post_donatepress_export_donors', array( $export_handler, 'export_donors' ) );
		add_action( 'admin_post_donatepress_export_subscriptions', array( $export_handler, 'export_subscriptions' ) );
	}

	/**
	 * Add admin menu pages.
	 */
	public function register_menu(): void {
		$donation_renderer     = new DonationRenderer( $this->db() );
		$donor_renderer        = new DonorRenderer( $this->db() );
		$form_renderer         = new FormRenderer( $this->db() );
		$campaign_renderer     = new CampaignRenderer( $this->db() );
		$subscription_renderer = new SubscriptionRenderer( $this->db() );
		$report_renderer       = new ReportRenderer( $this->db() );
		$demo_renderer         = new DemoImportRenderer( $this->db() );

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
			array( $demo_renderer, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Donations', 'donatepress' ),
			__( 'Donations', 'donatepress' ),
			$this->capability( 'admin.donations' ),
			'donatepress-donations',
			array( $donation_renderer, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Donors', 'donatepress' ),
			__( 'Donors', 'donatepress' ),
			$this->capability( 'admin.donors' ),
			'donatepress-donors',
			array( $donor_renderer, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Forms', 'donatepress' ),
			__( 'Forms', 'donatepress' ),
			$this->capability( 'admin.forms' ),
			'donatepress-forms',
			array( $form_renderer, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Campaigns', 'donatepress' ),
			__( 'Campaigns', 'donatepress' ),
			$this->capability( 'admin.campaigns' ),
			'donatepress-campaigns',
			array( $campaign_renderer, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Reports', 'donatepress' ),
			__( 'Reports', 'donatepress' ),
			$this->capability( 'admin.reports' ),
			'donatepress-reports',
			array( $report_renderer, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Subscriptions', 'donatepress' ),
			__( 'Subscriptions', 'donatepress' ),
			$this->capability( 'admin.subscriptions' ),
			'donatepress-subscriptions',
			array( $subscription_renderer, 'render' )
		);
	}

	/**
	 * Load page-specific assets.
	 *
	 * @param string $hook Current admin page hook suffix.
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
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'restBase'      => esc_url_raw( rest_url( 'donatepress/v1' ) ),
					'settingsUrl'   => esc_url_raw( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
					'formsUrl'      => esc_url_raw( admin_url( 'admin.php?page=donatepress-forms' ) ),
					'demoImportUrl' => esc_url_raw( admin_url( 'admin.php?page=donatepress-demo-import' ) ),
					'status'        => $this->setup_wizard_status(),
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

		$countries_csv                = sanitize_text_field( $input['supported_countries_csv'] ?? '' );
		$countries                    = array_filter( array_map( 'trim', explode( ',', strtoupper( $countries_csv ) ) ) );
		$clean['supported_countries'] = empty( $countries ) ? array( 'US' ) : array_values( $countries );
		$clean['supported_countries'] = apply_filters( 'donatepress_supported_countries', $clean['supported_countries'], $input );

		$clean['stripe_publishable_key'] = sanitize_text_field( $input['stripe_publishable_key'] ?? '' );
		$clean['paypal_client_id']       = sanitize_text_field( $input['paypal_client_id'] ?? '' );
		$clean['paypal_webhook_id']      = sanitize_text_field( $input['paypal_webhook_id'] ?? '' );

		$clean['stripe_secret_key']     = $this->resolve_secret_value( $input, $current, 'stripe_secret_key' );
		$clean['stripe_webhook_secret'] = $this->resolve_secret_value( $input, $current, 'stripe_webhook_secret' );
		$clean['paypal_secret']         = $this->resolve_secret_value( $input, $current, 'paypal_secret' );

		$required    = $this->required_fields();
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
	 * Required compliance fields.
	 *
	 * @return array<int,string>
	 */
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
	 *
	 * @param string $scope Admin scope identifier.
	 */
	private function capability( string $scope ): string {
		$manager = new CapabilityManager();
		$cap     = $manager->resolve_capability( $scope );
		return '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Verify access for one admin scope.
	 *
	 * @param string $scope Admin scope identifier.
	 */
	private function can_access( string $scope ): bool {
		$manager = new CapabilityManager();
		return $manager->can( $scope );
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
	 *
	 * @param array<string,mixed> $input   Raw input.
	 * @param array<string,mixed> $current Current stored settings.
	 * @param string              $key     Setting key for the secret field.
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
	 * @param string $key Setting key.
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
