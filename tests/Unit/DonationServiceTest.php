<?php

declare(strict_types=1);

use DonatePress\Repositories\DonationRepository;
use DonatePress\Services\DonationService;
use PHPUnit\Framework\TestCase;

final class DonationServiceTest extends TestCase {
	public function test_recurring_frequency_falls_back_to_allowed_value(): void {
		$repository = new class extends DonationRepository {
			public array $inserted = array();
			public function __construct() {}
			public function insert_pending(array $data): int {
				$this->inserted = $data;
				return 101;
			}
		};

		$service = new DonationService($repository);
		$result  = $service->create_pending(
			array(
				'form_id' => 12,
				'amount' => 50,
				'email' => 'donor@example.com',
				'first_name' => 'Ada',
				'last_name' => 'Lovelace',
				'gateway' => 'stripe',
				'is_recurring' => true,
				'recurring_frequency' => 'weekly',
			)
		);

		$this->assertTrue($result['success']);
		$this->assertSame('monthly', $result['donation']['recurring_frequency']);
		$this->assertSame('monthly', $repository->inserted['recurring_frequency']);
	}

	public function test_non_recurring_clears_frequency(): void {
		$repository = new class extends DonationRepository {
			public array $inserted = array();
			public function __construct() {}
			public function insert_pending(array $data): int {
				$this->inserted = $data;
				return 102;
			}
		};

		$service = new DonationService($repository);
		$result  = $service->create_pending(
			array(
				'form_id' => 20,
				'amount' => 20,
				'email' => 'donor@example.com',
				'first_name' => 'Grace',
				'gateway' => 'paypal',
				'is_recurring' => false,
				'recurring_frequency' => 'annual',
			)
		);

		$this->assertTrue($result['success']);
		$this->assertSame('', $result['donation']['recurring_frequency']);
		$this->assertSame('', $repository->inserted['recurring_frequency']);
	}
}
