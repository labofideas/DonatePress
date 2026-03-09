<?php

namespace DonatePress\Repositories;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists donation records.
 */
class DonationRepository {
	/**
	 * WPDB instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 */
	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Insert pending donation and return id.
	 */
	public function insert_pending( array $data ): int {
		$table = $this->db->prefix . 'dp_donations';

		$inserted = $this->db->insert(
			$table,
			array(
				'donation_number' => $data['donation_number'],
				'subscription_id' => $data['subscription_id'] ?? null,
				'donor_id'        => $data['donor_id'] ?? null,
				'campaign_id'     => $data['campaign_id'] ?? null,
				'donor_email'     => $data['donor_email'],
				'donor_first_name'=> $data['donor_first_name'],
				'donor_last_name' => $data['donor_last_name'],
				'amount'          => $data['amount'],
				'currency'        => $data['currency'],
				'gateway'         => $data['gateway'],
				// Older site schemas may still require a non-null transaction column for pending rows.
				'gateway_transaction_id' => ! empty( $data['gateway_transaction_id'] ) ? (string) $data['gateway_transaction_id'] : '',
				'is_recurring'    => ! empty( $data['is_recurring'] ) ? 1 : 0,
				'recurring_frequency' => $data['recurring_frequency'] ?? '',
				'status'          => 'pending',
				'form_id'         => $data['form_id'],
				'donor_comment'   => $data['donor_comment'],
				'created_at'      => current_time( 'mysql', true ),
				'updated_at'      => current_time( 'mysql', true ),
				'donated_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Fetch one donation by id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$table = $this->db->prefix . 'dp_donations';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update gateway transaction reference for a donation.
	 */
	public function update_gateway_transaction( int $id, string $transaction_id ): bool {
		$table  = $this->db->prefix . 'dp_donations';
		$value  = '' !== trim( $transaction_id ) ? $transaction_id : '';
		$result = $this->db->update(
			$table,
			array(
				'gateway_transaction_id' => $value,
				'updated_at'             => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update donation status by id.
	 */
	public function update_status( int $id, string $status ): bool {
		$table  = $this->db->prefix . 'dp_donations';
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
	 * Attach donation to a subscription.
	 */
	public function update_subscription( int $id, int $subscription_id ): bool {
		$table  = $this->db->prefix . 'dp_donations';
		$result = $this->db->update(
			$table,
			array(
				'subscription_id' => $subscription_id,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Find donation by gateway transaction id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_gateway_transaction( string $transaction_id ): ?array {
		$table = $this->db->prefix . 'dp_donations';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE gateway_transaction_id = %s LIMIT 1", $transaction_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Total donation count.
	 */
	public function count_all(): int {
		$table = $this->db->prefix . 'dp_donations';
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * List recent donations.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_recent( int $limit = 20 ): array {
		$table = $this->db->prefix . 'dp_donations';
		$limit = max( 1, absint( $limit ) );

		$rows = $this->db->get_results(
			"SELECT id, donation_number, donor_email, donor_first_name, donor_last_name, amount, currency, gateway, status, donated_at
			FROM {$table}
			ORDER BY donated_at DESC
			LIMIT {$limit}",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Donation count by status.
	 */
	public function count_by_status( string $status ): int {
		$table = $this->db->prefix . 'dp_donations';
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
		);
	}

	/**
	 * Sum amount by status.
	 */
	public function sum_by_status( string $status ): float {
		$table = $this->db->prefix . 'dp_donations';
		return (float) $this->db->get_var(
			$this->db->prepare( "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = %s", $status )
		);
	}

	/**
	 * Total donation count for one donor email.
	 */
	public function count_by_email( string $email ): int {
		$table = $this->db->prefix . 'dp_donations';
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE donor_email = %s", $email )
		);
	}

	/**
	 * Sum completed donations for one donor email.
	 */
	public function sum_completed_by_email( string $email ): float {
		$table = $this->db->prefix . 'dp_donations';
		return (float) $this->db->get_var(
			$this->db->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE donor_email = %s AND status = %s",
				$email,
				'completed'
			)
		);
	}

	/**
	 * List donor donations newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_by_email( string $email, int $limit = 20 ): array {
		$table = $this->db->prefix . 'dp_donations';
		$limit = max( 1, min( 200, absint( $limit ) ) );

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, donation_number, amount, currency, gateway, status, donated_at
				FROM {$table}
				WHERE donor_email = %s
				ORDER BY donated_at DESC
				LIMIT %d",
				$email,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Yearly completed donation summary for a donor.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function annual_summary_by_email( string $email ): array {
		$table = $this->db->prefix . 'dp_donations';

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT DATE_FORMAT(donated_at, '%%Y') AS year, currency, COUNT(*) AS donation_count, COALESCE(SUM(amount), 0) AS total_amount
				FROM {$table}
				WHERE donor_email = %s
				AND status = %s
				GROUP BY DATE_FORMAT(donated_at, '%%Y'), currency
				ORDER BY year DESC",
				$email,
				'completed'
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List donation IDs by gateway transaction prefix.
	 *
	 * @return array<int,int>
	 */
	public function list_ids_by_gateway_prefix( string $prefix, int $limit = 500 ): array {
		$table = $this->db->prefix . 'dp_donations';
		$limit = max( 1, min( 5000, absint( $limit ) ) );
		$like  = $this->db->esc_like( $prefix ) . '%';

		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT id FROM {$table}
				WHERE gateway_transaction_id LIKE %s
				ORDER BY id DESC
				LIMIT %d",
				$like,
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( 'intval', $rows );
	}

	/**
	 * Check whether donation belongs to email.
	 */
	public function belongs_to_email( int $donation_id, string $email ): bool {
		$table = $this->db->prefix . 'dp_donations';
		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE id = %d AND donor_email = %s",
				$donation_id,
				$email
			)
		);

		return $count > 0;
	}

	/**
	 * Update donor display name for all donations by email.
	 */
	public function update_donor_name_by_email( string $email, string $first_name, string $last_name ): bool {
		$table  = $this->db->prefix . 'dp_donations';
		$result = $this->db->update(
			$table,
			array(
				'donor_first_name' => $first_name,
				'donor_last_name'  => $last_name,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'donor_email' => $email ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}
}
