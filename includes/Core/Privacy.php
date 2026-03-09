<?php

namespace DonatePress\Core;

use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\DonorRepository;
use DonatePress\Repositories\PortalTokenRepository;
use DonatePress\Repositories\SubscriptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress personal data export/erase integration.
 */
class Privacy {
	/**
	 * Register privacy callbacks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Register exporter definition.
	 *
	 * @param array<string,mixed> $exporters
	 * @return array<string,mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['donatepress'] = array(
			'exporter_friendly_name' => __( 'DonatePress Donor Data', 'donatepress' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Register eraser definition.
	 *
	 * @param array<string,mixed> $erasers
	 * @return array<string,mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['donatepress'] = array(
			'eraser_friendly_name' => __( 'DonatePress Donor Data', 'donatepress' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Export personal data for one donor email.
	 *
	 * @return array<string,mixed>
	 */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		$email = sanitize_email( $email_address );
		if ( ! is_email( $email ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$donors        = new DonorRepository( $wpdb );
		$donations     = new DonationRepository( $wpdb );
		$subscriptions = new SubscriptionRepository( $wpdb );
		$items         = array();

		$donor = $donors->find_by_email( $email );
		if ( $donor ) {
			$items[] = array(
				'group_id'    => 'donatepress',
				'group_label' => __( 'DonatePress Donor Profile', 'donatepress' ),
				'item_id'     => 'donatepress-donor-' . (int) $donor['id'],
				'data'        => array(
					array(
						'name'  => __( 'Email', 'donatepress' ),
						'value' => (string) ( $donor['email'] ?? '' ),
					),
					array(
						'name'  => __( 'First Name', 'donatepress' ),
						'value' => (string) ( $donor['first_name'] ?? '' ),
					),
					array(
						'name'  => __( 'Last Name', 'donatepress' ),
						'value' => (string) ( $donor['last_name'] ?? '' ),
					),
					array(
						'name'  => __( 'Phone', 'donatepress' ),
						'value' => (string) ( $donor['phone'] ?? '' ),
					),
					array(
						'name'  => __( 'Status', 'donatepress' ),
						'value' => (string) ( $donor['status'] ?? '' ),
					),
				),
			);
		}

		foreach ( $donations->list_by_email( $email, 200 ) as $donation ) {
			$items[] = array(
				'group_id'    => 'donatepress',
				'group_label' => __( 'DonatePress Donations', 'donatepress' ),
				'item_id'     => 'donatepress-donation-' . (int) ( $donation['id'] ?? 0 ),
				'data'        => array(
					array(
						'name'  => __( 'Donation Number', 'donatepress' ),
						'value' => (string) ( $donation['donation_number'] ?? '' ),
					),
					array(
						'name'  => __( 'Amount', 'donatepress' ),
						'value' => (string) ( $donation['currency'] ?? '' ) . ' ' . (string) ( $donation['amount'] ?? '' ),
					),
					array(
						'name'  => __( 'Status', 'donatepress' ),
						'value' => (string) ( $donation['status'] ?? '' ),
					),
					array(
						'name'  => __( 'Gateway', 'donatepress' ),
						'value' => (string) ( $donation['gateway'] ?? '' ),
					),
					array(
						'name'  => __( 'Donated At', 'donatepress' ),
						'value' => (string) ( $donation['donated_at'] ?? '' ),
					),
				),
			);
		}

		foreach ( $subscriptions->list_by_email( $email, 200 ) as $subscription ) {
			$items[] = array(
				'group_id'    => 'donatepress',
				'group_label' => __( 'DonatePress Subscriptions', 'donatepress' ),
				'item_id'     => 'donatepress-subscription-' . (int) ( $subscription['id'] ?? 0 ),
				'data'        => array(
					array(
						'name'  => __( 'Subscription Number', 'donatepress' ),
						'value' => (string) ( $subscription['subscription_number'] ?? '' ),
					),
					array(
						'name'  => __( 'Amount', 'donatepress' ),
						'value' => (string) ( $subscription['currency'] ?? '' ) . ' ' . (string) ( $subscription['amount'] ?? '' ),
					),
					array(
						'name'  => __( 'Frequency', 'donatepress' ),
						'value' => (string) ( $subscription['frequency'] ?? '' ),
					),
					array(
						'name'  => __( 'Status', 'donatepress' ),
						'value' => (string) ( $subscription['status'] ?? '' ),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase/anonymize personal data for one donor email.
	 *
	 * @return array<string,mixed>
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		$email = sanitize_email( $email_address );
		if ( ! is_email( $email ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$donors = new DonorRepository( $wpdb );
		$tokens = new PortalTokenRepository( $wpdb );
		$donor  = $donors->find_by_email( $email );

		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		if ( $donor ) {
			$items_removed = $donors->anonymize( (int) $donor['id'] ) || $items_removed;
			$messages[]    = __( 'DonatePress donor profile was anonymized.', 'donatepress' );
		}

		$deleted = $tokens->delete_by_email( $email );
		if ( $deleted > 0 ) {
			$items_removed = true;
			$messages[]    = __( 'DonatePress portal login tokens were deleted.', 'donatepress' );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
