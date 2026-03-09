<?php

namespace DonatePress\Services;

use DonatePress\Repositories\SubscriptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles recurring retry queue and terminal expiry transitions.
 */
class RecurringRetryService {
	private SubscriptionRepository $repository;

	public function __construct( SubscriptionRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Process due subscriptions for retry/expiry handling.
	 *
	 * @return array<string,int>
	 */
	public function process_due_retries( int $limit = 50 ): array {
		$items    = $this->repository->list_retry_due( $limit );
		$expired  = 0;
		$dispatched = 0;

		foreach ( $items as $item ) {
			$subscription_id = (int) ( $item['id'] ?? 0 );
			if ( $subscription_id <= 0 ) {
				continue;
			}

			$failure_count = (int) ( $item['failure_count'] ?? 0 );
			$max_retries   = max( 1, (int) ( $item['max_retries'] ?? 3 ) );

			if ( $failure_count >= $max_retries ) {
				$this->repository->update_status( $subscription_id, 'expired' );
				$this->repository->update_next_payment_at( $subscription_id, null );
				++$expired;
				do_action( 'donatepress_subscription_retries_exhausted', $subscription_id, $failure_count, $max_retries );
				continue;
			}

			$this->repository->update_status( $subscription_id, 'pending' );
			++$dispatched;

			do_action(
				'donatepress_subscription_retry_due',
				$subscription_id,
				sanitize_key( (string) ( $item['gateway'] ?? '' ) ),
				$item
			);
		}

		return array(
			'processed'   => count( $items ),
			'dispatched'  => $dispatched,
			'expired'     => $expired,
		);
	}
}
