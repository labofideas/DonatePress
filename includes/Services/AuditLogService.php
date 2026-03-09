<?php

namespace DonatePress\Services;

use DonatePress\Repositories\AuditLogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight structured audit logger for sensitive financial/admin actions.
 */
class AuditLogService {
	/**
	 * Write audit entry.
	 *
	 * @param array<string,mixed> $context
	 */
	public function log( string $event, array $context = array() ): void {
		$record = array(
			'ts'      => current_time( 'mysql', true ),
			'event'   => sanitize_key( $event ),
			'actor'   => get_current_user_id(),
			'ip'      => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'context' => $context,
		);

		$line = wp_json_encode( $record );
		if ( ! is_string( $line ) || '' === $line ) {
			return;
		}

		do_action( 'donatepress_audit_log_entry', $record );
		$this->persist( $record );
		error_log( '[donatepress_audit] ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Persist audit entry when DB is available.
	 *
	 * @param array<string,mixed> $record
	 */
	private function persist( array $record ): void {
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return;
		}

		$repository = new AuditLogRepository( $wpdb );
		$repository->insert( $record );
	}
}
