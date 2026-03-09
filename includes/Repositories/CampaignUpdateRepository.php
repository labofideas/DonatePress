<?php

namespace DonatePress\Repositories;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign update data access.
 */
class CampaignUpdateRepository {
	private wpdb $db;

	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Find one update by id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$table = $this->db->prefix . 'dp_campaign_updates';
		$row   = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert update.
	 */
	public function insert( array $data ): int {
		$table = $this->db->prefix . 'dp_campaign_updates';
		$now   = current_time( 'mysql', true );

		$inserted = $this->db->insert(
			$table,
			array(
				'campaign_id'  => (int) $data['campaign_id'],
				'title'        => (string) ( $data['title'] ?? '' ),
				'content'      => (string) ( $data['content'] ?? '' ),
				'status'       => (string) ( $data['status'] ?? 'published' ),
				'published_at' => ! empty( $data['published_at'] ) ? (string) $data['published_at'] : $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $this->db->insert_id;
	}

	/**
	 * Update update.
	 */
	public function update( int $id, array $data ): bool {
		$current = $this->find( $id );
		if ( ! $current ) {
			return false;
		}

		$payload = array(
			'title'        => array_key_exists( 'title', $data ) ? (string) $data['title'] : (string) ( $current['title'] ?? '' ),
			'content'      => array_key_exists( 'content', $data ) ? (string) $data['content'] : (string) ( $current['content'] ?? '' ),
			'status'       => array_key_exists( 'status', $data ) ? (string) $data['status'] : (string) ( $current['status'] ?? 'published' ),
			'published_at' => array_key_exists( 'published_at', $data ) ? (string) $data['published_at'] : (string) ( $current['published_at'] ?? current_time( 'mysql', true ) ),
			'updated_at'   => current_time( 'mysql', true ),
		);

		$result = $this->db->update(
			$this->db->prefix . 'dp_campaign_updates',
			$payload,
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete update.
	 */
	public function delete( int $id ): bool {
		$deleted = $this->db->delete( $this->db->prefix . 'dp_campaign_updates', array( 'id' => $id ), array( '%d' ) );
		return false !== $deleted;
	}

	/**
	 * List updates for one campaign.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function list_by_campaign( int $campaign_id, int $limit = 20, int $offset = 0, string $status = '' ): array {
		$table  = $this->db->prefix . 'dp_campaign_updates';
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		if ( '' !== $status ) {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, campaign_id, title, content, status, published_at, created_at, updated_at
					FROM {$table}
					WHERE campaign_id = %d
					AND status = %s
					ORDER BY published_at DESC, id DESC
					LIMIT %d OFFSET %d",
					$campaign_id,
					$status,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, campaign_id, title, content, status, published_at, created_at, updated_at
					FROM {$table}
					WHERE campaign_id = %d
					ORDER BY published_at DESC, id DESC
					LIMIT %d OFFSET %d",
					$campaign_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count updates for campaign.
	 */
	public function count_by_campaign( int $campaign_id, string $status = '' ): int {
		$table = $this->db->prefix . 'dp_campaign_updates';
		if ( '' !== $status ) {
			return (int) $this->db->get_var(
				$this->db->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND status = %s",
					$campaign_id,
					$status
				)
			);
		}
		return (int) $this->db->get_var(
			$this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d", $campaign_id )
		);
	}
}

