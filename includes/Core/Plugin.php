<?php

namespace DonatePress\Core;

use DonatePress\Admin\SettingsPage;
use DonatePress\API\RestController;
use DonatePress\Frontend\CampaignShortcode;
use DonatePress\Frontend\FormBlock;
use DonatePress\Frontend\FormShortcode;
use DonatePress\Frontend\PortalShortcode;
use DonatePress\Integrations\BuddyPressIntegration;
use DonatePress\Integrations\WooCommerceIntegration;
use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\DonorRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Services\EmailNotificationService;
use DonatePress\Services\PaymentService;
use DonatePress\Services\ReceiptService;
use DonatePress\Services\RecurringChargeService;
use DonatePress\Services\RecurringRetryService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin runtime class.
 */
class Plugin {
	/**
	 * Plugin basename cache.
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_basename = plugin_basename( DONATEPRESS_FILE );
	}

	/**
	 * Initialize runtime hooks.
	 */
	public function init(): void {
		do_action( 'donatepress_loaded' );
		add_action( 'donatepress_retry_subscriptions_cron', array( $this, 'run_recurring_retry_job' ) );
		add_action( 'donatepress_subscription_retry_due', array( $this, 'process_subscription_retry' ), 10, 3 );
		add_action( 'donatepress_donation_status_synced', array( $this, 'maybe_send_receipt_email' ), 20, 4 );
		add_action( 'donatepress_donation_status_synced', array( $this, 'sync_donor_metrics' ), 30, 4 );
		add_action( 'donatepress_wc_order_synced', array( $this, 'maybe_send_wc_receipt_email' ), 20, 2 );
		add_action( 'donatepress_donation_pending_created', array( $this, 'notify_admin_donation_pending' ), 20, 2 );
		add_action( 'donatepress_subscription_retry_failed', array( $this, 'notify_subscription_retry_failed' ), 20, 5 );
		add_action( 'donatepress_subscription_retry_succeeded', array( $this, 'notify_subscription_retry_succeeded' ), 20, 3 );
		add_action( 'donatepress_subscription_retries_exhausted', array( $this, 'notify_subscription_retry_exhausted' ), 20, 3 );

		$rest_controller = new RestController();
		$rest_controller->register();

		$form_shortcode = new FormShortcode();
		$form_shortcode->register();
		$form_block = new FormBlock();
		$form_block->register();

		$portal_shortcode = new PortalShortcode();
		$portal_shortcode->register();
		$campaign_shortcode = new CampaignShortcode();
		$campaign_shortcode->register();

		$bp_integration = new BuddyPressIntegration();
		$bp_integration->register();

		$wc_integration = new WooCommerceIntegration();
		$wc_integration->register();

		$privacy = new Privacy();
		$privacy->register();

		if ( is_admin() ) {
			$settings_page = new SettingsPage();
			$settings_page->register();

			add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'add_settings_link' ) );
		}
	}

	/**
	 * Add settings link in plugins list row.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function add_settings_link( array $links ): array {
		$url             = admin_url( 'admin.php?page=donatepress' );
		$settings_link   = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'donatepress' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Process recurring retry queue on cron.
	 */
	public function run_recurring_retry_job(): void {
		global $wpdb;
		$repository = new SubscriptionRepository( $wpdb );
		$service    = new RecurringRetryService( $repository );
		$result     = $service->process_due_retries();
		do_action( 'donatepress_retry_cron_processed', $result );
	}

	/**
	 * Execute charge attempt for one due retry item.
	 *
	 * @param array<string,mixed> $subscription
	 */
	public function process_subscription_retry( int $subscription_id, string $gateway, array $subscription ): void {
		global $wpdb;
		$repository      = new SubscriptionRepository( $wpdb );
		$payment_service = new PaymentService();
		$service         = new RecurringChargeService( $repository, $payment_service );
		$result          = $service->attempt_retry( $subscription_id, $gateway, $subscription );

		do_action( 'donatepress_subscription_retry_processed', $subscription_id, $gateway, $result, $subscription );
	}

	/**
	 * Send receipt email when donation reaches completed state.
	 */
	public function maybe_send_receipt_email( int $donation_id, string $status, string $gateway, string $event_type ): void {
		if ( 'completed' !== sanitize_key( $status ) ) {
			return;
		}

		global $wpdb;
		$repository = new DonationRepository( $wpdb );
		$donation   = $repository->find( $donation_id );
		if ( ! $donation ) {
			return;
		}

		$this->send_receipt_email( $donation );
	}

	/**
	 * Send receipt email for WooCommerce-synced completed donation.
	 */
	public function maybe_send_wc_receipt_email( int $order_id, int $donation_id ): void {
		global $wpdb;
		$repository = new DonationRepository( $wpdb );
		$donation   = $repository->find( $donation_id );
		if ( ! $donation ) {
			return;
		}

		$this->send_receipt_email( $donation );
	}

	/**
	 * Recalculate donor totals after donation status changes.
	 */
	public function sync_donor_metrics( int $donation_id, string $status, string $gateway, string $event_type ): void {
		global $wpdb;
		$donation_repository = new DonationRepository( $wpdb );
		$donation            = $donation_repository->find( $donation_id );
		if ( ! $donation ) {
			return;
		}

		$email = sanitize_email( (string) ( $donation['donor_email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return;
		}

		$donor_repository = new DonorRepository( $wpdb );
		$donor_repository->sync_totals_by_email( $email );
	}

	/**
	 * Send receipt email to donor.
	 *
	 * @param array<string,mixed> $donation Donation row.
	 */
	private function send_receipt_email( array $donation ): void {
		$email = sanitize_email( (string) ( $donation['donor_email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return;
		}

		$receipt = new ReceiptService();
		$html    = $receipt->render_html( $donation );
		$subject = sprintf(
			/* translators: %s donation number */
			__( 'Donation Receipt %s', 'donatepress' ),
			sanitize_text_field( (string) ( $donation['donation_number'] ?? '' ) )
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $email, $subject, $html, $headers );

		do_action( 'donatepress_receipt_emailed', $donation );
	}

	/**
	 * Notify admin on new pending donation event.
	 *
	 * @param array<string,mixed> $data
	 */
	public function notify_admin_donation_pending( int $donation_id, array $data ): void {
		$notifications = new EmailNotificationService();
		$notifications->send_admin_notification(
			'new_pending_donation',
			array(
				'donation_id'     => $donation_id,
				'donation_number' => (string) ( $data['donation_number'] ?? '' ),
				'donor_email'     => sanitize_email( (string) ( $data['donor_email'] ?? '' ) ),
				'amount'          => (float) ( $data['amount'] ?? 0 ),
				'currency'        => strtoupper( sanitize_text_field( (string) ( $data['currency'] ?? 'USD' ) ) ),
				'gateway'         => sanitize_key( (string) ( $data['gateway'] ?? '' ) ),
			)
		);
	}

	/**
	 * Notify donor/admin when recurring retry attempt fails.
	 *
	 * @param array<string,mixed> $result
	 */
	public function notify_subscription_retry_failed( int $subscription_id, string $gateway, array $result, int $failure_count, string $retry_at ): void {
		$context = $this->subscription_email_context( $subscription_id );
		if ( '' === $context['email'] ) {
			return;
		}

		$notifications = new EmailNotificationService();
		$notifications->send_donor_notification(
			$context['email'],
			'recurring_retry_failed',
			array(
				'subscription_id'     => $subscription_id,
				'subscription_number' => $context['subscription_number'],
				'gateway'             => $gateway,
				'failure_count'       => $failure_count,
				'retry_at'            => $retry_at,
				'message'             => (string) ( $result['message'] ?? '' ),
			)
		);

		$notifications->send_admin_notification(
			'recurring_retry_failed',
			array(
				'subscription_id'     => $subscription_id,
				'subscription_number' => $context['subscription_number'],
				'donor_email'         => $context['email'],
				'gateway'             => $gateway,
				'failure_count'       => $failure_count,
				'retry_at'            => $retry_at,
			)
		);
	}

	/**
	 * Notify donor/admin when recurring retry succeeds.
	 *
	 * @param array<string,mixed> $result
	 */
	public function notify_subscription_retry_succeeded( int $subscription_id, string $gateway, array $result ): void {
		$context = $this->subscription_email_context( $subscription_id );
		if ( '' === $context['email'] ) {
			return;
		}

		$notifications = new EmailNotificationService();
		$notifications->send_donor_notification(
			$context['email'],
			'recurring_retry_succeeded',
			array(
				'subscription_id'     => $subscription_id,
				'subscription_number' => $context['subscription_number'],
				'gateway'             => $gateway,
			)
		);

		$notifications->send_admin_notification(
			'recurring_retry_succeeded',
			array(
				'subscription_id'     => $subscription_id,
				'subscription_number' => $context['subscription_number'],
				'donor_email'         => $context['email'],
				'gateway'             => $gateway,
			)
		);
	}

	/**
	 * Notify donor/admin when recurring subscription expires after retries.
	 */
	public function notify_subscription_retry_exhausted( int $subscription_id, int $failure_count, int $max_retries ): void {
		$context = $this->subscription_email_context( $subscription_id );
		if ( '' === $context['email'] ) {
			return;
		}

		$notifications = new EmailNotificationService();
		$notifications->send_donor_notification(
			$context['email'],
			'recurring_retry_exhausted',
			array(
				'subscription_id'     => $subscription_id,
				'subscription_number' => $context['subscription_number'],
				'failure_count'       => $failure_count,
				'max_retries'         => $max_retries,
			)
		);

		$notifications->send_admin_notification(
			'recurring_retry_exhausted',
			array(
				'subscription_id'     => $subscription_id,
				'subscription_number' => $context['subscription_number'],
				'donor_email'         => $context['email'],
				'failure_count'       => $failure_count,
				'max_retries'         => $max_retries,
			)
		);
	}

	/**
	 * Resolve donor email and subscription number by subscription ID.
	 *
	 * @return array{email:string,subscription_number:string}
	 */
	private function subscription_email_context( int $subscription_id ): array {
		global $wpdb;
		$subscriptions = new SubscriptionRepository( $wpdb );
		$donations     = new DonationRepository( $wpdb );
		$subscription  = $subscriptions->find( $subscription_id );
		if ( ! $subscription ) {
			return array(
				'email'               => '',
				'subscription_number' => '',
			);
		}

		$initial_donation_id = (int) ( $subscription['initial_donation_id'] ?? 0 );
		$donation            = $initial_donation_id > 0 ? $donations->find( $initial_donation_id ) : null;
		$email               = sanitize_email( (string) ( $donation['donor_email'] ?? '' ) );

		return array(
			'email'               => is_email( $email ) ? $email : '',
			'subscription_number' => sanitize_text_field( (string) ( $subscription['subscription_number'] ?? '' ) ),
		);
	}
}
