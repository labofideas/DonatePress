# DonatePress Architecture

## System Overview

```
                    ┌─────────────────────────────────────────┐
                    │              WordPress Core              │
                    └──────────────┬──────────────────────────┘
                                   │
                          plugins_loaded
                                   │
                    ┌──────────────▼──────────────────────────┐
                    │         donatepress_bootstrap()          │
                    │                                          │
                    │  Constants (donatepress.php)              │
                    │  PSR-4 Autoloader                        │
                    │  Plugin::init()                          │
                    └──────────────┬──────────────────────────┘
                                   │
         ┌─────────────────────────┼──────────────────────────┐
         │                         │                          │
    ┌────▼────┐             ┌──────▼──────┐            ┌──────▼──────┐
    │ Frontend│             │  REST API   │            │    Admin    │
    │         │             │             │            │             │
    │Shortcode│             │ Controllers │            │SettingsPage │
    │  Block  │             │ (10 routes) │            │ Setup Wizard│
    └────┬────┘             └──────┬──────┘            └─────────────┘
         │                         │
         │              ┌──────────▼──────────┐
         │              │      Services       │
         │              │  (business logic)   │
         │              └──────────┬──────────┘
         │                         │
         │              ┌──────────▼──────────┐
         │              │    Repositories     │
         │              │  (data access)      │
         │              └──────────┬──────────┘
         │                         │
         │              ┌──────────▼──────────┐
         └──────────────►  Custom MySQL       │
                        │  tables (9)         │
                        │  wp_dp_* via dbDelta│
                        └─────────────────────┘
```

## Request Lifecycle (Frontend Donation)

```
Browser: [donatepress_form id="1"]
        │
        ▼
┌──────────────┐
│FormShortcode │  Renders form HTML + enqueues JS/CSS
└──────┬───────┘
       ▼
┌──────────────┐
│  form.js     │  Collects input, validates, POST to REST API
└──────┬───────┘
       ▼
┌──────────────┐
│Donation      │  Rate-limit → nonce check → honeypot → validate
│Controller    │  → DonationService::create_pending()
└──────┬───────┘
       ▼
┌──────────────┐
│Payment       │  GatewayManager → resolve gateway → start_payment()
│Service       │  Returns redirect URL or client secret
└──────┬───────┘
       ▼
┌──────────────┐
│  Gateway     │  Stripe: PaymentIntent API
│  (Stripe/    │  PayPal: Orders API + redirect
│   PayPal)    │  Offline: immediate completion
└──────────────┘
```

## Webhook Lifecycle

```
Gateway server sends POST to /wp-json/donatepress/v1/webhooks/{gateway}
        │
        ▼
┌──────────────────┐
│WebhookController │  Route dispatch, extract headers + raw body
└──────┬───────────┘
       ▼
┌──────────────────┐
│  Gateway         │  Verify signature (Stripe HMAC / PayPal API verify)
│  handle_webhook()│  Parse event type and object
└──────┬───────────┘
       ▼
┌──────────────────┐
│WebhookProcessor  │  Shared logic for both gateways:
│                  │  - is_duplicate_event() → dedup check
│                  │  - resolve_donation() → find by gateway_transaction_id
│                  │  - sync_donation_status() → update status
│                  │  - process_subscription() → create/update recurring
└──────┬───────────┘
       ▼
┌──────────────────┐
│  Hooks fired     │  donatepress_donation_status_synced
│                  │  donatepress_subscription_status_updated
│                  │  → Receipt email, donor metric sync, admin notify
└──────────────────┘
```

## Data Layer

Custom MySQL tables (NOT WordPress CPTs). All prefixed `wp_dp_*`.

### Tables (9)

| Group | Tables |
|-------|--------|
| Transactions | `dp_donations`, `dp_subscriptions` |
| Entities | `dp_donors`, `dp_forms`, `dp_campaigns`, `dp_campaign_updates` |
| Security | `dp_portal_tokens`, `dp_webhook_events` |
| Audit | `dp_audit_logs` |

### Foreign Key Relationships

```
dp_donations
  ├── donor_id      → dp_donors(id)        ON DELETE SET NULL
  ├── form_id       → dp_forms(id)         ON DELETE RESTRICT
  └── campaign_id   → dp_campaigns(id)     ON DELETE SET NULL

dp_subscriptions
  ├── initial_donation_id → dp_donations(id)  ON DELETE RESTRICT
  ├── donor_id            → dp_donors(id)     ON DELETE SET NULL
  ├── form_id             → dp_forms(id)      ON DELETE RESTRICT
  └── campaign_id         → dp_campaigns(id)  ON DELETE SET NULL

dp_campaign_updates
  └── campaign_id   → dp_campaigns(id)     ON DELETE CASCADE
```

### Repository Base Pattern

Each repository class wraps `$wpdb` for one table group. Standard methods:

```
Repository
 ├── find($id)            → ?array
 ├── insert($data)        → int|false
 ├── update($id, $data)   → bool
 ├── delete($id)          → bool
 ├── list($args)          → array
 └── count($where)        → int
```

## Service Layer

14 services encapsulate business logic. Controllers never contain business rules.

| Service | Responsibility |
|---------|---------------|
| `DonationService` | Create pending donations, generate donation numbers |
| `SubscriptionService` | Create/manage recurring subscriptions |
| `PaymentService` | Gateway orchestration, start payments |
| `WebhookProcessor` | Shared webhook handling for all gateways |
| `RecurringChargeService` | Retry failed subscription charges |
| `RecurringRetryService` | Cron-driven retry queue processing |
| `EmailNotificationService` | Donor and admin email dispatch |
| `ReceiptService` | Donation receipt rendering |
| `SimplePdfService` | PDF receipt generation |
| `PortalService` | Magic-link auth, session management |
| `SettingsService` | Settings retrieval with defaults |
| `SetupWizardService` | First-time setup flow |
| `DemoImportService` | Demo content seeding |
| `AuditLogService` | Structured event logging |

## Payment Gateway Architecture

```
┌───────────────────┐
│  GatewayInterface │  start_payment(), handle_webhook()
└─────────┬─────────┘
          │
    ┌─────┴────────────────────────────┐
    │              │                   │
┌───▼────┐   ┌────▼─────┐   ┌────────▼────────┐
│ Stripe │   │  PayPal  │   │    Offline      │
│Gateway │   │ Gateway  │   │   Gateway       │
└───┬────┘   └────┬─────┘   └─────────────────┘
    │              │
    └──────┬───────┘
           ▼
   ┌───────────────┐
   │Webhook        │  Shared: dedup, resolve, sync, subscription
   │Processor      │
   └───────────────┘
```

Extension: `donatepress_gateways` filter to register custom gateway instances.

## Security Architecture

### Rate Limiting

Dual-strategy atomic rate limiter:
1. **Object cache path**: `wp_cache_add()` for init + `wp_cache_incr()` for atomic increment
2. **Database fallback**: Atomic `UPDATE ... WHERE value < limit` for transient-based sites

### Nonce System

HMAC-SHA256 tokens that work on cached pages. Per-form tokens with configurable TTL (default 2 days).

### Capability System

Scoped capabilities via `CapabilityManager`:

```
REST request
  │
  ▼
CapabilityManager::can( $scope, $capability, $request )
  │
  ├── donatepress_capability_map filter (override scope → WP cap mapping)
  ├── current_user_can( $wp_capability )
  └── donatepress_user_can filter (final override)
```

Scopes: `settings.manage`, `donors.manage`, `forms.manage`, `campaigns.manage`, `subscriptions.manage`, `reports.view`, plus admin page visibility scopes.

## Cron Jobs

| Hook | Schedule | Handler |
|------|----------|---------|
| `donatepress_retry_subscriptions_cron` | Hourly | `Plugin::run_recurring_retry_job()` → `RecurringRetryService::process_due_retries()` |

Retry flow: find subscriptions with `status=active`, `failure_count > 0`, `next_payment_at <= now` → fire `donatepress_subscription_retry_due` → `RecurringChargeService::attempt_retry()`.

## Integration Points

### WooCommerce

`WooCommerceIntegration` hooks into WC order completion. Each paid order line item creates a synced donation. WC refunds mark synced donations as refunded.

Hooks: `donatepress_wc_order_synced`, `donatepress_wc_refund_synced`.

### BuddyPress

`BuddyPressIntegration` adds a profile tab showing the member's donation history (most recent N donations).

Hooks: `donatepress_buddypress_loaded`, `donatepress_bp_profile_nav_registered`.

## Frontend Stack

```
┌─────────────────────────────────────────────────────┐
│  PHP Shortcodes (FormShortcode, CampaignShortcode,  │
│  PortalShortcode) + Gutenberg Block (FormBlock)     │
├─────────────────────────────────────────────────────┤
│  Vanilla JS (form.js, portal.js)                    │
│  DOM API for safe HTML construction (no innerHTML)  │
│  Fetch API for REST communication                   │
├─────────────────────────────────────────────────────┤
│  CSS (form.css, portal.css, campaign.css)            │
│  Admin: settings.css                                │
├─────────────────────────────────────────────────────┤
│  Admin SPA shell (admin-shell.js + settings.js)     │
│  React-based settings UI                            │
└─────────────────────────────────────────────────────┘
```

## Migration System

```
Activator::activate()
        │
        ▼
create_tables()          → dbDelta() for all 9 tables
        │
        ▼
run_migrations()         → Version-keyed callbacks
        │                   Compare donatepress_db_version vs DONATEPRESS_VERSION
        │                   Run each migration once, update version after each
        ▼
maybe_seed_defaults()    → First-install settings via add_option()
        │
        ▼
schedule_events()        → Register cron hooks
```
