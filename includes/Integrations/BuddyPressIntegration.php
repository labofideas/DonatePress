<?php

namespace DonatePress\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuddyPress compatibility and optional nav integration.
 */
class BuddyPressIntegration {
	/**
	 * Active nav slug.
	 */
	private string $nav_slug = 'donations';

	/**
	 * Register integration hooks.
	 */
	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		do_action( 'donatepress_buddypress_loaded' );

		add_action( 'bp_setup_nav', array( $this, 'register_profile_nav' ), 100 );
	}

	/**
	 * Whether BuddyPress is active.
	 */
	private function is_active(): bool {
		return class_exists( 'BuddyPress' ) || function_exists( 'buddypress' );
	}

	/**
	 * Add Donations tab to BuddyPress member profile.
	 */
	public function register_profile_nav(): void {
		if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
			return;
		}

		$enabled = (bool) apply_filters( 'donatepress_bp_profile_tab_enabled', true );
		if ( ! $enabled ) {
			return;
		}

		$slug = sanitize_title( (string) apply_filters( 'donatepress_bp_profile_tab_slug', 'donations' ) );
		$name = (string) apply_filters( 'donatepress_bp_profile_tab_label', __( 'Donations', 'donatepress' ) );
		$this->nav_slug = $slug;

		bp_core_new_nav_item(
			array(
				'name'                    => $name,
				'slug'                    => $slug,
				'position'                => 81,
				'screen_function'         => array( $this, 'screen_donations' ),
				'default_subnav_slug'     => $slug,
				'show_for_displayed_user' => true,
				'user_has_access'         => is_user_logged_in(),
				'item_css_id'             => 'donatepress-bp-donations',
			)
		);

		do_action( 'donatepress_bp_profile_nav_registered', $slug, $name );
	}

	/**
	 * BuddyPress screen callback.
	 */
	public function screen_donations(): void {
		add_action( 'bp_template_content', array( $this, 'render_profile_content' ) );
		bp_core_load_template( apply_filters( 'donatepress_bp_profile_template', 'members/single/plugins' ) );
	}

	/**
	 * Render member donation history in BuddyPress profile tab.
	 */
	public function render_profile_content(): void {
		if ( ! function_exists( 'bp_displayed_user_id' ) ) {
			return;
		}

		$displayed_user_id = (int) bp_displayed_user_id();
		if ( $displayed_user_id <= 0 ) {
			echo '<p>' . esc_html__( 'No member selected.', 'donatepress' ) . '</p>';
			return;
		}

		$user = get_userdata( $displayed_user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			echo '<p>' . esc_html__( 'Donation data is not available for this member.', 'donatepress' ) . '</p>';
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dp_donations';
		$limit = (int) apply_filters( 'donatepress_bp_profile_donations_limit', 20 );
		if ( $limit < 1 ) {
			$limit = 20;
		}
		$limit = min( $limit, 100 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT donation_number, amount, currency, status, donated_at
				 FROM {$table}
				 WHERE donor_email = %s
				 ORDER BY donated_at DESC
				 LIMIT %d",
				$user->user_email,
				$limit
			),
			ARRAY_A
		);

		echo '<div class="donatepress-bp-profile">';
		echo '<h2>' . esc_html__( 'Donation History', 'donatepress' ) . '</h2>';
		echo '<p>' . esc_html__( 'Recent donations associated with this member account.', 'donatepress' ) . '</p>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No donations found yet.', 'donatepress' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Donation #', 'donatepress' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'donatepress' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'donatepress' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'donatepress' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$amount = number_format_i18n( (float) ( $row['amount'] ?? 0 ), 2 );
			$currency = strtoupper( sanitize_text_field( (string) ( $row['currency'] ?? 'USD' ) ) );
			$date = '';
			if ( ! empty( $row['donated_at'] ) ) {
				$timestamp = strtotime( (string) $row['donated_at'] . ' UTC' );
				if ( false !== $timestamp ) {
					$date = wp_date( get_option( 'date_format' ), $timestamp );
				}
			}

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['donation_number'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $currency . ' ' . $amount ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) ( $row['status'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( $date ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
