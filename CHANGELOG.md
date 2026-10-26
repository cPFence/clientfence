# Changelog

All notable changes to ClientFence. Versions follow [Semantic Versioning](https://semver.org/):
a patch release (1.0.x) only fixes bugs, a minor release (1.x.0) adds features, a major release
changes or removes something you rely on.

## 1.0.1 (2026-10-26)

Update recommended for every site that charges tax.

### Fixed

- **Tax on account statements and reports now matches the invoice to the cent.** The client
  account statement, the Aging Invoices, Sales Tax Liability and Client Statement reports, the
  dashboard's amount owed and the automatic pay-from-credit check worked
  out tax in the database with floating-point numbers. When the tax came to exactly half a cent,
  they could round it down while the invoice rounded it up, so a statement or report could be one
  cent lower than the invoice the client was asked to pay. They now use exact decimal amounts and
  round the same way as the invoice. Stored invoices, payments and balances are not changed; the
  pages simply show the right amount after the update.

### Upgrading

Use System → Update, or replace the files as described in the README. No database changes.

## 1.0.0 (2026-10-26)

The first stable release, based on FOSSBilling 0.8.8.

### Highlights

- **Client area and admin:** the Palisade theme, an admin area with a sidebar menu, Ctrl+K search
  and a dashboard; light and dark, left to right and right to left (Arabic included).
- **Accounts:** authorized users with their own permissions, two-factor sign-in and Google sign-in.
- **Billing:** invoices, quotes, payment receipts, account statements, a wallet with top-ups,
  partial payments, refunds and credit notes, upgrades and downgrades with proration, bulk price
  updates, billing reports with CSV and PDF, and Stripe, PayPal and manual payment gateways
  (with an Egyptian pound total).
- **Hosting and domains:** an Enhance server manager with client pages for each hosting account
  (usage, websites, email, domains), and a Dynadot registrar.
- **Support:** tickets with file attachments, email piping, escalation rules, ratings and a
  network status page.
- **Security and health:** security hardening and a system health page, built in.
- **Updates:** a daily check of the release list on clientfence.com (it sends nothing about your
  site), a dashboard notice, a staff email and an Update button that backs up, installs and
  finalizes a new version. No telemetry, extension directory or error reports.
