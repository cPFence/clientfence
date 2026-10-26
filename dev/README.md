# Local test site

A full ClientFence install for development and testing: the release image built from this
checkout, MariaDB, a Mailpit inbox that catches every email the site sends, and a test mailbox
server for ticket email import. It listens on
this machine's loopback only (127.0.0.1 and ::1), so nothing is reachable from outside.

Needs Docker with Compose and Python 3; any user who may run Docker can use it (root is not
needed).

## Commands

```bash
bash dev/site.sh up       # first run: makes dev/.env, builds, installs and adds test data
bash dev/site.sh update   # rebuild from this checkout and restart; data and settings stay
bash dev/site.sh seed     # add missing test data and set the base settings again
bash dev/site.sh status   # containers and addresses
bash dev/site.sh logs     # follow the site's logs
bash dev/site.sh down     # stop (data stays)
bash dev/site.sh reset    # delete everything and install again
```

## Addresses and logins

- Site: http://clientfence.localhost:8090/ (client area), admin at `/admin`.
- Mail inbox: http://clientfence.localhost:8091/.
- Logins and passwords are in `dev/.env`, made with random values on the first run and ignored
  by git: the admin (`ADMIN_EMAIL`, `ADMIN_PASSWORD`, API token `ADMIN_API_TOKEN`), a support
  agent in the "Support" staff group (`STAFF_EMAIL`, `STAFF_PASSWORD`) and three test clients,
  test-alice@, test-bob@ and test-carol@ the test email domain, sharing `CLIENT_PASSWORD`.
- Test addresses use `TEST_EMAIL_DOMAIN` (required: export a domain you own before the first run):
  ClientFence checks that an email's domain has mail records, which
  `.test` names do not. Mailpit catches every email, so nothing is delivered to that domain.
- To use other ports, change `SITE_PORT`, `MAIL_PORT` and `SITE_URL` in `dev/.env` together,
  then run `reset` (the site's address is saved in its config).

From another computer, open an SSH tunnel and browse to the same addresses:

```bash
ssh -L 8090:127.0.0.1:8090 -L 8091:127.0.0.1:8091 user@server
```

## Test data

`dev/seed.py` adds, through the admin API: the company name, email through Mailpit, an open
store, a bank transfer gateway, the support agent, a "Testers" client group, the three clients,
a hosting server and plan on the "Custom" server manager, the products "Starter Hosting" and
"Website Care", the `.test` domain extension on the "Custom" registrar, an active hosting order
with its invoice (Alice), an unpaid order (Bob) and a support ticket (Carol).

The "Custom" server manager and registrar call nothing outside. Never put live keys (panels,
registrars, payment gateways) into the test site; use a provider's test mode and test keys.

## Ticket email import

A test mailbox server (GreenMail) runs beside the site: it takes mail for any address over SMTP
and keeps it in that address's IMAP mailbox (in memory; a restart empties it). Logins are not
checked, so any password works.

- In the admin area, open Settings > Support > Help desks > a help desk, and under "Email
  importing" use server `imap`, encryption None, port `3143` (or SSL/TLS, port `3993`, with
  certificate checking off: its certificate is self-signed), a username such as
  `tickets@clientfence.test` and any password.
- Send test emails to that address from this machine on port `SMTP_PORT` (default 8092), for
  example:

  ```bash
  python3 -c "import smtplib; s=smtplib.SMTP('127.0.0.1', 8092); s.sendmail('test-alice@example.com', 'tickets@clientfence.test', 'Subject: Help\n\nMy site is down'); s.quit()"
  ```

- The cron reads the mailbox every 5 minutes; "Read now" on the help desk page does it at once.
  Results are in Support > Email import log, replies and refusals in Mailpit.
- Guest tickets need a sender domain with mail records (the address check), so use
  `TEST_EMAIL_DOMAIN` addresses rather than `example.org` ones.

## Database backups

Two backup servers run beside the site, user `backup` with the password `BACKUP_PASSWORD` from
`dev/.env` (added to an older `.env` by `update`); nothing is published on this machine.

- In System > Settings > System, tab Database Backups, SFTP: host `backup`, port `22`, folder `backups`, "Use SFTP" on.
  FTP: host `backup-ftp`, port `21`, folder empty, "Use SFTP" off.
- Emailed backups arrive in Mailpit.
- See the files with `docker exec clientfence-dev-backup-1 ls /home/backup/backups` (SFTP) or
  `docker exec clientfence-dev-backup-ftp-1 ls /home/backup` (FTP).
- To check a restore, load a backup into a new database and compare it with `CHECKSUM TABLE`.

## Where things are kept

- Database, `data/`, receipts and ticket files: Docker volumes of the `clientfence-dev` project.
- `config.php`: `dev/state/config.php` (git-ignored, readable by the web server user only),
  linked into the web root, so settings saved in the admin area are kept.
- If the first `up` stops during the test data, the next `up` adds it.
