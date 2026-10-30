# Changelog

## 1.0.11 (2026-10-30)

### Fixed

- Orders: deleting an unpaid hosting order also deletes the free domain that came with it, so the domain is no longer registered for free.
- Orders: an add-on set to activate after payment, or by staff, is no longer activated with its main service before it is paid.
- Orders: an add-on is no longer activated a second time when an order includes hosting and a domain, which gave it an extra billing period.
- Orders: deleting an unpaid order also removes its setup fee from the invoice.
- Promo codes: a code limited to certain billing periods no longer discounts one-time products.
- Upgrades: a sold-out product is no longer offered as an upgrade, and an upgrade now takes one from its stock.
- Upgrades: changing a downloadable product now gives access to the new product's file.
- Renewals: a renewal invoice made while an upgrade is being applied now uses the new price.

## 1.0.10 (2026-10-30)

### Security

- Password reset, sign-in code, invite and email confirmation emails are no longer kept in email template previews, the email queue or the email history, where staff with email access could read the link. They are sent at once; if one fails, ask for a new link.
- Staff password reset: the response time no longer shows whether an email belongs to a staff account.
- Login as client: the link now needs the page's security token, so another site cannot switch your client session.

### Fixed

- Changing a client's password, or signing out their other sessions, while signed in as that client from the admin now signs out the client's other sessions instead of the staff member's.

## 1.0.9 (2026-10-30)

### Security

- Ticket merge: an old guest link no longer opens the merged ticket; the ticket gets a new guest link, sent with the next staff reply.
- System → Update: the backup made before an update is never saved inside the website.

### Changed

- Backups kept on this server go into a folder per install, `.clientfence-backups/<site folder name>`; move older backups there by hand to keep them listed.

### Fixed

- PayPal: a payment that is Pending first is credited when PayPal completes it.
- Stripe: 3-month, 6-month, 2-year and 3-year subscriptions are billed once per period, not every month or year. Subscriptions made before keep their old schedule: cancel them and subscribe again.
- Stripe and PayPal: an invoice with a one-time discount is paid once instead of starting a subscription at the discounted price.
- Paying an invoice from the account balance works when the invoice was set to another payment method.
- Account deletion requests work when the first help desk is hidden from clients.
- Staff ticket replies: no attach button on guest tickets, where uploads always failed.
- System → Update: two updates started together no longer remove each other's backup; a failed update frees disk space before putting the old files back and names any file it could not restore.
- WHMCS import: an archive batch that could not be written in full (full disk) stops the import instead of being skipped.

## 1.0.8 (2026-10-29)

### Changed

- Sign-in pages: the language button is an icon.

### Fixed

- Sign-in pages no longer slide sideways on phones when the logo is wide.
- Staff ticket reply: the canned responses list fits the card on phones and looks like the other fields.
- Staff reports, News, Notifications and Mass Mail: buttons and search move under the title on phones.
- Staff transaction and subscription pages: the details table scrolls on phones instead of running off the card.
- Client email view: the sender row wraps on phones.
- Staff client page: long subjects in Recent Emails wrap.

## 1.0.7 (2026-10-29)

### Security

- Email: symfony/mime 7.4.22 fixes CVE-2026-45070 (header injection through MIME parameter names).
- Staff editor: CKEditor 48.5.3 fixes two cross-site scripting issues (GHSA-v6mg-96c6-gmpq, GHSA-rh54-vffm-5fvp).

### Added

- Staff client page: an Add Funds shortcut next to the balance.
- Staff client page: paging on every list tab.

### Fixed

- Client area: long page lists no longer overflow the Wallet side card; they show at most 7 page numbers and wrap.

## 1.0.6 (2026-10-28)

### Added

- A cart page with the promo code, a summary and checkout; hosting and domain plans go to it with Add to cart.
- A cart icon in the header with the number of items.
- Add to cart on the one-page checkout.
- Staff client page: tickets opened this month, last month, this year and last year, and a search over the client's tickets.
- Staff client page: total in, total out and balance of the client's transactions.

### Changed

- Smaller, lighter headings across the client area; news posts and knowledge base articles get a clear page title.

### Fixed

- An empty domain field on a hosting order now says "Domain name is required." instead of a technical message.

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
