<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/Services/RecurringRetryService.php';

use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Services\PaymentService;
use DonatePress\Services\RecurringChargeService;
use DonatePress\Services\RecurringRetryService;

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		fwrite(STDERR, "[FAIL] {$message}\n");
		exit(1);
	}
}

// 1) Retry failure should schedule next retry and keep failed status when retries remain.
$repoFailing = new class extends SubscriptionRepository {
	public array $statuses = array();
	public ?string $next = null;
	public int $failureCount = 0;
	public function __construct() {}
	public function update_status(int $id, string $status): bool { $this->statuses[] = $status; return true; }
	public function mark_payment_success(int $id, ?string $next_payment_at = null): bool { return true; }
	public function increment_failure(int $id): bool { $this->failureCount++; return true; }
	public function find(int $id): ?array {
		return array(
			'id' => $id,
			'failure_count' => $this->failureCount,
			'max_retries' => 3,
		);
	}
	public function update_next_payment_at(int $id, ?string $next_payment_at): bool { $this->next = $next_payment_at; return true; }
};

$paymentFailing = new class extends PaymentService {
	public function __construct() {}
	public function retry_subscription_charge(string $gateway_id, array $subscription): array {
		return array(
			'success' => false,
			'code' => 'charge_declined',
			'message' => 'Card declined',
		);
	}
};

$chargeServiceFailing = new RecurringChargeService($repoFailing, $paymentFailing);
$failedResult = $chargeServiceFailing->attempt_retry(
	1001,
	'stripe',
	array(
		'frequency' => 'monthly',
		'failure_count' => 0,
		'max_retries' => 3,
	)
);
assert_true($failedResult['success'] === false, 'Failed retry should return success=false.');
assert_true(($failedResult['status'] ?? '') === 'failed', 'Failed retry should keep status failed.');
assert_true(!empty($repoFailing->next), 'Failed retry should set next retry time.');

// 2) Retry failure should expire subscription when max retries is reached.
$repoExhausted = new class extends SubscriptionRepository {
	public array $statuses = array();
	public ?string $next = 'placeholder';
	public int $failureCount = 2;
	public function __construct() {}
	public function update_status(int $id, string $status): bool { $this->statuses[] = $status; return true; }
	public function mark_payment_success(int $id, ?string $next_payment_at = null): bool { return true; }
	public function increment_failure(int $id): bool { $this->failureCount++; return true; }
	public function find(int $id): ?array {
		return array(
			'id' => $id,
			'failure_count' => $this->failureCount,
			'max_retries' => 3,
		);
	}
	public function update_next_payment_at(int $id, ?string $next_payment_at): bool { $this->next = $next_payment_at; return true; }
};

$chargeServiceExhausted = new RecurringChargeService($repoExhausted, $paymentFailing);
$expiredResult = $chargeServiceExhausted->attempt_retry(
	1002,
	'stripe',
	array(
		'frequency' => 'monthly',
		'failure_count' => 2,
		'max_retries' => 3,
	)
);
assert_true($expiredResult['success'] === false, 'Exhausted retry should return success=false.');
assert_true(($expiredResult['status'] ?? '') === 'expired', 'Exhausted retry should mark subscription expired.');
assert_true($repoExhausted->next === null, 'Expired subscription should clear next payment datetime.');
assert_true(in_array('expired', $repoExhausted->statuses, true), 'Expired status transition should be recorded.');

// 3) Retry queue processor should dispatch pending and expire exhausted subscriptions.
$retryRepo = new class extends SubscriptionRepository {
	public array $statuses = array();
	public array $next = array();
	public function __construct() {}
	public function list_retry_due(int $limit = 50): array {
		return array(
			array('id' => 2001, 'gateway' => 'stripe', 'failure_count' => 0, 'max_retries' => 3, 'next_payment_at' => gmdate('Y-m-d H:i:s', time() - 60), 'status' => 'failed'),
			array('id' => 2002, 'gateway' => 'paypal', 'failure_count' => 3, 'max_retries' => 3, 'next_payment_at' => gmdate('Y-m-d H:i:s', time() - 60), 'status' => 'failed'),
		);
	}
	public function update_status(int $id, string $status): bool { $this->statuses[$id] = $status; return true; }
	public function update_next_payment_at(int $id, ?string $next_payment_at): bool { $this->next[$id] = $next_payment_at; return true; }
};

$retryService = new RecurringRetryService($retryRepo);
$queueResult = $retryService->process_due_retries();
assert_true((int) ($queueResult['processed'] ?? 0) === 2, 'Retry queue should process two due subscriptions.');
assert_true((int) ($queueResult['dispatched'] ?? 0) === 1, 'Retry queue should dispatch one subscription.');
assert_true((int) ($queueResult['expired'] ?? 0) === 1, 'Retry queue should expire one subscription.');
assert_true(($retryRepo->statuses[2001] ?? '') === 'pending', 'Dispatch path should mark subscription pending.');
assert_true(($retryRepo->statuses[2002] ?? '') === 'expired', 'Exhausted path should mark subscription expired.');
assert_true(array_key_exists(2002, $retryRepo->next) && $retryRepo->next[2002] === null, 'Expired queue item should clear next payment.');

fwrite(STDOUT, "[PASS] Recurring retry smoke tests succeeded.\n");
exit(0);
