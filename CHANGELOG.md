# Changelog

## 1.0.41 (2026-11-10)

### Fixed

- When an upgrade, downgrade or staff move changes a service's price or billing cycle, its subscription at the old price is still cancelled, but the client now gets an email about it saying whether their credit or saved payment method pays the next renewal, and the order history notes it ([#5](https://github.com/cPFence/clientfence/issues/5)).

## 1.0.40 (2026-11-10)

### Added

- Import from Blesta: clients with their passwords (when Blesta's system key is entered), packages, services, domains, invoices, payments, tickets with attachments and tax rates, read from Blesta's database.
- Import from ClientExec: clients, packages, services, domains, invoices, payments, tickets and tax rules, read from ClientExec's database.
- Staff choose whether a client's credit pays a new order, as in WHMCS: Create New Order shows the client's credit and applies it up to the order total, or not at all. With "Do not apply credit", the cron no longer pays that invoice from credit.

### Changed

- The Migration tab starts with "Move from": pick WHMCS, Upmind, Blesta or ClientExec. Switching to another system deletes an unfinished plan after you confirm.
- Import checks and console messages name the system you import from.
- Marking a new order's first invoice as paid no longer takes the client's credit first.

### Fixed

- The admin area now reads right to left on every page in Arabic, Hebrew and Persian, not only the dashboard, and the text editor types right to left too.

## 1.0.39 (2026-11-09)

### Added

- [PayTabs](https://paytabs.com/en/) payment gateway, with saved cards for renewals. PayTabs is the number 1 payment gateway in the MENA region.
- Import from Upmind: clients, products, services, invoices, payments, tickets and tax rates, read through Upmind's API ([#3](https://github.com/cPFence/clientfence/issues/3)).

### Changed

- System Health's private paths check also tests the data folder, theme templates and library files, so a server without .htaccess support (Nginx) is caught.

### Fixed

- An empty Paid Invoice Prefix means no prefix again: paid invoices no longer keep the unpaid prefix. The update fills an empty paid prefix with the invoice prefix, so your numbers do not change ([#4](https://github.com/cPFence/clientfence/issues/4)).

## 1.0.38 (2026-11-09)

### Added

- PayPal Auto-Pay: a new PayPal gateway with billing agreements, for PayPal accounts without saved PayPal accounts (the Vault). Clients agree once at PayPal; renewals are then charged automatically, staff can charge now, and clients can pay at once with the saved agreement.
- PayPal Auto-Pay follows PayPal's notices: an agreement cancelled at PayPal is removed, a payment PayPal held is marked paid when it completes, and a chargeback refunds the invoice and moves the renewed services' due dates back.
- "Convert to for processing" in PayPal Auto-Pay charges invoices in other currencies in one PayPal currency, as WHMCS does.
- The WHMCS import can bring clients' PayPal billing agreements over, so they do not agree again (an import setting, off by default).
- A PayPal balance card on the admin dashboard for PayPal Auto-Pay and for PayPal with REST API keys, in your main currency. You can move it with Arrange cards (under Billing by default) and turn it off per gateway.
- Saved bank accounts for Stripe (a new Stripe setting, off by default; turn the method on in your Stripe dashboard too): clients can save a BECS (AUD), SEPA (EUR) or Bacs (GBP) Direct Debit account that pays renewals automatically. A debit still clearing shows "Payment pending" and is not charged again, reminded or suspended meanwhile.
- Support settings can turn tickets off, as Blesta can, for companies that use another help desk: clients and visitors then cannot open or reply to tickets, emails no longer become tickets, and ticket pages, menus and boxes are hidden. Tickets are kept for when tickets are turned on again. The knowledge base and network status keep their own switches.
- An optional outside help desk address (for example FreeScout): while tickets are off, the Support menu, the receipt upload on invoices and Request Account Deletion link to it.

### Changed

- Turning off or deleting a payment gateway works as in WHMCS: its unpaid invoices move to the gateway you choose. A gateway with active subscriptions or saved payment methods cannot be deleted (turn it off instead).
- A newly installed gateway takes its proper name and allows only the payment kinds it supports.
- Gateway settings always show On before Off. PayPal Auto-Pay settings are all on by default, and PayPal's pages show your company logo when no logo is set.
- The payment gateway list no longer shows "Allow subscriptions". On the gateway page the switch is now "Offer subscriptions for renewing services", with a note that renewal invoices can always be paid with every gateway.
- Every setting of the payment gateways, registrars and server panels, the "missing field" errors and the admin pop-ups and alerts are now in your language, in all 27 languages: about 380 texts per language, including ones FOSSBilling had left in English.

### Fixed

- After saving a payment gateway, its page shows the new name at once.
- The Add New Domain Registrar and payment gateway dialogs show the right adapter folder.
- Arabic: "Registration" is translated.

## 1.0.37 (2026-11-08)

### Added

- Five new domain registrars: Enom, INWX, NameSilo, Openprovider and Synergy Wholesale, for registrations, transfers, renewals, name servers, contacts, the transfer lock and the transfer code.
- Domain extensions that need registrant details ask for them on the order forms: the .us nexus questions, the .au eligibility details (Synergy Wholesale) and the .eu citizenship (NameSilo, INWX).
- Staff can correct those answers on the order before retrying a refused registration.

### Changed

- ClientFence renews domains when the invoice is paid: the registrar's own auto-renewal stays off with Enom, INWX and Openprovider, and is off by default with NameSilo. With all five new registrars, a cancelled domain is left to expire. Enom, INWX, NameSilo and Openprovider refuse premium names.

### Fixed

- Bell notifications of a deleted order or invoice are removed (they led to a missing page).
- A domain sync no longer clears the stored registration date when the registrar returns none.
- Translations of the premium-domain message (Indonesian, Traditional Chinese, Brazilian Portuguese) and of "Registrar" (Arabic, Brazilian Portuguese).

## 1.0.36 (2026-11-08)

### Added

- EU VAT: ClientFence checks a client's EU VAT number with the EU VIES service and refuses a wrong one.
- An EU business with a valid VAT number in another EU country than yours becomes tax exempt automatically, and its invoices carry a reverse-charge note.
- The exemption ends when a later save of the client's profile finds the number no longer valid, when the number is removed, or when the client moves out of the EU.
- When VIES does not answer, the number is saved, VAT is charged and the number is checked again the next day.
- Sign-up and the order page ask for an optional company VAT number while EU VAT checks are on; sign-up then also asks for the country.

### Removed

- The Client Balance payment gateway: account credit is applied with Apply credit on the invoice. If you added this gateway, delete it in Payment Gateways before you update.
- The unused option to send error reports to Sentry, and the libraries it needed (sentry/sentry and guzzlehttp/psr7).
- Logos of six payment gateways ClientFence does not have: Client Balance, Alipay, Interkassa, Onebip, 2Checkout and WebMoney.

### Fixed

- Brazilian Portuguese showed "ICMS" for the company VAT number.

## 1.0.35 (2026-11-07)

### Added

- Saved PayPal accounts: clients save their PayPal account on the Payment methods page or with "Pay and save my PayPal account", and their invoices are paid with it automatically.
- When a client saves their PayPal account, their old PayPal subscriptions are cancelled and the saved account pays the renewals.
- PayPal settings take optional REST API keys: staff send refunds back through PayPal, and ClientFence cancels PayPal subscriptions itself.
- Automatic payments settings: days between retries and how many retries after a failed charge.

### Changed

- The method marked "Pays invoices automatically" pays a client's invoices: their first saved card, a PayPal account when they save it, or the method they last chose with Use for invoices.
- The refund window asks whether to send the money back through PayPal, only record the refund, or add it as account credit.
- An invoice is not charged automatically again after a payment was taken for it or the payment service did not answer.
- "Charge saved card" on the invoice page is now "Charge saved method" and also charges a saved PayPal account.

### Fixed

- A saved card is no longer charged for an invoice the client already paid by bank transfer and sent the receipt for.

## 1.0.34 (2026-11-07)

### Added

- Modules can carry their own payment gateways, registrars, control panels and translations.
- Modules can add links to the client area menu.
- A module with a newer version in its manifest is upgraded on the next cron run.
- A module can name the oldest ClientFence version it needs; older sites refuse to activate it.

### Changed

- A module cannot be deactivated while one of its payment gateways, registrars or control panels is still installed.

### Fixed

- Extensions that name a minimum version in their manifest can be installed again (the check used a placeholder version).

## 1.0.33 (2026-11-06)

### Added

- Staff two-factor sign-in: staff turn on an authenticator app on their Profile page; the app code or the email PIN signs them in.
- Saved cards with Stripe: clients save a card when they pay or on the new Payment methods page (shown while Stripe is on).
- Invoices are paid with the saved card on the due date, with retries 7 and 14 days later and an email when a payment fails.
- Staff can charge a saved card from the invoice page and set the charge days and retries in Invoices settings.
- Find saved cards in Stripe links the cards of clients moved from WHMCS, matched by email.
- With the CAPTCHA on, guests answer it on their first domain search, then search without it for an hour.

### Changed

- System Health: Run checks now clears the script policy warnings the previous check already showed; only scripts blocked since then warn again.

### Fixed

- System Health no longer reports the scripts Cloudflare adds itself as script policy problems.

## 1.0.32 (2026-11-06)

### Added

- Security > Settings: every security setting on one page (sign-in rules, proxies, access lists, sessions), for Super Administrators.
- Trusted proxies such as Cloudflare, with the Cloudflare ranges built in, so sign-in rules, rate limits and logs see the visitor's real address.
- System Health warns when the portal sits behind a proxy that is not trusted.

### Changed

- Sign-in security moved from System > Settings, and the staff password reset switch from Staff settings, to Security > Settings.
- The API allowed addresses apply to API key sign-ins only and accept ranges.

### Fixed

- Turning the same browser check back on no longer signs everyone out.
- The same browser check reads the visitor's address through trusted proxies only.
- System Health no longer warns about an untrusted proxy when the web server already reads the visitor's address itself, as LiteSpeed does behind Cloudflare.

## 1.0.31 (2026-11-06, beta only)

### Added

- Security > Settings: every security setting on one page (sign-in rules, proxies, access lists, sessions), for Super Administrators.
- Trusted proxies such as Cloudflare, with the Cloudflare ranges built in, so sign-in rules, rate limits and logs see the visitor's real address.
- System Health warns when the portal sits behind a proxy that is not trusted.

### Changed

- Sign-in security moved from System > Settings, and the staff password reset switch from Staff settings, to Security > Settings.
- The API allowed addresses apply to API key sign-ins only and accept ranges.

### Fixed

- Turning the same browser check back on no longer signs everyone out.
- The same browser check reads the visitor's address through trusted proxies only.

## 1.0.30 (2026-11-04)

### Added

- Clients can add server access or other secrets to a ticket: encrypted, opened only by staff with the new "Open ticket secrets" permission (each opening is shown to the client), and deleted when the ticket closes. Turn it on under Settings > Support.

### Changed

- The staff IP address rule moved to Sign-in security and refuses a list that would lock you out.
- The sign-in country list starts with your current country.
- The client page tab "Payments" is now "Credit Balance".
- Admin help and documentation links point to ClientFence.

### Fixed

- Emails from staff actions (Mark as Paid, ticket replies, renewals, order activation) are sent at once instead of waiting for the cron.
- System Health sends a registrar or server alert only after two failed runs in a row.

## 1.0.29 (2026-11-03)

### Added

- Hebrew translations for the client and admin areas, with right-to-left client pages, sign-in screens, admin navigation and dashboard.

### Changed

- Importing clients from WHMCS preserves their Hebrew language preference.

### Fixed

- Persian (Farsi) now uses right-to-left layouts in the client area, sign-in screens, admin navigation and dashboard.
- Staff can choose their language before signing in.

## 1.0.28 (2026-11-03)

### Fixed

- A release whose database update fails is rolled back on its own: the database and the files go back to the backup made before the update, and the site keeps running the version it had.
- Database backups sent over SFTP are stored readable by their owner only.
- System Health no longer warns "Newest billing backup" on every install, and every check is shown in the staff member's language.

## 1.0.27 (2026-11-02)

### Changed

- Professional email templates: one clean layout for all 65 emails (logo, details box, button, footer) with new wording.

### Fixed

- Domain emails show the domain's nameservers; reminders for invoices already due no longer say "due in -N days".
- Removing a client also removes its subscriptions, cancelled at the payment gateway first.
- A module's "Manage settings" permission works without Extensions access.
- Pages a view-only role may not read show a 403 page instead of an error; Mass Mail and Hosting hide the tabs the role cannot use.
- Menu entries, the New menu, Settings and Export buttons show only to staff who have the permission.
- Staff password reset: the setting shows as on until it is saved, matching the sign-in page.
- More admin texts translated: module names, product types, promotion periods.

## 1.0.26 (2026-11-02)

### Fixed

- Invoices: a signed-in client can open, print and download their own invoices as often as needed; the invoice page no longer stops after 10 views an hour for everyone behind one address.
- Hosting: a retry of a failed Enhance setup reuses the panel login the first try made, so the order can be set up without first confirming the client's email.
- Sign-up: a used or expired email confirmation link shows its reason instead of an error page.
- Checkout: the renewal shown after a recurring promo code includes the discount.
- Language: the account owner's language choice is saved on the account.
- Invoices: the print view reads right to left in Arabic and fits a phone screen.
- Downloads: another client's download link shows the not-found page.
- Client area: confirmation dialogs show their question; tickets answered by staff show "Answered", also in the emails; a discount without a code is labelled "Discount"; US phone numbers show the United States; an empty gender stays empty; an authorized user without invoice access no longer sees the balance; no notifications bell before the email is confirmed.

## 1.0.25 (2026-11-02)

### Fixed

- PayPal: a failed subscription payment is logged as a failed payment; it no longer counts as money received.
- Payments: a Stripe or PayPal payment smaller than the balance due is recorded as a part payment, as in WHMCS, instead of being refused.
- Cancellations: a service paid by a PayPal subscription can be cancelled at the end of its billing period; a payment PayPal still takes for it goes to the client's credit. Staff cancel the subscription in PayPal.
- PayPal: a refund of a payment that account credit topped up is left to staff instead of refunding the whole invoice.
- Subscriptions: a renewal paid by the gateway no longer sends "Invoice Created" twice.
- Stripe: failed payments keep Stripe's reason, and failed subscription renewals are kept in the transactions list.

### Added

- Emails: the client gets a confirmation when they ask to cancel a service, and a refund email for each refund, as WHMCS sends.

## 1.0.24 (2026-11-02)

### Fixed

- Tax: with "Prices include tax", an invoice totals exactly the price the client was shown; the tax is the total less the net, as in WHMCS. Some prices were a cent off.
- Refunds: the credit note of a part refund totals exactly the amount refunded.

## 1.0.23 (2026-11-02)

### Changed

- Cancellation requests: they have their own list (Orders > Cancellation Requests) instead of a support ticket. The client gives a reason, chooses Immediate or End of billing period, and can withdraw the request while it waits. Staff get an email and accept or stop the request; with the automatic setting on, the daily cron processes waiting requests once a day. No renewal invoice is made while a request waits. Requests from 1.0.21 tickets move to the list.
- WHMCS import: open cancellation requests are imported into the list with their date, type and reason.

## 1.0.22 (2026-11-02)

### Fixed

- Invoices: the Remove credit link on the staff invoice page removes the applied credit.

## 1.0.21 (2026-11-02)

### Added

- Invoices: part payments stay on their invoice, which shows the amount paid and the balance due; Stripe and PayPal charge only the balance due.
- Invoices: staff and clients can apply credit to an invoice, in full or in part; staff can remove it.
- Refunds: part of a paid invoice can be refunded, and a refund can go to the client's credit.
- Cancellation requests: clients choose Immediate or End of billing period; staff accept or deny the request on the ticket, and the ticket list filters pending requests. A new Orders setting processes requests automatically by the cron.
- Hosting products: a new Free Domain Renewals setting renews a free domain at 0.00 while its hosting is active or suspended.
- Tax: a new "Prices include tax" setting: catalog prices are what the client pays, invoices show the net price and the tax in it, and a client exempt from tax pays the net price.
- Orders: staff can move a service to another product (Change Product), with the price recalculated and Change Package for hosting.

### Changed

- Invoices: Mark as Paid records only what is still due after payments and applied credit.
- Cancellation at the end of the period: staff can schedule it for any service billed by period, also without a gateway subscription.
- WHMCS import: free domain renewals and Inclusive Tax are kept as settings.
- Invoice emails: the amount due is the balance due (reset the invoice email templates to the default to use it).

## 1.0.20 (2026-11-01)

### Changed

- Plan changes: clients can change the plan of any active service; the days left are credited at its recurring price, also when it is paid ahead or has a discount.
- Invoices: Mark as Paid works on a partly paid invoice: the payments already recorded count, and only the rest is recorded.
- Hosting: staff can change the plan of a suspended or expired service.
- Hosting: a hosting plan on a full or disabled server can be ordered; its account waits in Failed actions for staff.

### Fixed

- Renewals: paying a renewal invoice always lifts the service's suspension.

## 1.0.19 (2026-11-01)

### Changed

- Hosting: a hosting plan on a full or disabled server can no longer be added to the cart.
- Cancellation at the end of the period: the service's open renewal invoice and its add-ons are cancelled with it, and its plan can no longer be changed.

### Fixed

- Updates: clearing the cache no longer lets a second update start, and two patch runs no longer overlap.
- Stripe: subscription checkout no longer charges an invoice that was already paid; a first charge that arrives late goes to the account balance.
- Stripe and PayPal: a failed renewal payment no longer marks the subscription cancelled while the gateway retries it.
- PayPal: refunds of duplicate payments and overpayments are left for staff, also when another gateway or account credit paid the invoice.
- Invoices: an invoice with a recorded transfer can no longer be set to Paid on its Manage tab.
- Renew Now is refused for a service whose cancellation at the end of its period is scheduled.
- Cancellation at the end of the period: a service paid beyond the gateway's end keeps its paid time.
- Sign-up: the verify link in the welcome email works.
- Sign-in: email PINs typed with Arabic or Persian digits are accepted.
- Orders: a staff-created order paid from the client's credit is activated once.

## 1.0.18 (2026-11-01)

### Changed

- Settings: only Super Administrators can change the cache settings and call hooks by hand.
- Email settings: staff who are not Super Administrators no longer see the SMTP password, SendGrid key or custom DSN.

### Fixed

- Email: password reset and sign-in emails are no longer copied to the Bcc address.
- Staff: "Allow Staff Reset Passwords" can be turned off.
- Checkout: the account balance pays the order whenever it covers the invoice in the client's currency.
- Support: a guest can reply to a closed ticket when its help desk allows reopening.

## 1.0.17 (2026-11-01)

### Changed

- Page loading: one modern, centered loading indicator in the admin and client areas.
- System Health: bz2 is no longer a suggested PHP extension.
- Network issues: staff with ticket management can post and remove them.

### Fixed

- Orders: marking a new order's invoice paid needs the invoice management permission.
- Orders: account users no longer see the hosting password set on the staff order form.
- Support: downloading ticket attachments and receipts needs the ticket view permission.
- Account users: saving an API key's allowed IPs and the options-change quote work again.
- Email: saved template previews hide API keys, licence keys, passwords and access links.
- Email: guest and staff emails can be resent from the email history.
- Domains: a registration, transfer or renewal the registrar refuses no longer activates or renews the order.
- Domains: a renewal retry no longer renews twice when the expiry lookup fails.
- Namecheap: errors are detected, unlocking works, and HTTP errors no longer log the API key.
- Internet.bs: renewals use the paid number of years, and pending transfers are accepted.
- Invoices: Mark as Paid is refused when a transfer is already recorded; record the rest as a transaction.
- PayPal: a refunded duplicate payment is left for staff instead of refunding the paid invoice.
- Account users: paying from the account balance needs the invoices permission.
- Licences: the licence check accepts only text for host, path and version.
- Downloads: replacing a file keeps the old one while a shopping cart still holds it.
- System Health: the checks fit on phone screens.

## 1.0.16 (2026-11-01)

### Changed

- Settings: only Super Administrators can change how email is sent and the client area footer JavaScript.
- Plan changes: a service paid by a PayPal subscription can change its plan once the subscription is cancelled in PayPal.
- Domains: a domain whose registration price differs from its renewal price is paid by invoice instead of a subscription.

### Removed

- The Order Button extension.

### Fixed

- Invoices: settling an invoice no longer takes the client's credit twice, and paying debits exactly the invoice total.
- Invoices: Mark as Paid after a partial transfer credits only the amount still due; an Add Funds invoice is paid only with Mark as Paid.
- Invoices: refund invoices get their own number, an accepted quote's invoice keeps the quote's tax, and the sales tax report shows refunds in their own period.
- Currencies: changing the default currency recalculates every exchange rate.
- Payments: a second card payment for a paid invoice goes to the account balance, and a retried payment is credited once.
- Payments: a renewal no longer starts a second gateway subscription, and a plan change or order deletion cancels the old one.
- PayPal: a subscription stays active until PayPal reports it cancelled; partial or unknown refunds are left for staff.
- Account balance: paying from the balance needs the page's security token.
- Automation: a service paid while the daily suspension or cancellation runs is no longer suspended or cancelled.
- Automation: cancelling a service cancels its unpaid renewal invoice also when it has a recurring discount.
- Automation: the web cron URL sends the suspension, renewal and ticket reply emails.
- Hosting: a Plesk plan change no longer changes the customer's password, and staff cannot change the plan of a suspended or expired service.
- API keys and licenses: a paid renewal keeps the key working, a plan change applies the new license checks, and keys get at least 16 random characters.
- Downloads: files with non-English names can be downloaded, and a file can be replaced while an order for it is pending.
- Sign-in: sign-up answers the same for registered and new email addresses; a sign-in waiting for its PIN or two-factor code is refused after a password change; an email change cancels older password reset links.
- Checkout: sign-up works with Turnstile and hCaptcha, and asks for the store's required client fields.
- Settings: changing the Redis host or port no longer sends the saved password to the new server; the invoice hash access and auto approval switches can be turned off.
- Support: a guest reply no longer reopens a closed ticket of a help desk that does not allow it.
- WHMCS import: two imports at once no longer undo each other, long names and messages are imported in full, the auto-suspend override carries over, and a fresh install can import.
- Translations: the plan change messages are translated.

## 1.0.15 (2026-11-01)

### Changed

- Staff permissions: changing a client's sign-in email, inviting an account user, making a user an owner or turning off a client's two-factor now needs "Change client passwords" as well as "Edit client profiles".

### Fixed

- Domains: a domain product can no longer be ordered as "use my own domain"; a client could take registrar control of another client's domain.
- Domains: a transfer renews at the registrar for the years it is billed for.
- Domains: a renewal is no longer sent to the registrar again when its first reply was lost.
- Domains: domain orders created by staff get a billing period, so they renew.
- Domains: nameservers are synced from the registrar.
- Staff permissions: staff without invoice access can no longer open invoices or invoice PDFs through invoice links, and each report needs the view permission of its group (billing, clients or support).
- Account users: quotes need the quotes permission, and order data no longer shows the owner's email, phone or address.
- Support: closing a ticket that is already closed no longer sends the feedback email again.
- Support: guest tickets from imported email are no longer refused by the CAPTCHA.
- Email: saved template previews no longer keep domain transfer codes.

## 1.0.14 (2026-10-31)

### Fixed

- Translations: the hosting texts added in 1.0.12 and 1.0.13 (Backups tab, backup restore, panel messages) are now translated in all languages.
- Enhance hosting: backups show their real size (files, databases and email); every backup showed 10 KB.
- Automation: an overdue suspended order the client pays while the daily cancellation runs is no longer cancelled.
- Automation: a client who renews while renewal invoices are being made is no longer charged a second renewal from credit.
- Domains: a renewal is no longer sent to the registrar again when reading the domain details afterwards fails.
- Invoices: with "remove unpaid invoices" on, the renewal invoice of an active or suspended order is kept; it was deleted and made again on every cron run, with an email each time.

## 1.0.13 (2026-10-31)

### Added

- Enhance hosting: a daily status check compares each hosting service with its Enhance subscription. A service suspended in Enhance but active in billing (or the reverse), a subscription deleted in Enhance, or a service that cannot be linked is listed in Orders > Failed actions. The check never changes billing or Enhance. Retry checks the service again and closes the entry once it matches.
- Enhance hosting: a Backups tab on the client's hosting account lists the last ten Enhance backups of each website. Staff can let clients restore them per product (Clients can restore backups, on the product's Configuration tab). Only the account owner can restore, with a confirmation, and email is optional. Each restore is written to the client's activity log.

## 1.0.12 (2026-10-30)

### Changed

- Enhance hosting: each service now keeps its Enhance organization and subscription, and every action (suspend, unsuspend, cancel, plan change, sign-in, mailboxes, DNS) uses them. A changed client email or domain, a website moved to another server, or a plan change in Enhance no longer breaks a service.
- Enhance hosting: services created before this version are linked to their subscription the first time they are used. A service that is not linked yet must be synced (Sync on the service) before it can be cancelled.

### Fixed

- Enhance hosting: making another domain the main one in the Enhance panel no longer stops the overdue suspension of the service.
- Enhance hosting: a new order no longer takes over a website the client made in the panel under another service, which let cancelling the new order delete the other service's websites.
- Enhance hosting: cancelling a service whose subscription is already gone from Enhance now completes.
- Cancellations: the payment subscription is cancelled before the hosting is removed, so a payment gateway error no longer leaves a deleted account on an open order.
- Upgrades: an upgrade paid after the service expired is no longer applied in billing without changing the server; staff settle it.
- Hosting servers: a server that is turned off, or has reached its maximum number of accounts, no longer gets new accounts.

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
