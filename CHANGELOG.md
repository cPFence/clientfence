# Changelog

## 1.0.64 (2026-11-27)

Server groups as in WHMCS: one hosting product on several servers. Guide: [Server groups](https://clientfence.com/docs/hosting-and-domains/server-groups/).

### Added

- **Server groups:** a hosting product can use a group of servers. New accounts go to the least full server, or fill the servers in the group's order, and skip inactive and full ones. Servers get a maximum number of accounts. The WHMCS import brings its server groups across.
- **Server and username of a hosting service:** staff can set which server and username an existing account uses, saved in ClientFence only (for imported or moved accounts).
- **Orders > Move services by account list:** for panels that cannot look accounts up, paste the server's account list (one "domain username" per line; cPanel's /etc/trueuserdomains as it is). Each listed service is linked to that server and username; nothing is created on the server.
- **Subreg:** WHOIS privacy on and off (Subreg does not offer it for .cz and .eu), and Get EPP Code shows the code or has Subreg email it to the domain contact ([#82](https://github.com/cPFence/clientfence/issues/82)).

### Changed

- NameSilo, OpenSRS and CentralNic Reseller: the domain page says the transfer code was emailed to the domain contact, instead of showing that sentence as the code.
- Upgrades and downgrades keep a yes/no or quantity option the client chose when the new product has its own option with the same code, at the new product's price.

### Fixed

- Products of a service module switched off in Extensions no longer show in the store, cart, checkout or add-on offers, and their product page opens again (it showed an error).
- Extensions no longer uninstalls a service module while orders of it exist (after a new install, an old order could open another client's service).

## 1.0.63 (2026-11-25)

### Added

- **DNS hosting product:** a built-in product type that creates the client's zone at Cloudflare, PowerDNS, Hetzner DNS, ClouDNS, DigitalOcean or deSEC, with a records page, the nameservers to use and DNSSEC where the provider offers it ([#73](https://github.com/cPFence/clientfence/issues/73)).
- **More hosting panels:** Dashmox, NovaPanel, CyberPanel and OpenPanel ([#72](https://github.com/cPFence/clientfence/issues/72)).
- **SVG logos**, and a Restore default button on each brand image ([#65](https://github.com/cPFence/clientfence/issues/65)).
- **Products and services on the ticket page:** the client's services at the top of an admin ticket, as in WHMCS (amount, billing cycle, signup date, next due date, status); each opens in a new tab.
- **Edit a client's reply** on a ticket, as in WHMCS, for example to remove a password the client posted. The edit history keeps the old text and who made each edit.
- The staff "New reply from client" email shows the client's reply.
- **Language versions of the texts clients see:** invoice notes, company signature and legal texts, the email signature, store title, maintenance message, bank transfer instructions, suspension and cancellation reasons, and escalation replies.
- **Import Products step:** before an import from Upmind, Blesta, Clientexec, Paymenter or FOSSBilling, choose for each source product whether it stays custom or becomes a hosting product on a chosen server and plan.
- **Orders > Move services:** move the services of one product to another product in bulk.
- **Failed staff sign-in email:** the Super Administrators get an email at once when a staff sign-in fails, as WHMCS.
- The domain checker, contact and sign-in embeds take the client area's look: brand colours, font, buttons and fields, in light and dark mode.
- **Registrars:** Update and Test Connection on the registrar settings page, showing how long the registrar took or its error.

### Changed

- Admin and client forms wait up to 130 seconds for a slow panel or registrar before showing a time-out (was 30).
- Product and add-on descriptions keep their formatting (paragraphs, headings, bold, links) on the store card and at checkout, and their translations get the same editor.
- Domain actions a registrar does not support are disabled with the reason, and clients no longer see the registrar's name in errors; an empty transfer code from a registrar now tells the client to contact support ([#79](https://github.com/cPFence/clientfence/issues/79)).
- Changing an order's billing cycle in Edit Order stops its gateway subscription, as Recalculate on Save does.
- Email templates a release adds are listed under Email Templates right after the update.
- Staff ticket emails go only to staff who may view tickets.
- Country and network lookups (sign-in history, the staff sign-in country rule, IP Lookup and Antispam hosting networks) use current data again; installs still refresh it every week, and the earlier source had stopped updating.
- Security updates: Symfony 7.4.23 and Twig 3.31.1.

### Fixed

- Ticket replies and notes keep their line breaks, on the ticket pages and in the ticket emails.
- The client's name on a ticket reply links to the client's account.
- The admin reply editor is tall again.
- Gateways that take only recurring payments were hidden on the order and cart pages; an invoice that cannot become a subscription now opens the invoice page instead of an error ([#83](https://github.com/cPFence/clientfence/issues/83)).
- A registrar's price list fetch no longer stops after 30 seconds on a slow registrar such as Openprovider.
- Login to Control Panel (Enhance) works after the client deletes their website in Enhance.
- WHMCS import: products without a server group import as hosting when a server of their module was imported, and existing staff get their WHMCS signature.
- Saving settings no longer leaves config.old.php in the web root ([#80](https://github.com/cPFence/clientfence/issues/80)).
- Registrar and payment gateway settings forms mark required fields before saving.
- With Quantity Selection on, the order page of a domain or DNS hosting product no longer fails for clients and guests.
- In Arabic emails, summary labels no longer touch their values, and the invoice number in the email body keeps its # in front.
- Network issue emails give the status, priority and built-in labels such as All services in each client's language.
- The hosting server edit form shows a saved login the panel does not keep hidden, such as Enhance's Organization ID, so a change saves without typing it again.
- The support ticket a payment receipt upload opens is in the language the client is using.
- A failed activation or renewal no longer writes a double full stop in the order history.
- Domain name placeholders are no longer cut off in right-to-left languages.
- Opening and saving a description or article no longer drops text that looks like a tag, such as <domain>.

## 1.0.62 (2026-11-22)

### Added

- **Email layout:** one header, footer and CSS around every email, in Email Settings > Email Layout, as WHMCS's global email header and footer. Built-in templates now hold only their message; a template you edited before keeps its own design until you reset it.
- **Subreg and Spaceship registrars** built in ([#56](https://github.com/cPFence/clientfence/issues/56)).
- **Default domain contact:** Settings > Domain registration > Contacts sends one company contact as the admin, tech and billing contact of every domain, as WHMCS; the registrant is always the client, and the client's Contact tab says its form updates the registrant. "Use the client's details" stays the default.
- **Change a domain's registrar** from its admin page, for a domain moved to another registrar ([#58](https://github.com/cPFence/clientfence/issues/58)).
- The checkout summary shows what the domain renews at, next to the plan's renewal, and the client's domain page shows what its next renewal costs, tax included.
- **Tax rule names per language:** a tax rule (Settings > Tax) has a name for each language, shown on the client's invoices, quotes and checkout.
- **Hide Birthday and Gender:** one switch in Clients > Settings > General takes both fields off the client's sign-up, checkout and profile pages.

### Changed

- System Health's Release files unchanged check no longer counts the root .htaccess, where you add your own rules; Pack .htaccess rules still checks ClientFence's own blocks.
- A domain registration or transfer asks for the client's address, city, postal code, phone and country before payment, so the registrar can register it; a staff order or quote for a client whose profile lacks them is refused until they are added.

### Fixed

- Enhance plans imported a limit left at 0 in Enhance, such as Addon domains, as Unlimited.
- On an Enhance plan without mailboxes, the client area showed no mailbox limit instead of none.
- Staff sign-in limit: saving the countries together with an IP list that is switched off but holds your address is no longer refused as a lock-out.
- An email template language version or the email layout using a tag emails can't run, such as include, is refused when you save it, instead of failing when the email is sent.
- A PayPal subscription can be tried again on the same invoice after an abandoned try; an invoice whose PayPal subscription is already set up says so instead of offering a second one ([#63](https://github.com/cPFence/clientfence/issues/63)).
- A wide logo no longer runs over the Admin tag in the admin menu.
- An email template or layout that can't be saved no longer shows an internal template name in the error.
- A help desk's Close Tickets After set to 0 closed every answered ticket at the next cron; 0 now turns automatic closing off, as WHMCS.
- A long Upgrade / Downgrade or Change options list in the client area scrolls, so its Continue button can be reached.
- The checkout's domain box has room for longer domain extensions, which were cut off.
- The email template list shows one subject for a template with two, such as IP address change approved or refused.
- Code boxes (email templates and layout, embed code, blocked IPs) stay left to right in right-to-left languages, and email and ticket subjects, domain names and extensions (.com) show the right way round.
- Saving a built-in email template without changes, or only adding a language version, marked it Customized, so it stopped following built-in updates and the email layout; templates saved this way follow the built-in again after the update.
- An email layout that can't be saved names the box (Header, Footer or CSS) and its line.
- The email template and layout editors no longer show internal notes in the built-in text.

## 1.0.61 (2026-11-20)

The WebHosting|4U registrar built in, domains for up to 10 years, and payment gateway and registrar keys stored encrypted. Guide: [Registrars](https://clientfence.com/docs/hosting-and-domains/registrars/).

Several fixes in this release build on the work of [FOSSBilling](https://github.com/FOSSBilling/FOSSBilling), with thanks to its team and [@admdly](https://github.com/admdly): the PDF download sign-out, Add Funds income, the activity log index, encrypted gateway and registrar keys, currencylayer rates, Plesk sign-in, the client-area error messages and the breadcrumb link. Some were adapted for ClientFence.

### Added

- WebHosting|4U registrar: sells .gr, .ελ and about 295 other extensions through a WebHosting|4U reseller account. Thanks to [scool](https://community.cpfence.app/u/scool). A registrar set up from their module keeps its settings.
- Domains can be registered for up to 10 years (Allowed Registration Periods per TLD); without a list a TLD offers five years from its minimum. A 10-year term renews for 9 years. Reported by [scool](https://community.cpfence.app/u/scool).

### Changed

- System Health checks every release file for changes, not only ClientFence's own files.
- A domain transfer takes the extension's shortest period (for example 2 years for .gr) instead of always 1 year.
- Payment gateway and registrar keys and passwords are stored encrypted. The update encrypts the saved ones.
- The daily activity log clean-up is faster on large installs.

### Fixed

- Staff and clients were signed out when downloading an invoice PDF in Chrome.
- A registrar's Prices tab shows the new extensions after a first price fetch instead of "Nothing to show".
- Domains imported from WHMCS with 6 to 10-year terms now renew.
- Renew Now tells the client from which date a domain can be renewed when the registry's 10-year limit is reached.
- Add Funds was counted as income twice (the Add Funds invoice and the invoice its credit paid) in Stats, the dashboard and TrustScore. Saved figures are corrected; imported ones are kept.
- After an update signed staff out, signing in could open a client-area address and show "Page not found".
- Maintenance mode blocked the staff passkey sign-in and password reset. ([#54](https://github.com/cPFence/clientfence/issues/54))
- currencylayer exchange rates failed to update.
- Plesk sign-in behind Cloudflare or another proxy used the proxy's IP.
- Client-area pages showed red error messages from other scripts such as the captcha or Stripe.js.
- The home icon in the breadcrumbs of the admin client, custom page and mass mail pages was not a link.
- PDF invoices print the client's full country name instead of its code. ([#53](https://github.com/cPFence/clientfence/issues/53))
- On iPhone, the client and staff sign-in pages opened a passkey sheet on load that did nothing. ([#55](https://github.com/cPFence/clientfence/issues/55))

## 1.0.60 (2026-11-19)

Custom client fields like WHMCS, and the service page in WHMCS's terms. Guide: [Custom client fields](https://clientfence.com/docs/clients-and-billing/custom-client-fields/).

### Added

- Custom client fields as in WHMCS (System → Settings → Client, Custom Fields): Text Box, Link/URL, Password, Drop Down, Checkbox and Text Area, with select options, description, validation, display order, Admin Only, Required, Show on Order Form, Show on Invoice and translations. Password answers are stored encrypted. The WHMCS import brings the fields with these settings.
- Show on Invoice: the answer prints on invoices made from then on (invoice page, print view and PDF).
- Module Commands row on the admin service page's Details tab, as in WHMCS: Create, Suspend, Unsuspend, Terminate, Renew and the product's main commands, with a More menu for the rest.
- Payment Method per service on Edit Order: renewal invoices preselect it, and services with different methods renew on separate invoices. The WHMCS import brings each service's payment method.
- Edit Order: Recalculate on Save reprices a service from its product, and First Payment Amount shows the first invoice.
- Wallet amount buttons per currency (System → Settings → Currency, Add Funds preset amounts). Left blank, the usual amounts are converted with the currency's rate.
- Imports: download the import plan and, after the import, an audit file as CSV.

### Changed

- Services are named as in WHMCS: Products & Services tab on the admin client page with links on its Overview, the Addons tab on every main service, and services instead of orders in the client area.
- Edit Order: Price is now Recurring Amount.

### Fixed

- Invoices show your company number with your company details on the invoice page, the print view and the PDF. ([#53](https://github.com/cPFence/clientfence/issues/53))
- Invoices show your company address on separate lines, as in your settings. ([#53](https://github.com/cPFence/clientfence/issues/53))
- The invoice page and print view show your bank details only when Display Bank Account Information on Documents is on, as the PDF does. ([#53](https://github.com/cPFence/clientfence/issues/53))
- Ending a service without a reason writes "Order canceled" in its history, not "Canceled order for "; with a reason it reads "Order canceled: <reason>".

## 1.0.59 (2026-11-19)

Client options from WHMCS: per-client switches and an Inactive client status. Guide: [Client options and the Inactive status](https://clientfence.com/docs/clients-and-billing/client-options/).

### Added

- Client options on the client's Profile, as in WHMCS: Overdue Notices, Separate Invoices, Disable CC Processing, Marketing Emails Opt-in, Status Update and Allow Single Sign-On, next to Tax Exempt. Each change is logged.
- Inactive client status: an Inactive client signs in, orders, pays and opens tickets as an Active one. An optional automatic status update (System → Settings → Clients) sets clients without services Inactive once a day, and Active again when they have one; a new order sets them Active at once.
- Mass Mail: a Marketing email switch per message. Clients who opted out are skipped only for marketing messages; service notices reach every client in the filter.

### Changed

- The WHMCS import brings these client options, except Allow Single Sign-On (in WHMCS it covers sign-in from other apps, not Google), and keeps Inactive clients.

### Fixed

- Clients imported from Blesta, ClientExec, Upmind, FOSSBilling or Paymenter were saved as opted out of marketing email; the update opts them in again.
- Getting TLD prices from a registrar no longer turns off transfers for TLDs with a free transfer, such as .uk: the transfer is priced with your transfer markup and rounding (a percent markup keeps it free), and a new .uk TLD with a free transfer does not require a transfer code. For TLDs imported before this fix whose price list now shows a transfer cost of 0, turn Allow Transfer on, and for .uk set Require Transfer Code to No. ([#52](https://github.com/cPFence/clientfence/issues/52))

## 1.0.58 (2026-11-19)

A fixes release from the QA rounds: renewal invoices use part of the account credit, view-only staff see fewer controls they cannot use, and many billing, ticket, date and language fixes.

### Added

- Company name and address per language: in System → Settings → Company Details, choose a language and enter the name and address as they read in it (for example Cyrillic or Arabic script). Emails, the client area, and new invoices, quotes and statements use the client's language; an empty field shows the default. ([#51](https://github.com/cPFence/clientfence/issues/51))
- PayU India payment gateway: clients pay in rupees on PayU's page (cards, UPI, net banking, wallets), with refunds. Not yet tested with a real PayU account.
- CCAvenue payment gateway: clients pay on CCAvenue's page, with refunds. Not yet tested with a real CCAvenue account.
- Porkbun domain registrar: register, renew and transfer domains, name servers, contacts and a TLD price import. Not yet tested with a real Porkbun account.
- A "Support ClientFence" button on System → Update opens clientfence.com/sponsor in a new tab.

### Changed

- System Health has two backup checks, Database backup and Site files backup, that look at every source (the daily database backup and cloud copies) instead of a folder only the Linkers pack has.
- When an update finishes, a welcome screen shows the new version; Continue opens the sign-in page.
- Check for Updates and other actions that reload the page keep the loading animation until the next page shows.
- With several Enhance servers, an order's Change Hosting Plan list leaves out plans imported from another server. Plans made by hand, and plans imported before 1.0.58 while several Enhance servers were set up, are still listed for every account.
- Renewal invoices take part of the account credit: when the credit does not cover a renewal invoice (from the cron, a staff renewal or the client's Renew Now), it is applied and only the rest is due. Checkout is unchanged.
- An add-on bought for a service that is already paid ahead is charged up to the service's due date; the recurring price stays one period.
- The cron no longer suspends an expired service while its renewal invoice is still a draft, which the client cannot see or pay; it waits until the invoice is approved.
- Console commands refuse to run as root when the site's data folder belongs to another user, and `system:update` always refuses root. `cron.php` and `cron:run` started as root switch to the site's user, and stop with a message when they cannot.
- Updating removes unused keys from `config.php` (`debug`, `log_stacktrace`, `stacktrace_length`, `disable_auto_cron`, `report_errors`); the stack trace settings move under `debug_and_monitoring`. New installs no longer write `disable_auto_cron`.
- Staff change their own sign-in email and password only on the Profile page, and a new email needs the current password. Saving your own row on the Staff page keeps your email.
- View-only staff no longer see controls they cannot use on these pages: order and ticket actions, the reply box, the save, delete and switch buttons of the product and promotion edit pages and of the knowledge base, extension, language and System → Update pages, and New Invoice and New Support Ticket.
- Draft invoices: a draft is "not found" for clients and guests by its link (invoice page, PDF and pay pages); staff with invoice access still see it. The admin pages, the client's invoice lists and the dashboard show it as Draft, not Unpaid.
- Only a Super Administrator can change the text of the emails that carry a sign-in link, password reset link, invitation, email confirmation or sign-in PIN. Staff with the template right can still reset them and switch them on or off.
- Staff cannot remove more credit than the client can spend (the credit the client page shows); part payments kept for an invoice cannot be removed as credit. The credit form says "Credit balance updated".
- The client's emails for a staff reply and for a ticket opened by staff show the message formatted as on the ticket page, and the reply email shows the reply itself. Built-in emails you never edited get this on their own. If you edited one of these two emails, Reset on its page (System → Settings → Email) brings the new version and replaces your text.
- A domain ordered together with a hosting plan is registered with the nameservers of the plan's server, as in WHMCS.
- Staff password, username, domain and IP changes on a suspended or expired hosting account now reach the control panel too.
- Deleting or anonymizing a client also removes the bank transfer receipts they uploaded. Deleting a ticket also removes its ratings, feedback, assignment, pin and the other records ClientFence keeps about it.
- The CAPTCHA secret keys are shown only to staff who may change the settings.
- The API's per-address limit applies only to requests without a signed-in session, and only failed sign-ins count against the sign-in limit, so a team behind one office address is no longer locked out.
- The installer advises `config.php` mode 600 and names the System Health page.
- Console: `system:version` shows the ClientFence version, and `system:releasenotes` says when the site is on the latest version or the update check failed.
- Enhance: when a hosting plan holds several websites, the client's Email and Domains tabs let them pick the website.

### Fixed

- System → Update: after a successful update the page could show "HTTP error 503" instead of the welcome screen; the update and patch buttons now send their request only once.
- Admin on phones: card buttons such as Quick setup on the Settings page now sit on the right of the card title.
- Sync with server shows only for the panels that can read the account (cPanel/WHM, CWP and Enhance): DirectAdmin and Plesk orders no longer show an error, and HestiaCP orders no longer show a button that changed nothing.
- The price change email template can be previewed and saved before the first price change email is sent. ([#50](https://github.com/cPFence/clientfence/issues/50))
- Bank transfer instructions with currency conversion: `converted.total` on an unpaid invoice is now what is left to pay (after part payments and credit), as the invoice page shows. ([#49](https://github.com/cPFence/clientfence/issues/49))
- Several Enhance servers: hosting plans now remember the Enhance server they were imported from, so importing from a second server no longer matches the first server's plans. A plan whose name is already taken gets the server name in brackets. With several Enhance servers set up before this update, plans imported earlier still match by name.
- Bank transfer payments that staff record show on the account statement as payments for the invoice, not as account credit added.
- Pages in Urdu and Yiddish read right to left, as their emails and PDFs already did.
- Dates: an unreadable date in a search filter, a list, a statistics chart, an order, an invoice or a promotion is refused with "Enter a valid date." instead of an error.
- Lists: the "to" date includes that whole day on the order, invoice, transaction, client and credit lists, and the client credit list's date filter works. The order list's filter by several order ids matches all of them, and a search keeps the title filter.
- Statistics charts show every day of the chosen range and count the whole last day.
- A gateway payment is credited only once when its notice comes again (Stripe, Mollie, eWAY, GoCardless, PayTabs, PayPal Auto-Pay and PayPal Email), so a retried notice no longer leaves free credit.
- Stripe: Process on a failed payment row no longer deletes the row that holds the credit, or shows the payment twice on the invoice.
- A card payment pays its invoice when the client's balance was already below zero.
- Deleting an invoice line works out the gateway fee again on the new amount.
- An invoice that staff lower to what was already paid on it is marked paid, and the rest stays the client's credit.
- Refunds: a part refund of a taxed invoice counts without its tax in income figures, credit notes use the invoice number as the invoice shows it, and the WHMCS import keeps the refund amount WHMCS recorded.
- The account statement lists a paid Mass Pay invoice by its fee only, so its totals no longer count the money twice.
- Saving a product's Configuration tab no longer clears the Prorata Billing days and the affiliate commission set on its General tab.
- Failed actions: two Retry clicks at once no longer set up the account twice; the second is refused with a message.
- Suspending, cancelling or deleting a domain ordered with hosting no longer does the same to the hosting's add-on.
- Add New Order: the start date defaults to the server's today, so orders are no longer refused when the staff browser's day is ahead of the server's.
- Emails to a recipient without a time zone use the system time zone, so dates match the client area and the PDF.
- Emails in the reader's language: ticket status and priority, the mail import "not added" notice and the mass mail unsubscribe line.
- Admin labels in the staff member's language: escalation rule statuses, failed actions on the order page, staff status, staff group permission headings and the System Health email check.
- Arabic: a percentage promotion line keeps its "%".
- The sign-in PIN email's link to the sign-in page carries the email address.
- Pages that failed for staff with fewer rights now open: product and add-on pages without the Currency, server or licence rights, the email template page without Staff access, and the staff group page (a script error from an empty tooltip).
- Account users without access get the usual no-access page on Payment methods, services and emails, and the invoice list opens for users without quotes access.
- Control panels: Plesk reseller accounts can be suspended, unsuspended and changed, Plesk unlimited limits are sent as unlimited and empty ones are left out, the CWP package change works, and cPanel, HestiaCP, DirectAdmin and CWP errors say clearly whether the panel answered an error or could not be reached. A new hosting plan keeps the settings given when it is created.
- Form builder: product and order counts are right, a copied form keeps its choices, style and texts, and saving a field without options keeps its choices.
- Products: the delete message is right, deleting an add-on can no longer delete a main product, staff with the view right can read categories, the store pairs only enabled and visible products, and an option group with one option reads "1 option".
- Tickets: an unknown help desk or status is refused, staff cannot open a ticket for a client who does not exist, an escalation rule whose help desk was deleted still applies its other changes, and a canned reply's title cannot be cleared.
- Domains: installed registrars are no longer offered as available, and a contact's company and second address line are optional.
- Email templates: your own templates can use the client and staff variables (for example `{{ c.first_name }}`), and a bulk delete also removes their staff group links.
- System Health: a failed backup shows the time of its own run.
- Security → Settings offers the Traefik proxy header and keeps it on save. A sign-in security save without the country fields keeps the staff country rule.
- Themes: a theme is only a folder name, a theme that is not installed answers "not found", and the client area falls back to the default theme when its folder is missing.
- Confirm boxes in the admin area show their texts as plain text, so a client's sign-up email cannot add HTML to them.
- Custom pages keep their description and keywords when an update leaves them out.
- The staff groups table scrolls inside its card on a phone, and the knowledge base category filter's label works.
- API: calls with numbers or lists where text is expected answer a clear message instead of a PHP error; methods starting with `_` are not calls; `template_send` takes 1 and 0; only an enabled language can be the default; an unknown staff status or passkey is refused; `user_create` needs the same rights as inviting a user; the core update checks the right before asking the update feed.
- A hosting order can use a subdomain such as shop.example.com as its domain; the staff order form no longer says "TLD is invalid".
- An action that returns to another tab of the same page now reloads it, so the list is up to date.

## 1.0.57 (2026-11-17)

Mass Pay, moving a service to another client, part payments, draft invoices and 16 more languages.

### Added

- **Mass Pay:** clients tick unpaid invoices (or Pay all) and pay them in one payment with any payment method. One invoice lists them, and each one is marked paid when it is paid. One gateway fee for the one payment.
- **Move a service to another client:** staff move a service and its add-ons to another client from its order page. Invoices, payments and credit stay with the old client, and renewals invoice the new one. A service with an unpaid invoice or a gateway subscription is refused until that is settled. Nothing changes on servers or registrars.
- **Part payments:** Mark as Paid has an Amount field, so staff record part of a payment on any payment method and the rest stays due. A part payment recorded by mistake can be reversed. ([#46](https://github.com/cPFence/clientfence/issues/46))
- **Draft invoices:** Add New Order can leave the new invoice as a draft for staff to check; Approve sends it to the client. An invoice whose email was not sent shows Send invoice, and the invoice list has a Not sent filter. ([#48](https://github.com/cPFence/clientfence/issues/48))
- **Enhance Link:** link a hosting order to an existing Enhance organization and subscription by hand, on its Service Management tab. ([#47](https://github.com/cPFence/clientfence/issues/47))
- **16 new languages:** Swedish, Danish, Norwegian Bokmål, Estonian, Slovak, Slovenian, Croatian, Bosnian, Serbian in Cyrillic, Georgian, Azerbaijani, Korean, Chinese (Hong Kong), Hindi, Bengali and Kannada. Invoice, quote and statement PDFs in Hindi, Bengali and Kannada are in English.
- **Import from FOSSBilling and Paymenter:** two new importers on the Migration page. ([#40](https://github.com/cPFence/clientfence/issues/40))
- The converting bank transfer gateway is now called CustomConvert and works with all currencies: it shows the invoice total in the currency you set on it. Set the currency after updating: with none set it shows euros.

### Changed

- Mark as Paid records the payment on every payment method, without charging the gateway. A draft invoice cannot be marked as paid.
- When an Enhance panel cannot be reached, the error says why: host name not found, connection refused, timed out or a certificate problem.
- The store's billing cycles fit on a phone screen.
- Korean, Chinese and Thai invoice, quote and statement PDFs show their letters instead of empty boxes.
- Browsers set to Chinese for Hong Kong or Taiwan get Traditional Chinese.
- The language menu shows a scroll bar when more languages are below.

### Fixed

- Passkey sign-in from the email field's autofill works on iPhone. ([#45](https://github.com/cPFence/clientfence/issues/45))

## 1.0.56 (2026-11-16)

A new look for the admin and client areas, and new billing features.

### Added

- **Reverse payment:** undo a payment staff recorded by mistake on a paid invoice (for example a bank transfer that never arrived). The invoice is unpaid again, credit it used comes back, renewals move back and a service it set up is suspended. Gateway payments still use Refund. ([#38](https://github.com/cPFence/clientfence/issues/38))
- **Prorata billing:** a product can renew all its services on one day of the month (Prorata Date), with a Charge Next Month day. The first invoice charges the days up to that date; add-ons follow their service's date. The WHMCS import brings over a product's Prorata Billing settings. ([#33](https://github.com/cPFence/clientfence/issues/33))
- **Bulk domain check:** clients check one name across up to 20 extensions at once and add several domains to the cart. Staff can pin up to 8 extensions that are ticked first. The embedded domain checker does the same. ([#29](https://github.com/cPFence/clientfence/issues/29))
- **Logo for emails:** a separate logo for emails, for example one with its own background so it stays clear in dark mode.
- **Taxed lines on staff invoices:** a new Tax setting, "Tax lines staff add to invoices", starts new invoice lines as taxed when the client pays tax.
- **CAPTCHA off from the console:** `php console.php clientfence:signin-security-off --captcha` turns CAPTCHA Protection off when a wrong key locks staff out.
- **Client Overview:** the admin client page opens on an Overview with key figures, billing, a trust score for staff, services, support, recent services and invoices, notes, files and emails.
- Suspend all, Unsuspend all and Cancel all services of a client from its Overview; each service goes through its own action, as on its order page.
- Send a client the welcome email or one of your own templates from its Overview.
- **Database tab:** System → Settings → System lists the database tables by size. Optimize tables gives back the space of deleted rows, and Run clean-up now removes old logs and ticket files at once, following your retention settings. Super Administrators only.
- **Payment method order:** arrows on System → Payment Gateways set the order clients see the payment methods in. The first one a client can use is picked at checkout and on Add Funds; an invoice keeps its own method. ([#43](https://github.com/cPFence/clientfence/issues/43))
- **PO numbers:** a purchase order number per client, used on their new invoices, which staff can change on one invoice. Clients can set their own on their profile. It shows on the invoice, the print view, the PDF and the invoice emails, and invoice search finds it. ([#31](https://github.com/cPFence/clientfence/issues/31))

### Changed

- **New look:** the admin area, the client area, the emails, the invoice PDF and the print view have a modern redesign, with dark mode, right-to-left languages and phones covered. Built-in emails you never edited get the new look on their own. Built-in emails you edited keep your text: Regenerate Built-in Templates (System → Settings → Email, Bulk Actions) replaces them with the new look. Language versions you added and your own templates stay as they are.
- Hosting plans go from the store straight to the checkout page, where the client chooses the domain. Domains on their own still go through the cart.
- A promo code never takes away a free domain: each line gets the larger of the code's discount and the free domain.
- The checkout places an order only at the total it shows: when an automatic promotion changes the total, it shows the new total first.
- With "Prices include tax" on, a price staff type on a taxed invoice line includes the tax, as order and renewal lines do.
- The import plan lists the clients with the most credit first, so a wrong imported balance is easy to spot.
- Profile and Edit on the client page are one Profile tab with General, Security, Cards and Custom fields.
- Every client page tab has paging and a records selector, including Users, Contacts, ID documents and Affiliate.
- Admin lists show the records selector at the start and the page numbers at the end, on every list.
- **Icons:** the admin and client areas use Bootstrap Icons, and the edit and delete buttons in admin lists are plain icons without a box.
- **Side menus:** both areas use a rail like WiseCP's, dark glass in dark mode and a solid deep rail in light mode, without scrollbars. A collapsed client menu opens over the page on hover.
- Services and Support tickets in the client area have small status cards that filter the list, and active services come first (also on the admin client page).
- Client pages for a service, ticket, email, quote, statement, knowledge base article and hosting account have a Back link to their list.
- Wallet, payment and public pages have smaller page heads.
- Users, Contacts and Support tickets show the list first; the add form opens from its button.
- Table rows hover with one flat tint, secondary buttons hover in soft teal, and admin primary buttons in dark mode use the client area's brand colour.
- On the admin client page, the Credit Balance tab shows the balance in key figure cards and the Tickets tab counts filter the list.
- **Brand color:** the default brand color is now the theme's own navy and teal. The quick setup wizard keeps the admin's colors and buttons, and its preview shows the new client area. Emails use the brand color when it is the default too.
- On the admin client page, New Order only shows to staff who may create orders. The invoice page shows each action only to staff who have the right to use it.
- New Order in the New menu and on the Orders list, and the invoice editing tabs, also show only to staff who may use them.
- An invoice link opened without signing in shows the bank transfer and other manual payment details. Sending the transfer receipt still needs signing in. ([#41](https://github.com/cPFence/clientfence/issues/41))
- Deleting a client also removes its quotes, service and order records, ticket messages and saved payment links. Payment history is kept.
- The store's domain search runs straight away, with the chosen extension ticked first.
- The client page's trust score counts unpaid invoices past their due date as paid late.

### Fixed

- Add New Order no longer adds the first product on its own. ([#35](https://github.com/cPFence/clientfence/issues/35))
- The admin back-to-top button no longer covers the version number. ([#36](https://github.com/cPFence/clientfence/issues/36))
- Email history shows emails with their styling. ([#39](https://github.com/cPFence/clientfence/issues/39))
- A payment fee no longer stays on an invoice when the client picks another payment method: the fee follows the chosen method on the invoice, the store checkout and the cart page, and Due today and the pay button show it. ([#42](https://github.com/cPFence/clientfence/issues/42))
- The store no longer shows a free billing cycle as a saving.
- System Health no longer says the database backup is kept only on this server when cloud storage keeps a copy.
- The empty-list icon no longer covers its text, and the Security sign-in summary rows line up.
- Behind Cloudflare, email addresses on pages no longer show as "[email protected]".
- In client area dark mode, the language, notification and account menus are readable.
- The account statement shows the payment for an invoice paid partly from account credit and partly by a payment that staff recorded.
- A draft invoice can no longer be charged to a saved card.
- Add Funds on the admin client page's Credit Balance tab no longer opens an error page.
- The quote page of a deleted client opens again, showing "Deleted client".
- In client area dark mode, outline buttons are readable.
- The checkout no longer shows a "Discount −$0" line after an automatic promotion ends.
- An invoice link opened while signed out returns to that invoice after sign-in.
- Opening another client's email shows "not found" instead of an error page.
- The ticket feedback page has a Back link, and the admin side menu arrows are smaller.
- Service types and "MRR" are translated, and the email template list shows readable subjects.
- Enhance: the client area shows this month's real bandwidth instead of 0 MB. ([#44](https://github.com/cPFence/clientfence/issues/44))
- After a part payment, the payment fee is worked out on what is left, so the amount the invoice page asks for pays the invoice.
- The Orders list no longer opens an error page for staff who may manage orders but not view products.
- The checkout accepts only a payment method it offers.

## 1.0.55 (2026-11-16)

A security release. Every new feature is off until you turn it on, and does no extra work while it is off. A new security page explains each feature: https://clientfence.com/security/

### Added

- **Passkeys:** clients and staff can sign in with a fingerprint, face or device PIN instead of a password. A passkey also counts as the second sign-in step.
- **Required two-factor sign-in:** require it of every client or of chosen client groups; they set it up at their next sign-in.
- **Sign-in history:** clients and staff see their recent sign-ins with country, network and browser, and can sign out their other sessions.
- **Email PIN only from a new place:** the sign-in PIN can be asked only when the country or network is new for that login.
- **Fraud check on new orders:** MaxMind minFraud or FraudLabs Pro scores each new order, and orders above your limit wait for staff review in Orders → Waiting for review.
- **ID documents:** ask new clients, chosen client groups or clients with a held order to upload an ID document; their orders wait until staff approve it.
- **Banned emails and phone numbers:** banned addresses, email domains and phone numbers are refused at sign-up, at checkout and on profile changes.
- **Hosting and VPN network check:** sign-ups and orders from hosting, data-centre and VPN networks can be held for review or blocked, with exceptions per client.
- **Cloud backups:** scheduled copies of the database, and of the site's files if you choose, to S3-compatible storage, Bunny Storage, Google Drive or OneDrive, with optional encryption, keeping the last copies.
- **API request log:** staff API calls, payment gateway notices, staff sessions and client changes can be logged with secrets hidden, kept for the days you choose.
- **Low balance alert:** clients can ask for an email when their account credit falls below an amount they choose.

### Changed

- The Database Backups tab is now called Backups (System → Settings → System): an overview of every destination with its last result, Back up now with a choice of destinations, one tab per destination with setup steps for each service, and the cloud storage settings.
- The Blocked IP List accepts IP ranges and wildcards, and also applies at checkout.
- Clients get an email when two-factor sign-in is switched on for their account.
- Stored payment gateway notices keep only the request headers and basic details, not the server's settings.
- The client's data download also lists their passkeys, ID document records, low balance alert and logged API requests.

## 1.0.54 (2026-11-15)

Updating from 1.0.52 or older? 1.0.53 came out earlier today with the Mollie and GoCardless payment gateways, automatic promotions, several client groups and Slack, Discord and Telegram notifications: read its [release notes](https://github.com/cPFence/clientfence/releases/tag/v1.0.53).

### Fixed

- Upmind import: the read no longer stops with a "UNIQUE constraint failed" error on tickets, and no records are skipped on larger accounts ([#28](https://github.com/cPFence/clientfence/issues/28)).

## 1.0.53 (2026-11-15)

### Added

- **Mollie payment gateway:** clients pay on Mollie's page (cards, iDEAL, Bancontact, SEPA, PayPal and more) and can save a card or bank account for automatic renewals, which you can turn off. Refunds go back through Mollie, and chargebacks are taken off the invoice. Not tested on real accounts yet.
- **GoCardless payment gateway:** clients set up a Direct Debit once (Bacs, SEPA, BECS, ACH, PAD and other schemes) and their invoices are collected from their bank account, with failed payments retried, chargebacks and refunds. Not tested on real accounts yet.
- **Automatic promotions:** a promotion can apply without a code, to every client or only to chosen client groups, at checkout and on renewals, and staff can apply a promotion to an unpaid invoice or set the promotion an order renews with. ([#22](https://github.com/cPFence/clientfence/issues/22))
- **Several client groups:** a client can belong to more than one client group. ([#22](https://github.com/cPFence/clientfence/issues/22))
- **Slack, Discord and Telegram notifications:** staff alerts, such as new orders, payments, tickets and failures, can be sent to your Slack, Discord or Telegram channels, each channel with the alerts you choose.

### Changed

- A promotion you disable no longer comes off renewals, also for services that already have it; turn it back on to resume. Its end date and last use still do not stop it, as before.

### Fixed

- With several automatic promotions, the one that saves the client most is now chosen when products have configurable options with setup fees.
- Staff can apply a Free Setup promotion to an unpaid invoice, and deleting a promotion's discount line gives its use back.
- The automatic promotion's name now shows on the cart, the first invoice and the service page.
- The admin search box no longer makes pages wider than the screen on phones.
- The theme icon in the admin header has less space around it, like the icons next to it.

## 1.0.52 (2026-11-14)

### Added

- Start date on staff Add New Order: a service can start on a past day and its first period counts from it, while the invoice is dated today. "Already set up" now works for every product type, a domain can be added as an existing domain with no registrar action, and the activation date can be edited on the order page. ([#19](https://github.com/cPFence/clientfence/issues/19))
- Support PIN lookup: an 8-digit Support PIN typed into the admin client search finds that client, and each match is logged. ([#20](https://github.com/cPFence/clientfence/issues/20))
- Upgrades to every product in a category, including products added to it later. ([#24](https://github.com/cPFence/clientfence/issues/24))
- Receipt upload can be turned on or off for each offline payment method. ([#26](https://github.com/cPFence/clientfence/issues/26))
- Update from the server: `php console.php system:update` runs the same checks, backup and rollback as System > Update; `--check` only shows the versions.

### Changed

- The New menu opens New Client, New Invoice and New Client Ticket as popups on the page you are on, New Order opens Add New Order, and on a client's page they fill in that client. ([#21](https://github.com/cPFence/clientfence/issues/21))
- The logo, dark logo and icon on Settings > System are upload boxes, as in the setup wizard. ([#23](https://github.com/cPFence/clientfence/issues/23))
- The Email registrar opens one support ticket per domain for its requests and changes the domain only when staff mark them done. It no longer emails staff and shows the change as made. With support tickets off, orders and renewals go through with a warning in the activity log, and clients are asked to contact you for other domain changes. ([#25](https://github.com/cPFence/clientfence/issues/25))
- Support PINs are unique, and the client dashboard shows the PIN only after a click.

### Fixed

- A staff email entered in the client area's password reset form is now named as such in the security log, which points to the reset on the admin sign-in page.
- A product added in the New Invoice form gets its first available price instead of 0.
- The Openprovider registrar in test mode reaches the Openprovider sandbox again; every test-mode call timed out before. ([#27](https://github.com/cPFence/clientfence/issues/27))

## 1.0.51 (2026-11-14)

### Added

- Affiliate program: clients share a link and earn a commission on the services their visitors order, as a percentage or a fixed amount, once or on every renewal, set per product and per affiliate. Commissions clear after the clearing days, and refunds, chargebacks and downgrade credit take them back. Affiliates ask for a payout in the client area and get a monthly report email; staff pay it as account credit or outside ClientFence.
- Custom CSS for the client area: super admins add their own stylesheet in the Palisade theme settings, loaded after the theme on every client page.

### Changed

- The WHMCS and Blesta importers bring affiliates with their referred services, commissions, payouts and balances, and their old affiliate links keep working.

## 1.0.50 (2026-11-14)

### Added

- Add-ons for a service a client already has: the client orders one from the service page through the cart, and staff add one from the order's Addons tab. The first invoice covers the days left to the service's due date, then the add-on renews with the service.
- Bulk invoice actions on the invoice list: Mark Paid, Mark Unpaid, Mark Cancelled, Send Reminder and Delete for the selected invoices, with a list of the ones skipped and why.

### Changed

- A service started on the 29th, 30th or 31st now renews on the last day of shorter months and goes back to its own day after them (31 Jan, 28 Feb, 31 Mar). The new Renewal Day setting in Orders settings can add calendar months instead, as WHMCS does.
- The Blesta and ClientExec importers bring clients' add-ons, linked to their service.

### Fixed

- Deleting an order that is still pending setup now cancels its unpaid invoice instead of leaving it empty. If the invoice also bills another order, only the deleted order's lines are removed.

## 1.0.49 (2026-11-13)

### Added

- Accounting sync to Xero, FreeAgent and QuickBooks Online: approved invoices with their clients, payments and refunds go to your books on their own, one way. You choose the bank account for each payment method and the matching tax rates, and a sync log shows what was sent. FreeAgent and QuickBooks can connect to their test company first.
- Six new domain registrars: OpenSRS, Realtime Register, ConnectReseller, CentralNic Reseller, OVHcloud and Nominet (.uk), each with a TLD price import (not yet tested with live registrar accounts).

## 1.0.48 (2026-11-12)

### Added

- Chargebacks: a Stripe dispute or a PayPal reversal refunds the payment on its invoice and moves the renewed services' due dates back. A dispute you win, or one that takes back only part of the invoice, leaves a note on the invoice for you.

### Changed

- A client's services due on the same day renew on one invoice, as in WHMCS. A service paid by a gateway subscription keeps its own invoice.

### Fixed

- DirectAdmin: creating accounts, suspend, unsuspend and package changes work again.
- A client cannot order a domain again while their earlier order for it waits for setup.
- System Activity shows "System" instead of "Guest" for entries the server writes, such as cron.

## 1.0.47 (2026-11-12)

### Added

- eWAY payment gateway: payments on eWAY's secure page, saved cards for renewals and pay-now, and refunds (not yet tested with a live eWAY account).
- Payment method fees: per gateway a percentage and a fixed amount, an option to tax the fee, and client groups that pay none. The fee follows the payment method the invoice is paid with, and checkout and the staff Order Summary show it before paying.
- `php console.php clientfence:staff-password` sets a staff member's password from the server, for an owner locked out of the admin area.
- "Show Client Balance" has a third choice, "Only when not zero" ([#17](https://github.com/cPFence/clientfence/issues/17)).

### Changed

- Staff cancelling or deleting an order also cancels its unpaid first invoice, as in WHMCS, and frees the credit applied to it.

### Fixed

- New customers return to the checkout after signing up and after confirming their email ([#16](https://github.com/cPFence/clientfence/issues/16)).
- The count badges on the client Invoices tabs sit in the middle of their labels ([#15](https://github.com/cPFence/clientfence/issues/15)).

## 1.0.46 (2026-11-12)

### Added

- TLD prices from your registrar: get its price list, set a markup and rounding, preview the new prices and apply the TLDs you tick. For Enom, ResellerClub, Namecheap, NameSilo, Dynadot, Internet.bs, INWX, Openprovider and Synergy Wholesale (not yet fully tested with each registrar).
- Scheduled TLD price sync per registrar: off, email the changes, or apply them. "Keep my price" leaves a TLD's price as you set it.
- Daily domain expiry sync: a share of the domains each day instead of one big monthly run (a switch, off by default).
- Domain transfer tracking: a transfer stays "in progress" until the registrar completes it, then the client is emailed; a failed or stuck transfer goes to Failed actions (a switch, off by default; not yet fully tested with each registrar).
- Licences answer the checks of software sold with the WHMCS Licensing Addon, so installed software keeps working after a move from WHMCS.
- Per-unit billing of licences: the licence reports its usage and the monthly price follows the count, with switches to hold big drops, warn and suspend.
- Maintenance mode in System > Settings, as in WHMCS: a switch, your own message to visitors, an optional redirect address and IP addresses that still see the site. Signed-in staff keep the client area, and every admin page shows a banner while it is on.

### Changed

- The WHMCS import keeps each licence's WHMCS service and product numbers.
- The TLD list has a search, pages and an Active switch.

### Fixed

- When the store is closed, or open to testers only, staff can add domains in Add New Order, and a hosting product's free domain list shows its TLDs.
- Domain and hosting activation emails leave out the Nameservers, Account details and Control panel sections when they have nothing to show.
- System Health shows "Last run 0 seconds ago" right after you run the checks, instead of "Last run ago".

## 1.0.45 (2026-11-11)

### Added

- Licences and API keys for a set number of servers: clients add and remove their own IP addresses, with a limit of changes per 30 days and optional staff approval (product Configuration, Client IP Addresses).
- The licence page shows the allowed IPs and domains, the recent checks and how to check a licence.

### Changed

- A new client service page: the main facts at the top, a Manage panel with the actions, and the service in tabs, for every service type.
- A licence is not offered a plan with fewer servers than its IP addresses.
- Cookie Consent settings take a message and button text per language; left blank, visitors see the built-in text in their own language.

### Fixed

- Deleting a ticket also removes its notifications.
- The admin invoice page's "Subtotal" is translated, and the admin dashboard tabs fit on a phone in right-to-left languages.
- Two addresses no longer give a server error: the old admin client create page and the download of an order without a file.
- System Health also checks the files of the newer registrars, payment gateways and email templates.

## 1.0.44 (2026-11-11)

### Added

- Add New Order works as in WHMCS: staff order several products and domains on one invoice, with a promo code, a payment method and a live order summary.
- Email templates, products, help desks and order form questions in each language; a new language starts from the default text (an email template already in that language), and emails go out in the recipient's language.
- Support PIN for every client, shown on the client dashboard and the staff client page.
- Ticket limit per client: how many tickets a client may open in a period (Support settings).
- Your own links at the end of the client menu (Palisade theme settings).
- Store links that open a product with a billing cycle and a promo code already applied.
- "Store opens on" in the Orders settings: the billing cycle the store shows first, for example yearly.
- A store category's description shows as a note on its tab.
- Clients see cancelled and refunded invoices in a third tab ([#12](https://github.com/cPFence/clientfence/issues/12)).
- "Use this card for automatic payments" when a client saves a new card and already has an automatic one ([#11](https://github.com/cPFence/clientfence/issues/11)).

### Changed

- With email confirmation required, a new client can still order and pay, as in WHMCS; a banner asks them to confirm ([#12](https://github.com/cPFence/clientfence/issues/12)).
- Creating an account from the checkout returns to the cart ([#12](https://github.com/cPFence/clientfence/issues/12)).
- The card payment page is headed "Pay invoice" with the amount due, and the save boxes show only for a new card ([#11](https://github.com/cPFence/clientfence/issues/11)).
- With a renewal invoice open, the service page shows "Pay invoice" instead of "Renew Now" ([#14](https://github.com/cPFence/clientfence/issues/14)).
- The hosting page hides the Email tab when the plan allows no email accounts ([#14](https://github.com/cPFence/clientfence/issues/14)).
- Quick setup gives your business email to the help desk that still has the installer's address, and System Health warns about one ([#14](https://github.com/cPFence/clientfence/issues/14)).
- Clearer bank transfer texts: the client uploads their own transfer receipt ([#13](https://github.com/cPFence/clientfence/issues/13)).
- Imports from Blesta, Upmind and ClientExec keep each client's language, and the WHMCS, Blesta and Upmind imports bring their translated product, help desk and order form texts along.
- Dropdown lists open as a modern menu in the theme's colours, in the admin and client areas (Chrome and Edge; other browsers keep their own list).
- The text editor follows the dark theme in the admin and client areas.

### Fixed

- The hosting activation email gives the website's own IP on an Enhance cluster, not the control panel's ([#14](https://github.com/cPFence/clientfence/issues/14)).
- A Stripe payment no longer leaves an error transaction when order setup takes longer than 10 seconds ([#14](https://github.com/cPFence/clientfence/issues/14)).
- Radio buttons no longer look dark on the light theme when the device is in dark mode ([#13](https://github.com/cPFence/clientfence/issues/13)).
- Security review fixes: plan change credit with recurring promotions, adding funds, payment notices and domain registration years.
- The Blesta import copies ticket attachments only from Blesta's support folder.

## 1.0.43 (2026-11-10)

### Changed

- Stripe card payments have a "Save this card for future invoices" box, ticked by default as in WHMCS. A saved card pays renewals automatically, and the client can untick the box or remove the card later on Payment methods ([#7](https://github.com/cPFence/clientfence/issues/7)).
- A newly installed Stripe gateway starts with subscriptions off: Stripe renews with the saved card, as WHMCS and Blesta do. Stripe subscriptions that clients already have keep running ([#7](https://github.com/cPFence/clientfence/issues/7)).

## 1.0.42 (2026-11-10)

### Added

- Invoice settings: Document Title and Tax Number Label, for example "Tax Invoice" and "ABN" for Australian GST invoices ([#9](https://github.com/cPFence/clientfence/issues/9)).
- Pay an invoice at once with your saved PayPal account ("Pay now with").
- Payment methods link in the client area sidebar ([#7](https://github.com/cPFence/clientfence/issues/7)).

### Changed

- Emails use your brand color for their buttons, top line and links ([#10](https://github.com/cPFence/clientfence/issues/10)).
- The service page says why Upgrade / Downgrade is not available right now ([#8](https://github.com/cPFence/clientfence/issues/8)).
- The "automatic payment stopped" email links the Payment methods page when no payment method is saved ([#7](https://github.com/cPFence/clientfence/issues/7)).

### Fixed

- The Stripe payment form lists the client's saved cards, also those saved on the Payment methods page ([#6](https://github.com/cPFence/clientfence/issues/6)).

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
