<?php

namespace DonatePress\Repositories;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donor data access.
 */
class DonorRepository {
	private wpdb $db;

	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Find donor by email.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_email( string $email ): ?array {
		$table = $this->db->prefix . 'dp_donors';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", $email ),
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : null;
		return $this->hydrate_metadata( $row );
	}

	/**
	 * Find donor by ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$table = $this->db->prefix . 'dp_donors';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : null;
		return $this->hydrate_metadata( $row );
	}

	/**
	 * Upsert donor profile by email and return id.
	 */
	public function find_or_create( string $email, string $first_name = '', string $last_name = '' ): int {
		$current = $this->find_by_email( $email );
		if ( $current ) {
			$this->db->update(
				$this->db->prefix . 'dp_donors',
				array(
					'first_name' => '' !== $first_name ? $first_name : (string) ( $current['first_name'] ?? '' ),
					'last_name'  => '' !== $last_name ? $last_name : (string) ( $current['last_name'] ?? '' ),
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $current['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $current['id'];
		}

		$table    = $this->db->prefix . 'dp_donors';
		$now      = current_time( 'mysql', true );
		$inserted = $this->db->insert(
			$table,
			array(
				'email'          => $email,
				'first_name'     => $first_name,
				'last_name'      => $last_name,
				'phone'          => '',
				'total_donated'  => 0,
				'donation_count' => 0,
				'status'         => 'active',
				'tags'           => wp_json_encode( array() ),
				'notes'          => '',
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Create donor manually.
	 */
	public function insert( array $data ): int {
		$table = $this->db->prefix . 'dp_donors';
		$now   = current_time( 'mysql', true );

		$inserted = $this->db->insert(
			$table,
			array(
				'email'          => $data['email'],
				'first_name'     => $data['first_name'] ?? '',
				'last_name'      => $data['last_name'] ?? '',
				'phone'          => $data['phone'] ?? '',
				'total_donated'  => (float) ( $data['total_donated'] ?? 0 ),
				'donation_count' => (int) ( $data['donation_count'] ?? 0 ),
				'status'         => $data['status'] ?? 'active',
				'tags'           => wp_json_encode( $this->normalize_tags( (array) ( $data['tags'] ?? array() ) ) ),
				'notes'          => (string) ( $data['notes'] ?? '' ),
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Update donor profile fields.
	 */
	public function update( int $id, array $data ): bool {
		$current = $this->find( $id );
		if ( ! $current ) {
			return false;
		}

		$payload = array(
			'first_name' => array_key_exists( 'first_name', $data ) ? (string) $data['first_name'] : (string) ( $current['first_name'] ?? '' ),
			'last_name'  => array_key_exists( 'last_name', $data ) ? (string) $data['last_name'] : (string) ( $current['last_name'] ?? '' ),
			'phone'      => array_key_exists( 'phone', $data ) ? (string) $data['phone'] : (string) ( $current['phone'] ?? '' ),
			'status'     => array_key_exists( 'status', $data ) ? (string) $data['status'] : (string) ( $current['status'] ?? 'active' ),
			'tags'       => wp_json_encode( $this->normalize_tags( (array) ( $data['tags'] ?? (array) ( $current['tags'] ?? array() ) ) ) ),
			'notes'      => array_key_exists( 'notes', $data ) ? (string) $data['notes'] : (string) ( $current['notes'] ?? '' ),
			'updated_at' => current_time( 'mysql', true ),
		);

		$result = $this->db->update(
			$this->db->prefix . 'dp_donors',
			$payload,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update donor profile by email.
	 */
	public function update_by_email( string $email, array $data ): bool {
		$current = $this->find_by_email( $email );
		if ( ! $current ) {
			return false;
		}

		return $this->update( (int) $current['id'], $data );
	}

	/**
	 * Update donor status.
	 */
	public function update_status( int $id, string $status ): bool {
		$result = $this->db->update(
			$this->db->prefix . 'dp_donors',
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
	 * Total donor count.
	 */
	public function count_all(): int {
		$table = $this->db->prefix . 'dp_donors';
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * List recent donors.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_recent( int $limit = 20 ): array {
		$table = $this->db->prefix . 'dp_donors';
		$limit = max( 1, absint( $limit ) );

		$rows = $this->db->get_results(
			"SELECT id, email, first_name, last_name, total_donated, donation_count, status, created_at
			FROM {$table}
			ORDER BY created_at DESC
			LIMIT {$limit}",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values( array_filter( array_map( array( $this, 'hydrate_metadata' ), $rows ) ) );
	}

	/**
	 * List donors with paging/filter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list( int $limit = 25, int $offset = 0, string $search = '', string $status = '' ): array {
		$table  = $this->db->prefix . 'dp_donors';
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		$where  = array( '1=1' );
		$args   = array();

		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $search ) {
			$like    = '%' . $this->db->esc_like( $search ) . '%';
			$where[] = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		$sql = "SELECT id, email, first_name, last_name, phone, total_donated, donation_count, status, tags, notes, blocked_at, anonymized_at, created_at, updated_at
			FROM {$table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY created_at DESC
			LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;

		$rows = $this->db->get_results( $this->db->prepare( $sql, ...$args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values( array_filter( array_map( array( $this, 'hydrate_metadata' ), $rows ) ) );
	}

	/**
	 * Count donors with optional filters.
	 */
	public function count_filtered( string $search = '', string $status = '' ): int {
		$table  = $this->db->prefix . 'dp_donors';
		$where  = array( '1=1' );
		$args   = array();

		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $search ) {
			$like    = '%' . $this->db->esc_like( $search ) . '%';
			$where[] = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
		return (int) $this->db->get_var( $this->db->prepare( $sql, ...$args ) );
	}

	/**
	 * Block donor.
	 */
	public function block( int $id ): bool {
		$result = $this->db->update(
			$this->db->prefix . 'dp_donors',
			array(
				'status'     => 'blocked',
				'blocked_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Anonymize donor PII.
	 */
	public function anonymize( int $id ): bool {
		$current = $this->find( $id );
		if ( ! $current ) {
			return false;
		}

		$email_alias = 'anon+' . $id . '@donatepress.local';
		$result      = $this->db->update(
			$this->db->prefix . 'dp_donors',
			array(
				'email'        => $email_alias,
				'first_name'   => 'Anonymous',
				'last_name'    => 'Donor',
				'phone'        => '',
				'status'       => 'anonymized',
				'notes'        => '',
				'tags'         => wp_json_encode( array( 'anonymized' ) ),
				'anonymized_at'=> current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$donations_table = $this->db->prefix . 'dp_donations';
		$this->db->update(
			$donations_table,
			array(
				'donor_email'      => $email_alias,
				'donor_first_name' => 'Anonymous',
				'donor_last_name'  => 'Donor',
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'donor_id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Merge one donor into target donor.
	 */
	public function merge( int $source_id, int $target_id ): bool {
		if ( $source_id <= 0 || $target_id <= 0 || $source_id === $target_id ) {
			return false;
		}

		$source = $this->find( $source_id );
		$target = $this->find( $target_id );
		if ( ! $source || ! $target ) {
			return false;
		}

		$donations_table = $this->db->prefix . 'dp_donations';
		$subs_table      = $this->db->prefix . 'dp_subscriptions';
		$target_email    = (string) ( $target['email'] ?? '' );

		$this->db->update(
			$donations_table,
			array(
				'donor_id'         => $target_id,
				'donor_email'      => $target_email,
				'donor_first_name' => (string) ( $target['first_name'] ?? '' ),
				'donor_last_name'  => (string) ( $target['last_name'] ?? '' ),
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'donor_id' => $source_id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		$this->db->update(
			$subs_table,
			array(
				'donor_id'    => $target_id,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'donor_id' => $source_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$this->db->update(
			$this->db->prefix . 'dp_donors',
			array(
				'status'     => 'merged',
				'notes'      => 'Merged into donor #' . $target_id,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $source_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->sync_totals_by_email( $target_email );
		return true;
	}

	/**
	 * Sync donor total/donation_count from completed donations.
	 */
	public function sync_totals_by_email( string $email ): bool {
		$donations_table = $this->db->prefix . 'dp_donations';
		$donor_table     = $this->db->prefix . 'dp_donors';
		$stats           = $this->db->get_row(
			$this->db->prepare(
				"SELECT COUNT(*) AS donation_count, COALESCE(SUM(amount), 0) AS total_donated
				FROM {$donations_table}
				WHERE donor_email = %s AND status = %s",
				$email,
				'completed'
			),
			ARRAY_A
		);

		if ( ! is_array( $stats ) ) {
			return false;
		}

		$result = $this->db->update(
			$donor_table,
			array(
				'donation_count' => (int) ( $stats['donation_count'] ?? 0 ),
				'total_donated'  => (float) ( $stats['total_donated'] ?? 0 ),
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'email' => $email ),
			array( '%d', '%f', '%s' ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Sum total donated amount.
	 */
	public function sum_total_donated(): float {
		$table = $this->db->prefix . 'dp_donors';
		return (float) $this->db->get_var( "SELECT COALESCE(SUM(total_donated), 0) FROM {$table}" );
	}

	/**
	 * Normalize donor tags.
	 *
	 * @param array<int|string,mixed> $tags
	 * @return array<int,string>
	 */
	private function normalize_tags( array $tags ): array {
		$clean = array();
		foreach ( $tags as $tag ) {
			$value = sanitize_text_field( (string) $tag );
			if ( '' !== $value ) {
				$clean[] = strtolower( $value );
			}
		}
		$clean = array_values( array_unique( $clean ) );
		sort( $clean );
		return $clean;
	}

	/**
	 * Decode tags JSON into array.
	 *
	 * @param array<string,mixed>|null $row
	 * @return array<string,mixed>|null
	 */
	private function hydrate_metadata( ?array $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$tags = array();
		if ( isset( $row['tags'] ) ) {
			$decoded = json_decode( (string) $row['tags'], true );
			if ( is_array( $decoded ) ) {
				$tags = $this->normalize_tags( $decoded );
			}
		}
		$row['tags']  = $tags;
		$row['notes'] = sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) );
		return $row;
	}
}
