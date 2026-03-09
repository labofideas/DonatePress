<?php

namespace DonatePress\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists structured audit log records.
 */
class AuditLogRepository {
	/**
	 * WordPress database connection.
	 */
	private \wpdb $db;

	/**
	 * Constructor.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Insert one audit record.
	 *
	 * @param array<string,mixed> $record
	 */
	public function insert( array $record ): int {
		$table   = $this->db->prefix . 'dp_audit_logs';
		$context = isset( $record['context'] ) ? wp_json_encode( $record['context'] ) : null;

		$ok = $this->db->insert(
			$table,
			array(
				'event'      => sanitize_key( (string) ( $record['event'] ?? '' ) ),
				'actor_id'   => (int) ( $record['actor'] ?? 0 ),
				'ip_address' => sanitize_text_field( (string) ( $record['ip'] ?? '' ) ),
				'context'    => is_string( $context ) ? $context : null,
				'created_at' => sanitize_text_field( (string) ( $record['ts'] ?? gmdate( 'Y-m-d H:i:s' ) ) ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		return $ok ? (int) $this->db->insert_id : 0;
	}
}
