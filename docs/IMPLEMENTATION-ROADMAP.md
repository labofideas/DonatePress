# DonatePress Implementation Roadmap (v1)

## Sprint 1 (In Progress)

- Scope: Core data foundations and admin scaffolding.
- Deliverables:
  - Expand DB schema with `dp_donors`, `dp_forms`, `dp_campaigns`.
  - Keep `dp_donations` as transactional source of truth.
  - Add base repositories for donors/forms/campaigns.
  - Add admin submenu structure: Donations, Donors, Forms, Campaigns, Reports, Settings.
- Exit criteria:
  - Plugin activates cleanly and creates/upgrades all Sprint 1 tables.
  - Admin menus render without fatals.
  - Existing donation flow remains functional.

## Sprint 2

- Scope: Donation forms module.
- Deliverables:
  - Form management screen (create/edit/archive).
  - Multiple amount presets and feature toggles.
  - Form rendering from DB configuration.
  - Gutenberg block integration baseline.

## Sprint 3

- Scope: Donor CRM + Campaigns.
- Deliverables:
  - Donor list/detail, tags/notes basics.
  - Campaign CRUD and campaign assignment to forms/donations.
  - Donor and campaign stats aggregation jobs.

## Sprint 4

- Scope: Recurring + Receipts + Emails.
- Deliverables:
  - Recurring records and lifecycle management.
  - Email template engine and queue.
  - Receipt emails and downloadable PDF receipts.

## Sprint 5

- Scope: WooCommerce mode + Donor Portal + Reports.
- Deliverables:
  - WC one-time donation checkout bridge and refund sync.
  - Donor portal auth and profile/donation history.
  - Reporting dashboards and exports.

## Sprint 6

- Scope: Hardening and release readiness.
- Deliverables:
  - Capability matrix, privacy exporter/eraser integration.
  - End-to-end automated test suite (PHPUnit + Playwright).
  - Performance profiling and release packaging.

## Current Release Risks

- Frontend UX still uses minimal form UI.
- No production-ready donor/campaign/report admin pages yet.
- Stripe/PayPal flows still need final browser confirmation path and production credential validation.
