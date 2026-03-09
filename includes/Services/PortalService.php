<?php

namespace DonatePress\Services;

use DonatePress\Repositories\PortalTokenRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donor portal magic-link and session service.
 */
class PortalService {
	private PortalTokenRepository $token_repository;

	public function __construct( PortalTokenRepository $token_repository ) {
		$this->token_repository = $token_repository;
	}

	/**
	 * Generate and persist a new magic link token.
	 *
	 * @return array<string,mixed>
	 */
	public function create_magic_link( string $email, string $ip_address, string $user_agent ): array {
		$raw_token = wp_generate_password( 48, false, false );
		$hash      = hash( 'sha256', $raw_token );
		$ttl       = (int) apply_filters( 'donatepress_portal_magic_ttl', 15 * MINUTE_IN_SECONDS );
		if ( $ttl < 60 ) {
			$ttl = 15 * MINUTE_IN_SECONDS;
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		$id         = $this->token_repository->insert( $email, $hash, $ip_address, $user_agent, $expires_at );

		if ( $id <= 0 ) {
			return array(
				'success' => false,
				'code'    => 'token_store_failed',
				'message' => __( 'Could not generate portal link.', 'donatepress' ),
			);
		}

		return array(
			'success'    => true,
			'token'      => $raw_token,
			'expires_at' => $expires_at,
			'ttl'        => $ttl,
		);
	}

	/**
	 * Consume one token and issue session token.
	 *
	 * @return array<string,mixed>
	 */
	public function consume_magic_link( string $raw_token ): array {
		$hash = hash( 'sha256', $raw_token );
		$row  = $this->token_repository->find_active_by_hash( $hash );
		if ( ! $row ) {
			return array(
				'success' => false,
				'code'    => 'invalid_or_expired',
				'message' => __( 'Portal link is invalid or expired.', 'donatepress' ),
			);
		}

		$token_id = (int) ( $row['id'] ?? 0 );
		if ( $token_id <= 0 || ! $this->token_repository->mark_used( $token_id ) ) {
			return array(
				'success' => false,
				'code'    => 'token_consume_failed',
				'message' => __( 'Portal link could not be consumed.', 'donatepress' ),
			);
		}

		$session_ttl   = (int) apply_filters( 'donatepress_portal_session_ttl', DAY_IN_SECONDS );
		$session_token = 'dps_' . strtolower( wp_generate_password( 40, false, false ) );
		$key           = 'dp_portal_session_' . hash( 'sha256', $session_token );
		$email         = sanitize_email( (string) ( $row['donor_email'] ?? '' ) );

		set_transient(
			$key,
			array(
				'email'      => $email,
				'issued_at'  => current_time( 'mysql', true ),
				'expires_in' => $session_ttl,
			),
			$session_ttl
		);

		return array(
			'success'       => true,
			'session_token' => $session_token,
			'email'         => $email,
			'expires_in'    => $session_ttl,
		);
	}

	/**
	 * Resolve portal session payload.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_session( string $session_token ): ?array {
		$key = 'dp_portal_session_' . hash( 'sha256', $session_token );
		$row = get_transient( $key );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Revoke session token.
	 */
	public function revoke_session( string $session_token ): void {
		$key = 'dp_portal_session_' . hash( 'sha256', $session_token );
		delete_transient( $key );
	}
}
