<?php

namespace DonatePress\API\Controllers;

use DonatePress\Admin\SettingsPage;
use DonatePress\Security\CapabilityManager;
use DonatePress\Services\SetupWizardService;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes settings-focused endpoints for admin and health checks.
 */
class SettingsController {
	private const OPTION_KEY = 'donatepress_settings';

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'settings' => array(
							'type'              => 'object',
							'required'          => false,
							'sanitize_callback' => array( $this, 'sanitize_settings_payload' ),
							'validate_callback' => array( $this, 'validate_settings_payload' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/setup-wizard/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'setup_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/setup-wizard/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'setup_save' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'settings' => array(
						'type'              => 'object',
						'required'          => false,
						'sanitize_callback' => array( $this, 'sanitize_settings_payload' ),
						'validate_callback' => array( $this, 'validate_settings_payload' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/setup-wizard/first-form',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'setup_first_form' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/setup-wizard/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'setup_complete' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Basic plugin status endpoint.
	 */
	public function status(): WP_REST_Response {
		$data = array(
			'plugin'         => 'donatepress',
			'version'        => DONATEPRESS_VERSION,
			'setup_complete' => (int) get_option( 'donatepress_setup_completed', 0 ) === 1,
		);

		/**
		 * Filters public status payload.
		 *
		 * @param array<string,mixed> $data Status payload.
		 */
		$data = apply_filters( 'donatepress_rest_status_data', $data );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Return safe settings subset.
	 */
	public function get_settings(): WP_REST_Response {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$secret_keys = array( 'stripe_secret_key', 'stripe_webhook_secret', 'paypal_secret' );
		foreach ( $secret_keys as $secret_key ) {
			if ( isset( $settings[ $secret_key ] ) ) {
				$settings[ $secret_key ] = '' !== trim( (string) $settings[ $secret_key ] ) ? '************' : '';
			}
		}

		/**
		 * Filters response data for admin settings API.
		 *
		 * @param array<string,mixed> $settings Sanitized settings response.
		 */
		$settings = apply_filters( 'donatepress_rest_settings_response', $settings );

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * Update settings through REST with existing sanitization pipeline.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}
		$payload = $this->extract_settings_payload( $payload );

		/**
		 * Filter incoming REST settings payload before save.
		 *
		 * @param array<string,mixed> $payload Raw request payload.
		 */
		$payload = apply_filters( 'donatepress_rest_settings_payload', $payload );

		$current = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$merged = array_merge( $current, $payload );
		$settings_page = new SettingsPage();
		$clean         = $settings_page->sanitize_settings( $merged );
		update_option( self::OPTION_KEY, $clean, false );

		do_action( 'donatepress_rest_settings_updated', $clean, $payload );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Settings updated.', 'donatepress' ),
			),
			200
		);
	}

	/**
	 * Setup wizard status endpoint.
	 */
	public function setup_status(): WP_REST_Response {
		$service = new SetupWizardService();
		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => $service->status(),
			),
			200
		);
	}

	/**
	 * Setup wizard save endpoint.
	 */
	public function setup_save( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}
		$payload = $this->extract_settings_payload( $payload );

		$service = new SetupWizardService();
		$status  = $service->save_settings( $payload );

		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => $status,
			),
			200
		);
	}

	/**
	 * Setup wizard first-form endpoint.
	 */
	public function setup_first_form(): WP_REST_Response {
		$service = new SetupWizardService();
		$result  = $service->ensure_first_form();
		$status  = ! empty( $result['success'] ) ? 200 : 500;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * Setup wizard completion endpoint.
	 */
	public function setup_complete(): WP_REST_Response {
		$service = new SetupWizardService();
		$result  = $service->complete();
		$status  = ! empty( $result['success'] ) ? 200 : 422;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * Manage permission callback (filterable).
	 */
	public function can_manage( WP_REST_Request $request ): bool {
		$manager = new CapabilityManager();
		$allowed = $manager->can( 'settings.manage', $request );

		/**
		 * Filters REST manage permission.
		 *
		 * @param bool            $allowed Permission state.
		 * @param WP_REST_Request $request Current request.
		 */
		return (bool) apply_filters( 'donatepress_rest_can_manage', $allowed, $request );
	}

	/**
	 * Resolve nested "settings" payload when provided.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function extract_settings_payload( array $payload ): array {
		if ( isset( $payload['settings'] ) && is_array( $payload['settings'] ) ) {
			return $payload['settings'];
		}
		return $payload;
	}

	/**
	 * Sanitize settings payload args.
	 *
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	public function sanitize_settings_payload( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return $value;
	}

	/**
	 * Validate settings payload args.
	 *
	 * @param mixed $value
	 */
	public function validate_settings_payload( $value ): bool {
		return is_array( $value );
	}
}
