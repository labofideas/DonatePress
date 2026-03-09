<?php

namespace DonatePress\Services;

use DonatePress\Repositories\SubscriptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles recurring subscription creation from initial donations.
 */
class SubscriptionService {
	private SubscriptionRepository $repository;

	public function __construct( SubscriptionRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Create subscription seeded from an initial donation.
	 *
	 * @param array<string,mixed> $donation Donation snapshot.
	 * @param array<string,mixed> $payment  Payment response.
	 */
	public function create_from_initial_donation( array $donation, array $payment ): array {
		$frequency = sanitize_key( (string) ( $donation['recurring_frequency'] ?? 'monthly' ) );
		if ( '' === $frequency ) {
			$frequency = 'monthly';
		}

		$gateway_subscription_id = sanitize_text_field(
			(string) ( $payment['subscription_id'] ?? $payment['billing_agreement_id'] ?? '' )
		);

		$id = $this->repository->insert(
			array(
				'subscription_number'     => $this->generate_number(),
				'initial_donation_id'     => (int) ( $donation['id'] ?? 0 ),
				'donor_id'                => isset( $donation['donor_id'] ) ? (int) $donation['donor_id'] : null,
				'form_id'                 => (int) ( $donation['form_id'] ?? 1 ),
				'campaign_id'             => isset( $donation['campaign_id'] ) ? (int) $donation['campaign_id'] : null,
				'gateway'                 => sanitize_key( (string) ( $donation['gateway'] ?? 'stripe' ) ),
				'gateway_subscription_id' => $gateway_subscription_id,
				'amount'                  => (float) ( $donation['amount'] ?? 0 ),
				'currency'                => strtoupper( sanitize_text_field( (string) ( $donation['currency'] ?? 'USD' ) ) ),
				'frequency'               => $frequency,
				'status'                  => '' !== $gateway_subscription_id ? 'active' : 'pending',
				'next_payment_at'         => $this->next_cycle_datetime( $frequency ),
			)
		);

		if ( $id <= 0 ) {
			return array(
				'success' => false,
				'code'    => 'subscription_create_failed',
				'message' => __( 'Could not create recurring subscription.', 'donatepress' ),
			);
		}

		return array(
			'success' => true,
			'id'      => $id,
		);
	}

	private function generate_number(): string {
		return 'DPS-' . gmdate( 'YmdHis' ) . '-' . wp_rand( 100, 999 );
	}

	/**
	 * Resolve next expected billing datetime from frequency.
	 */
	private function next_cycle_datetime( string $frequency ): string {
		$base = current_time( 'timestamp', true );
		if ( 'annual' === $frequency ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $base ) );
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( '+1 month', $base ) );
	}
}
