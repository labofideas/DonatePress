# DonatePress Performance Evidence

Run from plugin root:

```bash
php tests/performance-smoke.php
php tests/performance-db-smoke.php
```

DB-backed run with optional 100k-row seeding and cleanup:

```bash
DONATEPRESS_DB_SMOKE=1 \
DONATEPRESS_DB_SEED=1 \
DONATEPRESS_DB_TARGET_ROWS=100000 \
DONATEPRESS_DB_CLEANUP=1 \
php tests/performance-db-smoke.php
```

This script records p95 timings for:

- Donation create service path (`DonationService::create_pending`) target `< 400ms` excluding gateway latency
- Recurring retry service path (`RecurringChargeService::attempt_retry`) sanity target `< 400ms`

Notes:

- Results are synthetic local benchmarks and should be attached to release notes as smoke evidence.
- For v1 final sign-off, run dedicated DB-backed load tests for render and dashboard p95 targets.
- `performance-db-smoke.php` is optional and exits with `[SKIP]` when CLI DB access is unavailable.
- Seeding uses `DP-PERF-*` donation numbers so generated rows can be safely cleaned after benchmarking.

Latest captured run evidence is documented in `docs/PERFORMANCE-EVIDENCE.md`.
