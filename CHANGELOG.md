# Changelog

## 1.0.5 (2026-10-28)

### Added

- An Add credit button next to the account balance in the client area.
- Clients can dismiss the network status notice on their dashboard; it shows again when an issue changes.

### Changed

- A new Network Status page: a status summary, tabs for open, scheduled and resolved issues, and clearer issue cards.

## 1.0.4 (2026-10-28)

### Added

- Client page summary: invoice totals per status, revenue, credit, services and quick actions (add funds invoice, due invoices, statement, send email, close account).
- Product, domain and addon lists on the client page, with renewal invoices and delete for the selected orders.
- Staff notes and private files on each client.
- The client profile shows whether the email is verified, how long the client has been a customer, and the last login IP.

### Fixed

- The payment email and the renewal run as soon as an invoice is paid, by staff or by the client.
- Arabic invoice PDFs keep addresses that mix Arabic and Latin text in the right order.

## 1.0.3 (2026-10-28)

### Security

- Visitors and clients no longer see database or server details on error pages; staff still do.

### Added

- Staff can download an invoice PDF in any installed language from the invoice page.

### Fixed

- Arabic and Hebrew text in invoice PDFs is joined and reads right to left. Invoice PDFs are in the client's language again, not always English.
- Phone layouts: the client header, hosting page, email history and admin invoice items fit narrow screens.

## 1.0.2 (2026-10-27)

### Security

- Payment return links no longer contain the session ID.
- A password reset link works once, and every open link stops working when the password changes.
- Guest sign-in, sign-up and cart changes need a form token from the same site.
- Invoice PDF logos can no longer be loaded from private network addresses.
- Smaller hardening from FOSSBilling 0.8.9 (DirectAdmin, theme settings, payment callbacks, installer).

### Fixed

- Stripe subscription renewals are recorded on the renewal invoice.
- Saving an order's form answers no longer clears the order's other settings.
- PayPal payments are no longer rejected when PayPal sends back an old item number.
- Bug fixes from FOSSBilling 0.8.9 for WHM, CWP, Hestia, Internet.bs, products, order lists and email language.

## 1.0.1 (2026-10-26)

### Fixed

- Account statements and billing reports now show the same tax as the invoice (they could be one cent lower).

## 1.0.0 (2026-10-26)

This is the first stable release of ClientFence.
