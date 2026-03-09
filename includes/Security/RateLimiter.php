<?php

namespace DonatePress\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple transient-based rate limiter.
 */
class RateLimiter {
	/**
	 * Check and consume one attempt for a key.
	 */
	public function allow( string $key, int $limit, int $window_seconds ): bool {
		$transient_key = 'dp_rl_' . md5( $key );
		$current       = (int) get_transient( $transient_key );

		if ( $current >= $limit ) {
			return false;
		}

		set_transient( $transient_key, $current + 1, $window_seconds );
		return true;
	}
}
