#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

run() {
  echo
  echo "==> $*"
  "$@"
}

echo "DonatePress v1 gate runner"
echo "Plugin root: $ROOT_DIR"

run php tests/smoke-security.php
run php tests/smoke-capabilities.php
run php tests/smoke-rest-schema.php
run php tests/smoke-frontend-assets.php
run php tests/smoke-recurring-retry.php
run php tests/smoke-webhook-controller.php
run php tests/smoke-setup-wizard.php
run php tests/smoke-privacy.php
run php tests/smoke-financial.php
run php tests/smoke-integration.php
run php tests/smoke-woocommerce.php
run php tests/performance-smoke.php

if [[ "${RUN_DB_PERF:-0}" == "1" ]]; then
  echo
  echo "RUN_DB_PERF=1 detected; executing DB-backed benchmark."
  run env \
    DONATEPRESS_DB_SMOKE=1 \
    DONATEPRESS_DB_SEED="${DONATEPRESS_DB_SEED:-1}" \
    DONATEPRESS_DB_TARGET_ROWS="${DONATEPRESS_DB_TARGET_ROWS:-100000}" \
    DONATEPRESS_DB_CLEANUP="${DONATEPRESS_DB_CLEANUP:-1}" \
    php tests/performance-db-smoke.php
else
  echo
  echo "Skipping DB-backed benchmark (set RUN_DB_PERF=1 to enable)."
fi

if [[ "${RUN_E2E:-1}" == "1" ]]; then
  run env E2E_BROWSER="${E2E_BROWSER:-chromium}" npm run test:e2e -- --grep "Donation Form|Donor Portal"

  if [[ "${RUN_REAL_SECURITY_E2E:-0}" == "1" ]]; then
    echo
    echo "RUN_REAL_SECURITY_E2E=1 detected; executing real-endpoint security checks."
    run env E2E_BROWSER="${E2E_BROWSER:-chromium}" E2E_MOCK_API=0 npm run test:e2e -- --grep "Security API"
  else
    echo
    echo "Skipping real-endpoint Security API E2E (set RUN_REAL_SECURITY_E2E=1 to enable)."
  fi

  if [[ "${RUN_WC_UI_E2E:-0}" == "1" ]]; then
    echo
    echo "RUN_WC_UI_E2E=1 detected; executing real WooCommerce checkout/refund UI E2E."
    run env \
      E2E_BROWSER="${E2E_BROWSER:-chromium}" \
      E2E_MOCK_API=0 \
      E2E_WC_UI=1 \
      E2E_WP_ADMIN_USER="${E2E_WP_ADMIN_USER:-}" \
      E2E_WP_ADMIN_PASSWORD="${E2E_WP_ADMIN_PASSWORD:-}" \
      npm run test:e2e -- --grep "WooCommerce Checkout Runtime"
  else
    echo
    echo "Skipping WooCommerce checkout/refund UI E2E (set RUN_WC_UI_E2E=1 to enable)."
  fi

  if [[ "${RUN_ADMIN_UI_E2E:-0}" == "1" ]]; then
    echo
    echo "RUN_ADMIN_UI_E2E=1 detected; executing real admin settings/forms/setup E2E."
    run env \
      E2E_BROWSER="${E2E_BROWSER:-chromium}" \
      E2E_ADMIN_UI=1 \
      E2E_WP_ADMIN_USER="${E2E_WP_ADMIN_USER:-}" \
      E2E_WP_ADMIN_PASSWORD="${E2E_WP_ADMIN_PASSWORD:-}" \
      npm run test:e2e -- --grep "Admin Settings Runtime"
  else
    echo
    echo "Skipping admin settings/forms/setup E2E (set RUN_ADMIN_UI_E2E=1 to enable)."
  fi

  if [[ "${RUN_PORTAL_RUNTIME_E2E:-0}" == "1" ]]; then
    echo
    echo "RUN_PORTAL_RUNTIME_E2E=1 detected; executing real donor portal runtime E2E."
    run env \
      E2E_BROWSER="${E2E_BROWSER:-chromium}" \
      E2E_MOCK_API=0 \
      E2E_PORTAL_RUNTIME=1 \
      npm run test:e2e -- --grep "Portal Runtime"
  else
    echo
    echo "Skipping donor portal runtime E2E (set RUN_PORTAL_RUNTIME_E2E=1 to enable)."
  fi
else
  echo
  echo "Skipping Playwright E2E (set RUN_E2E=1 to enable)."
fi

echo
echo "[PASS] DonatePress v1 gate completed."
