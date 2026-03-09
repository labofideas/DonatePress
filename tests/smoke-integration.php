<?php

declare(strict_types=1);

namespace {
	require_once __DIR__ . '/bootstrap.php';
	require_once __DIR__ . '/../includes/Services/SettingsService.php';
}

namespace DonatePress\Gateways {
	class FakeGateway {
		public static bool $failNext = false;

		public function verify_webhook(array $headers, string $payload): bool {
			return true;
		}

		public function handle_webhook(array $headers, string $payload): array {
			if (self::$failNext) {
				self::$failNext = false;
				return array(
					'success' => false,
					'code' => 'provider_error',
					'message' => 'Provider temporary error',
				);
			}
			$data = json_decode($payload, true);
			return array(
				'success' => true,
				'event_type' => (string) ($data['type'] ?? ''),
				'message' => 'ok',
			);
		}
	}

	class GatewayManager {
		public function __construct($settings) {}
		public function get(string $gateway_id) {
			return new FakeGateway();
		}
	}
}

namespace DonatePress\Repositories {
	class WebhookEventRepository {
		private static array $locks = array();
		private static array $processed = array();
		public function __construct($db) {}
		public function acquire(string $gateway, string $event_id, string $payload_hash): bool {
			$key = $gateway . '|' . $event_id;
			if (isset(self::$processed[$key]) || isset(self::$locks[$key])) {
				return false;
			}
			self::$locks[$key] = $payload_hash;
			return true;
		}
		public function release(string $gateway, string $event_id): bool {
			$key = $gateway . '|' . $event_id;
			unset(self::$locks[$key]);
			return true;
		}
		public function mark_processed(string $gateway, string $event_id, string $message = ''): bool {
			$key = $gateway . '|' . $event_id;
			unset(self::$locks[$key]);
			self::$processed[$key] = $message;
			return true;
		}
	}
}

namespace {
	use DonatePress\Gateways\FakeGateway;
	use DonatePress\Services\PaymentService;

	function assert_true(bool $condition, string $message): void {
		if (!$condition) {
			fwrite(STDERR, "[FAIL] {$message}\n");
			exit(1);
		}
	}

	$service = new PaymentService();
	$payload = json_encode(array('id' => 'evt_100', 'type' => 'payment_intent.succeeded'));
	assert_true(is_string($payload), 'Payload must be json.');

	$first = $service->handle_webhook('stripe', array(), $payload);
	assert_true(!empty($first['success']), 'First webhook should succeed.');
	assert_true(empty($first['duplicate']), 'First webhook should not be duplicate.');

	$second = $service->handle_webhook('stripe', array(), $payload);
	assert_true(!empty($second['success']), 'Duplicate webhook should still return success.');
	assert_true(!empty($second['duplicate']), 'Duplicate webhook should be marked duplicate.');

	FakeGateway::$failNext = true;
	$payloadFail = json_encode(array('id' => 'evt_101', 'type' => 'payment_intent.payment_failed'));
	assert_true(is_string($payloadFail), 'Fail payload must be json.');

	$failed = $service->handle_webhook('stripe', array(), $payloadFail);
	assert_true(empty($failed['success']), 'Webhook failure path should return success=false.');

	$retry = $service->handle_webhook('stripe', array(), $payloadFail);
	assert_true(!empty($retry['success']), 'Webhook retry after release should process successfully.');
	assert_true(empty($retry['duplicate']), 'Webhook retry after release should not be duplicate.');

	fwrite(STDOUT, "[PASS] Integration smoke tests succeeded.\n");
	exit(0);
}
