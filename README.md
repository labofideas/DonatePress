# DonatePress

Performance-first donation plugin for WordPress with Stripe, PayPal, recurring giving, donor portal access, reporting, and WooCommerce sync.

## Highlights

- Donation forms via shortcode and block support
- Campaign pages with embedded giving flows
- Donor portal with secure email-link access
- Recurring donations and subscription management
- Stripe and PayPal gateway support
- WooCommerce order-to-donation sync with refund handling
- Reporting, audit logging, privacy tooling, and setup wizard
- Demo import for first-run onboarding

## Requirements

- WordPress `6.4+`
- PHP `8.0+`

## Installation

1. Copy the `donatepress` plugin folder into `wp-content/plugins/`.
2. Activate `DonatePress` in WordPress admin.
3. Open `DonatePress` in the admin menu.
4. Run the setup wizard or `Demo Import`.
5. Configure organization details and payment gateways.

## Shortcodes

- `[donatepress_form id="1"]`
- `[donatepress_campaign id="1" form="1"]`
- `[donatepress_portal]`

## First-Time Setup

The fastest path for a fresh install:

1. Activate the plugin.
2. Open `DonatePress > Demo Import`.
3. Import starter content and menu links.
4. Open the generated demo pages and replace the content with your organization details.
5. Add real Stripe or PayPal credentials before accepting payments.

## Development

Install Playwright dependencies:

```bash
npm install
```

Key test commands:

```bash
php tests/smoke-frontend-assets.php
php tests/smoke-security.php
DONATEPRESS_WC_RUNTIME=1 php tests/smoke-woocommerce-runtime.php
npm run test:e2e -- --grep 'Donation Form|Donor Portal'
```

Headed local browser runs:

```bash
npm run test:e2e -- --headed --grep 'Admin Settings Runtime'
npm run test:e2e -- --headed --grep 'WooCommerce Checkout Runtime'
```

## Release Notes

The repository includes:

- runtime smoke coverage
- Playwright E2E coverage
- WooCommerce checkout/refund runtime validation
- admin CRUD for forms and campaigns
- demo onboarding flow for first installs

## Important

Do not use placeholder gateway keys in production. Configure real Stripe or PayPal credentials in DonatePress settings before enabling live payments.
