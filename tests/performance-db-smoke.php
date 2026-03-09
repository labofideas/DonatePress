<?php
/**
 * Optional DB-backed performance smoke.
 *
 * Run: php tests/performance-db-smoke.php
 * If WordPress DB is unavailable in CLI context, the script exits with [SKIP].
 *
 * Optional env vars:
 * - DONATEPRESS_DB_SMOKE=1            Enable script.
 * - DONATEPRESS_DB_SEED=1             Seed PERF rows up to target before benchmarking.
 * - DONATEPRESS_DB_TARGET_ROWS=100000 Target row count when seeding.
 * - DONATEPRESS_DB_CLEANUP=1          Delete seeded PERF rows after benchmark.
 */

declare(strict_types=1);

if (getenv('DONATEPRESS_DB_SMOKE') !== '1') {
	fwrite(STDOUT, "[SKIP] Set DONATEPRESS_DB_SMOKE=1 to run DB-backed performance smoke.\n");
	exit(0);
}

$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (!file_exists($wp_load)) {
	fwrite(STDOUT, "[SKIP] wp-load.php not found for DB-backed performance smoke.\n");
	exit(0);
}

require_once $wp_load;

if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
	fwrite(STDOUT, "[SKIP] wpdb is not available.\n");
	exit(0);
}

global $wpdb;
if (empty($wpdb->dbh)) {
	fwrite(STDOUT, "[SKIP] DB connection is unavailable in CLI context.\n");
	exit(0);
}

function dp_db_p95(array $samples): float {
	sort($samples, SORT_NUMERIC);
	if (empty($samples)) return 0;
	$i = (int) ceil(count($samples) * 0.95) - 1;
	$i = max(0, min($i, count($samples) - 1));
	return (float) $samples[$i];
}

function dp_db_bench(callable $fn, int $runs): float {
	$samples = array();
	for ($i = 0; $i < $runs; $i++) {
		$start = microtime(true);
		$fn();
		$samples[] = (microtime(true) - $start) * 1000;
	}
	return dp_db_p95($samples);
}

$donationsTable = $wpdb->prefix . 'dp_donations';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $donationsTable)) !== $donationsTable) {
	fwrite(STDOUT, "[SKIP] DonatePress tables are not available.\n");
	exit(0);
}

$seedEnabled = getenv('DONATEPRESS_DB_SEED') === '1';
$targetRows = (int) (getenv('DONATEPRESS_DB_TARGET_ROWS') ?: 100000);
$targetRows = max(1000, $targetRows);
$cleanup = getenv('DONATEPRESS_DB_CLEANUP') === '1';
$seedPrefix = 'DP-PERF-';

if ($seedEnabled) {
	$currentRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$donationsTable}");
	$missingRows = max(0, $targetRows - $currentRows);
	if ($missingRows > 0) {
		$currency = 'USD';
		$gateway = 'stripe';
		$status = 'completed';
		$start = microtime(true);

		$insertedRows = 0;
		$failedRows = 0;
		$firstInsertError = '';
		for ($seq = 1; $seq <= $missingRows; $seq++) {
			$now = gmdate('Y-m-d H:i:s');
			$result = $wpdb->insert(
				$donationsTable,
				array(
					'donation_number' => $seedPrefix . (string) $seq,
					'donor_email' => 'perf+' . (string) $seq . '@example.org',
					'donor_first_name' => 'Perf',
					'donor_last_name' => 'Donor',
					'amount' => 25.00,
					'currency' => $currency,
					'gateway' => $gateway,
					'gateway_transaction_id' => 'tx_perf_' . (string) $seq,
					'is_recurring' => 1,
					'recurring_frequency' => 'monthly',
					'status' => $status,
					'form_id' => 1,
					'campaign_id' => null,
					'created_at' => $now,
					'updated_at' => $now,
					'donated_at' => $now,
				),
				array(
					'%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s',
					'%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s',
				)
			);

			if ($result === false) {
				$failedRows++;
				if ($firstInsertError === '') {
					$nativeError = '';
					if (isset($wpdb->dbh) && is_object($wpdb->dbh) && isset($wpdb->dbh->error)) {
						$nativeError = (string) $wpdb->dbh->error;
					}
					$firstInsertError = (string) ($wpdb->last_error ?? '');
					if ($firstInsertError === '' && $nativeError !== '') {
						$firstInsertError = $nativeError;
					}
					if ($firstInsertError === '') {
						$firstInsertError = 'unknown DB error';
					}
				}
				continue;
			}

			$insertedRows++;
		}

		$seedSeconds = microtime(true) - $start;
		$finalRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$donationsTable}");
		printf(
			"Seed attempt requested %d rows; inserted %d rows in %.2fs (failed rows: %d). Row count: %d -> %d.\n",
			$missingRows,
			$insertedRows,
			$seedSeconds,
			$failedRows,
			$currentRows,
			$finalRows
		);
		if ($failedRows > 0) {
			printf("First failed row insert DB error: %s\n", $firstInsertError);
		}
	} else {
		printf("Current rows (%d) already meet/exceed target (%d); no seeding needed.\n", $currentRows, $targetRows);
	}
}

$summaryP95 = dp_db_bench(
	static function () use ($wpdb, $donationsTable): void {
		$wpdb->get_var("SELECT COUNT(*) FROM {$donationsTable}");
		$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$donationsTable} WHERE status = %s", 'completed'));
		$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$donationsTable} WHERE status = %s", 'pending'));
	},
	40
);

printf("DB dashboard query bundle p95: %.2fms (target < 800ms at scale)\n", $summaryP95);

if ($cleanup) {
	$deleted = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$donationsTable} WHERE donation_number LIKE %s",
			$wpdb->esc_like($seedPrefix) . '%'
		)
	);
	$remainingPerfRows = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$donationsTable} WHERE donation_number LIKE %s",
			$wpdb->esc_like($seedPrefix) . '%'
		)
	);
	printf("Cleanup removed %d PERF rows. Remaining PERF rows: %d.\n", $deleted, $remainingPerfRows);
}

fwrite(STDOUT, "[PASS] DB-backed performance smoke executed.\n");
exit(0);
