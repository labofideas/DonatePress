<?php

namespace DonatePress\Gateways\Offline;

use DonatePress\Gateways\GatewayInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual/offline donation gateway.
 */
class OfflineGateway implements GatewayInterface {
	public function id(): string {
		return 'offline';
	}

	public function start_payment( array $donation, array $context ): array {
		$transaction_id = 'off_' . strtolower( wp_generate_password( 12, false, false ) );

		return array(
			'success'        => true,
			'provider'       => 'offline',
			'flow'           => 'manual',
			'transaction_id' => $transaction_id,
			'message'        => __( 'Offline donation captured. Awaiting manual settlement.', 'donatepress' ),
		);
	}

	public function verify_webhook( array $headers, string $payload ): bool {
		return false;
	}

	public function handle_webhook( array $headers, string $payload ): array {
		return array(
			'success' => false,
			'code'    => 'webhook_not_supported',
			'message' => __( 'Offline gateway does not support webhooks.', 'donatepress' ),
		);
	}

	/**
	 * Offline gateway does not auto-charge retries.
	 *
	 * @param array<string,mixed> $subscription Subscription payload.
	 * @return array<string,mixed>
	 */
	public function retry_subscription_charge( array $subscription ): array {
		return array(
			'success' => false,
			'code'    => 'manual_retry_required',
			'message' => __( 'Offline subscriptions require manual follow-up.', 'donatepress' ),
		);
	}
}
