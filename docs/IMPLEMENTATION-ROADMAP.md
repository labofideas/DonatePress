# DonatePress Implementation Roadmap (v1)

## Sprint 1 — Complete

- Scope: Core data foundations and admin scaffolding.
- Deliverables:
  - 9 custom tables via `dbDelta()` with foreign key constraints.
  - 9 repository classes for all table groups.
  - Admin submenu structure: Setup Wizard, Settings, Demo Import, Donations, Donors, Forms, Campaigns, Reports, Subscriptions.
  - PSR-4 autoloading, activation/deactivation lifecycle.

## Sprint 2 — Complete

- Scope: Donation forms module.
- Deliverables:
  - Form CRUD admin screens with search, filter, and pagination.
  - Multi-step form shortcode with amount presets, custom fields, fee recovery, tribute, recurring toggle.
  - Form rendering from DB configuration merged with shortcode attributes.
  - Gutenberg block (`donatepress/form`) with InspectorControls.

## Sprint 3 — Complete

- Scope: Donor CRM + Campaigns.
- Deliverables:
  - Donor list, tags/notes, block, anonymize, merge via REST API.
  - Campaign CRUD admin screens with shortcode copy, search, filter, pagination.
  - Campaign shortcode with progress bar, updates list, embedded donation form.
  - Donor metric sync on donation status changes.

## Sprint 4 — Complete

- Scope: Recurring + Receipts + Emails.
- Deliverables:
  - Subscription records with lifecycle management (create, pause, resume, cancel, expire).
  - Hourly retry cron for failed recurring charges with configurable max retries.
  - Email notifications: admin pending, retry failed/succeeded/exhausted.
  - Receipt HTML rendering and PDF generation (`SimplePdfService`).

## Sprint 5 — Complete

- Scope: WooCommerce mode + Donor Portal + Reports.
- Deliverables:
  - WooCommerce order-to-donation sync with refund handling (idempotent).
  - Donor portal with magic-link auth, donation history, subscription actions, annual summary (CSV), receipt download (PDF).
  - Reports page with aggregate metrics from all repositories.

## Sprint 6 — Complete

- Scope: Hardening and release readiness.
- Deliverables:
  - Scoped capability matrix with filter overrides.
  - GDPR privacy exporter/eraser integration.
  - 12 smoke tests, 2 PHPUnit test classes, 6 Playwright E2E specs.
  - Performance benchmarks (100k-row p95 at 275ms).
  - Build release script with 9 verification stages.
  - CI pipeline: PHP lint, smoke tests, PHPUnit, PHPStan, WPCS.

## v1.1 Roadmap

- Admin form config editor (custom fields, amount presets, fee recovery, tribute fields editable from wp-admin).
- Admin donation detail view with full transaction timeline.
- Admin subscription actions (pause/resume/cancel from wp-admin).
- Campaign updates CRUD in admin.
- Granular capability roles (Donation Manager, Donation Viewer) beyond `manage_options`.
- i18n `.pot` file generation and `languages/` directory.
- Admin CSV export for donations, donors, and subscriptions.
- Email template system with theme-overridable templates.
- UTF-8 PDF receipt support for international donor names.
