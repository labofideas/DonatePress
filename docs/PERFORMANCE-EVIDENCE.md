# DonatePress v1 Performance Evidence

## Latest DB-Backed 100k Run

- Date: March 6, 2026
- Command:

```bash
DONATEPRESS_DB_SMOKE=1 \
DONATEPRESS_DB_SEED=1 \
DONATEPRESS_DB_TARGET_ROWS=100000 \
DONATEPRESS_DB_CLEANUP=1 \
php tests/performance-db-smoke.php
```

- Output summary:
  - `Seed attempt requested 99964 rows; inserted 99964 rows in 144.52s (failed rows: 0). Row count: 36 -> 100000.`
  - `DB dashboard query bundle p95: 275.48ms (target < 800ms at scale)`
  - `Cleanup removed 99964 PERF rows. Remaining PERF rows: 0.`

## Synthetic Service-Layer Smoke

- Command:

```bash
php tests/performance-smoke.php
```

- Output summary:
  - Donation create path p95: `0.03ms` (target `< 400ms`, gateway excluded)
  - Recurring retry path p95: `0.18ms`
