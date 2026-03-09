<?php

namespace DonatePress\Repositories;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign data access.
 */
class CampaignRepository {
	private wpdb $db;

	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Find campaign by slug.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		$table = $this->db->prefix . 'dp_campaigns';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find campaign by id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$table = $this->db->prefix . 'dp_campaigns';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Total active campaigns.
	 */
	public function count_active(): int {
		$table = $this->db->prefix . 'dp_campaigns';
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'active' ) );
	}

	/**
	 * Total campaign count.
	 */
	public function count_all(): int {
		$table = $this->db->prefix . 'dp_campaigns';
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * List recent campaigns.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_recent( int $limit = 20 ): array {
		$table = $this->db->prefix . 'dp_campaigns';
		$limit = max( 1, absint( $limit ) );

		$rows = $this->db->get_results(
			"SELECT id, title, slug, status, goal_amount, raised_amount, starts_at, ends_at, created_at
			FROM {$table}
			ORDER BY created_at DESC
			LIMIT {$limit}",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List campaigns with paging/filter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list( int $limit = 25, int $offset = 0, string $search = '', string $status = '' ): array {
		$table  = $this->db->prefix . 'dp_campaigns';
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

		$sql = "SELECT id, title, slug, description, status, goal_amount, raised_amount, starts_at, ends_at, created_at, updated_at
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
	 * Count campaigns with filters.
	 */
	public function count_filtered( string $search = '', string $status = '' ): int {
		$table = $this->db->prefix . 'dp_campaigns';
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
	 * Total raised across campaigns.
	 */
	public function sum_raised_amount(): float {
		$table = $this->db->prefix . 'dp_campaigns';
		return (float) $this->db->get_var( "SELECT COALESCE(SUM(raised_amount), 0) FROM {$table}" );
	}

	/**
	 * Insert campaign.
	 */
	public function insert( array $data ): int {
		$table = $this->db->prefix . 'dp_campaigns';
		$now   = current_time( 'mysql', true );
		$slug  = $this->unique_slug( sanitize_title( (string) ( $data['slug'] ?? $data['title'] ?? 'campaign' ) ) );

		$inserted = $this->db->insert(
			$table,
			array(
				'title'        => (string) ( $data['title'] ?? '' ),
				'slug'         => $slug,
				'description'  => (string) ( $data['description'] ?? '' ),
				'goal_amount'  => isset( $data['goal_amount'] ) ? (float) $data['goal_amount'] : null,
				'raised_amount'=> isset( $data['raised_amount'] ) ? (float) $data['raised_amount'] : 0,
				'status'       => (string) ( $data['status'] ?? 'draft' ),
				'starts_at'    => $data['starts_at'] ?? null,
				'ends_at'      => $data['ends_at'] ?? null,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Update campaign.
	 */
	public function update( int $id, array $data ): bool {
		$current = $this->find( $id );
		if ( ! $current ) {
			return false;
		}

		$payload = array(
			'title'         => array_key_exists( 'title', $data ) ? (string) $data['title'] : (string) ( $current['title'] ?? '' ),
			'description'   => array_key_exists( 'description', $data ) ? (string) $data['description'] : (string) ( $current['description'] ?? '' ),
			'goal_amount'   => array_key_exists( 'goal_amount', $data ) ? (float) $data['goal_amount'] : (float) ( $current['goal_amount'] ?? 0 ),
			'raised_amount' => array_key_exists( 'raised_amount', $data ) ? (float) $data['raised_amount'] : (float) ( $current['raised_amount'] ?? 0 ),
			'status'        => array_key_exists( 'status', $data ) ? (string) $data['status'] : (string) ( $current['status'] ?? 'draft' ),
			'starts_at'     => array_key_exists( 'starts_at', $data ) ? $data['starts_at'] : ( $current['starts_at'] ?? null ),
			'ends_at'       => array_key_exists( 'ends_at', $data ) ? $data['ends_at'] : ( $current['ends_at'] ?? null ),
			'updated_at'    => current_time( 'mysql', true ),
		);

		$formats = array( '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s' );
		if ( array_key_exists( 'slug', $data ) ) {
			$payload['slug'] = $this->unique_slug( sanitize_title( (string) $data['slug'] ), $id );
			$formats[]       = '%s';
		}

		$result = $this->db->update( $this->db->prefix . 'dp_campaigns', $payload, array( 'id' => $id ), $formats, array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Delete campaign by id.
	 */
	public function delete( int $id ): bool {
		$deleted = $this->db->delete( $this->db->prefix . 'dp_campaigns', array( 'id' => $id ), array( '%d' ) );
		return false !== $deleted;
	}

	/**
	 * Resolve unique slug.
	 */
	private function unique_slug( string $base, int $ignore_id = 0 ): string {
		$base = '' !== $base ? $base : 'campaign';
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
