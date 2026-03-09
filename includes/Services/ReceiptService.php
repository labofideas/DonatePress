<?php

namespace DonatePress\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds donor receipt payloads (HTML/text).
 */
class ReceiptService {
	private SettingsService $settings;

	public function __construct() {
		$this->settings = new SettingsService();
	}

	/**
	 * Render receipt HTML.
	 *
	 * @param array<string,mixed> $donation Donation record.
	 */
	public function render_html( array $donation ): string {
		$org_name   = (string) $this->settings->get( 'receipt_legal_entity_name', get_bloginfo( 'name' ) );
		$tax_text   = (string) $this->settings->get( 'tax_disclaimer_text', '' );
		$footer     = (string) $this->settings->get( 'receipt_footer_text', '' );
		$donation_no = sanitize_text_field( (string) ( $donation['donation_number'] ?? '' ) );
		$email      = sanitize_email( (string) ( $donation['donor_email'] ?? '' ) );
		$name       = trim( (string) ( $donation['donor_first_name'] ?? '' ) . ' ' . (string) ( $donation['donor_last_name'] ?? '' ) );
		$currency   = strtoupper( sanitize_text_field( (string) ( $donation['currency'] ?? 'USD' ) ) );
		$amount     = number_format_i18n( (float) ( $donation['amount'] ?? 0 ), 2 );
		$date       = sanitize_text_field( (string) ( $donation['donated_at'] ?? current_time( 'mysql', true ) ) );

		return sprintf(
			'<h1>%1$s</h1><p>Donation Receipt</p><hr><p><strong>Donation #:</strong> %2$s</p><p><strong>Name:</strong> %3$s</p><p><strong>Email:</strong> %4$s</p><p><strong>Amount:</strong> %5$s %6$s</p><p><strong>Date:</strong> %7$s</p><hr><p>%8$s</p><p>%9$s</p>',
			esc_html( $org_name ),
			esc_html( $donation_no ),
			esc_html( '' !== $name ? $name : 'Donor' ),
			esc_html( $email ),
			esc_html( $currency ),
			esc_html( $amount ),
			esc_html( $date ),
			esc_html( $tax_text ),
			esc_html( $footer )
		);
	}

	/**
	 * Render plain text receipt.
	 *
	 * @param array<string,mixed> $donation Donation record.
	 */
	public function render_text( array $donation ): string {
		return wp_strip_all_tags( str_replace( '<hr>', "\n---------------------\n", $this->render_html( $donation ) ) );
	}
}
