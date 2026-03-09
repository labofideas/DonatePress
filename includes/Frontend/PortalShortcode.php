<?php

namespace DonatePress\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donor portal frontend shortcode.
 */
class PortalShortcode {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_shortcode( 'donatepress_portal', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register script/style handles.
	 */
	public function register_assets(): void {
		wp_register_style(
			'donatepress-portal',
			DONATEPRESS_URL . 'assets/frontend/portal.css',
			array(),
			DONATEPRESS_VERSION
		);

		wp_register_script(
			'donatepress-portal',
			DONATEPRESS_URL . 'assets/frontend/portal.js',
			array(),
			DONATEPRESS_VERSION,
			true
		);
	}

	/**
	 * Render portal shortcode.
	 */
	public function render( array $atts ): string {
		wp_enqueue_style( 'donatepress-portal' );
		wp_enqueue_script( 'donatepress-portal' );

		$base = esc_url_raw( rest_url( 'donatepress/v1/portal' ) );
		$nonce = wp_create_nonce( 'donatepress_portal' );
		ob_start();
		?>
		<div class="donatepress-portal" data-dp-portal data-rest-base="<?php echo esc_url( $base ); ?>" data-dp-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="dp-portal-card" data-dp-stage="request">
				<h3><?php echo esc_html__( 'Donor Portal Access', 'donatepress' ); ?></h3>
				<p><?php echo esc_html__( 'Enter your donation email to receive a secure login link. Returning donors can click the email link and sign in automatically.', 'donatepress' ); ?></p>
				<form data-dp-request-link>
					<label><?php echo esc_html__( 'Email', 'donatepress' ); ?></label>
					<input type="email" name="email" required />
					<button type="submit"><?php echo esc_html__( 'Send Link', 'donatepress' ); ?></button>
				</form>
			</div>

			<div class="dp-portal-card" data-dp-stage="auth">
				<h3><?php echo esc_html__( 'Magic Token Login', 'donatepress' ); ?></h3>
				<p><?php echo esc_html__( 'If you opened the email link directly, DonatePress will sign you in automatically. You can still paste the token manually if needed.', 'donatepress' ); ?></p>
				<form data-dp-auth>
					<label><?php echo esc_html__( 'Token', 'donatepress' ); ?></label>
					<input type="text" name="token" required />
					<button type="submit"><?php echo esc_html__( 'Sign In', 'donatepress' ); ?></button>
				</form>
			</div>

			<div class="dp-portal-card" data-dp-stage="dashboard" hidden>
				<h3><?php echo esc_html__( 'Your Donor Dashboard', 'donatepress' ); ?></h3>
				<div data-dp-profile></div>
				<div data-dp-donations></div>
				<div data-dp-subscriptions></div>
				<div data-dp-annual-summary></div>
				<button type="button" data-dp-logout><?php echo esc_html__( 'Logout', 'donatepress' ); ?></button>
			</div>

			<p class="dp-portal-message" data-dp-message aria-live="polite"></p>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
