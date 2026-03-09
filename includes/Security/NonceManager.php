<?php

namespace DonatePress\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nonce generation and verification for form submissions.
 */
class NonceManager {
	/**
	 * Custom token separator.
	 */
	private const TOKEN_SEPARATOR = '.';

	/**
	 * Build action string.
	 */
	private function action( int $form_id ): string {
		return 'donatepress_submit_form_' . $form_id;
	}

	/**
	 * Create nonce for form.
	 */
	public function create( int $form_id ): string {
		$timestamp = (string) time();
		$payload   = $form_id . self::TOKEN_SEPARATOR . $timestamp;
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'donatepress_form_token' ) );
		return $payload . self::TOKEN_SEPARATOR . $signature;
	}

	/**
	 * Verify nonce for form.
	 */
	public function verify( int $form_id, string $nonce ): bool {
		// Legacy support for wp_create_nonce tokens.
		$action = $this->action( $form_id );
		if ( (bool) wp_verify_nonce( $nonce, $action ) ) {
			return true;
		}

		/*
		 * Fallback for cached public pages shown to logged-in users:
		 * try verification in logged-out nonce context as well.
		 */
		if ( is_user_logged_in() ) {
			$current_user = get_current_user_id();
			wp_set_current_user( 0 );
			$is_valid = (bool) wp_verify_nonce( $nonce, $action );
			wp_set_current_user( $current_user );
			if ( $is_valid ) {
				return true;
			}
		}

		$parts = explode( self::TOKEN_SEPARATOR, $nonce );
		if ( 3 !== count( $parts ) ) {
			return false;
		}

		$token_form_id = (int) $parts[0];
		$timestamp     = (int) $parts[1];
		$signature     = (string) $parts[2];

		if ( $token_form_id !== $form_id || $timestamp <= 0 || '' === $signature ) {
			return false;
		}

		$ttl = (int) apply_filters( 'donatepress_form_token_ttl', DAY_IN_SECONDS * 7 );
		if ( time() - $timestamp > $ttl ) {
			return false;
		}

		$payload  = $token_form_id . self::TOKEN_SEPARATOR . $timestamp;
		$expected = hash_hmac( 'sha256', $payload, wp_salt( 'donatepress_form_token' ) );
		return hash_equals( $expected, $signature );
	}
}
