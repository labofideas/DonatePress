<?php

namespace DonatePress\Gateways;

use DonatePress\Gateways\Offline\OfflineGateway;
use DonatePress\Gateways\PayPal\PayPalGateway;
use DonatePress\Gateways\Stripe\StripeGateway;
use DonatePress\Services\SettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gateway registry and resolver.
 */
class GatewayManager {
	/**
	 * Settings service.
	 */
	private SettingsService $settings;

	/**
	 * Constructor.
	 */
	public function __construct( SettingsService $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Resolve gateway by id.
	 */
	public function get( string $gateway_id ): ?GatewayInterface {
		$gateway_id = sanitize_key( $gateway_id );
		$registry   = $this->registry();
		return $registry[ $gateway_id ] ?? null;
	}

	/**
	 * Build gateway map.
	 *
	 * @return array<string,GatewayInterface>
	 */
	private function registry(): array {
		$gateways = array(
			'stripe' => new StripeGateway( $this->settings ),
			'paypal' => new PayPalGateway( $this->settings ),
			'offline' => new OfflineGateway(),
		);

		/**
		 * Filter registered gateway instances.
		 *
		 * @param array<string,GatewayInterface> $gateways Gateway map.
		 */
		return apply_filters( 'donatepress_gateways', $gateways );
	}
}
