<?php

namespace DonatePress\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles donor/admin transactional notifications.
 */
class EmailNotificationService {
	private SettingsService $settings;

	public function __construct( ?SettingsService $settings = null ) {
		$this->settings = $settings ?: new SettingsService();
	}

	/**
	 * Send donor-facing notification.
	 *
	 * @param array<string,mixed> $context
	 */
	public function send_donor_notification( string $email, string $event, array $context = array() ): bool {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return false;
		}

		$template = $this->build_template( $event, $context, false );
		if ( '' === $template['subject'] || '' === $template['message'] ) {
			return false;
		}

		return (bool) wp_mail( $email, $template['subject'], $template['message'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/**
	 * Send admin-facing notification.
	 *
	 * @param array<string,mixed> $context
	 */
	public function send_admin_notification( string $event, array $context = array() ): bool {
		$admin = sanitize_email( (string) $this->settings->get( 'organization_email', get_option( 'admin_email' ) ) );
		if ( ! is_email( $admin ) ) {
			return false;
		}

		$template = $this->build_template( $event, $context, true );
		if ( '' === $template['subject'] || '' === $template['message'] ) {
			return false;
		}

		return (bool) wp_mail( $admin, $template['subject'], $template['message'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/**
	 * Build event templates.
	 *
	 * @param array<string,mixed> $context
	 * @return array{subject:string,message:string}
	 */
	private function build_template( string $event, array $context, bool $admin ): array {
		$org_name = (string) $this->settings->get( 'organization_name', get_bloginfo( 'name' ) );
		$subject  = '';
		$message  = '';

		switch ( $event ) {
			case 'new_pending_donation':
				$subject = sprintf( '[%s] New Pending Donation %s', $org_name, (string) ( $context['donation_number'] ?? '' ) );
				$message = sprintf(
					"Donation %s is pending.\nDonor: %s\nAmount: %s %s\nGateway: %s\nDonation ID: %d",
					(string) ( $context['donation_number'] ?? '' ),
					(string) ( $context['donor_email'] ?? '' ),
					(string) number_format( (float) ( $context['amount'] ?? 0 ), 2, '.', '' ),
					(string) ( $context['currency'] ?? 'USD' ),
					(string) ( $context['gateway'] ?? '' ),
					(int) ( $context['donation_id'] ?? 0 )
				);
				break;

			case 'recurring_retry_failed':
				$subject = $admin
					? sprintf( '[%s] Subscription Retry Failed (%s)', $org_name, (string) ( $context['subscription_number'] ?? '' ) )
					: sprintf( '%s: We could not process your recurring donation', $org_name );
				$message = $admin
					? sprintf(
						"Subscription: %s\nDonor: %s\nFailure count: %d\nNext retry: %s\nGateway: %s",
						(string) ( $context['subscription_number'] ?? '' ),
						(string) ( $context['donor_email'] ?? '' ),
						(int) ( $context['failure_count'] ?? 0 ),
						(string) ( $context['retry_at'] ?? '' ),
						(string) ( $context['gateway'] ?? '' )
					)
					: sprintf(
						"We could not process your recurring donation for subscription %s.\nWe will retry on %s.\nIf your card details changed, please update your payment method in the donor portal.",
						(string) ( $context['subscription_number'] ?? '' ),
						(string) ( $context['retry_at'] ?? '' )
					);
				break;

			case 'recurring_retry_succeeded':
				$subject = $admin
					? sprintf( '[%s] Subscription Retry Succeeded (%s)', $org_name, (string) ( $context['subscription_number'] ?? '' ) )
					: sprintf( '%s: Recurring donation processed successfully', $org_name );
				$message = $admin
					? sprintf(
						"Subscription: %s\nDonor: %s\nGateway: %s",
						(string) ( $context['subscription_number'] ?? '' ),
						(string) ( $context['donor_email'] ?? '' ),
						(string) ( $context['gateway'] ?? '' )
					)
					: sprintf(
						"Your recurring donation for subscription %s was processed successfully.\nThank you for your continued support.",
						(string) ( $context['subscription_number'] ?? '' )
					);
				break;

			case 'recurring_retry_exhausted':
				$subject = $admin
					? sprintf( '[%s] Subscription Expired After Retries (%s)', $org_name, (string) ( $context['subscription_number'] ?? '' ) )
					: sprintf( '%s: Recurring donation stopped', $org_name );
				$message = $admin
					? sprintf(
						"Subscription: %s\nDonor: %s\nFailures: %d/%d\nStatus: expired",
						(string) ( $context['subscription_number'] ?? '' ),
						(string) ( $context['donor_email'] ?? '' ),
						(int) ( $context['failure_count'] ?? 0 ),
						(int) ( $context['max_retries'] ?? 0 )
					)
					: sprintf(
						"Your recurring donation for subscription %s has been paused after %d failed attempts.\nPlease restart it from your donor portal when ready.",
						(string) ( $context['subscription_number'] ?? '' ),
						(int) ( $context['failure_count'] ?? 0 )
					);
				break;
		}

		$subject = (string) apply_filters( 'donatepress_email_template_subject', $subject, $event, $context, $admin );
		$message = (string) apply_filters( 'donatepress_email_template_message', $message, $event, $context, $admin );

		return array(
			'subject' => sanitize_text_field( $subject ),
			'message' => wp_strip_all_tags( $message, true ),
		);
	}
}
