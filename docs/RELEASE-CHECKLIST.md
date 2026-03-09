# Release Checklist

## Before Tagging

- Confirm plugin version in [donatepress.php](/Users/shashankdubey/Local%20Sites/kindcart/app/public/wp-content/plugins/donatepress/donatepress.php)
- Confirm no placeholder payment credentials are committed
- Review `git status` for test artifacts or local-only files
- Re-check onboarding pages and shortcodes on a clean install

## Core Verification

- Run `php tests/smoke-frontend-assets.php`
- Run `php tests/smoke-security.php`
- Run `DONATEPRESS_WC_RUNTIME=1 php tests/smoke-woocommerce-runtime.php`
- Run `php tests/performance-db-smoke.php` with your target row count

## Browser Verification

- Run `npm run test:e2e -- --headed --grep 'Admin Settings Runtime'`
- Run `npm run test:e2e -- --headed --grep 'Donation Form|Donor Portal'`
- Run `npm run test:e2e -- --headed --grep 'WooCommerce Checkout Runtime'`
- Run `npm run test:e2e -- --grep 'Security API'`

## Gateway Verification

- Add real Stripe test keys
- Verify one-time donation checkout
- Verify recurring subscription checkout
- Verify webhook delivery
- Verify refund sync
- Add real PayPal sandbox credentials
- Verify PayPal redirect/approval flow

## Final Review

- Check admin screens for obvious layout breaks
- Check donor-facing forms and portal on mobile and desktop
- Confirm receipts, portal access, and reporting data are correct
- Create tag and publish release notes
