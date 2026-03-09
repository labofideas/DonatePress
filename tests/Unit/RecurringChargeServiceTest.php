<?php

declare(strict_types=1);

use DonatePress\Repositories\SubscriptionRepository;
use DonatePress\Services\PaymentService;
use DonatePress\Services\RecurringChargeService;
use PHPUnit\Framework\TestCase;

final class RecurringChargeServiceTest extends TestCase {
	public function test_retry_success_sets_active_and_next_cycle(): void {
		$repository = new class extends SubscriptionRepository {
			public array $statuses = array();
			public ?string $nextPaymentAt = null;
			public function __construct() {}
			public function update_status(int $id, string $status): bool {
				$this->statuses[] = $status;
				return true;
			}
			public function mark_payment_success(int $id, ?string $next_payment_at = null): bool {
				$this->nextPaymentAt = $next_payment_at;
				return true;
			}
		};

		$payment = new class extends PaymentService {
			public function __construct() {}
			public function retry_subscription_charge(string $gateway_id, array $subscription): array {
				return array('success' => true, 'transaction_id' => 'tx_123');
			}
		};

		$service = new RecurringChargeService($repository, $payment);
		$result  = $service->attempt_retry(
			9,
			'stripe',
			array('frequency' => 'monthly', 'max_retries' => 3, 'failure_count' => 0)
		);

		$this->assertTrue($result['success']);
		$this->assertSame('active', $result['status']);
		$this->assertContains('active', $repository->statuses);
		$this->assertNotEmpty($repository->nextPaymentAt);
	}

	public function test_retry_failure_reschedules_when_retries_remain(): void {
		$repository = new class extends SubscriptionRepository {
			public array $statuses = array();
			public ?string $nextPaymentAt = null;
			public function __construct() {}
			public function update_status(int $id, string $status): bool {
				$this->statuses[] = $status;
				return true;
			}
			public function increment_failure(int $id): bool { return true; }
			public function find(int $id): ?array {
				return array('failure_count' => 1, 'max_retries' => 3);
			}
			public function update_next_payment_at(int $id, ?string $next_payment_at): bool {
				$this->nextPaymentAt = $next_payment_at;
				return true;
			}
		};

		$payment = new class extends PaymentService {
			public function __construct() {}
			public function retry_subscription_charge(string $gateway_id, array $subscription): array {
				return array('success' => false, 'code' => 'declined', 'message' => 'Declined');
			}
		};

		$service = new RecurringChargeService($repository, $payment);
		$result  = $service->attempt_retry(9, 'stripe', array('frequency' => 'monthly'));

		$this->assertFalse($result['success']);
		$this->assertSame('failed', $result['status']);
		$this->assertContains('failed', $repository->statuses);
		$this->assertNotEmpty($repository->nextPaymentAt);
	}

	public function test_retry_failure_expires_when_retries_exhausted(): void {
		$repository = new class extends SubscriptionRepository {
			public array $statuses = array();
			public ?string $nextPaymentAt = 'placeholder';
			public function __construct() {}
			public function update_status(int $id, string $status): bool {
				$this->statuses[] = $status;
				return true;
			}
			public function increment_failure(int $id): bool { return true; }
			public function find(int $id): ?array {
				return array('failure_count' => 3, 'max_retries' => 3);
			}
			public function update_next_payment_at(int $id, ?string $next_payment_at): bool {
				$this->nextPaymentAt = $next_payment_at;
				return true;
			}
		};

		$payment = new class extends PaymentService {
			public function __construct() {}
			public function retry_subscription_charge(string $gateway_id, array $subscription): array {
				return array('success' => false, 'code' => 'declined', 'message' => 'Declined');
			}
		};

		$service = new RecurringChargeService($repository, $payment);
		$result  = $service->attempt_retry(9, 'stripe', array('frequency' => 'monthly'));

		$this->assertFalse($result['success']);
		$this->assertSame('expired', $result['status']);
		$this->assertContains('expired', $repository->statuses);
		$this->assertNull($repository->nextPaymentAt);
	}
}
