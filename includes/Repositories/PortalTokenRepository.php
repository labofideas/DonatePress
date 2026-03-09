<?php

namespace DonatePress\Repositories;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donor portal magic-link token storage.
 */
class PortalTokenRepository {
	private wpdb $db;

	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Insert a hashed portal token.
	 */
	public function insert( string $email, string $token_hash, string $ip_address, string $user_agent, string $expires_at ): int {
		$table = $this->db->prefix . 'dp_portal_tokens';
		$now   = current_time( 'mysql', true );

		$inserted = $this->db->insert(
			$table,
			array(
				'donor_email' => $email,
				'token_hash'  => $token_hash,
				'ip_address'  => $ip_address,
				'user_agent'  => $user_agent,
				'created_at'  => $now,
				'expires_at'  => $expires_at,
				'used_at'     => null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Resolve an active token row by hash.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_active_by_hash( string $token_hash ): ?array {
		$table = $this->db->prefix . 'dp_portal_tokens';
		$now   = current_time( 'mysql', true );

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$table}
				WHERE token_hash = %s
				AND used_at IS NULL
				AND expires_at >= %s
				LIMIT 1",
				$token_hash,
				$now
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Mark token as consumed.
	 */
	public function mark_used( int $id ): bool {
		$table  = $this->db->prefix . 'dp_portal_tokens';
		$result = $this->db->update(
			$table,
			array(
				'used_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete all portal tokens for a donor email.
	 */
	public function delete_by_email( string $email ): int {
		$table = $this->db->prefix . 'dp_portal_tokens';
		$rows  = $this->db->delete(
			$table,
			array( 'donor_email' => $email ),
			array( '%s' )
		);

		return false === $rows ? 0 : (int) $rows;
	}
}
