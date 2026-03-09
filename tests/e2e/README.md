# DonatePress Playwright E2E

## Setup

```bash
cd wp-content/plugins/donatepress
npm install
npx playwright install
```

## Environment

Copy `.env.example` values into your shell or export directly:

```bash
export E2E_BASE_URL=http://kindcart.local
export E2E_BROWSER=firefox
export E2E_DONATION_PAGE_PATH=/donate/
export E2E_PORTAL_PAGE_PATH=/donor-portal/
export E2E_MOCK_API=1
```

`E2E_MOCK_API=1` runs deterministic browser flows with mocked API responses.

`E2E_MOCK_API=0` disables mocks and hits real DonatePress REST endpoints.

`E2E_WC_UI=1` enables the real WooCommerce checkout/refund browser flow. This stays opt-in because it needs a working local WordPress + DB runtime.

`E2E_ADMIN_UI=1` enables real WordPress admin settings/forms/setup validation.

`E2E_PORTAL_RUNTIME=1` enables the real donor portal magic-link/dashboard runtime flow.

WooCommerce UI runtime also requires:

```bash
export E2E_WP_ADMIN_USER=admin
export E2E_WP_ADMIN_PASSWORD=password
```

Run only real-endpoint security checks:

```bash
E2E_MOCK_API=0 E2E_BROWSER=chromium npm run test:e2e -- --grep "Security API"
```

Run the real WooCommerce checkout, admin completion, and refund flow:

```bash
E2E_MOCK_API=0 E2E_WC_UI=1 E2E_BROWSER=chromium npm run test:e2e -- --grep "WooCommerce Checkout Runtime"
```

Run the real admin settings/forms/setup validation:

```bash
E2E_ADMIN_UI=1 E2E_WP_ADMIN_USER=admin E2E_WP_ADMIN_PASSWORD=password E2E_BROWSER=chromium npm run test:e2e -- --grep "Admin Settings Runtime"
```

Run the real donor portal runtime flow:

```bash
E2E_MOCK_API=0 E2E_PORTAL_RUNTIME=1 E2E_BROWSER=chromium npm run test:e2e -- --grep "Portal Runtime"
```

The runtime helper will:

- create temporary classic WooCommerce cart and checkout pages
- create a temporary hidden DonatePress donation product
- enable guest checkout and offline gateways for the test
- inspect DonatePress donation rows for the created Woo order
- restore options and delete fixture content during cleanup

## Run

```bash
npm run test:e2e
```

Headed mode:

```bash
npm run test:e2e:headed
```

## Specs Included

- `specs/donation-form.spec.js`
  - donation form render and submit success flow
  - recurring payment failure message flow (mocked)
- `specs/portal-flow.spec.js`
  - portal request/auth/dashboard, subscription action, logout
- `specs/security-api.spec.js`
  - real API nonce/signature rejection checks (`E2E_MOCK_API=0`)
- `specs/woocommerce-checkout-refund.spec.js`
  - real WooCommerce guest checkout
  - admin order completion to trigger DonatePress sync
  - admin refund UI to verify DonatePress refund sync
- `specs/admin-settings-runtime.spec.js`
  - real admin settings save
  - forms list shortcode visibility
  - setup wizard load
- `specs/portal-runtime.spec.js`
  - real portal request-link and token auth
  - dashboard/profile/subscription/logout flow
