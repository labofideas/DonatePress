<?php

namespace DonatePress\Services;

use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\DonorRepository;
use DonatePress\Repositories\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and prepares donation records.
 */
class DonationService {
	/**
	 * Repository.
	 */
	private DonationRepository $repository;

	/**
	 * Constructor.
	 */
	public function __construct( DonationRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Create a pending donation.
	 *
	 * @return array<string,mixed>
	 */
	public function create_pending( array $payload ): array {
		$currency = strtoupper( sanitize_text_field( (string) ( $payload['currency'] ?? 'USD' ) ) );
		$gateway  = sanitize_key( (string) ( $payload['gateway'] ?? 'stripe' ) );
		$amount   = (float) $payload['amount'];
		$is_recurring = ! empty( $payload['is_recurring'] );
		$frequency    = sanitize_key( (string) ( $payload['recurring_frequency'] ?? 'monthly' ) );
		$form_id      = isset( $payload['form_id'] ) ? (int) $payload['form_id'] : 1;

		if ( $is_recurring ) {
			$allowed = apply_filters( 'donatepress_allowed_recurring_frequencies', array( 'monthly', 'annual' ) );
			if ( ! is_array( $allowed ) || empty( $allowed ) ) {
				$allowed = array( 'monthly', 'annual' );
			}
			$allowed = array_values( array_unique( array_map( 'sanitize_key', $allowed ) ) );
			if ( ! in_array( $frequency, $allowed, true ) ) {
				$frequency = (string) reset( $allowed );
			}
		} else {
			$frequency = '';
		}

		global $wpdb;
		if ( $wpdb instanceof \wpdb ) {
			$form_repository = new FormRepository( $wpdb );
			$form            = $form_repository->find( $form_id );
			if ( ! $form ) {
				$form = $form_repository->find_first_active();
			}
			if ( ! $form ) {
				return array(
					'success' => false,
					'code'    => 'invalid_form',
					'message' => __( 'No active donation form is available.', 'donatepress' ),
				);
			}
			$form_id = (int) ( $form['id'] ?? 0 );
		}

		$data = array(
			'donation_number'  => $this->generate_number(),
			'donor_email'      => sanitize_email( (string) ( $payload['email'] ?? '' ) ),
			'donor_first_name' => sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) ),
			'donor_last_name'  => sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) ),
			'amount'           => $amount,
			'currency'         => $currency,
			'gateway'          => $gateway,
			'is_recurring'     => $is_recurring,
			'recurring_frequency' => $frequency,
			'form_id'          => $form_id,
			'campaign_id'      => isset( $payload['campaign_id'] ) ? (int) $payload['campaign_id'] : null,
			'donor_comment'    => sanitize_textarea_field( (string) ( $payload['comment'] ?? '' ) ),
		);

		if ( '' !== $data['donor_email'] ) {
			if ( $wpdb instanceof \wpdb ) {
				$donor_repository = new DonorRepository( $wpdb );
				$donor_id         = $donor_repository->find_or_create(
					$data['donor_email'],
					$data['donor_first_name'],
					$data['donor_last_name']
				);
				$data['donor_id'] = $donor_id > 0 ? $donor_id : null;
			}
		}

		/**
		 * Filter pending donation payload before persistence.
		 *
		 * @param array<string,mixed> $data Prepared data.
		 * @param array<string,mixed> $payload Raw payload.
		 */
		$data = apply_filters( 'donatepress_prepare_pending_donation', $data, $payload );

		$id = $this->repository->insert_pending( $data );
		if ( $id <= 0 ) {
			return array(
				'success' => false,
				'code'    => 'insert_failed',
				'message' => __( 'Could not create donation.', 'donatepress' ),
			);
		}

		do_action( 'donatepress_donation_pending_created', $id, $data );

		return array(
			'success'         => true,
			'donation_id'     => $id,
			'donation_number' => $data['donation_number'],
			'status'          => 'pending',
			'donation'        => array(
				'id'              => $id,
				'donation_number' => $data['donation_number'],
				'amount'          => $data['amount'],
				'currency'        => $data['currency'],
				'gateway'         => $data['gateway'],
				'is_recurring'    => $data['is_recurring'],
				'recurring_frequency' => $data['recurring_frequency'],
				'donor_email'     => $data['donor_email'],
				'donor_id'        => $data['donor_id'] ?? null,
				'campaign_id'     => $data['campaign_id'] ?? null,
				'form_id'         => $data['form_id'],
			),
		);
	}

	/**
	 * Generate donation number.
	 */
	private function generate_number(): string {
		return 'DP-' . gmdate( 'YmdHis' ) . '-' . wp_rand( 100, 999 );
	}
}
