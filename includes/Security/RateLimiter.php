<?php

namespace DonatePress\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate limiter with atomic increment to prevent race conditions.
 */
class RateLimiter {
	/**
	 * Check and consume one attempt for a key.
	 *
	 * Uses wp_cache when an external object cache is available for atomic
	 * increments. Falls back to a database-level atomic UPDATE for sites
	 * without an object cache.
	 */
	public function allow( string $key, int $limit, int $window_seconds ): bool {
		$cache_key = 'dp_rl_' . hash( 'sha256', $key );

		if ( wp_using_ext_object_cache() ) {
			return $this->allow_via_cache( $cache_key, $limit, $window_seconds );
		}

		return $this->allow_via_transient( $cache_key, $limit, $window_seconds );
	}

	/**
	 * Atomic increment via external object cache.
	 */
	private function allow_via_cache( string $key, int $limit, int $window_seconds ): bool {
		if ( $limit <= 0 ) {
			return false;
		}

		if ( wp_cache_add( $key, 1, 'donatepress', $window_seconds ) ) {
			return true;
		}

		$current = (int) wp_cache_get( $key, 'donatepress' );
		if ( $current >= $limit ) {
			return false;
		}

		wp_cache_incr( $key, 1, 'donatepress' );
		return true;
	}

	/**
	 * Transient-based fallback with single atomic step.
	 *
	 * Uses a direct UPDATE ... WHERE value < limit to avoid the
	 * read-then-write race in the original implementation.
	 */
	private function allow_via_transient( string $key, int $limit, int $window_seconds ): bool {
		if ( $limit <= 0 ) {
			return false;
		}

		$transient_key = '_transient_' . $key;

		global $wpdb;

		$current = get_transient( $key );

		if ( false === $current ) {
			set_transient( $key, 1, $window_seconds );
			return true;
		}

		if ( (int) $current >= $limit ) {
			return false;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND option_value < %d",
				$transient_key,
				$limit
			)
		);

		return $updated > 0;
	}
}
