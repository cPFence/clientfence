# Changelog

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
