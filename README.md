<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="branding/clientfence-logo-dark.png">
    <img src="branding/clientfence-logo.png" alt="ClientFence by cPFence" width="360">
  </picture>
</h1>

The open-source billing platform built for hosting providers.
100% free. Open source. Your infrastructure, your rules.

ClientFence is a billing and client management system for hosting businesses: invoices,
payments, orders, domains, hosting accounts and support tickets in one client area.

- **Download:** the latest release from [GitHub Releases](https://github.com/cPFence/clientfence/releases/latest)
  (the zip and its SHA-256).
- **Documentation:** [clientfence.com/docs](https://clientfence.com/docs/), from installing to
  moving from [WHMCS](https://clientfence.com/docs/getting-started/import-from-whmcs/) or
  [Upmind](https://clientfence.com/docs/getting-started/import-from-upmind/).
- **Demo:** [demo.clientfence.com](https://demo.clientfence.com/login) (changes are not saved).
- **Community:** [ClientFence forum](https://community.cpfence.app/t/clientfence), for setup help
  and usage questions.

## Features

- **Client area** with the Palisade theme, and an admin area with a sidebar menu, Ctrl+K
  search and a dashboard. Both come in light and dark, left to right and right to left
  (Arabic, Hebrew and Persian), in 30 languages.
- **Accounts:** authorized users with their own permissions, two-factor sign-in and
  Google sign-in; staff groups with detailed permissions and sign-in security rules.
- **Billing:** invoices with part payments, credit and part refunds, an optional "prices
  include tax" mode, payment receipts, order form answers, cancellation requests, Stripe,
  PayPal and a manual payment gateway.
- **Hosting and domains:** an Enhance server manager with client pages for each hosting
  account (including Enhance backups), a daily status check, Change Product, and a Dynadot
  registrar.
- **Support:** tickets with file attachments, and ticket secrets: server logins a client adds
  to a ticket, stored encrypted and opened only by staff with that permission.
- **Moving from WHMCS or Upmind:** an import of clients, products, services, invoices and tickets
  from a WHMCS database, or from Upmind through its API.
- **Security and health:** security hardening and a system health page, built in.
- **Updates:** a daily check of the ClientFence release list on clientfence.com (it sends
  nothing about your site, and System → Update turns it off), a dashboard notice and a staff
  email for a new release, and an Update button that backs up, installs and finalizes it.
  Nothing else calls home: no telemetry, extension directory or error reports.

## Requirements

- PHP 8.3, 8.4 or 8.5 (64-bit), with the curl, dom, gd, iconv, intl, json, openssl,
  pdo_mysql, xml and zlib extensions. mbstring and OPcache are recommended.
- MySQL or MariaDB.
- A web server that reads `.htaccess` rules, such as Apache or LiteSpeed.
- Cron, to run the scheduled tasks every five minutes.

## Building a release

Build the release with Docker from the repository root:

```bash
docker buildx build --target release-artifact --output type=local,dest=build/release .
```

`build/release` then holds the files to upload.

## Installing

1. Create a MySQL or MariaDB database and a user for it.
2. Upload the release files to the document root of a domain or subdomain (not a
   subfolder). Include `.htaccess`: some upload tools skip files whose name starts with a dot.
3. Open `https://your-domain/install/` and follow the installer.
4. Do the final steps the installer shows: remove the `install` folder if it is still there,
   make `config.php` read-only and add the cron line, for example:

   ```text
   */5 * * * * php /path/to/clientfence/cron.php
   ```

## Upgrading

From the admin area: **System → Update** shows the new release and its notes; **Update
ClientFence** saves a backup of the database and the files it replaces (in
`.clientfence-backups/<site folder name>` next to the site folder), installs the release, updates the database and
signs you out. The site reopens on its own once the update is complete; only if the database
update needs attention does the admin area ask you to finalize it after signing in again. If the
release cannot be unpacked, the previous files are put back and the site keeps running the version
it ran before.

By hand:

1. Back up the database and the files (`config.php` and the `data` folder above all).
2. Upload the new release files over the old ones. Keep `config.php` and `data`.
3. Sign in to the admin area at once: it opens **Finalize update**, which applies the database
   updates; from then until you finish, visitors see a maintenance page. Or run the updates from
   the command line:

   ```bash
   php console.php system:run-patcher
   ```

System → Settings → System, Health tab, shows "Database updates" pending until they are
applied.

## Development

[CONTRIBUTING.md](CONTRIBUTING.md) describes how to send changes. Report security issues
as described in [SECURITY.md](.github/SECURITY.md).

## License

ClientFence is released under the Apache 2.0 license. It is based on FOSSBilling; see
[LICENSE](LICENSE) and [NOTICE](NOTICE).
