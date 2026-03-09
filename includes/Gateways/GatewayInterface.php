<?php

namespace DonatePress\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gateway contract for payment and webhook lifecycle.
 */
interface GatewayInterface {
	/**
	 * Gateway slug.
	 */
	public function id(): string;

	/**
	 * Start payment flow.
	 *
	 * @param array<string,mixed> $donation Donation snapshot.
	 * @param array<string,mixed> $context  Submission context.
	 * @return array<string,mixed>
	 */
	public function start_payment( array $donation, array $context ): array;

	/**
	 * Verify incoming webhook signature.
	 *
	 * @param array<string,string> $headers HTTP headers map.
	 */
	public function verify_webhook( array $headers, string $payload ): bool;

	/**
	 * Handle verified webhook payload.
	 *
	 * @param array<string,string> $headers HTTP headers map.
	 * @return array<string,mixed>
	 */
	public function handle_webhook( array $headers, string $payload ): array;
}
