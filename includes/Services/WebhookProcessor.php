<?php

namespace DonatePress\Services;

use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\SubscriptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared webhook processing logic used by all payment gateways.
 *
 * Extracts the common donation-lookup, status-mapping, subscription-update,
 * and retry-scheduling code that was previously duplicated across gateway
 * implementations.
 */
class WebhookProcessor {
	private DonationRepository $donation_repo;
	private SubscriptionRepository $subscription_repo;

	public function __construct( DonationRepository $donation_repo, SubscriptionRepository $subscription_repo ) {
		$this->donation_repo      = $donation_repo;
		$this->subscription_repo = $subscription_repo;
	}

	/**
	 * Create an instance using the global $wpdb.
	 */
	public static function from_globals(): self {
		global $wpdb;
		return new self(
			new DonationRepository( $wpdb ),
			new SubscriptionRepository( $wpdb )
		);
	}

	/**
	 * Resolve donation record from webhook data.
	 *
	 * @param int      $donation_id       Donation ID from metadata (0 if unknown).
	 * @param string[] $transaction_candidates Gateway transaction IDs to search.
	 * @return array<string,mixed>|null
	 */
	public function resolve_donation( int $donation_id, array $transaction_candidates ): ?array {
		$donation = null;

		if ( $donation_id > 0 ) {
			$donation = $this->donation_repo->find( $donation_id );
		}

		if ( ! $donation ) {
			foreach ( $transaction_candidates as $candidate ) {
				$candidate = (string) $candidate;
				if ( '' === $candidate ) {
					continue;
				}
				$found = $this->donation_repo->find_by_gateway_transaction( $candidate );
				if ( $found ) {
					$donation = $found;
					break;
				}
			}
		}

		return $donation;
	}

	/**
	 * Update donation gateway transaction ID.
	 */
	public function update_donation_transaction( int $donation_id, string $transaction_id ): void {
		if ( '' !== $transaction_id ) {
			$this->donation_repo->update_gateway_transaction( $donation_id, $transaction_id );
		}
	}

	/**
	 * Apply a donation status change based on the event type and status map.
	 *
	 * @param array<string,mixed>  $donation   Donation record.
	 * @param string               $event_type Gateway event type.
	 * @param array<string,string> $status_map Event type → internal status.
	 * @param string               $gateway    Gateway identifier.
	 */
	public function sync_donation_status( array $donation, string $event_type, array $status_map, string $gateway ): void {
		if ( isset( $status_map[ $event_type ] ) ) {
			$this->donation_repo->update_status( (int) $donation['id'], $status_map[ $event_type ] );
			do_action( 'donatepress_donation_status_synced', (int) $donation['id'], $status_map[ $event_type ], $gateway, $event_type );
		}
	}

	/**
	 * Resolve subscription from donation or gateway reference.
	 *
	 * @param array<string,mixed>|null $donation         Resolved donation or null.
	 * @param string                   $gateway          Gateway slug.
	 * @param string                   $subscription_ref Gateway subscription ID.
	 * @return array<string,mixed>|null
	 */
	public function resolve_subscription( ?array $donation, string $gateway, string $subscription_ref ): ?array {
		$subscription = null;

		if ( $donation && ! empty( $donation['subscription_id'] ) ) {
			$subscription = $this->subscription_repo->find( (int) $donation['subscription_id'] );
		}

		if ( ! $subscription && '' !== $subscription_ref ) {
			$subscription = $this->subscription_repo->find_by_gateway_subscription( $gateway, $subscription_ref );
		}

		return $subscription;
	}

	/**
	 * Process subscription status and payment tracking from a webhook event.
	 *
	 * @param array<string,mixed> $subscription     Subscription record.
	 * @param string              $subscription_ref Gateway subscription ID.
	 * @param string              $status           Mapped internal status (empty = no change).
	 * @param string[]            $success_events   Event types indicating successful payment.
	 * @param string[]            $failure_events   Event types indicating failed payment.
	 * @param string              $event_type       The actual webhook event type.
	 */
	public function process_subscription(
		array $subscription,
		string $subscription_ref,
		string $status,
		array $success_events,
		array $failure_events,
		string $event_type
	): void {
		$subscription_id = (int) $subscription['id'];

		if ( '' !== $subscription_ref && '' === (string) ( $subscription['gateway_subscription_id'] ?? '' ) ) {
			$this->subscription_repo->update_gateway_subscription_id( $subscription_id, $subscription_ref );
		}

		if ( '' !== $status ) {
			$this->subscription_repo->update_status( $subscription_id, $status );

			if ( in_array( $event_type, $success_events, true ) ) {
				$frequency = sanitize_key( (string) ( $subscription['frequency'] ?? 'monthly' ) );
				$next_at   = self::next_cycle_datetime( $frequency );
				$this->subscription_repo->mark_payment_success( $subscription_id, $next_at );
			} elseif ( in_array( $event_type, $failure_events, true ) ) {
				$this->subscription_repo->increment_failure( $subscription_id );
				$current = $this->subscription_repo->find( $subscription_id );
				if ( $current ) {
					$failure_count = (int) ( $current['failure_count'] ?? 0 );
					$max_retries   = max( 1, (int) ( $current['max_retries'] ?? 3 ) );
					if ( $failure_count >= $max_retries ) {
						$this->subscription_repo->update_status( $subscription_id, 'expired' );
						$this->subscription_repo->update_next_payment_at( $subscription_id, null );
					} else {
						$this->subscription_repo->update_next_payment_at(
							$subscription_id,
							self::next_retry_datetime( $failure_count )
						);
					}
				}
			}
		}
	}

	/**
	 * Check for duplicate event via transient lock.
	 *
	 * @return bool True if this is a duplicate (already seen).
	 */
	public function is_duplicate_event( string $gateway, string $event_id ): bool {
		if ( '' === $event_id ) {
			return false;
		}

		$lock_key = 'dp_wh_' . $gateway . '_' . hash( 'sha256', $event_id );
		if ( get_transient( $lock_key ) ) {
			return true;
		}

		set_transient( $lock_key, 1, DAY_IN_SECONDS );
		return false;
	}

	/**
	 * Resolve next scheduled renewal based on frequency.
	 */
	public static function next_cycle_datetime( string $frequency ): string {
		$now = time();
		if ( 'annual' === $frequency ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $now ) );
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( '+1 month', $now ) );
	}

	/**
	 * Exponential retry backoff in hours (6, 12, 24... capped at 7 days).
	 */
	public static function next_retry_datetime( int $failure_count ): string {
		$now   = time();
		$hours = min( 24 * 7, max( 1, (int) pow( 2, $failure_count ) * 3 ) );
		return gmdate( 'Y-m-d H:i:s', strtotime( '+' . $hours . ' hours', $now ) );
	}
}
