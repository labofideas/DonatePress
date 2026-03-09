<?php
declare(strict_types=1);

use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\DonorRepository;
use DonatePress\Repositories\FormRepository;
use DonatePress\Repositories\PortalTokenRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Services\PortalService;
use DonatePress\Services\SetupWizardService;

function dp_portal_json_exit(array $payload, int $code = 0): void {
	fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
	exit($code);
}

function dp_portal_fail(string $message, int $code = 1): void {
	fwrite(STDERR, $message . PHP_EOL);
	exit($code);
}

function dp_portal_bootstrap(): void {
	$wp_load = dirname(__DIR__, 6) . '/wp-load.php';
	if (!file_exists($wp_load)) {
		dp_portal_fail('wp-load.php not found.');
	}

	require_once $wp_load;
}

function dp_portal_action(): string {
	global $argv;
	return isset($argv[1]) ? sanitize_key((string) $argv[1]) : '';
}

/**
 * @return array<int,string>
 */
function dp_portal_args(): array {
	global $argv;
	return array_slice($argv, 2);
}

function dp_portal_cleanup_email(string $email): void {
	global $wpdb;
	if (!($wpdb instanceof wpdb)) {
		return;
	}

	$subscription_repository = new SubscriptionRepository($wpdb);
	$subscriptions           = $subscription_repository->list_by_email($email, 50);
	foreach ($subscriptions as $subscription) {
		$wpdb->delete($wpdb->prefix . 'dp_subscriptions', array('id' => (int) $subscription['id']), array('%d'));
	}

	$donation_repository = new DonationRepository($wpdb);
	$donations           = $donation_repository->list_by_email($email, 100);
	foreach ($donations as $donation) {
		$wpdb->delete($wpdb->prefix . 'dp_donations', array('id' => (int) $donation['id']), array('%d'));
	}

	$token_repository = new PortalTokenRepository($wpdb);
	$token_repository->delete_by_email($email);

	$wpdb->delete($wpdb->prefix . 'dp_donors', array('email' => $email), array('%s'));
}

function dp_portal_seed(): void {
	global $wpdb;
	if (!($wpdb instanceof wpdb)) {
		dp_portal_fail('Database unavailable.');
	}

	$wizard        = new SetupWizardService();
	$first_form    = $wizard->ensure_first_form();
	$form_id       = !empty($first_form['item']['id']) ? (int) $first_form['item']['id'] : 1;
	$form_repo     = new FormRepository($wpdb);
	$form          = $form_repo->find($form_id);
	if (!$form) {
		$form = $form_repo->find_first_active();
	}
	if (!$form) {
		dp_portal_fail('No active form available for portal seed.');
	}
	$form_id = (int) $form['id'];

	$email    = 'portal-e2e-' . wp_generate_password(8, false, false) . '@example.com';
	$currency = strtoupper((string) ($form['currency'] ?? 'USD'));
	$gateway  = sanitize_key((string) ($form['gateway'] ?? 'stripe'));

	dp_portal_cleanup_email($email);

	$donor_repository    = new DonorRepository($wpdb);
	$donor_id            = $donor_repository->find_or_create($email, 'Portal', 'Runtime');
	$donation_repository = new DonationRepository($wpdb);
	$donation_id         = $donation_repository->insert_pending(
		array(
			'donation_number'        => 'DP-PORTAL-' . wp_generate_password(6, false, false),
			'donor_id'               => $donor_id,
			'donor_email'            => $email,
			'donor_first_name'       => 'Portal',
			'donor_last_name'        => 'Runtime',
			'amount'                 => 42.00,
			'currency'               => $currency,
			'gateway'                => $gateway,
			'gateway_transaction_id' => 'portal_runtime_' . wp_generate_password(10, false, false),
			'form_id'                => $form_id,
			'donor_comment'          => 'Portal runtime seed',
			'is_recurring'           => false,
			'recurring_frequency'    => '',
		)
	);
	if ($donation_id <= 0) {
		dp_portal_fail('Could not create portal seed donation.');
	}
	$donation_repository->update_status($donation_id, 'completed');

	$subscription_repository = new SubscriptionRepository($wpdb);
	$subscription_id         = $subscription_repository->insert(
		array(
			'subscription_number'     => 'DPS-PORTAL-' . wp_generate_password(6, false, false),
			'initial_donation_id'     => $donation_id,
			'donor_id'                => $donor_id,
			'form_id'                 => $form_id,
			'gateway'                 => $gateway,
			'gateway_subscription_id' => 'portal_sub_' . wp_generate_password(10, false, false),
			'amount'                  => 21.00,
			'currency'                => $currency,
			'frequency'               => 'monthly',
			'status'                  => 'active',
		)
	);
	if ($subscription_id <= 0) {
		dp_portal_fail('Could not create portal seed subscription.');
	}

	dp_portal_json_exit(
		array(
			'ok'             => true,
			'email'          => $email,
			'formId'         => $form_id,
			'donationId'     => $donation_id,
			'subscriptionId' => $subscription_id,
			'portalUrl'      => home_url('/donor-portal/'),
		)
	);
}

function dp_portal_magic(string $email): void {
	global $wpdb;
	if (!($wpdb instanceof wpdb)) {
		dp_portal_fail('Database unavailable.');
	}

	$service = new PortalService(new PortalTokenRepository($wpdb));
	$result  = $service->create_magic_link($email, '127.0.0.1', 'Codex Portal Runtime');
	if (empty($result['success'])) {
		dp_portal_fail((string) ($result['message'] ?? 'Could not create magic link.'));
	}

	$magic_url = add_query_arg('dp_token', rawurlencode((string) $result['token']), home_url('/donor-portal/'));

	dp_portal_json_exit(
		array(
			'ok'       => true,
			'email'    => $email,
			'token'    => (string) $result['token'],
			'magicUrl' => $magic_url,
		)
	);
}

function dp_portal_cleanup(string $email): void {
	dp_portal_cleanup_email($email);
	dp_portal_json_exit(array('ok' => true, 'cleaned' => true, 'email' => $email));
}

dp_portal_bootstrap();
$action = dp_portal_action();
$args   = dp_portal_args();

switch ($action) {
	case 'seed':
		dp_portal_seed();
		break;
	case 'magic':
		dp_portal_magic((string) ($args[0] ?? ''));
		break;
	case 'cleanup':
		dp_portal_cleanup((string) ($args[0] ?? ''));
		break;
	default:
		dp_portal_fail('Unknown action. Use seed, magic, or cleanup.');
}
