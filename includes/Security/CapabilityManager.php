<?php

namespace DonatePress\Security;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central capability resolver for REST and admin-sensitive actions.
 */
class CapabilityManager {
	/**
	 * Determine if current user can access a scoped action.
	 */
	public function can( string $scope, ?WP_REST_Request $request = null ): bool {
		$capability = $this->resolve_capability( $scope, $request );
		if ( '' === $capability ) {
			return false;
		}

		$allowed = current_user_can( $capability );

		/**
		 * Filter final capability decision.
		 *
		 * @param bool                  $allowed    Whether access is granted.
		 * @param string                $scope      Capability scope key.
		 * @param string                $capability Resolved WP capability.
		 * @param WP_REST_Request|null  $request    Current request object.
		 */
		return (bool) apply_filters( 'donatepress_user_can', $allowed, $scope, $capability, $request );
	}

	/**
	 * Resolve capability string for scope.
	 */
	public function resolve_capability( string $scope, ?WP_REST_Request $request = null ): string {
		$map = array(
			'settings.manage'      => 'manage_options',
			'donors.manage'        => 'manage_options',
			'forms.manage'         => 'manage_options',
			'campaigns.manage'     => 'manage_options',
			'campaign_updates.manage' => 'manage_options',
			'subscriptions.manage' => 'manage_options',
			'reports.view'         => 'manage_options',
			'admin.settings'       => 'manage_options',
			'admin.setup'          => 'manage_options',
			'admin.donations'      => 'manage_options',
			'admin.donors'         => 'manage_options',
			'admin.forms'          => 'manage_options',
			'admin.campaigns'      => 'manage_options',
			'admin.reports'        => 'manage_options',
			'admin.subscriptions'  => 'manage_options',
		);

		/**
		 * Filter capability map by scope.
		 *
		 * @param array<string,string>  $map     Scope=>capability mapping.
		 * @param WP_REST_Request|null  $request Current request object.
		 */
		$map = apply_filters( 'donatepress_capability_map', $map, $request );

		$scope = sanitize_key( str_replace( '.', '_', $scope ) );
		$scope = str_replace( '_', '.', $scope );

		return isset( $map[ $scope ] ) ? sanitize_key( (string) $map[ $scope ] ) : '';
	}
}
