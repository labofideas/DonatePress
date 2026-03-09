<?php

namespace DonatePress\Services;

use DonatePress\Admin\SettingsPage;
use DonatePress\Repositories\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup wizard orchestration for onboarding progress/actions.
 */
class SetupWizardService {
	/**
	 * Return setup progress snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$required     = $this->required_fields();
		$required_set = 0;
		$settings     = $this->settings();

		foreach ( $required as $field_key ) {
			if ( '' !== trim( (string) ( $settings[ $field_key ] ?? '' ) ) ) {
				++$required_set;
			}
		}

		$form_count = 0;
		global $wpdb;
		if ( $wpdb instanceof \wpdb && method_exists( $wpdb, 'get_var' ) ) {
			$form_repository = new FormRepository( $wpdb );
			$form_count      = $form_repository->count_all();
		}

		$status = array(
			'required_total'    => count( $required ),
			'required_set'      => $required_set,
			'forms_count'       => $form_count,
			'base_currency'     => (string) ( $settings['base_currency'] ?? 'USD' ),
			'stripe_configured' => '' !== trim( (string) ( $settings['stripe_publishable_key'] ?? '' ) ),
			'paypal_configured' => '' !== trim( (string) ( $settings['paypal_client_id'] ?? '' ) ),
		);

		$status['is_complete'] = $this->is_complete( $status );
		return $status;
	}

	/**
	 * Save settings payload from setup wizard.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function save_settings( array $payload ): array {
		$current = $this->settings();
		$merged  = array_merge( $current, $payload );

		$settings_page = new SettingsPage();
		$clean         = $settings_page->sanitize_settings( $merged );
		update_option( 'donatepress_settings', $clean, false );

		return $this->status();
	}

	/**
	 * Ensure a first form exists and return it.
	 *
	 * @return array<string,mixed>
	 */
	public function ensure_first_form(): array {
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Database connection unavailable.', 'donatepress' ),
			);
		}

		$form_repository = new FormRepository( $wpdb );
		$existing        = $form_repository->find_by_slug( 'general-donation-form' );
		if ( $existing ) {
			return array(
				'success' => true,
				'item'    => $existing,
				'status'  => $this->status(),
			);
		}

		$wizard_status = $this->status();
		$gateway       = ! empty( $wizard_status['stripe_configured'] ) ? 'stripe' : 'paypal';
		if ( empty( $wizard_status['paypal_configured'] ) && empty( $wizard_status['stripe_configured'] ) ) {
			$gateway = 'stripe';
		}

		$form_id = $form_repository->insert(
			array(
				'title'          => 'General Donation Form',
				'slug'           => 'general-donation-form',
				'default_amount' => 25,
				'currency'       => (string) ( $wizard_status['base_currency'] ?? 'USD' ),
				'gateway'        => $gateway,
				'status'         => 'active',
				'config'         => array(
					'multi_step'          => true,
					'amount_presets'      => array( 25, 50, 100 ),
					'allow_custom_amount' => true,
					'allow_recurring'     => true,
					'fee_recovery'        => true,
				),
			)
		);

		if ( $form_id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'Could not create first form.', 'donatepress' ),
			);
		}

		$item = $form_repository->find( $form_id );
		return array(
			'success' => true,
			'item'    => $item,
			'status'  => $this->status(),
		);
	}

	/**
	 * Mark setup complete when requirements are satisfied.
	 *
	 * @return array<string,mixed>
	 */
	public function complete(): array {
		$status = $this->status();
		if ( ! $this->is_complete( $status ) ) {
			update_option( 'donatepress_setup_completed', 0 );
			return array(
				'success' => false,
				'status'  => $status,
				'message' => __( 'Setup is not complete yet.', 'donatepress' ),
			);
		}

		update_option( 'donatepress_setup_completed', 1 );
		return array(
			'success' => true,
			'status'  => $status,
		);
	}

	/**
	 * Setup completion rule.
	 *
	 * @param array<string,mixed> $status
	 */
	private function is_complete( array $status ): bool {
		$required_complete = (int) ( $status['required_set'] ?? 0 ) >= (int) ( $status['required_total'] ?? 0 );
		$gateway_complete  = ! empty( $status['stripe_configured'] ) || ! empty( $status['paypal_configured'] );
		$form_complete     = (int) ( $status['forms_count'] ?? 0 ) > 0;
		return $required_complete && $gateway_complete && $form_complete;
	}

	/**
	 * Required settings fields for onboarding.
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
	 * Current settings map.
	 *
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		$settings = get_option( 'donatepress_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}
}
