# DonatePress

Performance-first donation plugin for WordPress with Stripe, PayPal, recurring giving, donor portal, campaigns, reporting, and WooCommerce sync.

## Stability Rules

1. **Never break activation.** `Activator::activate()` must succeed on a fresh WP install. Every schema change goes through `create_tables()` + `dbDelta()`.
2. **Never break the donation flow.** The `[donatepress_form]` shortcode, REST submission endpoint, and webhook handlers are the revenue path. Test them after every change.
3. **Never commit credentials.** Gateway keys live in `donatepress_settings` option only. The `.gitignore` excludes `.env` and `vendor/`. Verify before pushing.

## Quick Reference

| Item | Value |
|------|-------|
| Plugin slug | `donatepress` |
| Text domain | `donatepress` |
| Min WordPress | 6.4 |
| Min PHP | 8.0 |
| DB table prefix | `wp_dp_` |
| REST namespace | `donatepress/v1` |
| Settings option | `donatepress_settings` |
| Main file | `donatepress.php` |
| Autoloader | PSR-4 from `includes/` |

## Build & Test

```bash
# Install dependencies
composer install

# Code quality (both must pass with zero errors before committing)
vendor/bin/phpcs --standard=.phpcs.xml.dist
vendor/bin/phpstan analyse -c phpstan.neon.dist

# Smoke tests (no WordPress install needed)
php tests/smoke-security.php
php tests/smoke-financial.php
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
DONATEPRESS_WC_RUNTIME=1 php tests/smoke-woocommerce-runtime.php

# PHPUnit
vendor/bin/phpunit

# Playwright E2E
npm install
npm run test:e2e
npm run test:e2e -- --headed --grep 'Donation Form|Donor Portal'
npm run test:e2e -- --headed --grep 'Admin Settings Runtime'
npm run test:e2e -- --headed --grep 'WooCommerce Checkout Runtime'

# Build release zip
bash bin/build-release.sh
```

## Pre-Commit Checks (mandatory)

Both must pass with zero errors before committing:

```bash
# WPCS
vendor/bin/phpcs --standard=.phpcs.xml.dist

# PHPStan
vendor/bin/phpstan analyse -c phpstan.neon.dist
```

## Build & Release

`bin/build-release.sh` produces a distributable zip with 9 verification stages:

1. Clean-tree gate (no uncommitted changes)
2. Version triangulation (plugin header, constant, and composer.json must match)
3. PHP syntax lint on all source files
4. Smoke test suite
5. Staging into `dist/` via rsync (excludes dev files)
6. Required files sanity check
7. Staged PHP lint
8. Zip creation
9. Zip re-extraction and version verification

## CI Pipeline

GitHub Actions runs on push to `main`/`v*` branches and all PRs:

| Job | What it checks |
|-----|----------------|
| **php-lint** | Syntax across PHP 8.0/8.1/8.2/8.3/8.4 |
| **smoke-tests** | All smoke test scripts |
| **phpunit** | Full matrix: PHP 8.0–8.3 with MySQL 8.0 |
| **phpstan** | Static analysis, zero errors required |
| **wpcs** | Coding standards, zero errors required |

All jobs must pass before merge.

## Architecture Overview

```
donatepress.php          → Constants, autoloader, bootstrap
includes/
  Core/                  → Activator (schema + migrations), Plugin (runtime hooks), Privacy
  API/
    RestController.php   → Route registration hub
    Controllers/         → 10 REST controllers (Donation, Form, Campaign, Donor, etc.)
  Services/              → 14 business logic services
  Repositories/          → 9 data-access classes (one per table group)
  Gateways/              → GatewayInterface + Stripe, PayPal, Offline implementations
  Security/              → RateLimiter, NonceManager, CapabilityManager
  Frontend/              → Shortcodes (Form, Campaign, Portal) + Gutenberg block
  Admin/                 → SettingsPage (menus, settings, rendering)
    Handlers/            → FormHandler, CampaignHandler (CRUD actions)
  Integrations/          → WooCommerce, BuddyPress
assets/
  admin/                 → Admin JS/CSS (settings, setup wizard)
  frontend/              → Frontend JS/CSS (form, portal, campaign)
  blocks/                → Gutenberg block JS
```

## Key Files

| Purpose | File |
|---------|------|
| Bootstrap | `donatepress.php` |
| Schema + migrations | `includes/Core/Activator.php` |
| Runtime init | `includes/Core/Plugin.php` |
| Route hub | `includes/API/RestController.php` |
| Donation flow | `includes/Services/DonationService.php` |
| Payment orchestration | `includes/Services/PaymentService.php` |
| Webhook processing | `includes/Services/WebhookProcessor.php` |
| Stripe gateway | `includes/Gateways/Stripe/StripeGateway.php` |
| PayPal gateway | `includes/Gateways/PayPal/PayPalGateway.php` |
| Rate limiter | `includes/Security/RateLimiter.php` |
| Form shortcode | `includes/Frontend/FormShortcode.php` |
| Settings admin | `includes/Admin/SettingsPage.php` |
| Hooks reference | `docs/EXTENSIBILITY.md` |

## Database Tables (9)

All prefixed `wp_dp_*`, created via `dbDelta()` in `Activator::create_tables()`.

| Table | Purpose |
|-------|---------|
| `dp_donations` | Donation transactions (source of truth) |
| `dp_subscriptions` | Recurring subscription records |
| `dp_donors` | Donor profiles with aggregate totals |
| `dp_forms` | Donation form configurations |
| `dp_campaigns` | Campaign pages with goals |
| `dp_campaign_updates` | Campaign progress updates |
| `dp_portal_tokens` | Magic-link tokens for donor portal |
| `dp_webhook_events` | Gateway webhook deduplication log |
| `dp_audit_logs` | Structured audit trail |

Foreign keys enforce relational integrity between donations, subscriptions, donors, forms, and campaigns.

## REST Endpoints

Base: `/wp-json/donatepress/v1`

**Public:**
- `POST /donations/submit` — Form submission (nonce + honeypot + rate-limit)
- `POST /webhooks/{gateway}` — Stripe/PayPal webhook receiver (signature-verified)
- `GET /status` — Public plugin status

**Portal (magic-link session):**
- `POST /portal/request-link` — Send magic login link
- `POST /portal/auth` — Exchange token for session
- `GET /portal/me`, `GET /portal/donations`, `GET /portal/subscriptions`
- `POST /portal/subscriptions/{id}/action` — pause/resume/cancel
- `GET /portal/annual-summary`, `GET /portal/annual-summary/download`
- `GET /portal/receipts/{id}/download`
- `POST /portal/logout`

**Admin (manage_options):**
- CRUD for settings, forms, campaigns, campaign updates, donors, subscriptions, reports

## Coding Patterns

### Repository Pattern

All database access goes through repository classes in `includes/Repositories/`. No raw `$wpdb` queries in controllers or services.

```php
// Correct — use repository
$repo = new DonationRepository( $wpdb );
$donation = $repo->find( $id );
$repo->insert( $data );

// Wrong — raw query in a controller
$wpdb->get_row( "SELECT * FROM {$wpdb->prefix}dp_donations WHERE id = $id" );
```

### Service Layer

Business logic lives in `includes/Services/`. Controllers validate input and delegate to services. Services use repositories for data access.

```
Controller → validates input → Service → Repository → $wpdb
```

### Gateway Interface

All payment gateways implement `GatewayInterface`. Registered via `GatewayManager`. Custom gateways hook into `donatepress_gateways` filter.

```php
// Gateway contract
interface GatewayInterface {
    public function start_payment( array $donation, array $context ): array;
    public function handle_webhook( array $headers, string $payload ): array;
}
```

### Settings

All plugin settings live in one option: `get_option( 'donatepress_settings', [] )`. Never create separate `donatepress_*` options for individual settings. Use `SettingsService` to read and write settings with built-in sanitization.

```php
// Read
$service = new SettingsService();
$currency = $service->get( 'base_currency', 'USD' );

// Write (sanitizes all values)
$service->update( array( 'base_currency' => 'EUR' ) );
```

### Admin Architecture

`SettingsPage` handles menus, settings registration, and page rendering only (max 750 lines). CRUD action handlers live in `Admin\Handlers\*Handler` classes (max 400 lines each). Never add POST/form handlers directly to `SettingsPage`.

```php
// Correct — handler class
class FormHandler {
    public function save(): void { ... }
    public function delete(): void { ... }
}

// Wrong — handler method inside SettingsPage
class SettingsPage {
    public function handle_save_form(): void { ... }  // too much responsibility
}
```

### Webhook Processing

Shared webhook logic lives in `WebhookProcessor`. Both Stripe and PayPal gateways delegate to it for donation resolution, status sync, subscription processing, and deduplication.

### Security

- **Rate limiting**: Atomic operations via `wp_cache_add`/`wp_cache_incr` (object cache) with DB fallback. See `RateLimiter.php`.
- **Nonce tokens**: HMAC-SHA256 tokens for cached pages. See `NonceManager.php`.
- **Capabilities**: Scoped capability map via `CapabilityManager`. Admin REST routes check `donatepress_rest_can_manage` filter.
- **Input validation**: Always `sanitize_*()` and `wp_kses_post()` on input. Amount fields validated with min/max bounds ($0.50–$50,000 default).

### Cron Jobs

| Hook | Schedule | Purpose |
|------|----------|---------|
| `donatepress_retry_subscriptions_cron` | Hourly | Retry failed recurring charges |

## Naming Conventions

| Entity | Prefix | Example |
|--------|--------|---------|
| Options | `donatepress_` | `donatepress_settings` |
| DB tables | `dp_` | `wp_dp_donations` |
| Hooks (actions) | `donatepress_` | `donatepress_donation_status_synced` |
| Hooks (filters) | `donatepress_` | `donatepress_gateways` |
| REST namespace | `donatepress/v1` | `/wp-json/donatepress/v1/donations/submit` |
| Asset handles | `donatepress` | `donatepress`, `donatepress-admin` |
| Text domain | `donatepress` | `__( 'Donate', 'donatepress' )` |
| CSS classes | `dp-` | `.dp-form`, `.dp-btn` |
| Constants | `DONATEPRESS_` | `DONATEPRESS_VERSION` |
| Namespaces | `DonatePress\` | `DonatePress\Services\DonationService` |
| FK constraints | `dp_fk_` | `dp_fk_donations_donor` |

## Migration Framework

Version-keyed migrations in `Activator::run_migrations()`. Each migration runs once, tracked by `donatepress_db_version` option.

```php
$migrations = array(
    '0.2.0' => array( self::class, 'migrate_0_2_0' ),
);
```

Add new migrations keyed by the version that introduces the schema change. `dbDelta()` handles additive column changes; use explicit migrations for data transforms, column renames, or index changes.

## Anti-Patterns

Things to avoid in this codebase:

- Raw `$wpdb` queries outside `Repositories/`
- Separate `donatepress_*` options for individual settings (use the settings array)
- `current_time( 'timestamp', true )` — use `time()` instead (deprecated in WP)
- `innerHTML` in frontend JS — use DOM API (`createElement`, `textContent`)
- `md5()` for security hashing — use `hash( 'sha256', ... )`
- Duplicate business logic across gateways — extract to `WebhookProcessor`
- Hardcoded rate limits — use filters (`donatepress_submission_rate_limit`, etc.)
- CRUD action handlers inside `SettingsPage` — use `Admin\Handlers\*Handler` classes
- Bypassing `SettingsService::update()` for programmatic writes — always use the service
- Committing test artifacts, node_modules, or vendor/

## Extension Points

See `docs/EXTENSIBILITY.md` for the complete hooks reference (25 actions, 40+ filters).

Key extension patterns:
- **Custom gateways**: `donatepress_gateways` filter
- **Custom capabilities**: `donatepress_capability_map` + `donatepress_user_can` filters
- **Webhook customization**: `donatepress_webhook_result` filter
- **Portal customization**: `donatepress_portal_magic_ttl`, `donatepress_portal_session_ttl` filters
- **Admin menu**: `donatepress_render_settings_panels` action

## Integrations

| Integration | File | Trigger |
|-------------|------|---------|
| WooCommerce | `Integrations/WooCommerceIntegration.php` | Order completion → donation sync; refund → donation refund |
| BuddyPress | `Integrations/BuddyPressIntegration.php` | Profile tab with donation history |
