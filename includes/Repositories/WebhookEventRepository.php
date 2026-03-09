<?php

namespace DonatePress\Repositories;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent idempotency log for inbound webhook events.
 */
class WebhookEventRepository {
	private wpdb $db;

	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Try to acquire processing lock for one gateway event.
	 */
	public function acquire( string $gateway, string $event_id, string $payload_hash ): bool {
		$table = $this->db->prefix . 'dp_webhook_events';
		$now   = current_time( 'mysql', true );

		$inserted = $this->db->insert(
			$table,
			array(
				'gateway'      => $gateway,
				'event_id'     => $event_id,
				'payload_hash' => $payload_hash,
				'status'       => 'processing',
				'received_at'  => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Mark event successfully processed.
	 */
	public function mark_processed( string $gateway, string $event_id, string $message = '' ): void {
		$table = $this->db->prefix . 'dp_webhook_events';
		$now   = current_time( 'mysql', true );

		$this->db->update(
			$table,
			array(
				'status'           => 'processed',
				'processed_at'     => $now,
				'response_message' => $message,
				'updated_at'       => $now,
			),
			array(
				'gateway'  => $gateway,
				'event_id' => $event_id,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Release lock when processing fails to allow retries.
	 */
	public function release( string $gateway, string $event_id ): void {
		$table = $this->db->prefix . 'dp_webhook_events';
		$this->db->delete(
			$table,
			array(
				'gateway'  => $gateway,
				'event_id' => $event_id,
			),
			array( '%s', '%s' )
		);
	}
}

