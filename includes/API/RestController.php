<?php

namespace DonatePress\API;

use DonatePress\API\Controllers\DonationController;
use DonatePress\API\Controllers\DonorController;
use DonatePress\API\Controllers\FormController;
use DonatePress\API\Controllers\CampaignController;
use DonatePress\API\Controllers\CampaignUpdateController;
use DonatePress\API\Controllers\PortalController;
use DonatePress\API\Controllers\ReportsController;
use DonatePress\API\Controllers\SettingsController;
use DonatePress\API\Controllers\SubscriptionController;
use DonatePress\API\Controllers\WebhookController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers DonatePress REST API routes.
 */
class RestController {
	/**
	 * Register API bootstrap hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all versioned routes.
	 */
	public function register_routes(): void {
		$namespace           = 'donatepress/v1';
		$settings_controller = new SettingsController( $namespace );
		$donation_controller = new DonationController( $namespace );
		$portal_controller   = new PortalController( $namespace );
		$reports_controller  = new ReportsController( $namespace );
		$webhook_controller  = new WebhookController( $namespace );
		$subscription_controller = new SubscriptionController( $namespace );
		$donor_controller    = new DonorController( $namespace );
		$form_controller     = new FormController( $namespace );
		$campaign_controller = new CampaignController( $namespace );
		$campaign_update_controller = new CampaignUpdateController( $namespace );

		$settings_controller->register_routes();
		$donation_controller->register_routes();
		$portal_controller->register_routes();
		$reports_controller->register_routes();
		$webhook_controller->register_routes();
		$subscription_controller->register_routes();
		$donor_controller->register_routes();
		$form_controller->register_routes();
		$campaign_controller->register_routes();
		$campaign_update_controller->register_routes();

		do_action( 'donatepress_register_rest_routes', $namespace );
	}
}
