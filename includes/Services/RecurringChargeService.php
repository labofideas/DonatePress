<?php

namespace DonatePress\Services;

use DonatePress\Repositories\SubscriptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes recurring retry charge attempts and state transitions.
 */
class RecurringChargeService {
	private SubscriptionRepository $repository;
	private PaymentService $payment_service;
	private AuditLogService $audit_log;

	public function __construct( SubscriptionRepository $repository, PaymentService $payment_service ) {
		$this->repository      = $repository;
		$this->payment_service = $payment_service;
		$this->audit_log       = new AuditLogService();
	}

	/**
	 * Attempt one retry charge for a subscription.
	 */
	public function attempt_retry( int $subscription_id, string $gateway, array $subscription ): array {
		$result = $this->payment_service->retry_subscription_charge( $gateway, $subscription );

		if ( ! empty( $result['success'] ) ) {
			$next_at = $this->next_cycle_datetime( sanitize_key( (string) ( $subscription['frequency'] ?? 'monthly' ) ) );
			$this->repository->update_status( $subscription_id, 'active' );
			$this->repository->mark_payment_success( $subscription_id, $next_at );

			do_action( 'donatepress_subscription_retry_succeeded', $subscription_id, $gateway, $result );
			$this->audit_log->log(
				'subscription_retry_succeeded',
				array(
					'subscription_id' => $subscription_id,
					'gateway'         => $gateway,
					'result'          => $result,
				)
			);

			return array(
				'success' => true,
				'status'  => 'active',
				'next_payment_at' => $next_at,
			);
		}

		$this->repository->update_status( $subscription_id, 'failed' );
		$this->repository->increment_failure( $subscription_id );

		$current = $this->repository->find( $subscription_id );
		if ( ! $current ) {
			return array(
				'success' => false,
				'code'    => 'subscription_missing',
				'message' => __( 'Subscription disappeared during retry processing.', 'donatepress' ),
			);
		}

		$failure_count = (int) ( $current['failure_count'] ?? 0 );
		$max_retries   = max( 1, (int) ( $current['max_retries'] ?? 3 ) );
		if ( $failure_count >= $max_retries ) {
			$this->repository->update_status( $subscription_id, 'expired' );
			$this->repository->update_next_payment_at( $subscription_id, null );
			do_action( 'donatepress_subscription_retries_exhausted', $subscription_id, $failure_count, $max_retries );
			$this->audit_log->log(
				'subscription_retry_expired',
				array(
					'subscription_id' => $subscription_id,
					'gateway'         => $gateway,
					'failure_count'   => $failure_count,
					'max_retries'     => $max_retries,
					'result'          => $result,
				)
			);
			return array(
				'success' => false,
				'status'  => 'expired',
				'code'    => (string) ( $result['code'] ?? 'retry_failed' ),
				'message' => (string) ( $result['message'] ?? __( 'Retry failed; subscription expired.', 'donatepress' ) ),
			);
		}

		$retry_at = $this->next_retry_datetime( $failure_count );
		$this->repository->update_next_payment_at( $subscription_id, $retry_at );

		do_action( 'donatepress_subscription_retry_failed', $subscription_id, $gateway, $result, $failure_count, $retry_at );
		$this->audit_log->log(
			'subscription_retry_failed',
			array(
				'subscription_id' => $subscription_id,
				'gateway'         => $gateway,
				'failure_count'   => $failure_count,
				'retry_at'        => $retry_at,
				'result'          => $result,
			)
		);

		return array(
			'success' => false,
			'status'  => 'failed',
			'next_payment_at' => $retry_at,
			'code'    => (string) ( $result['code'] ?? 'retry_failed' ),
			'message' => (string) ( $result['message'] ?? __( 'Retry charge failed.', 'donatepress' ) ),
		);
	}

	private function next_cycle_datetime( string $frequency ): string {
		$base = current_time( 'timestamp', true );
		if ( 'annual' === $frequency ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $base ) );
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( '+1 month', $base ) );
	}

	private function next_retry_datetime( int $failure_count ): string {
		$base  = current_time( 'timestamp', true );
		$hours = min( 24 * 7, max( 1, (int) pow( 2, $failure_count ) * 3 ) );
		return gmdate( 'Y-m-d H:i:s', strtotime( '+' . $hours . ' hours', $base ) );
	}
}
