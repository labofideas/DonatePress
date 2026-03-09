# DonatePress Testing

## Quick Smoke Gates

Run from plugin root:

```bash
cd wp-content/plugins/donatepress
php tests/smoke-financial.php
php tests/smoke-security.php
php tests/smoke-privacy.php
php tests/smoke-capabilities.php
php tests/smoke-rest-schema.php
php tests/smoke-frontend-assets.php
php tests/smoke-recurring-retry.php
php tests/smoke-webhook-controller.php
php tests/smoke-setup-wizard.php
php tests/smoke-integration.php
php tests/smoke-woocommerce.php
php tests/performance-smoke.php
php tests/performance-db-smoke.php
```

Optional real Woo runtime smoke:

```bash
DONATEPRESS_WC_RUNTIME=1 php tests/smoke-woocommerce-runtime.php
```

## E2E (Playwright)

```bash
cd wp-content/plugins/donatepress
npm run test:e2e
```

Default browser is `firefox` in `tests/e2e/playwright.config.js`.
Override with:

```bash
E2E_BROWSER=chromium npm run test:e2e
```

Real WooCommerce browser runtime is opt-in:

```bash
E2E_MOCK_API=0 \
E2E_WC_UI=1 \
E2E_WP_ADMIN_USER=admin \
E2E_WP_ADMIN_PASSWORD=password \
E2E_BROWSER=chromium \
npm run test:e2e -- --grep "WooCommerce Checkout Runtime"
```

This flow seeds a temporary WooCommerce donation product plus classic cart/checkout pages, places a real browser checkout, marks the order completed in Woo admin, performs a manual refund in Woo admin, and verifies DonatePress donation rows after both transitions.

Real admin UI validation is also available:

```bash
E2E_ADMIN_UI=1 \
E2E_WP_ADMIN_USER=admin \
E2E_WP_ADMIN_PASSWORD=password \
E2E_BROWSER=chromium \
npm run test:e2e -- --grep "Admin Settings Runtime"
```

Real donor portal runtime validation is opt-in:

```bash
E2E_MOCK_API=0 \
E2E_PORTAL_RUNTIME=1 \
E2E_BROWSER=chromium \
npm run test:e2e -- --grep "Portal Runtime"
```
