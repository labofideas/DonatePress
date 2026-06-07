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

	private const DEFAULT_MAP = array(
		'settings.manage'         => 'manage_options',
		'admin.settings'          => 'manage_options',
		'admin.setup'             => 'manage_options',
		'donors.manage'           => 'donatepress_manage_donors',
		'forms.manage'            => 'donatepress_manage_forms',
		'campaigns.manage'        => 'donatepress_manage_campaigns',
		'campaign_updates.manage' => 'donatepress_manage_campaigns',
		'subscriptions.manage'    => 'donatepress_manage_subscriptions',
		'reports.view'            => 'donatepress_view_reports',
		'admin.donations'         => 'donatepress_view_reports',
		'admin.donors'            => 'donatepress_manage_donors',
		'admin.forms'             => 'donatepress_manage_forms',
		'admin.campaigns'         => 'donatepress_manage_campaigns',
		'admin.reports'           => 'donatepress_view_reports',
		'admin.subscriptions'     => 'donatepress_manage_subscriptions',
	);

	/**
	 * Register custom capabilities on activation.
	 */
	public static function register_capabilities(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		$capabilities = array(
			'donatepress_manage_donors',
			'donatepress_manage_forms',
			'donatepress_manage_campaigns',
			'donatepress_manage_subscriptions',
			'donatepress_view_reports',
		);

		foreach ( $capabilities as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Remove custom capabilities on uninstall.
	 */
	public static function remove_capabilities(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		$capabilities = array(
			'donatepress_manage_donors',
			'donatepress_manage_forms',
			'donatepress_manage_campaigns',
			'donatepress_manage_subscriptions',
			'donatepress_view_reports',
		);

		foreach ( $capabilities as $cap ) {
			$admin->remove_cap( $cap );
		}
	}

	/**
	 * Determine if current user can access a scoped action.
	 */
	public function can( string $scope, ?WP_REST_Request $request = null ): bool {
		$capability = $this->resolve_capability( $scope, $request );
		if ( '' === $capability ) {
			return false;
		}

		$allowed = current_user_can( $capability );

		return (bool) apply_filters( 'donatepress_user_can', $allowed, $scope, $capability, $request );
	}

	/**
	 * Resolve capability string for scope.
	 */
	public function resolve_capability( string $scope, ?WP_REST_Request $request = null ): string {
		$map = self::DEFAULT_MAP;

		$map = apply_filters( 'donatepress_capability_map', $map, $request );

		$scope = sanitize_key( str_replace( '.', '_', $scope ) );
		$scope = str_replace( '_', '.', $scope );

		return isset( $map[ $scope ] ) ? sanitize_key( (string) $map[ $scope ] ) : '';
	}
}
