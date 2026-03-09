<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
@ini_set('error_log', '/dev/null');

use DonatePress\Repositories\DonationRepository;
use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Services\DonationService;
use DonatePress\Services\PaymentService;
use DonatePress\Services\RecurringChargeService;

function dp_perf_p95(array $samples): float {
	sort($samples, SORT_NUMERIC);
	if (empty($samples)) {
		return 0.0;
	}
	$index = (int) ceil(count($samples) * 0.95) - 1;
	$index = max(0, min($index, count($samples) - 1));
	return (float) $samples[$index];
}

function dp_perf_bench(callable $fn, int $runs): float {
	$samples = array();
	for ($i = 0; $i < $runs; $i++) {
		$start = microtime(true);
		$fn();
		$samples[] = (microtime(true) - $start) * 1000;
	}
	return dp_perf_p95($samples);
}

function dp_perf_assert(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, "[FAIL] {$message}\n");
		exit(1);
	}
}

$donationRepo = new class extends DonationRepository {
	public function __construct() {}
	public function insert_pending(array $data): int {
		return 1;
	}
};

$donationService = new DonationService($donationRepo);
$donationCreateP95 = dp_perf_bench(
	static function () use ($donationService): void {
		$donationService->create_pending(
			array(
				'form_id' => 3,
				'amount' => 25,
				'currency' => 'USD',
				'gateway' => 'stripe',
				'email' => 'perf@example.com',
				'first_name' => 'Perf',
				'last_name' => 'Smoke',
				'comment' => '',
				'is_recurring' => false,
				'recurring_frequency' => 'monthly',
			)
		);
	},
	250
);

$subscriptionRepo = new class extends SubscriptionRepository {
	public function __construct() {}
	public function update_status(int $id, string $status): bool { return true; }
	public function mark_payment_success(int $id, ?string $next_payment_at = null): bool { return true; }
	public function increment_failure(int $id): bool { return true; }
	public function find(int $id): ?array {
		return array('id' => $id, 'failure_count' => 0, 'max_retries' => 3);
	}
	public function update_next_payment_at(int $id, ?string $next_payment_at): bool { return true; }
};

$paymentService = new class extends PaymentService {
	public function __construct() {}
	public function retry_subscription_charge(string $gateway_id, array $subscription): array {
		return array('success' => true, 'transaction_id' => 'tx_perf');
	}
};

$retryService = new RecurringChargeService($subscriptionRepo, $paymentService);
$retryFlowP95 = dp_perf_bench(
	static function () use ($retryService): void {
		$retryService->attempt_retry(99, 'stripe', array('frequency' => 'monthly', 'failure_count' => 0, 'max_retries' => 3));
	},
	250
);

printf("Donation create path p95: %.2fms (target < 400ms, gateway excluded)\n", $donationCreateP95);
printf("Recurring retry path p95: %.2fms (service-layer benchmark)\n", $retryFlowP95);

dp_perf_assert($donationCreateP95 < 400, 'Donation create p95 exceeded 400ms.');
dp_perf_assert($retryFlowP95 < 400, 'Recurring retry p95 exceeded 400ms.');

fwrite(STDOUT, "[PASS] Performance smoke tests succeeded.\n");
