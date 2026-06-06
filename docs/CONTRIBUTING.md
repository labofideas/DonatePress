# Contributing to DonatePress

## Branch Workflow

All work happens on feature branches off the current version branch. **Never develop on `main`.**

```
main (production)
 └── v0.2.0 (current version branch)
      ├── feature/recurring-emails
      ├── fix/stripe-webhook-dedup
      └── refactor/repository-base
```

1. Create feature branch off the version branch: `git checkout -b feature/my-feature v0.2.0`
2. Make changes, commit, push
3. PR into the version branch (never directly into `main`)
4. Version branch merges into `main` at release

## Pre-Commit Checks

Run all smoke tests before committing:

```bash
# Core smoke tests
php tests/smoke-security.php
php tests/smoke-financial.php
php tests/smoke-frontend-assets.php
php tests/smoke-rest-schema.php

# PHP syntax check on changed files
find includes/ -name "*.php" -exec php -l {} \;
```

All must pass with zero errors before committing.

## Code Patterns

### Repositories (`includes/Repositories/`)

All data access goes through repository classes. No raw `$wpdb` queries outside repositories.

```php
// Good — use repository
$repo = new DonationRepository( $wpdb );
$donation = $repo->find( $id );

// Bad — raw query in a controller or service
$wpdb->get_row( "SELECT * FROM {$wpdb->prefix}dp_donations WHERE id = $id" );
```

### Services (`includes/Services/`)

Business logic lives in service classes. Controllers validate and delegate — they never contain business rules.

```php
// Good — controller delegates to service
public function create_item( $request ) {
    $data = $this->validate( $request );
    $result = $this->donation_service->create_pending( $data );
    return rest_ensure_response( $result );
}

// Bad — business logic in controller
public function create_item( $request ) {
    $wpdb->insert( ... ); // Repository concern
    wp_mail( ... );       // Service concern
}
```

### REST Controllers (`includes/API/Controllers/`)

All extend WordPress `WP_REST_Controller` pattern. Follow these conventions:

- Permission checks via `CapabilityManager` and `donatepress_rest_can_manage` filter
- Rate limiting on public endpoints via `RateLimiter`
- Nonce verification on form submissions via `NonceManager`
- Input sanitization with `sanitize_*()` functions on every field

### Gateway Interface

Payment gateways implement `GatewayInterface`. Shared webhook logic goes in `WebhookProcessor`, not in individual gateways.

```php
// Good — shared logic in WebhookProcessor
$processor = WebhookProcessor::from_globals();
$processor->sync_donation_status( $donation_id, $status );

// Bad — duplicate sync logic in each gateway
// StripeGateway::handle_webhook() and PayPalGateway::handle_webhook()
// each writing their own status update code
```

### Settings

All settings live in one option: `get_option( 'donatepress_settings', [] )`. Never create separate `donatepress_*` options for individual settings.

```php
// Good
$settings = get_option( 'donatepress_settings', array() );
$currency = $settings['base_currency'] ?? 'USD';

// Bad
$currency = get_option( 'donatepress_base_currency', 'USD' );
```

### Frontend JavaScript

Use DOM API for HTML construction. Never use `innerHTML` with dynamic content.

```javascript
// Good — safe DOM construction
const el = document.createElement( 'a' );
el.href = url;
el.textContent = label;
container.appendChild( el );

// Bad — XSS risk
container.innerHTML = `<a href="${url}">${label}</a>`;
```

## Testing

### Smoke Tests

Standalone PHP scripts that verify module contracts without a WordPress install:

```bash
php tests/smoke-security.php       # Rate limiter, nonce, capabilities
php tests/smoke-financial.php      # Donation amounts, currency handling
php tests/smoke-privacy.php        # Data export/erasure
php tests/smoke-capabilities.php   # Capability scopes
php tests/smoke-rest-schema.php    # REST route definitions
php tests/smoke-frontend-assets.php # Asset handles and enqueue
```

### PHPUnit

```bash
vendor/bin/phpunit
vendor/bin/phpunit --testsuite unit
```

### Playwright E2E

```bash
npm install
npm run test:e2e
npm run test:e2e -- --headed --grep 'Donation Form'
```

### What to Test

- Repository CRUD operations
- Service business logic (donation creation, subscription lifecycle)
- REST endpoint permission checks and response shapes
- Webhook signature verification and deduplication
- Rate limiter atomicity under concurrent requests
- Gateway payment flows (Stripe PaymentIntent, PayPal Orders)
- Edge cases: duplicate webhooks, expired tokens, blocked donors

## Database Migrations

When adding schema changes:

1. Add the table definition to `Activator::create_tables()` (for `dbDelta()`)
2. For data transforms or column renames, add a versioned migration:

```php
private static function run_migrations(): void {
    $migrations = array(
        '0.2.0' => array( self::class, 'migrate_0_2_0' ),
        '0.3.0' => array( self::class, 'migrate_0_3_0' ),
    );
    // ...
}
```

3. Bump `DONATEPRESS_VERSION` in `donatepress.php`

## Naming Conventions

| Entity | Prefix | Example |
|--------|--------|---------|
| Options | `donatepress_` | `donatepress_settings` |
| DB tables | `dp_` | `wp_dp_donations` |
| Hooks | `donatepress_` | `donatepress_after_create_donation` |
| REST namespace | `donatepress/v1` | `/wp-json/donatepress/v1/forms` |
| Asset handles | `donatepress` | `donatepress`, `donatepress-admin` |
| CSS classes | `dp-` | `.dp-form`, `.dp-btn` |
| Constants | `DONATEPRESS_` | `DONATEPRESS_VERSION` |
| Namespaces | `DonatePress\` | `DonatePress\Services\DonationService` |
| FK constraints | `dp_fk_` | `dp_fk_donations_donor` |

## File Size Limits

- `SettingsPage.php` is the largest file (~66 KB). Consider extracting AJAX handlers into dedicated handler classes if it grows further.
- Repositories: max 500 lines each
- Controllers: max 400 lines each
- Services: max 600 lines each

## Security Checklist

Before merging any code change:

- [ ] No raw `$wpdb` queries outside `Repositories/`
- [ ] All user input sanitized (`sanitize_text_field`, `sanitize_email`, `absint`, etc.)
- [ ] No `innerHTML` with dynamic content in JS
- [ ] Rate limiting on public-facing endpoints
- [ ] Nonce verification on form submissions
- [ ] Capability checks on admin REST routes
- [ ] No credentials or API keys in committed code
- [ ] Webhook handlers verify signatures before processing
