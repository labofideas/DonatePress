<?php

namespace DonatePress\Repositories;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donation form data access.
 */
class FormRepository {
	private wpdb $db;

	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Find form by id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$table = $this->db->prefix . 'dp_forms';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find form by slug.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		$table = $this->db->prefix . 'dp_forms';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find the first active form.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_first_active(): ?array {
		$table = $this->db->prefix . 'dp_forms';
		$row   = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC, id ASC LIMIT 1",
				'active'
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Total active forms.
	 */
	public function count_active(): int {
		$table = $this->db->prefix . 'dp_forms';
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'active' ) );
	}

	/**
	 * Total form count.
	 */
	public function count_all(): int {
		$table = $this->db->prefix . 'dp_forms';
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * List recent forms.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_recent( int $limit = 20 ): array {
		$table = $this->db->prefix . 'dp_forms';
		$limit = max( 1, absint( $limit ) );

		$rows = $this->db->get_results(
			"SELECT id, title, slug, status, default_amount, currency, gateway, created_at
			FROM {$table}
			ORDER BY created_at DESC
			LIMIT {$limit}",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List forms with paging/filter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list( int $limit = 25, int $offset = 0, string $search = '', string $status = '' ): array {
		$table  = $this->db->prefix . 'dp_forms';
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
			$where[] = '(title LIKE %s OR slug LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}

		$sql = "SELECT id, title, slug, status, default_amount, currency, gateway, config, created_at, updated_at
			FROM {$table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY created_at DESC
			LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;

		$rows = $this->db->get_results( $this->db->prepare( $sql, ...$args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count forms with filters.
	 */
	public function count_filtered( string $search = '', string $status = '' ): int {
		$table = $this->db->prefix . 'dp_forms';
		$where = array( '1=1' );
		$args  = array();

		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $search ) {
			$like    = '%' . $this->db->esc_like( $search ) . '%';
			$where[] = '(title LIKE %s OR slug LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
		return (int) $this->db->get_var( $this->db->prepare( $sql, ...$args ) );
	}

	/**
	 * Insert new form.
	 */
	public function insert( array $data ): int {
		$table = $this->db->prefix . 'dp_forms';
		$now   = current_time( 'mysql', true );
		$slug  = $this->unique_slug( sanitize_title( (string) ( $data['slug'] ?? $data['title'] ?? 'form' ) ) );

		$inserted = $this->db->insert(
			$table,
			array(
				'title'          => (string) ( $data['title'] ?? '' ),
				'slug'           => $slug,
				'status'         => (string) ( $data['status'] ?? 'active' ),
				'default_amount' => (float) ( $data['default_amount'] ?? 25 ),
				'currency'       => strtoupper( (string) ( $data['currency'] ?? 'USD' ) ),
				'gateway'        => sanitize_key( (string) ( $data['gateway'] ?? 'stripe' ) ),
				'config'         => isset( $data['config'] ) ? wp_json_encode( $data['config'] ) : null,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
		);
		return false === $inserted ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Update existing form.
	 */
	public function update( int $id, array $data ): bool {
		$current = $this->find( $id );
		if ( ! $current ) {
			return false;
		}

		$payload = array(
			'title'          => array_key_exists( 'title', $data ) ? (string) $data['title'] : (string) ( $current['title'] ?? '' ),
			'status'         => array_key_exists( 'status', $data ) ? (string) $data['status'] : (string) ( $current['status'] ?? 'active' ),
			'default_amount' => array_key_exists( 'default_amount', $data ) ? (float) $data['default_amount'] : (float) ( $current['default_amount'] ?? 25 ),
			'currency'       => array_key_exists( 'currency', $data ) ? strtoupper( (string) $data['currency'] ) : (string) ( $current['currency'] ?? 'USD' ),
			'gateway'        => array_key_exists( 'gateway', $data ) ? sanitize_key( (string) $data['gateway'] ) : (string) ( $current['gateway'] ?? 'stripe' ),
			'config'         => array_key_exists( 'config', $data ) ? wp_json_encode( $data['config'] ) : (string) ( $current['config'] ?? '' ),
			'updated_at'     => current_time( 'mysql', true ),
		);

		if ( array_key_exists( 'slug', $data ) ) {
			$slug            = sanitize_title( (string) $data['slug'] );
			$payload['slug'] = $this->unique_slug( $slug, $id );
		}

		$formats = array(
			'%s', // title
			'%s', // status
			'%f', // default_amount
			'%s', // currency
			'%s', // gateway
			'%s', // config
			'%s', // updated_at
		);
		if ( isset( $payload['slug'] ) ) {
			$formats[] = '%s';
		}
		$result = $this->db->update( $this->db->prefix . 'dp_forms', $payload, array( 'id' => $id ), $formats, array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Delete form by id.
	 */
	public function delete( int $id ): bool {
		$deleted = $this->db->delete( $this->db->prefix . 'dp_forms', array( 'id' => $id ), array( '%d' ) );
		return false !== $deleted;
	}

	/**
	 * Resolve unique slug.
	 */
	private function unique_slug( string $base, int $ignore_id = 0 ): string {
		$base = '' !== $base ? $base : 'form';
		$slug = $base;
		$i    = 2;
		while ( true ) {
			$current = $this->find_by_slug( $slug );
			if ( ! $current || (int) ( $current['id'] ?? 0 ) === $ignore_id ) {
				return $slug;
			}
			$slug = $base . '-' . $i;
			++$i;
		}
	}
}
