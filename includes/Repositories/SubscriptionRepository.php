<?php

namespace DonatePress\Repositories;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recurring subscription data access.
 */
class SubscriptionRepository {
	private wpdb $db;

	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Insert subscription and return ID.
	 */
	public function insert( array $data ): int {
		$table = $this->db->prefix . 'dp_subscriptions';
		$now   = current_time( 'mysql', true );

		$inserted = $this->db->insert(
			$table,
			array(
				'subscription_number'     => $data['subscription_number'],
				'initial_donation_id'     => (int) $data['initial_donation_id'],
				'donor_id'                => isset( $data['donor_id'] ) ? (int) $data['donor_id'] : null,
				'form_id'                 => (int) $data['form_id'],
				'campaign_id'             => isset( $data['campaign_id'] ) ? (int) $data['campaign_id'] : null,
				'gateway'                 => $data['gateway'],
				'gateway_subscription_id' => ! empty( $data['gateway_subscription_id'] ) ? (string) $data['gateway_subscription_id'] : null,
				'amount'                  => (float) $data['amount'],
				'currency'                => strtoupper( (string) $data['currency'] ),
				'frequency'               => $data['frequency'],
				'status'                  => $data['status'] ?? 'pending',
				'failure_count'           => (int) ( $data['failure_count'] ?? 0 ),
				'max_retries'             => (int) ( $data['max_retries'] ?? 3 ),
				'next_payment_at'         => $data['next_payment_at'] ?? null,
				'last_payment_at'         => $data['last_payment_at'] ?? null,
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Find subscription by ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$table = $this->db->prefix . 'dp_subscriptions';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find subscription by gateway + remote subscription id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_gateway_subscription( string $gateway, string $gateway_subscription_id ): ?array {
		$table = $this->db->prefix . 'dp_subscriptions';
		$row   = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE gateway = %s AND gateway_subscription_id = %s LIMIT 1",
				$gateway,
				$gateway_subscription_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update gateway subscription ID for existing record.
	 */
	public function update_gateway_subscription_id( int $id, string $gateway_subscription_id ): bool {
		$table  = $this->db->prefix . 'dp_subscriptions';
		$value  = '' !== trim( $gateway_subscription_id ) ? $gateway_subscription_id : null;
		$result = $this->db->update(
			$table,
			array(
				'gateway_subscription_id' => $value,
				'updated_at'              => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update subscription status.
	 */
	public function update_status( int $id, string $status ): bool {
		$table  = $this->db->prefix . 'dp_subscriptions';
		$result = $this->db->update(
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update next retry/payment datetime.
	 */
	public function update_next_payment_at( int $id, ?string $next_payment_at ): bool {
		$table  = $this->db->prefix . 'dp_subscriptions';
		$result = $this->db->update(
			$table,
			array(
				'next_payment_at' => $next_payment_at,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Reset failures and mark successful renewal.
	 */
	public function mark_payment_success( int $id, ?string $next_payment_at = null ): bool {
		$table  = $this->db->prefix . 'dp_subscriptions';
		$result = $this->db->update(
			$table,
			array(
				'failure_count'   => 0,
				'last_payment_at' => current_time( 'mysql', true ),
				'next_payment_at' => $next_payment_at,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Increment failure count for a subscription.
	 */
	public function increment_failure( int $id ): bool {
		$table = $this->db->prefix . 'dp_subscriptions';
		$rows  = $this->db->query(
			$this->db->prepare(
				"UPDATE {$table}
				SET failure_count = failure_count + 1,
					updated_at = %s
				WHERE id = %d",
				current_time( 'mysql', true ),
				$id
			)
		);

		return false !== $rows;
	}

	/**
	 * Total subscription count.
	 */
	public function count_all(): int {
		$table = $this->db->prefix . 'dp_subscriptions';
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Count subscriptions by status.
	 */
	public function count_by_status( string $status ): int {
		$table = $this->db->prefix . 'dp_subscriptions';
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
		);
	}

	/**
	 * List recent subscriptions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_recent( int $limit = 20, string $status = '' ): array {
		$table = $this->db->prefix . 'dp_subscriptions';
		$limit = max( 1, absint( $limit ) );

		if ( '' !== $status ) {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, subscription_number, initial_donation_id, gateway, amount, currency, frequency, status, failure_count, created_at, updated_at
					FROM {$table}
					WHERE status = %s
					ORDER BY created_at DESC
					LIMIT %d",
					$status,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $this->db->get_results(
				"SELECT id, subscription_number, initial_donation_id, gateway, amount, currency, frequency, status, failure_count, created_at, updated_at
				FROM {$table}
				ORDER BY created_at DESC
				LIMIT {$limit}",
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List subscriptions by donor email (via initial donation relation).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_by_email( string $email, int $limit = 20 ): array {
		$subs_table      = $this->db->prefix . 'dp_subscriptions';
		$donations_table = $this->db->prefix . 'dp_donations';
		$limit           = max( 1, min( 200, absint( $limit ) ) );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT s.id, s.subscription_number, s.gateway, s.amount, s.currency, s.frequency, s.status, s.failure_count, s.created_at, s.updated_at
				FROM {$subs_table} s
				INNER JOIN {$donations_table} d ON d.id = s.initial_donation_id
				WHERE d.donor_email = %s
				ORDER BY s.created_at DESC
				LIMIT %d",
				$email,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List subscriptions due for retry handling.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_retry_due( int $limit = 50 ): array {
		$table = $this->db->prefix . 'dp_subscriptions';
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$now   = current_time( 'mysql', true );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, subscription_number, gateway, gateway_subscription_id, amount, currency, frequency, status, failure_count, max_retries, next_payment_at
				FROM {$table}
				WHERE status IN (%s, %s)
				AND next_payment_at IS NOT NULL
				AND next_payment_at <= %s
				ORDER BY next_payment_at ASC
				LIMIT %d",
				'failed',
				'pending',
				$now,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Verify subscription belongs to donor email.
	 */
	public function belongs_to_email( int $subscription_id, string $email ): bool {
		$subs_table      = $this->db->prefix . 'dp_subscriptions';
		$donations_table = $this->db->prefix . 'dp_donations';

		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*)
				FROM {$subs_table} s
				INNER JOIN {$donations_table} d ON d.id = s.initial_donation_id
				WHERE s.id = %d
				AND d.donor_email = %s",
				$subscription_id,
				$email
			)
		);

		return $count > 0;
	}
}
