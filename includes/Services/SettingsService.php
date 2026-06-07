<?php

namespace DonatePress\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central settings access helper.
 */
class SettingsService {
	private const OPTION_KEY = 'donatepress_settings';

	private const SECRET_KEYS = array(
		'stripe_secret_key',
		'stripe_webhook_secret',
		'paypal_secret',
	);

	/**
	 * Return full settings array.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Read one setting.
	 *
	 * @param mixed $default Default value.
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) {
		$settings = $this->all();
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Update one or more settings with sanitization.
	 *
	 * @param array<string,mixed> $values Key-value pairs to merge.
	 */
	public function update( array $values ): void {
		$current = $this->all();
		$merged  = array_merge( $current, $this->sanitize( $values, $current ) );
		update_option( self::OPTION_KEY, $merged );
	}

	/**
	 * Sanitize a partial settings array.
	 *
	 * @param array<string,mixed> $input   Incoming values.
	 * @param array<string,mixed> $current Existing stored values.
	 * @return array<string,mixed>
	 */
	private function sanitize( array $input, array $current ): array {
		$clean = array();

		$text_fields = array(
			'organization_name',
			'organization_phone',
			'organization_tax_id',
			'receipt_legal_entity_name',
			'statement_descriptor',
			'stripe_publishable_key',
			'paypal_client_id',
			'paypal_webhook_id',
		);

		$textarea_fields = array(
			'organization_address',
			'tax_disclaimer_text',
			'receipt_footer_text',
		);

		$url_fields = array(
			'privacy_policy_url',
			'terms_url',
		);

		foreach ( $text_fields as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		foreach ( $textarea_fields as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $input[ $key ] );
			}
		}

		foreach ( $url_fields as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = esc_url_raw( (string) $input[ $key ] );
			}
		}

		if ( array_key_exists( 'organization_email', $input ) ) {
			$clean['organization_email'] = sanitize_email( (string) $input['organization_email'] );
		}

		if ( array_key_exists( 'base_currency', $input ) ) {
			$clean['base_currency'] = strtoupper( sanitize_text_field( (string) $input['base_currency'] ) );
		}

		$bool_fields = array( 'wc_enabled', 'live_mode_enabled', 'enable_captcha' );
		foreach ( $bool_fields as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
			}
		}

		if ( array_key_exists( 'recurring_frequencies', $input ) && is_array( $input['recurring_frequencies'] ) ) {
			$clean['recurring_frequencies'] = array_values(
				array_intersect(
					array( 'monthly', 'annual' ),
					array_map( 'sanitize_key', $input['recurring_frequencies'] )
				)
			);
		}

		if ( array_key_exists( 'supported_countries', $input ) && is_array( $input['supported_countries'] ) ) {
			$clean['supported_countries'] = array_values(
				array_filter( array_map( static function ( $c ) {
					return strtoupper( sanitize_text_field( trim( (string) $c ) ) );
				}, $input['supported_countries'] ) )
			);
		}

		foreach ( self::SECRET_KEYS as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$incoming = trim( (string) $input[ $key ] );
			$clean[ $key ] = '' !== $incoming
				? sanitize_text_field( $incoming )
				: ( $current[ $key ] ?? '' );
		}

		return $clean;
	}
}
