<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Services\DonationService;
use DonatePress\Services\PaymentService;
use DonatePress\Services\RecurringChargeService;

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, "[FAIL] {$message}\n");
		exit(1);
	}
}

// 1) DonationService recurring frequency fallback.
$repo = new class extends DonationRepository {
	public array $inserted = array();
	public function __construct() {}
	public function insert_pending(array $data): int {
		$this->inserted = $data;
		return 1;
	}
};
$service = new DonationService($repo);
$result  = $service->create_pending(
	array(
		'form_id' => 3,
		'amount' => 25,
		'email' => 'donor@example.com',
		'first_name' => 'Donor',
		'gateway' => 'stripe',
		'is_recurring' => true,
		'recurring_frequency' => 'weekly',
	)
);
assert_true($result['success'] === true, 'Donation creation should succeed.');
assert_true($result['donation']['recurring_frequency'] === 'monthly', 'Invalid recurring frequency should fallback to monthly.');

// 2) RecurringChargeService success transition.
$subscriptionRepo = new class extends SubscriptionRepository {
	public array $statuses = array();
	public ?string $next = null;
	public function __construct() {}
	public function update_status(int $id, string $status): bool { $this->statuses[] = $status; return true; }
	public function mark_payment_success(int $id, ?string $next_payment_at = null): bool { $this->next = $next_payment_at; return true; }
};
$payment = new class extends PaymentService {
	public function __construct() {}
	public function retry_subscription_charge(string $gateway_id, array $subscription): array {
		return array('success' => true, 'transaction_id' => 'tx_ok');
	}
};
$retryService = new RecurringChargeService($subscriptionRepo, $payment);
$retryResult  = $retryService->attempt_retry(11, 'stripe', array('frequency' => 'monthly', 'max_retries' => 3, 'failure_count' => 0));
assert_true($retryResult['success'] === true, 'Retry should succeed.');
assert_true(in_array('active', $subscriptionRepo->statuses, true), 'Retry success should set active status.');
assert_true(!empty($subscriptionRepo->next), 'Retry success should set next payment date.');

fwrite(STDOUT, "[PASS] Financial smoke tests succeeded.\n");
exit(0);
