<?php

namespace DonatePress\API\Controllers;

use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\DonorRepository;
use DonatePress\Repositories\PortalTokenRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Security\RateLimiter;
use DonatePress\Services\SimplePdfService;
use DonatePress\Services\PortalService;
use DonatePress\Services\ReceiptService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Donor portal magic-link and session endpoints.
 */
class PortalController {
	private string $namespace;

	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/portal/request-link',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'request_link' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => 'is_email',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/auth',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'auth' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'me' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/profile',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_profile' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'first_name' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'last_name'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'phone'      => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/donations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'donations' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/annual-summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'annual_summary' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/annual-summary/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'annual_summary_download' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/subscriptions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'subscriptions' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/subscriptions/(?P<id>\d+)/action',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'subscription_action' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'     => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'action' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/portal/receipts/(?P<id>\d+)/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'receipt_download' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'     => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'format' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
	}

	/**
	 * Request portal magic link.
	 */
	public function request_link( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->verify_origin( $request ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'If your email exists in our records, a portal link has been sent.', 'donatepress' ),
				),
				200
			);
		}
		if ( ! $this->verify_portal_nonce( $request ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'If your email exists in our records, a portal link has been sent.', 'donatepress' ),
				),
				200
			);
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}

		$email = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'If your email exists in our records, a portal link has been sent.', 'donatepress' ),
				),
				200
			);
		}

		$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		$rate_limiter = new RateLimiter();
		$email_limit  = (int) apply_filters( 'donatepress_portal_email_rate_limit', 5 );
		$ip_limit     = (int) apply_filters( 'donatepress_portal_ip_rate_limit', 20 );
		$window       = (int) apply_filters( 'donatepress_portal_rate_window', 15 * MINUTE_IN_SECONDS );

		if ( ! $rate_limiter->allow( 'portal_email_' . md5( strtolower( $email ) ), $email_limit, $window ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'If your email exists in our records, a portal link has been sent.', 'donatepress' ),
				),
				200
			);
		}
		if ( ! $rate_limiter->allow( 'portal_ip_' . md5( $ip_address ), $ip_limit, $window ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'If your email exists in our records, a portal link has been sent.', 'donatepress' ),
				),
				200
			);
		}

		global $wpdb;
		$donation_repository = new DonationRepository( $wpdb );
		$has_donations       = $donation_repository->count_by_email( $email ) > 0;

		if ( $has_donations ) {
			$portal_service = new PortalService( new PortalTokenRepository( $wpdb ) );
			$result         = $portal_service->create_magic_link( $email, $ip_address, $user_agent );

			if ( ! empty( $result['success'] ) ) {
				$magic_url_base = (string) apply_filters( 'donatepress_portal_magic_url_base', home_url( '/donor-portal/' ) );
				$magic_url      = add_query_arg( 'dp_token', rawurlencode( (string) $result['token'] ), $magic_url_base );
				$this->send_magic_email( $email, $magic_url, (int) $result['ttl'] );

				do_action( 'donatepress_portal_magic_link_requested', $email, $magic_url, $result );

				if ( (bool) apply_filters( 'donatepress_portal_expose_magic_url', false ) ) {
					return new WP_REST_Response(
						array(
							'success'   => true,
							'message'   => __( 'Magic link generated.', 'donatepress' ),
							'magic_url' => esc_url_raw( $magic_url ),
						),
						200
					);
				}
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'If your email exists in our records, a portal link has been sent.', 'donatepress' ),
			),
			200
		);
	}

	/**
	 * Exchange magic token for portal session token.
	 */
	public function auth( WP_REST_Request $request ) {
		if ( ! $this->verify_origin( $request ) ) {
			return new WP_Error( 'invalid_origin', __( 'Origin check failed.', 'donatepress' ), array( 'status' => 403 ) );
		}
		if ( ! $this->verify_portal_nonce( $request ) ) {
			return new WP_Error( 'invalid_nonce', __( 'Portal security check failed.', 'donatepress' ), array( 'status' => 403 ) );
		}

		$ip_address   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$rate_limiter = new RateLimiter();
		$limit        = (int) apply_filters( 'donatepress_portal_auth_rate_limit', 20 );
		$window       = (int) apply_filters( 'donatepress_portal_rate_window', 15 * MINUTE_IN_SECONDS );

		if ( ! $rate_limiter->allow( 'portal_auth_' . md5( $ip_address ), $limit, $window ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Please retry shortly.', 'donatepress' ), array( 'status' => 429 ) );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}

		$token = sanitize_text_field( (string) ( $payload['token'] ?? '' ) );
		if ( '' === $token ) {
			return new WP_Error( 'invalid_token', __( 'Portal token is required.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$portal_service = new PortalService( new PortalTokenRepository( $wpdb ) );
		$result         = $portal_service->consume_magic_link( $token );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'auth_failed', (string) ( $result['message'] ?? __( 'Portal authentication failed.', 'donatepress' ) ), array( 'status' => 403 ) );
		}

		return new WP_REST_Response(
			array(
				'success'       => true,
				'session_token' => (string) $result['session_token'],
				'expires_in'    => (int) $result['expires_in'],
			),
			200
		);
	}

	/**
	 * Return portal profile summary.
	 */
	public function me( WP_REST_Request $request ) {
		$session = $this->require_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$email = sanitize_email( (string) ( $session['email'] ?? '' ) );
		global $wpdb;
		$repository       = new DonationRepository( $wpdb );
		$donor_repository = new DonorRepository( $wpdb );
		$donor            = $donor_repository->find_by_email( $email );

		$response = array(
			'success' => true,
			'profile' => array(
				'email'            => $email,
				'donation_count'   => $repository->count_by_email( $email ),
				'completed_volume' => $repository->sum_completed_by_email( $email ),
				'first_name'       => (string) ( $donor['first_name'] ?? '' ),
				'last_name'        => (string) ( $donor['last_name'] ?? '' ),
				'phone'            => (string) ( $donor['phone'] ?? '' ),
			),
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Return donation history for authenticated portal session.
	 */
	public function donations( WP_REST_Request $request ) {
		$session = $this->require_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$limit = max( 1, min( 100, (int) $request->get_param( 'limit' ) ?: 20 ) );
		$email = sanitize_email( (string) ( $session['email'] ?? '' ) );

		global $wpdb;
		$repository = new DonationRepository( $wpdb );

		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $repository->list_by_email( $email, $limit ),
			),
			200
		);
	}

	/**
	 * Update donor profile fields for portal user.
	 */
	public function update_profile( WP_REST_Request $request ) {
		if ( ! $this->verify_origin( $request ) ) {
			return new WP_Error( 'invalid_origin', __( 'Origin check failed.', 'donatepress' ), array( 'status' => 403 ) );
		}
		if ( ! $this->verify_portal_nonce( $request ) ) {
			return new WP_Error( 'invalid_nonce', __( 'Portal security check failed.', 'donatepress' ), array( 'status' => 403 ) );
		}

		$session = $this->require_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}

		$email      = sanitize_email( (string) ( $session['email'] ?? '' ) );
		$first_name = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );
		$phone      = sanitize_text_field( (string) ( $payload['phone'] ?? '' ) );

		global $wpdb;
		$donor_repository    = new DonorRepository( $wpdb );
		$donation_repository = new DonationRepository( $wpdb );

		$updated = $donor_repository->update_by_email(
			$email,
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'phone'      => $phone,
			)
		);

		$donation_repository->update_donor_name_by_email( $email, $first_name, $last_name );

		return new WP_REST_Response(
			array(
				'success' => true,
				'updated' => (bool) $updated,
				'profile' => array(
					'email'      => $email,
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'phone'      => $phone,
				),
			),
			200
		);
	}

	/**
	 * Return donor subscriptions for current portal session.
	 */
	public function subscriptions( WP_REST_Request $request ) {
		$session = $this->require_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$limit = max( 1, min( 100, (int) $request->get_param( 'limit' ) ?: 20 ) );
		$email = sanitize_email( (string) ( $session['email'] ?? '' ) );

		global $wpdb;
		$repository = new SubscriptionRepository( $wpdb );

		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $repository->list_by_email( $email, $limit ),
			),
			200
		);
	}

	/**
	 * Apply donor subscription action (pause/resume/cancel).
	 */
	public function subscription_action( WP_REST_Request $request ) {
		if ( ! $this->verify_origin( $request ) ) {
			return new WP_Error( 'invalid_origin', __( 'Origin check failed.', 'donatepress' ), array( 'status' => 403 ) );
		}
		if ( ! $this->verify_portal_nonce( $request ) ) {
			return new WP_Error( 'invalid_nonce', __( 'Portal security check failed.', 'donatepress' ), array( 'status' => 403 ) );
		}

		$session = $this->require_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}

		$id     = (int) $request->get_param( 'id' );
		$action = sanitize_key( (string) ( $payload['action'] ?? $request->get_param( 'action' ) ) );
		$email  = sanitize_email( (string) ( $session['email'] ?? '' ) );

		$action_map = array(
			'pause'  => 'paused',
			'resume' => 'active',
			'cancel' => 'cancelled',
		);
		if ( ! isset( $action_map[ $action ] ) ) {
			return new WP_Error( 'invalid_action', __( 'Invalid subscription action.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new SubscriptionRepository( $wpdb );
		if ( ! $repository->belongs_to_email( $id, $email ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to modify this subscription.', 'donatepress' ), array( 'status' => 403 ) );
		}

		$subscription = $repository->find( $id );
		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		$current_status = sanitize_key( (string) ( $subscription['status'] ?? '' ) );
		if ( ! $this->is_transition_allowed( $current_status, $action ) ) {
			return new WP_Error( 'invalid_transition', __( 'Subscription action is not allowed in the current state.', 'donatepress' ), array( 'status' => 409 ) );
		}

		$next_status = $action_map[ $action ];
		if ( ! $repository->update_status( $id, $next_status ) ) {
			return new WP_Error( 'update_failed', __( 'Could not update subscription status.', 'donatepress' ), array( 'status' => 500 ) );
		}

		do_action( 'donatepress_portal_subscription_action', $id, $action, $current_status, $next_status, $email );

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'action'  => $action,
				'status'  => $next_status,
			),
			200
		);
	}

	/**
	 * Revoke current portal session token.
	 */
	public function logout( WP_REST_Request $request ) {
		if ( ! $this->verify_origin( $request ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Origin check failed.', 'donatepress' ) ), 403 );
		}
		if ( ! $this->verify_portal_nonce( $request ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Portal security check failed.', 'donatepress' ) ), 403 );
		}

		$session_token = $this->read_session_token( $request );
		if ( '' === $session_token ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		global $wpdb;
		$portal_service = new PortalService( new PortalTokenRepository( $wpdb ) );
		$portal_service->revoke_session( $session_token );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Return annual completed-donations summary for portal user.
	 */
	public function annual_summary( WP_REST_Request $request ) {
		$session = $this->require_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$email = sanitize_email( (string) ( $session['email'] ?? '' ) );
		global $wpdb;
		$repository = new DonationRepository( $wpdb );
		$items      = $repository->annual_summary_by_email( $email );

		return new WP_REST_Response(
			array(
				'success' => true,
				'items'   => $items,
			),
			200
		);
	}

	/**
	 * Return annual summary CSV payload for client-side download.
	 */
	public function annual_summary_download( WP_REST_Request $request ) {
		$session = $this->require_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$email = sanitize_email( (string) ( $session['email'] ?? '' ) );
		global $wpdb;
		$repository = new DonationRepository( $wpdb );
		$items      = $repository->annual_summary_by_email( $email );
		$format     = sanitize_key( (string) $request->get_param( 'format' ) );

		if ( 'pdf' === $format ) {
			$lines = array(
				sprintf( 'Annual Summary - %s', $email ),
				'',
				'Year | Currency | Donations | Total',
				'-----------------------------------',
			);
			foreach ( $items as $item ) {
				$lines[] = sprintf(
					'%s | %s | %d | %s',
					sanitize_text_field( (string) ( $item['year'] ?? '' ) ),
					sanitize_text_field( (string) ( $item['currency'] ?? '' ) ),
					(int) ( $item['donation_count'] ?? 0 ),
					sanitize_text_field( (string) ( $item['total_amount'] ?? '0' ) )
				);
			}

			$pdf_service = new SimplePdfService();
			$pdf_binary  = $pdf_service->render_text_pdf( implode( "\n", $lines ), 'DonatePress Annual Summary' );

			return new WP_REST_Response(
				array(
					'success'  => true,
					'filename' => 'donatepress-annual-summary-' . gmdate( 'Y-m-d' ) . '.pdf',
					'mime'     => 'application/pdf',
					'encoding' => 'base64',
					'content'  => base64_encode( $pdf_binary ),
				),
				200
			);
		}

		$lines   = array( 'year,currency,donation_count,total_amount' );
		foreach ( $items as $item ) {
			$lines[] = sprintf(
				'%s,%s,%d,%s',
				sanitize_text_field( (string) ( $item['year'] ?? '' ) ),
				sanitize_text_field( (string) ( $item['currency'] ?? '' ) ),
				(int) ( $item['donation_count'] ?? 0 ),
				sanitize_text_field( (string) ( $item['total_amount'] ?? '0' ) )
			);
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'filename' => 'donatepress-annual-summary-' . gmdate( 'Y-m-d' ) . '.csv',
				'mime'     => 'text/csv',
				'content'  => implode( "\n", $lines ),
			),
			200
		);
	}

	/**
	 * Return donation receipt HTML payload for client-side download.
	 */
	public function receipt_download( WP_REST_Request $request ) {
		$session = $this->require_session( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$donation_id = (int) $request->get_param( 'id' );
		$email       = sanitize_email( (string) ( $session['email'] ?? '' ) );
		if ( $donation_id <= 0 ) {
			return new WP_Error( 'invalid_receipt', __( 'Invalid receipt request.', 'donatepress' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		$repository = new DonationRepository( $wpdb );
		if ( ! $repository->belongs_to_email( $donation_id, $email ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to access this receipt.', 'donatepress' ), array( 'status' => 403 ) );
		}

		$donation = $repository->find( $donation_id );
		if ( ! $donation ) {
			return new WP_Error( 'not_found', __( 'Donation not found.', 'donatepress' ), array( 'status' => 404 ) );
		}

		$receipt = new ReceiptService();
		$html    = $receipt->render_html( $donation );
		$number  = sanitize_file_name( (string) ( $donation['donation_number'] ?? 'receipt' ) );
		$format  = sanitize_key( (string) $request->get_param( 'format' ) );

		if ( 'html' === $format ) {
			return new WP_REST_Response(
				array(
					'success'  => true,
					'filename' => $number . '.html',
					'mime'     => 'text/html',
					'content'  => $html,
				),
				200
			);
		}

		$pdf_service = new SimplePdfService();
		$pdf_binary  = $pdf_service->render_text_pdf( $receipt->render_text( $donation ), 'Donation Receipt ' . (string) ( $donation['donation_number'] ?? '' ) );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'filename' => $number . '.pdf',
				'mime'     => 'application/pdf',
				'encoding' => 'base64',
				'content'  => base64_encode( $pdf_binary ),
			),
			200
		);
	}

	/**
	 * Require valid portal session.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function require_session( WP_REST_Request $request ) {
		$session_token = $this->read_session_token( $request );
		if ( '' === $session_token ) {
			return new WP_Error( 'unauthorized', __( 'Portal session is required.', 'donatepress' ), array( 'status' => 401 ) );
		}

		global $wpdb;
		$portal_service = new PortalService( new PortalTokenRepository( $wpdb ) );
		$session        = $portal_service->get_session( $session_token );
		if ( ! $session ) {
			return new WP_Error( 'session_expired', __( 'Portal session is invalid or expired.', 'donatepress' ), array( 'status' => 401 ) );
		}

		return $session;
	}

	/**
	 * Read portal session token from header or request param.
	 */
	private function read_session_token( WP_REST_Request $request ): string {
		$token = (string) $request->get_header( 'x-donatepress-portal-token' );
		if ( '' === $token ) {
			$auth = (string) $request->get_header( 'authorization' );
			if ( 0 === stripos( $auth, 'Bearer ' ) ) {
				$token = trim( substr( $auth, 7 ) );
			}
		}
		if ( '' === $token ) {
			$token = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
		}
		return sanitize_text_field( $token );
	}

	/**
	 * Send donor magic-link email.
	 */
	private function send_magic_email( string $email, string $magic_url, int $ttl_seconds ): void {
		$subject = (string) apply_filters( 'donatepress_portal_magic_subject', __( 'Your DonatePress Donor Portal Link', 'donatepress' ), $email, $magic_url );
		$minutes = max( 1, (int) floor( $ttl_seconds / 60 ) );
		$body    = sprintf(
			/* translators: 1: magic link URL, 2: minutes until expiry */
			__( "Open your donor portal: %1\$s\n\nThis link expires in %2\$d minutes.", 'donatepress' ),
			$magic_url,
			$minutes
		);
		$body = (string) apply_filters( 'donatepress_portal_magic_body', $body, $email, $magic_url, $ttl_seconds );

		wp_mail( $email, $subject, $body );
	}

	/**
	 * Verify browser origin/referer against current site.
	 */
	private function verify_origin( WP_REST_Request $request ): bool {
		$enforce = (bool) apply_filters( 'donatepress_enforce_origin_check', true, $request );
		if ( ! $enforce ) {
			return true;
		}

		$origin  = (string) $request->get_header( 'origin' );
		$referer = (string) $request->get_header( 'referer' );
		$target  = '' !== $origin ? $origin : $referer;
		if ( '' === $target ) {
			return false;
		}

		$target_host = wp_parse_url( $target, PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $target_host ) || '' === $target_host || ! is_string( $site_host ) || '' === $site_host ) {
			return false;
		}

		return hash_equals( strtolower( $site_host ), strtolower( $target_host ) );
	}

	/**
	 * Verify portal nonce header for frontend write actions.
	 */
	private function verify_portal_nonce( WP_REST_Request $request ): bool {
		$nonce = (string) $request->get_header( 'x-donatepress-portal-nonce' );
		if ( '' === $nonce ) {
			$nonce = sanitize_text_field( (string) $request->get_param( 'dp_portal_nonce' ) );
		}
		if ( '' === $nonce ) {
			return false;
		}

		return (bool) wp_verify_nonce( $nonce, 'donatepress_portal' );
	}

	/**
	 * Validate donor-allowed state transitions.
	 */
	private function is_transition_allowed( string $current_status, string $action ): bool {
		$matrix = array(
			'pause'  => array( 'active' ),
			'resume' => array( 'paused', 'failed' ),
			'cancel' => array( 'active', 'paused', 'failed', 'pending' ),
		);

		return in_array( $current_status, $matrix[ $action ] ?? array(), true );
	}
}
