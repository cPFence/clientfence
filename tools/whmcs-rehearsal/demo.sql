-- An invented WHMCS 9 database for tools/whmcs-rehearsal.sh: WHMCS's table and column names (the
-- ones the import reads so far), with made-up people and numbers. No WHMCS code or data.
-- Encryption key (cc_encryption_hash) of the demo: demo-whmcs-encryption-key.
-- Login password of every demo login with a bcrypt hash: demo-password-1.
-- Two-factor: Avery's setting is in the older WHMCS cipher; Bruno's in WHMCS 9's format (after its
-- 2FA storage migration), TOTP secret KRSXG5CTMVRXEZLUIRSW23ZS.

CREATE TABLE tblconfiguration (setting varchar(64) NOT NULL, value text NOT NULL, PRIMARY KEY (setting));
INSERT INTO tblconfiguration VALUES ('Version', '9.0.4-release.1'), ('CompanyName', 'Demo Hosting');

CREATE TABLE tblcurrencies (id int(10) NOT NULL AUTO_INCREMENT, code text NOT NULL, prefix text NOT NULL, suffix text NOT NULL, format int(1) NOT NULL, rate decimal(10,5) NOT NULL, `default` int(1) NOT NULL, PRIMARY KEY (id));
INSERT INTO tblcurrencies VALUES (1, 'USD', '$', ' USD', 2, 1.00000, 1), (2, 'EUR', '€', '', 3, 0.92000, 0);

CREATE TABLE tblclientgroups (id int(10) unsigned NOT NULL AUTO_INCREMENT, groupname varchar(45) NOT NULL, groupcolour varchar(45) NOT NULL DEFAULT '', discountpercent decimal(10,2) unsigned NOT NULL DEFAULT 0, susptermexempt text NOT NULL, separateinvoices text NOT NULL, PRIMARY KEY (id));
INSERT INTO tblclientgroups VALUES (1, 'Resellers', '#ff0000', 5.00, 'on', '');

CREATE TABLE tblcustomfields (id int(10) NOT NULL AUTO_INCREMENT, type text NOT NULL, relid int(10) NOT NULL DEFAULT 0, fieldname text NOT NULL, fieldtype text NOT NULL, description text NOT NULL, fieldoptions text NOT NULL, regexpr text NOT NULL, adminonly text NOT NULL, required text NOT NULL, showorder text NOT NULL, showinvoice text NOT NULL, sortorder int(10) NOT NULL DEFAULT 0, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblcustomfields (id, type, relid, fieldname, fieldtype, description, fieldoptions, regexpr, adminonly, required, showorder, showinvoice, sortorder) VALUES
  (1, 'client', 0, 'How did you find us?', 'dropdown', '', 'Search,Friend,Ad', '', '', '', 'on', '', 0),
  (2, 'client', 0, 'Account manager', 'text', '', '', '', 'on', '', '', '', 1),
  (3, 'product', 1, 'Server hostname', 'text', '', '', '', '', 'on', 'on', '', 0);

CREATE TABLE tblcustomfieldsvalues (id int(10) unsigned NOT NULL AUTO_INCREMENT, fieldid int(10) NOT NULL, relid int(10) NOT NULL, value text NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (1, 1, 'Friend'), (2, 1, 'Dana'), (1, 2, 'Search'), (3, 1, 'srv1.demo.example');

CREATE TABLE tblclients (
  id int(10) NOT NULL AUTO_INCREMENT, uuid varchar(255) NOT NULL DEFAULT '', firstname text NOT NULL, lastname text NOT NULL, companyname text NOT NULL, email text NOT NULL,
  address1 text NOT NULL, address2 text NOT NULL, city text NOT NULL, state text NOT NULL, postcode text NOT NULL, country text NOT NULL, phonenumber text NOT NULL, tax_id varchar(128) NOT NULL DEFAULT '',
  password text NOT NULL, authmodule text NOT NULL, authdata text NOT NULL, currency int(10) NOT NULL, defaultgateway text NOT NULL, credit decimal(16,2) NOT NULL DEFAULT 0,
  taxexempt tinyint(1) NOT NULL DEFAULT 0, latefeeoveride tinyint(1) NOT NULL DEFAULT 0, overideduenotices tinyint(1) NOT NULL DEFAULT 0, separateinvoices tinyint(1) NOT NULL DEFAULT 0, disableautocc tinyint(1) NOT NULL DEFAULT 0,
  datecreated date NOT NULL, notes text NOT NULL, billingcid int(10) NOT NULL DEFAULT 0, securityqid int(10) NOT NULL DEFAULT 0, securityqans text NOT NULL, groupid int(10) NOT NULL DEFAULT 0,
  lastlogin datetime NULL, ip text NOT NULL, host text NOT NULL, status enum('Active','Inactive','Closed') NOT NULL DEFAULT 'Active', language text NOT NULL, pwresetkey text NOT NULL,
  emailoptout tinyint(1) NOT NULL DEFAULT 0, marketing_emails_opt_in tinyint(1) NOT NULL DEFAULT 0, overrideautoclose tinyint(1) NOT NULL DEFAULT 0, allow_sso tinyint(1) NOT NULL DEFAULT 1,
  email_verified tinyint(1) NOT NULL DEFAULT 0, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblclients (id, firstname, lastname, companyname, email, address1, address2, city, state, postcode, country, phonenumber, tax_id, password, authmodule, authdata, currency, defaultgateway, datecreated, notes, securityqans, groupid, ip, host, status, language, pwresetkey, marketing_emails_opt_in) VALUES
  (1, 'Avery', 'Stone', 'Stone Studio', 'avery@demo-client.example', '12 High St', '', 'Leeds', 'West Yorkshire', 'LS1 1AA', 'GB', '+44.1132000000', 'GB123456789', '', '', '', 1, 'paypal', '2023-04-01', 'Prefers email.', '', 1, '198.51.100.10', '', 'Active', 'english', '', 1),
  (2, 'Bruno', 'Keller', '', 'billing@keller.example', 'Hauptstr. 5', '', 'Berlin', '', '10115', 'DE', '+49.301234567', '', '', '', '', 2, 'banktransfer', '2024-02-10', '', '', 0, '198.51.100.11', '', 'Active', 'german', '', 0),
  (3, 'Chen', 'Wu', '', 'chen@demo-client.example', '8 Garden Rd', '', 'Singapore', '', '018956', 'SG', '+65.61234567', '', '', '', '', 1, 'paypal', '2022-08-15', '', '', 0, '198.51.100.12', '', 'Inactive', '', '', 0),
  (4, 'Dana', 'Ortiz', 'Ortiz Legal', 'dana@ortiz.example', '1 Plaza Mayor', '', 'Madrid', '', '28012', 'ES', '+34.911234567', 'ESB12345678', '', '', '', 2, 'banktransfer', '2021-01-20', '', '', 0, '198.51.100.13', '', 'Closed', 'spanish', '', 0),
  (5, 'Avery', 'Stone', 'Stone Labs', 'labs@demo-client.example', '12 High St', '', 'Leeds', '', 'LS1 1AA', 'GB', '', '', '', '', '', 1, 'paypal', '2025-06-01', '', '', 0, '198.51.100.10', '', 'Active', 'english', '', 1);

CREATE TABLE tblusers (id int(10) unsigned NOT NULL AUTO_INCREMENT, first_name varchar(255) NOT NULL, last_name varchar(255) NOT NULL, email varchar(255) NOT NULL, password varchar(255) NOT NULL, language varchar(32) NOT NULL DEFAULT '',
  second_factor varchar(255) NOT NULL DEFAULT '', second_factor_config text, remember_token varchar(100) NOT NULL DEFAULT '', reset_token varchar(255) NOT NULL DEFAULT '', security_question_id int(10) unsigned NOT NULL DEFAULT 0, security_question_answer varchar(255) NOT NULL DEFAULT '',
  last_ip varchar(64) NOT NULL DEFAULT '', last_hostname varchar(255) NOT NULL DEFAULT '', last_login timestamp NULL, email_verification_token varchar(100) NOT NULL DEFAULT '', email_verification_token_expiry timestamp NULL, email_verified_at timestamp NULL,
  reset_token_expiry timestamp NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblusers (id, first_name, last_name, email, password, language, second_factor, second_factor_config, last_ip, last_login, created_at) VALUES
  (1, 'Avery', 'Stone', 'avery@demo-client.example', '$2y$12$ztLAxpLtfFARcVKHruNiOuaoSPj2CJ2ttszID4BeE4Zh0166QnQj.', 'english', 'totp', 'SuXEtg07Up8rdkTszGD8++yJaJMfRx4KThsTWRILEHhmZm5wDXo1L/ooQfTnjuBw6gTIssAQrVouPtvPt0gDAAg=', '198.51.100.10', '2026-09-30 10:00:00', '2023-04-01 09:00:00'),
  (2, 'Bruno', 'Keller', 'bruno@keller.example', '$2y$12$ztLAxpLtfFARcVKHruNiOuaoSPj2CJ2ttszID4BeE4Zh0166QnQj.', 'german', 'totp', '7777d0d96c399d99ccb7bbd80ef3a204b8c1211942de9555c8c78e9dc3c8f34d7955cfd9e335fcf4b006968dfeb0ad6bd51c54fe73ec041929ad16c1a75b9775', '198.51.100.11', '2026-08-01 12:00:00', '2024-02-10 08:00:00'),
  (3, 'Chen', 'Wu', 'chen@demo-client.example', 'e10adc3949ba59abbe56e057f20f883e', '', '', NULL, '', NULL, '2022-08-15 08:00:00'),
  (4, 'Dana', 'Ortiz', 'dana@ortiz.example', '$2y$12$ztLAxpLtfFARcVKHruNiOuaoSPj2CJ2ttszID4BeE4Zh0166QnQj.', 'spanish', '', NULL, '', NULL, '2021-01-20 08:00:00'),
  (5, 'Eli', 'Park', 'eli@stone-studio.example', '$2y$12$ztLAxpLtfFARcVKHruNiOuaoSPj2CJ2ttszID4BeE4Zh0166QnQj.', '', '', NULL, '198.51.100.20', '2026-09-01 15:00:00', '2025-01-05 08:00:00'),
  (6, 'Fay', 'Nolan', 'fay@nowhere.example', '$2y$12$ztLAxpLtfFARcVKHruNiOuaoSPj2CJ2ttszID4BeE4Zh0166QnQj.', '', '', NULL, '', NULL, '2025-03-03 08:00:00');

CREATE TABLE tblusers_clients (id int(10) unsigned NOT NULL AUTO_INCREMENT, auth_user_id int(10) unsigned NOT NULL, client_id int(10) unsigned NOT NULL, invite_id int(10) unsigned NOT NULL DEFAULT 0, owner tinyint(3) unsigned NOT NULL DEFAULT 0, permissions text, last_login timestamp NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblusers_clients (auth_user_id, client_id, owner, permissions, last_login, created_at) VALUES
  (1, 1, 1, '', '2026-09-30 10:00:00', '2023-04-01 09:00:00'),
  (2, 2, 1, '', NULL, '2024-02-10 08:00:00'),
  (3, 3, 1, '', NULL, '2022-08-15 08:00:00'),
  (4, 4, 1, '', NULL, '2021-01-20 08:00:00'),
  (1, 5, 1, '', NULL, '2025-06-01 08:00:00'),
  (5, 1, 0, 'profile,contacts,products,invoices,tickets', '2026-09-01 15:00:00', '2025-01-05 08:00:00'),
  (5, 5, 0, 'products,tickets', NULL, '2025-06-02 08:00:00'),
  (1, 2, 0, 'invoices', NULL, '2025-07-01 08:00:00');

CREATE TABLE tblcontacts (id int(10) NOT NULL AUTO_INCREMENT, userid int(10) NOT NULL, firstname text NOT NULL, lastname text NOT NULL, companyname text NOT NULL, email text NOT NULL, address1 text NOT NULL, address2 text NOT NULL, city text NOT NULL, state text NOT NULL, postcode text NOT NULL, country text NOT NULL, phonenumber text NOT NULL, tax_id varchar(128) NOT NULL DEFAULT '',
  subaccount int(1) NOT NULL DEFAULT 0, password text NOT NULL, permissions text NOT NULL, domainemails int(1) NOT NULL DEFAULT 0, generalemails int(1) NOT NULL DEFAULT 0, invoiceemails int(1) NOT NULL DEFAULT 0, productemails int(1) NOT NULL DEFAULT 0, supportemails int(1) NOT NULL DEFAULT 0, affiliateemails int(1) NOT NULL DEFAULT 0, pwresetkey text NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, pwresetexpiry timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblcontacts (userid, firstname, lastname, companyname, email, address1, address2, city, state, postcode, country, phonenumber, password, permissions, invoiceemails, supportemails, domainemails, pwresetkey, created_at) VALUES
  (1, 'Gina', 'Books', 'Books & Co', 'accounts@books.example', '3 Ledger Ln', '', 'Leeds', '', 'LS2 2BB', 'GB', '+44.1132000001', '', '', 1, 0, 0, '', '2024-05-05 08:00:00'),
  (2, 'Hans', 'Tech', '', 'it@keller.example', '', '', '', '', '', 'DE', '', '', '', 0, 1, 1, '', '2024-06-06 08:00:00'),
  (2, 'Broken', 'Address', '', 'not-an-email', '', '', '', '', '', 'DE', '', '', '', 1, 0, 0, '', '2024-06-07 08:00:00');

CREATE TABLE tblnotes (id int(10) NOT NULL AUTO_INCREMENT, userid int(10) NOT NULL, adminid int(10) NOT NULL, created datetime NOT NULL, modified datetime NOT NULL, note text NOT NULL, sticky int(1) NOT NULL, PRIMARY KEY (id));
INSERT INTO tblnotes (userid, adminid, created, modified, note, sticky) VALUES (1, 1, '2025-02-02 10:00:00', '2025-02-02 10:00:00', 'Agreed a 10% loyalty discount by phone.', 1), (4, 1, '2024-12-12 10:00:00', '2024-12-12 10:00:00', 'Closed: moved to another provider.', 0);

CREATE TABLE tblauthn_account_links (id int(10) unsigned NOT NULL AUTO_INCREMENT, provider char(32) NOT NULL, remote_user_id char(255) NOT NULL, user_id int(11) NULL, client_id int(11) NULL, contact_id int(11) NULL, metadata text, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblauthn_account_links (provider, remote_user_id, user_id, metadata, created_at) VALUES ('google_signin', '109876543210987654321', 2, '{"email":"bruno.keller@gmail.example"}', '2025-01-01 08:00:00');

-- The catalogue: two groups, a cPanel server (password encrypted with the demo key) and one
-- with a module ClientFence lacks, products with USD and EUR prices, add-ons, an upgrade path,
-- promotions, two TLDs and a bundle.
CREATE TABLE tblproductgroups (id int(10) NOT NULL AUTO_INCREMENT, name text NOT NULL, slug varchar(128) NOT NULL DEFAULT '', headline text, tagline text, orderfrmtpl text NOT NULL, disabledgateways text NOT NULL, hidden tinyint(1) NOT NULL DEFAULT 0, `order` int(1) NOT NULL DEFAULT 0, icon varchar(255) NOT NULL DEFAULT '', created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblproductgroups (id, name, slug, headline, tagline, orderfrmtpl, disabledgateways, hidden, `order`) VALUES
  (1, 'Care plans', 'care', 'Website care', 'We look after your site', '', '', 0, 1),
  (2, 'Hosting', 'hosting', '', '', '', '', 0, 2);
CREATE TABLE tblservers (id int(10) NOT NULL AUTO_INCREMENT, name text NOT NULL, ipaddress text NOT NULL, assignedips text NOT NULL, hostname text NOT NULL, monthlycost decimal(16,2) NOT NULL DEFAULT 0, noc text NOT NULL, statusaddress text NOT NULL, nameserver1 text NOT NULL, nameserver1ip text NOT NULL, nameserver2 text NOT NULL, nameserver2ip text NOT NULL, nameserver3 text NOT NULL, nameserver3ip text NOT NULL, nameserver4 text NOT NULL, nameserver4ip text NOT NULL, nameserver5 text NOT NULL, nameserver5ip text NOT NULL, maxaccounts int(10) NOT NULL DEFAULT 0, type text NOT NULL, username text NOT NULL, password text NOT NULL, accesshash text NOT NULL, secure text NOT NULL, port int(8) NULL, active int(1) NOT NULL, disabled int(1) NOT NULL DEFAULT 0, PRIMARY KEY (id));
INSERT INTO tblservers (id, name, ipaddress, assignedips, hostname, noc, statusaddress, nameserver1, nameserver1ip, nameserver2, nameserver2ip, nameserver3, nameserver3ip, nameserver4, nameserver4ip, nameserver5, nameserver5ip, maxaccounts, type, username, password, accesshash, secure, port, active) VALUES
  (1, 'Demo cPanel', '192.0.2.10', '192.0.2.11', 'cp.demo.example', '', '', 'ns1.demo.example', '', 'ns2.demo.example', '', '', '', '', '', '', '', 300, 'cpanel', 'root', 'SuXEtg0hQcBtIgTykSPn/OSBCfEAAAAAAAAAAAAAAAAZF15DR15yZA==', 'demo-whm-api-token', 'on', NULL, 1),
  (2, 'Old Virtualmin', '192.0.2.20', '', 'vm.demo.example', '', '', '', '', '', '', '', '', '', '', '', '', 0, 'virtualmin', 'root', '', '', '', NULL, 1);
CREATE TABLE tblservergroups (id int(10) NOT NULL AUTO_INCREMENT, name text NOT NULL, filltype int(1) NOT NULL, PRIMARY KEY (id));
INSERT INTO tblservergroups VALUES (1, 'cPanel servers', 1);
CREATE TABLE tblservergroupsrel (id int(10) NOT NULL AUTO_INCREMENT, groupid int(10) NOT NULL, serverid int(10) NOT NULL, PRIMARY KEY (id));
INSERT INTO tblservergroupsrel VALUES (1, 1, 1);
CREATE TABLE tblproducts (id int(10) NOT NULL AUTO_INCREMENT, type text NOT NULL, gid int(10) NOT NULL, name text NOT NULL, slug varchar(128) NOT NULL DEFAULT '', description text NOT NULL, hidden tinyint(1) NOT NULL DEFAULT 0, showdomainoptions tinyint(1) NOT NULL DEFAULT 0, stockcontrol tinyint(1) NOT NULL DEFAULT 0, qty int(10) NOT NULL DEFAULT 0, proratabilling tinyint(1) NOT NULL DEFAULT 0, paytype text NOT NULL, allowqty int(1) NOT NULL DEFAULT 0, autosetup text NOT NULL, servertype text NOT NULL, servergroup int(10) NOT NULL DEFAULT 0, configoption1 text NOT NULL, freedomain text NOT NULL, freedomainpaymentterms text NOT NULL, freedomaintlds text NOT NULL, recurringcycles int(2) NOT NULL DEFAULT 0, autoterminatedays int(4) NOT NULL DEFAULT 0, overagesenabled varchar(10) NOT NULL DEFAULT '', `order` int(10) NOT NULL DEFAULT 0, retired tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id));
INSERT INTO tblproducts (id, type, gid, name, slug, description, paytype, autosetup, servertype, servergroup, configoption1, freedomain, freedomainpaymentterms, freedomaintlds, `order`) VALUES
  (1, 'other', 1, 'Website Care', 'website-care', 'Updates, backups and checks every month.', 'recurring', 'payment', '', 0, '', '', '', '', 1),
  (2, 'hostingaccount', 2, 'Hosting Starter', 'hosting-starter', 'One site, 10 GB.', 'recurring', 'payment', 'cpanel', 1, 'demo_starter', 'once', 'annually', '.com', 2),
  (3, 'hostingaccount', 2, 'Hosting Plus', 'hosting-plus', 'Five sites, 50 GB.', 'recurring', 'payment', 'cpanel', 1, 'demo_plus', '', '', '', 3),
  (4, 'other', 1, 'Site audit', '', 'A one-off review of your site.', 'onetime', '', '', 0, '', '', '', '', 4);
CREATE TABLE tblpricing (id int(10) NOT NULL AUTO_INCREMENT, type enum('product','addon','configoptions','domainregister','domaintransfer','domainrenew','domainaddons','usage') NOT NULL, currency int(10) NOT NULL, relid int(10) NOT NULL, msetupfee decimal(16,2) NOT NULL, qsetupfee decimal(16,2) NOT NULL, ssetupfee decimal(16,2) NOT NULL, asetupfee decimal(16,2) NOT NULL, bsetupfee decimal(16,2) NOT NULL, tsetupfee decimal(16,2) NOT NULL, monthly decimal(16,2) NOT NULL, quarterly decimal(16,2) NOT NULL, semiannually decimal(16,2) NOT NULL, annually decimal(16,2) NOT NULL, biennially decimal(16,2) NOT NULL, triennially decimal(16,2) NOT NULL, PRIMARY KEY (id));
INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, tsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially) VALUES
  ('product', 1, 1, 0, 0, 0, 0, 0, 0, 20.00, -1, -1, 200.00, -1, -1),
  ('product', 2, 1, 0, 0, 0, 0, 0, 0, 19.00, -1, -1, 190.00, -1, -1),
  ('product', 1, 2, 5.00, 0, 0, 0, 0, 0, 5.00, -1, -1, 50.00, -1, -1),
  ('product', 1, 3, 0, 0, 0, 0, 0, 0, 12.00, -1, -1, 120.00, -1, -1),
  ('product', 1, 4, 10.00, 0, 0, 0, 0, 0, 99.00, -1, -1, -1, -1, -1),
  ('addon', 1, 1, 0, 0, 0, 0, 0, 0, 3.00, -1, -1, 30.00, -1, -1),
  ('addon', 2, 1, 0, 0, 0, 0, 0, 0, 2.80, -1, -1, 28.00, -1, -1),
  ('domainregister', 1, 1, 15.00, 30.00, 45.00, -1, -1, -1, -1, -1, -1, -1, -1, -1),
  ('domainrenew', 1, 1, 15.00, 30.00, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1),
  ('domaintransfer', 1, 1, 12.00, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1),
  ('domainregister', 2, 1, 14.00, 28.00, 42.00, -1, -1, -1, -1, -1, -1, -1, -1, -1),
  ('domainrenew', 2, 1, 14.00, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1),
  ('domainregister', 1, 2, 9.00, 18.00, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1),
  ('domainrenew', 1, 2, 11.00, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1),
  ('domaintransfer', 1, 2, 9.00, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1);
CREATE TABLE tbladdons (id int(10) NOT NULL AUTO_INCREMENT, packages text NOT NULL, name text NOT NULL, description text NOT NULL, billingcycle text NOT NULL, allowqty tinyint(3) unsigned NOT NULL DEFAULT 0, tax tinyint(1) NOT NULL DEFAULT 0, showorder tinyint(1) NOT NULL DEFAULT 0, hidden tinyint(1) NOT NULL DEFAULT 0, retired tinyint(1) NOT NULL DEFAULT 0, downloads text NOT NULL, autoactivate text NOT NULL, suspendproduct tinyint(1) NOT NULL DEFAULT 0, welcomeemail int(10) NOT NULL DEFAULT 0, type varchar(16) NOT NULL DEFAULT '', module varchar(32) NOT NULL DEFAULT '', server_group_id int(10) NOT NULL DEFAULT 0, prorate tinyint(1) NOT NULL DEFAULT 0, weight int(2) NOT NULL DEFAULT 0, autolinkby text NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tbladdons (id, packages, name, description, billingcycle, showorder, downloads, autoactivate, autolinkby) VALUES
  (1, '1,2,3', 'Daily backups', 'Off-site copies every day.', 'recurring', 1, '', 'payment', '');
CREATE TABLE tblproduct_upgrade_products (id int(10) unsigned NOT NULL AUTO_INCREMENT, product_id int(10) NOT NULL, upgrade_product_id int(10) NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblproduct_upgrade_products (product_id, upgrade_product_id) VALUES (2, 3), (3, 2);
CREATE TABLE tblpromotions (id int(10) NOT NULL AUTO_INCREMENT, code text NOT NULL, type text NOT NULL, recurring int(1) DEFAULT NULL, value decimal(16,2) NOT NULL DEFAULT 0, cycles text NOT NULL, appliesto text NOT NULL, requires text NOT NULL, requiresexisting int(1) NOT NULL DEFAULT 0, startdate date NOT NULL, expirationdate date DEFAULT NULL, maxuses int(10) NOT NULL DEFAULT 0, uses int(10) NOT NULL DEFAULT 0, lifetimepromo int(1) NOT NULL DEFAULT 0, applyonce int(1) NOT NULL DEFAULT 0, newsignups int(1) NOT NULL DEFAULT 0, existingclient int(11) NOT NULL DEFAULT 0, onceperclient int(11) NOT NULL DEFAULT 0, recurfor int(3) NOT NULL DEFAULT 0, upgrades int(1) NOT NULL DEFAULT 0, upgradeconfig text NOT NULL, notes text NOT NULL, PRIMARY KEY (id));
INSERT INTO tblpromotions (code, type, recurring, value, cycles, appliesto, requires, startdate, expirationdate, maxuses, uses, onceperclient, upgradeconfig, notes) VALUES
  ('CARE10', 'Percentage', 1, 10.00, 'Monthly,Annually', '1', '', '2026-01-01', '2026-12-31', 100, 7, 1, '', 'Care plan launch'),
  ('HOST5', 'Fixed Amount', 0, 5.00, '', '2,3', '', '0000-00-00', '0000-00-00', 0, 0, 0, '', ''),
  ('FLAT9', 'Price Override', 0, 9.00, '', '', '', '0000-00-00', '0000-00-00', 0, 0, 0, '', '');
CREATE TABLE tbldomainpricing (id int(10) NOT NULL AUTO_INCREMENT, extension text NOT NULL, dnsmanagement tinyint(1) NOT NULL DEFAULT 0, emailforwarding tinyint(1) NOT NULL DEFAULT 0, idprotection tinyint(1) NOT NULL DEFAULT 0, eppcode tinyint(1) NOT NULL DEFAULT 0, autoreg text NOT NULL, `order` int(1) NOT NULL DEFAULT 0, `group` varchar(5) NOT NULL DEFAULT '', grace_period int(1) NOT NULL DEFAULT -1, grace_period_fee decimal(16,2) NOT NULL DEFAULT 0, redemption_grace_period int(1) NOT NULL DEFAULT -1, redemption_grace_period_fee decimal(16,2) NOT NULL DEFAULT -1, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tbldomainpricing (id, extension, eppcode, autoreg, `order`) VALUES (1, '.com', 1, 'dynadot', 1), (2, '.org', 1, 'enom', 2);
CREATE TABLE tblbundles (id int(10) NOT NULL AUTO_INCREMENT, name text NOT NULL, validfrom date NOT NULL, validuntil date NOT NULL, uses int(4) NOT NULL DEFAULT 0, maxuses int(4) NOT NULL DEFAULT 0, itemdata text NOT NULL, allowpromo int(1) NOT NULL DEFAULT 0, showgroup int(1) NOT NULL DEFAULT 0, gid int(10) NOT NULL DEFAULT 0, description text NOT NULL, displayprice decimal(16,2) NOT NULL DEFAULT 0, sortorder int(5) NOT NULL DEFAULT 0, is_featured tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id));
INSERT INTO tblbundles (id, name, validfrom, validuntil, itemdata, description) VALUES (1, 'Site starter pack', '2026-01-01', '2026-12-31', '', '');

-- Configurable options on Hosting Starter (invented).
CREATE TABLE tblproductconfiggroups (id int(10) NOT NULL AUTO_INCREMENT, name text NOT NULL, description text NOT NULL, PRIMARY KEY (id));
CREATE TABLE tblproductconfiglinks (id int(10) NOT NULL AUTO_INCREMENT, gid int(10) NOT NULL, pid int(10) NOT NULL, PRIMARY KEY (id));
CREATE TABLE tblproductconfigoptions (id int(10) NOT NULL AUTO_INCREMENT, gid int(10) NOT NULL DEFAULT 0, optionname text NOT NULL, optiontype text NOT NULL, qtyminimum int(10) NOT NULL DEFAULT 0, qtymaximum int(10) NOT NULL DEFAULT 0, `order` int(1) NOT NULL DEFAULT 0, hidden tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id));
CREATE TABLE tblproductconfigoptionssub (id int(10) NOT NULL AUTO_INCREMENT, configid int(10) NOT NULL, optionname text NOT NULL, sortorder int(10) NOT NULL DEFAULT 0, hidden tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id));
CREATE TABLE tblhostingconfigoptions (id int(10) NOT NULL AUTO_INCREMENT, relid int(10) NOT NULL, configid int(10) NOT NULL, optionid int(10) NOT NULL, qty int(10) NOT NULL DEFAULT 0, PRIMARY KEY (id));
INSERT INTO tblproductconfiggroups VALUES (1, 'Hosting extras', '');
INSERT INTO tblproductconfiglinks (gid, pid) VALUES (1, 2);
INSERT INTO tblproductconfigoptions (id, gid, optionname, optiontype, qtyminimum, qtymaximum, `order`) VALUES (1, 1, 'snapshots|Daily snapshots', '3', 0, 0, 1), (2, 1, 'ips|Extra IPs', '4', 0, 4, 2);
INSERT INTO tblproductconfigoptionssub (id, configid, optionname, sortorder) VALUES (1, 1, 'Yes', 0), (2, 2, 'IP', 0);
INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, tsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially) VALUES
  ('configoptions', 1, 1, 0, 0, 0, 0, 0, 0, 2.00, 0, 0, 20.00, 0, 0),
  ('configoptions', 1, 2, 0, 0, 0, 0, 0, 0, 3.00, 0, 0, 30.00, 0, 0);
INSERT INTO tblhostingconfigoptions (relid, configid, optionid, qty) VALUES (2, 1, 1, 1), (2, 2, 2, 1);

-- Services, service add-ons and domains (invented).
CREATE TABLE tblhosting (id int(10) NOT NULL AUTO_INCREMENT, userid int(10) NOT NULL, orderid int(10) NOT NULL DEFAULT 0, packageid int(10) NOT NULL, server int(10) NOT NULL DEFAULT 0, regdate date NOT NULL, domain text NOT NULL, paymentmethod text NOT NULL,
  qty int(10) unsigned NOT NULL DEFAULT 1, firstpaymentamount decimal(16,2) NOT NULL DEFAULT 0, amount decimal(16,2) NOT NULL DEFAULT 0, billingcycle text NOT NULL, nextduedate date DEFAULT NULL, nextinvoicedate date NOT NULL DEFAULT '0000-00-00',
  termination_date date NOT NULL DEFAULT '0000-00-00', completed_date date NOT NULL DEFAULT '0000-00-00', domainstatus enum('Pending','Active','Suspended','Terminated','Cancelled','Fraud','Completed') NOT NULL DEFAULT 'Pending',
  username text NOT NULL, password text NOT NULL, notes text NOT NULL, subscriptionid text NOT NULL, promoid int(10) NOT NULL DEFAULT 0, suspendreason text NOT NULL, overideautosuspend tinyint(1) NOT NULL DEFAULT 0,
  overidesuspenduntil date NOT NULL DEFAULT '0000-00-00', dedicatedip text NOT NULL, assignedips text NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblhosting (id, userid, packageid, server, regdate, domain, paymentmethod, amount, billingcycle, nextduedate, termination_date, domainstatus, username, password, notes, subscriptionid, promoid, suspendreason, dedicatedip, assignedips) VALUES
  (1, 1, 1, 0, '2026-01-05', '', 'paypal', 18.00, 'Monthly', '2026-11-05', '0000-00-00', 'Active', '', '', '', '', 1, '', '', ''),
  (2, 1, 2, 1, '2025-03-01', 'stone-studio.example', 'paypal', 50.00, 'Annually', '2027-03-01', '0000-00-00', 'Active', 'stonest', 'demo-not-a-password', 'Migrated from old host.', '', 0, '', '', ''),
  (3, 2, 1, 0, '2025-08-10', '', 'banktransfer', 19.00, 'Monthly', '2026-09-10', '0000-00-00', 'Suspended', '', '', '', '', 0, 'Overdue on payment', '', ''),
  (4, 4, 3, 1, '2021-02-01', 'ortiz-legal.example', 'banktransfer', 120.00, 'Annually', '2024-02-01', '2024-02-02', 'Terminated', 'ortizleg', '', '', '', 0, '', '', ''),
  (5, 3, 4, 0, '2023-01-15', '', 'paypal', 99.00, 'One Time', '0000-00-00', '0000-00-00', 'Completed', '', '', '', '', 0, '', '', '');
CREATE TABLE tblhostingaddons (id int(10) NOT NULL AUTO_INCREMENT, orderid int(10) NOT NULL DEFAULT 0, hostingid int(10) NOT NULL, addonid int(10) NOT NULL, userid int(10) NOT NULL DEFAULT 0, server int(10) NOT NULL DEFAULT 0, name text NOT NULL,
  qty int(10) unsigned NOT NULL DEFAULT 1, firstpaymentamount decimal(16,2) NOT NULL DEFAULT 0, setupfee decimal(16,2) NOT NULL DEFAULT 0, recurring decimal(16,2) NOT NULL DEFAULT 0, billingcycle text NOT NULL, tax text NOT NULL,
  status enum('Pending','Active','Suspended','Terminated','Cancelled','Fraud','Completed') NOT NULL DEFAULT 'Pending', regdate date NOT NULL, nextduedate date DEFAULT NULL, nextinvoicedate date NOT NULL DEFAULT '0000-00-00',
  termination_date date NOT NULL DEFAULT '0000-00-00', paymentmethod text NOT NULL, notes text NOT NULL, subscriptionid varchar(128) NOT NULL DEFAULT '', created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblhostingaddons (id, hostingid, addonid, userid, name, recurring, billingcycle, tax, status, regdate, nextduedate, paymentmethod, notes) VALUES
  (1, 2, 1, 1, '', 3.00, 'Monthly', '', 'Active', '2025-03-01', '2026-11-01', 'paypal', '');
CREATE TABLE tbldomains (id int(10) NOT NULL AUTO_INCREMENT, userid int(10) NOT NULL, orderid int(10) NOT NULL DEFAULT 0, type enum('Register','Transfer') NOT NULL, registrationdate date NOT NULL, domain text NOT NULL,
  firstpaymentamount decimal(16,2) NOT NULL DEFAULT 0, recurringamount decimal(16,2) NOT NULL DEFAULT 0, registrar text NOT NULL, registrationperiod int(1) NOT NULL DEFAULT 1, expirydate date DEFAULT NULL, subscriptionid text NOT NULL,
  promoid int(10) NOT NULL DEFAULT 0, status enum('Pending','Pending Registration','Pending Transfer','Active','Grace','Redemption','Expired','Transferred Away','Cancelled','Fraud') NOT NULL DEFAULT 'Pending',
  nextduedate date NOT NULL DEFAULT '0000-00-00', nextinvoicedate date NOT NULL DEFAULT '0000-00-00', additionalnotes text NOT NULL, paymentmethod text NOT NULL, dnsmanagement tinyint(1) NOT NULL DEFAULT 0,
  emailforwarding tinyint(1) NOT NULL DEFAULT 0, idprotection tinyint(1) NOT NULL DEFAULT 0, is_premium tinyint(1) DEFAULT NULL, donotrenew tinyint(1) NOT NULL DEFAULT 0, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tbldomains (id, userid, type, registrationdate, domain, recurringamount, registrar, registrationperiod, expirydate, subscriptionid, status, nextduedate, additionalnotes, paymentmethod, idprotection, donotrenew) VALUES
  (1, 1, 'Register', '2025-03-01', 'stone-studio.com', 15.00, 'dynadot', 1, '2027-03-01', '', 'Active', '2027-02-15', '', 'paypal', 1, 0),
  (2, 2, 'Register', '2024-06-01', 'keller.org', 14.00, 'enom', 1, '2027-06-01', '', 'Active', '2027-05-20', '', 'banktransfer', 0, 0),
  (3, 4, 'Transfer', '2020-01-10', 'ortiz.com', 15.00, 'dynadot', 1, '2024-01-10', '', 'Cancelled', '2024-01-10', '', 'banktransfer', 0, 1);
CREATE TABLE tblcancelrequests (id int(10) NOT NULL AUTO_INCREMENT, date datetime NOT NULL, relid int(10) NOT NULL, reason text NOT NULL, type text NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblcancelrequests (date, relid, reason, type) VALUES ('2024-01-20 10:00:00', 4, 'Closing the firm.', 'Immediate');

-- What the plan reads besides: an empty support side, and one add-on module.
-- Billing (invented): invoices, transactions, credit and a credit note.
INSERT INTO tblconfiguration VALUES ('SequentialInvoiceNumbering', 'on'), ('SequentialInvoiceNumberFormat', '{NUMBER}'), ('SequentialInvoiceNumberValue', '1003');
UPDATE tblclients SET credit = 10.00 WHERE id = 1;
UPDATE tblclients SET credit = 5.00 WHERE id = 2;
CREATE TABLE tblinvoices (id int(10) unsigned NOT NULL AUTO_INCREMENT, userid int(10) NOT NULL, invoicenum text NOT NULL, date date DEFAULT NULL, duedate date DEFAULT NULL, datepaid datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  last_capture_attempt timestamp NULL, date_refunded timestamp NULL, date_cancelled timestamp NULL, subtotal decimal(16,2) NOT NULL DEFAULT 0, credit decimal(16,2) NOT NULL DEFAULT 0, tax decimal(16,2) NOT NULL DEFAULT 0,
  tax2 decimal(16,2) NOT NULL DEFAULT 0, total decimal(16,2) NOT NULL DEFAULT 0, taxrate decimal(10,3) NOT NULL DEFAULT 0, taxrate2 decimal(10,3) NOT NULL DEFAULT 0, status text NOT NULL, paymentmethod text NOT NULL,
  paymethodid int(10) unsigned DEFAULT NULL, notes text NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblinvoices (id, userid, invoicenum, date, duedate, datepaid, subtotal, total, status, paymentmethod, notes) VALUES
  (1, 1, '1001', '2026-09-05', '2026-09-05', '2026-09-05 11:00:00', 18.00, 18.00, 'Paid', 'paypal', ''),
  (2, 1, '', '2026-10-15', '2026-11-05', '0000-00-00 00:00:00', 18.00, 18.00, 'Unpaid', 'paypal', ''),
  (3, 2, '1002', '2026-08-10', '2026-08-10', '2026-08-11 09:30:00', 19.00, 19.00, 'Paid', 'banktransfer', ''),
  (4, 4, '', '2024-02-01', '2024-02-01', '0000-00-00 00:00:00', 120.00, 120.00, 'Cancelled', 'banktransfer', 'Firm closed.');
CREATE TABLE tblinvoiceitems (id int(10) NOT NULL AUTO_INCREMENT, invoiceid int(10) unsigned NOT NULL DEFAULT 0, userid int(10) NOT NULL, type varchar(30) NOT NULL, relid int(10) NOT NULL, description text NOT NULL,
  amount decimal(16,2) NOT NULL DEFAULT 0, taxed int(1) NOT NULL, duedate date DEFAULT NULL, paymentmethod text NOT NULL, notes text NOT NULL, PRIMARY KEY (id));
INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, paymentmethod, notes) VALUES
  (1, 1, 'Hosting', 1, 'Website Care (05/09/2026 - 04/10/2026)', 20.00, 0, 'paypal', ''),
  (1, 1, 'PromoHosting', 1, 'Promotional Code: CARE10 - 10% Recurring Discount', -2.00, 0, 'paypal', ''),
  (2, 1, 'Hosting', 1, 'Website Care (05/11/2026 - 04/12/2026)', 18.00, 0, 'paypal', ''),
  (3, 2, 'Hosting', 3, 'Website Care (10/08/2026 - 09/09/2026)', 19.00, 0, 'banktransfer', ''),
  (4, 4, 'Hosting', 4, 'Hosting Plus - ortiz-legal.example', 120.00, 0, 'banktransfer', '');
CREATE TABLE tblaccounts (id int(10) NOT NULL AUTO_INCREMENT, userid int(10) NOT NULL, currency int(10) NOT NULL, gateway text NOT NULL, date datetime DEFAULT NULL, description text NOT NULL, amountin decimal(16,2) NOT NULL DEFAULT 0,
  fees decimal(16,2) NOT NULL DEFAULT 0, amountout decimal(16,2) NOT NULL DEFAULT 0, rate decimal(16,5) NOT NULL DEFAULT 1, transid text NOT NULL, invoiceid int(10) unsigned NOT NULL DEFAULT 0, refundid int(10) NOT NULL DEFAULT 0,
  billingnoteid int(10) unsigned NOT NULL DEFAULT 0, type varchar(64) NOT NULL DEFAULT '', relid int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id));
INSERT INTO tblaccounts (userid, currency, gateway, date, description, amountin, fees, transid, invoiceid) VALUES
  (1, 0, 'paypal', '2026-09-05 11:00:00', 'Invoice Payment', 18.00, 0.82, 'DEMO-PP-0001', 1),
  (2, 0, 'banktransfer', '2026-08-11 09:30:00', 'Invoice Payment', 19.00, 0, 'DEMO-BANK-0001', 3);
CREATE TABLE tblcredit (id int(10) NOT NULL AUTO_INCREMENT, clientid int(10) NOT NULL, admin_id int(10) unsigned NOT NULL DEFAULT 0, date date NOT NULL, description text NOT NULL, amount decimal(16,2) NOT NULL DEFAULT 0,
  relid int(10) NOT NULL DEFAULT 0, billing_note_id int(10) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id));
INSERT INTO tblcredit (clientid, date, description, amount) VALUES (1, '2026-06-01', 'Goodwill credit', 10.00);
CREATE TABLE tblbillingnotes (id int(10) unsigned NOT NULL AUTO_INCREMENT, note_type enum('credit','debit') NOT NULL, custom_number varchar(255) NOT NULL DEFAULT '', client_id int(10) unsigned NOT NULL, date_issued timestamp NULL,
  subtotal decimal(16,2) NOT NULL DEFAULT 0, tax decimal(16,2) NOT NULL DEFAULT 0, tax2 decimal(16,2) NOT NULL DEFAULT 0, total decimal(16,2) NOT NULL DEFAULT 0, taxrate decimal(10,3) NOT NULL DEFAULT 0, taxrate2 decimal(10,3) NOT NULL DEFAULT 0,
  status enum('draft','issued','closed') NOT NULL DEFAULT 'draft', notes text, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblbillingnotes (id, note_type, custom_number, client_id, date_issued, subtotal, total, status, notes) VALUES (1, 'credit', '1', 1, '2026-06-01 00:00:00', 10.00, 10.00, 'closed', 'Goodwill for the outage.');
CREATE TABLE tblbillingnoteitems (id int(10) unsigned NOT NULL AUTO_INCREMENT, billingnote_id int(10) unsigned NOT NULL, note_type enum('credit','debit') NOT NULL, client_id int(10) unsigned NOT NULL, type varchar(32) NOT NULL DEFAULT '',
  relid int(11) NOT NULL DEFAULT 0, description text NOT NULL, amount decimal(16,2) NOT NULL DEFAULT 0, taxed tinyint(4) NOT NULL DEFAULT 0, tax decimal(16,2) NOT NULL DEFAULT 0, tax2 decimal(16,2) NOT NULL DEFAULT 0,
  taxrate decimal(10,3) NOT NULL DEFAULT 0, taxrate2 decimal(10,3) NOT NULL DEFAULT 0, status enum('draft','issued','closed') NOT NULL DEFAULT 'draft', created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblbillingnoteitems (billingnote_id, note_type, client_id, description, amount, status) VALUES (1, 'credit', 1, 'Credit for the June outage', 10.00, 'closed');
CREATE TABLE tbladdonmodules (module text NOT NULL, setting text NOT NULL, value text NOT NULL);
INSERT INTO tbladdonmodules VALUES ('project_management', 'version', '2.0');
CREATE TABLE tblpaymentgateways (gateway text NOT NULL, setting text NOT NULL, value text NOT NULL, `order` int(1) NOT NULL DEFAULT 0);
INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES ('paypal', 'name', 'PayPal'), ('banktransfer', 'name', 'Bank Transfer');

-- Support (WHMCS import part 6). Staff: Olive is the rehearsal's own admin (same email), Sam a support
-- operator, Sally a sales operator; replies signed "Fred Former" come from a deleted admin.
CREATE TABLE tbladmins (id int(10) unsigned NOT NULL AUTO_INCREMENT, uuid varchar(255) NOT NULL DEFAULT '', roleid int(10) unsigned NOT NULL, username text NOT NULL, password varchar(255) NOT NULL DEFAULT '', passwordhash varchar(255) NOT NULL DEFAULT '', authmodule text NOT NULL, authdata text NOT NULL,
  firstname text NOT NULL, lastname text NOT NULL, email text NOT NULL, signature text NOT NULL, notes text NOT NULL, template text NOT NULL, language text NOT NULL, disabled int(1) NOT NULL DEFAULT 0, loginattempts int(1) NOT NULL DEFAULT 0, supportdepts text NOT NULL,
  ticketnotifications text NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tbladmins (id, roleid, username, passwordhash, authmodule, authdata, firstname, lastname, email, signature, notes, template, language, supportdepts, ticketnotifications, created_at) VALUES
  (1, 1, 'olive', '$2y$12$ztLAxpLtfFARcVKHruNiOuaoSPj2CJ2ttszID4BeE4Zh0166QnQj.', '', '', 'Olive', 'Owner', 'rehearsal-admin@clientfence.com', '', '', 'blend', 'english', '1,2,3', '', '2021-01-01 08:00:00'),
  (2, 3, 'sam', '$2y$12$ztLAxpLtfFARcVKHruNiOuaoSPj2CJ2ttszID4BeE4Zh0166QnQj.', 'totp', '', 'Sam', 'Support', 'sam@demo-host.example', 'Sam, Demo Hosting support', '', 'blend', 'english', '1,2', '', '2023-05-01 08:00:00'),
  (3, 2, 'sally', '$2y$12$ztLAxpLtfFARcVKHruNiOuaoSPj2CJ2ttszID4BeE4Zh0166QnQj.', '', '', 'Sally', 'Sales', 'sally@demo-host.example', '', '', 'blend', 'english', '3', '', '2024-05-01 08:00:00');
INSERT INTO tblconfiguration VALUES ('CloseInactiveTickets', '72'), ('TicketRatingEnabled', 'on');

-- Departments: Support; Abuse is hidden with an IMAP mailbox (password demo-mailbox-password, encrypted with the demo key); Sales.
CREATE TABLE tblticketdepartments (id int(10) NOT NULL AUTO_INCREMENT, name text NOT NULL, description text NOT NULL, email text NOT NULL, clientsonly text NOT NULL, piperepliesonly text NOT NULL, noautoresponder text NOT NULL, hidden text NOT NULL, `order` int(1) NOT NULL DEFAULT 0,
  host text NOT NULL, port text NOT NULL, login text NOT NULL, password text NOT NULL, mail_auth_config text, feedback_request tinyint(1) NOT NULL DEFAULT 0, prevent_client_closure tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id));
INSERT INTO tblticketdepartments (id, name, description, email, clientsonly, piperepliesonly, noautoresponder, hidden, `order`, host, port, login, password, mail_auth_config, feedback_request) VALUES
  (1, 'Support', 'Technical help', 'support@demo-host.example', '', '', '', '', 1, '', '', '', '', NULL, 1),
  (2, 'Abuse', 'Abuse reports', 'abuse@demo-host.example', 'on', 'on', '', 'on', 2, 'imap.demo-host.example', '993', 'abuse@demo-host.example', 'SuXEtkkkQNd2KB+pnDCno73GbZQAAAAARBsFDAENBg5JFQwcGgELF+M=', NULL, 0),
  (3, 'Sales', 'Before you buy', 'sales@demo-host.example', '', '', '', '', 3, '', '', '', '', NULL, 1);
CREATE TABLE tblticketstatuses (id int(10) NOT NULL AUTO_INCREMENT, title text NOT NULL, color text NOT NULL, sortorder int(2) NOT NULL, showactive int(1) NOT NULL, showawaiting int(1) NOT NULL, autoclose int(1) NOT NULL, PRIMARY KEY (id));
INSERT INTO tblticketstatuses VALUES (1, 'Open', '#779500', 1, 1, 1, 0), (2, 'Answered', '#000000', 2, 1, 0, 1), (3, 'Customer-Reply', '#ff6600', 3, 1, 1, 1), (4, 'Closed', '#888888', 10, 0, 0, 0), (5, 'On Hold', '#224488', 5, 1, 0, 0), (6, 'In Progress', '#cc0000', 6, 1, 0, 0);
CREATE TABLE tblticketpredefinedcats (id int(10) NOT NULL AUTO_INCREMENT, parentid int(10) NOT NULL DEFAULT 0, name text NOT NULL, PRIMARY KEY (id));
INSERT INTO tblticketpredefinedcats VALUES (1, 0, 'Billing'), (2, 1, 'Refunds');
CREATE TABLE tblticketpredefinedreplies (id int(10) NOT NULL AUTO_INCREMENT, catid int(10) NOT NULL, name text NOT NULL, reply text NOT NULL, PRIMARY KEY (id));
INSERT INTO tblticketpredefinedreplies VALUES (1, 2, 'Refund sent', 'Hi,\nYour refund is on its way.\n\nDemo Hosting'), (2, 0, 'Welcome', 'Welcome to Demo Hosting!');

-- Tickets: 1 Avery's (answered, about service 2, assigned to Sam, a screenshot and a log in
-- tools/whmcs-rehearsal/attachments), 2 a guest's, 3 opened by Sam for Bruno, 4 merged into 1, 5 open.
CREATE TABLE tbltickets (id int(10) NOT NULL AUTO_INCREMENT, tid varchar(128) NOT NULL, did int(10) NOT NULL, userid int(10) NOT NULL, contactid int(10) unsigned NOT NULL DEFAULT 0, requestor_id int(10) unsigned NOT NULL DEFAULT 0, name text NOT NULL, email text NOT NULL, cc text NOT NULL, c text NOT NULL,
  ipaddress text NOT NULL, date datetime NOT NULL, title text NOT NULL, message text NOT NULL, status varchar(64) NOT NULL, urgency text NOT NULL, admin text NOT NULL, attachment text NOT NULL, attachments_removed tinyint(1) NOT NULL DEFAULT 0, lastreply datetime NOT NULL,
  flag int(1) NOT NULL DEFAULT 0, clientunread int(1) NOT NULL DEFAULT 0, adminunread text NOT NULL, replyingadmin int(1) NOT NULL DEFAULT 0, replyingtime datetime NOT NULL DEFAULT '0000-00-00 00:00:00', service text NOT NULL, merged_ticket_id int(10) NOT NULL DEFAULT 0, editor enum('plain','markdown') NOT NULL DEFAULT 'plain',
  created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tbltickets (id, tid, did, userid, requestor_id, name, email, cc, c, ipaddress, date, title, message, status, urgency, admin, attachment, lastreply, flag, adminunread, service, merged_ticket_id, editor) VALUES
  (1, '1', 1, 1, 1, '', '', '', 'a1B2c3D4', '198.51.100.10', '2026-09-20 09:00:00', 'Website shows an error', 'Hi,\nmy site shows a **500 error** since this morning.\nScreenshot and log attached.', 'Answered', 'High', '', '482910_error-screenshot.png|482911_php-errors.log', '2026-09-20 11:30:00', 2, '', 'S2', 0, 'markdown'),
  (2, '2', 3, 0, 0, 'Gina Guest', 'gina@guest.example', '', 'e5F6g7H8', '198.51.100.30', '2026-09-22 14:00:00', 'Do you offer VPS?', 'Hello, do you sell VPS plans?', 'Closed', 'Low', '', '', '2026-09-22 16:00:00', 0, '', '', 0, 'plain'),
  (3, '3', 3, 2, 0, '', '', '', 'i9J0k1L2', '', '2026-08-01 10:00:00', 'Your payment failed', 'Hello Bruno,\nyour last payment failed. Please check your card.', 'Closed', 'Medium', 'Sam Support', '', '2026-08-02 09:00:00', 0, '', '', 0, 'plain'),
  (4, '4', 1, 1, 1, '', '', '', 'm3N4o5P6', '198.51.100.10', '2026-09-20 09:20:00', 'Site still down', '', 'Closed', 'Medium', '', '', '2026-09-20 09:20:00', 0, '', '', 1, 'markdown'),
  (5, '5', 1, 1, 5, '', '', '', 'q7R8s9T0', '198.51.100.20', '2026-10-10 08:00:00', 'Add an email account', 'Please add info@stone-studio.example.', 'Open', 'Medium', '', '', '2026-10-10 08:00:00', 0, '', '', 0, 'markdown');
CREATE TABLE tblticketreplies (id int(10) NOT NULL AUTO_INCREMENT, tid int(10) NOT NULL, userid int(10) NOT NULL DEFAULT 0, contactid int(10) unsigned NOT NULL DEFAULT 0, requestor_id int(10) unsigned NOT NULL DEFAULT 0, name text NOT NULL, email text NOT NULL, date datetime NOT NULL,
  message text NOT NULL, admin text NOT NULL, attachment text NOT NULL, attachments_removed tinyint(1) NOT NULL DEFAULT 0, rating int(5) NOT NULL DEFAULT 0, editor enum('plain','markdown') NOT NULL DEFAULT 'plain', created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblticketreplies (id, tid, userid, requestor_id, name, email, date, message, admin, attachment, attachments_removed, rating, editor) VALUES
  (1, 1, 1, 1, '', '', '2026-09-20 09:20:00', 'Site still down, any news?', '', '', 0, 0, 'markdown'),
  (2, 1, 0, 0, '', '', '2026-09-20 10:00:00', 'Hi Avery,\nthe PHP version changed during maintenance. I switched it back.\n\nCan you check now?', 'Sam Support', '', 0, 5, 'markdown'),
  (3, 1, 1, 5, '', '', '2026-09-20 11:00:00', 'Eli here, it works again. Thanks!', '', '', 0, 0, 'markdown'),
  (4, 1, 0, 0, '', '', '2026-09-20 11:30:00', 'Glad to hear it.', 'Fred Former', '482912_old-report.pdf', 1, 0, 'markdown'),
  (5, 2, 0, 0, 'Gina Guest', 'gina@guest.example', '2026-09-22 15:00:00', 'Not yet, but soon. We will announce it.', 'Sam Support', '', 0, 0, 'plain'),
  (6, 3, 2, 2, '', '', '2026-08-02 09:00:00', 'Done, I updated the card.', '', '', 0, 0, 'plain');
CREATE TABLE tblticketnotes (id int(10) NOT NULL AUTO_INCREMENT, ticketid int(10) NOT NULL, admin text NOT NULL, date datetime NOT NULL, message text NOT NULL, attachments text NOT NULL, attachments_removed tinyint(1) NOT NULL DEFAULT 0, editor enum('plain','markdown') NOT NULL DEFAULT 'plain', PRIMARY KEY (id));
INSERT INTO tblticketnotes VALUES (1, 1, 'Sam Support', '2026-09-20 09:55:00', 'PHP was 8.1 after the maintenance; set back to 8.3.', '', 0, 'markdown');
CREATE TABLE tblticketfeedback (id int(10) unsigned NOT NULL AUTO_INCREMENT, ticketid int(10) unsigned NOT NULL, adminid int(10) unsigned NOT NULL, rating int(2) NOT NULL, comments text NOT NULL, datetime datetime NOT NULL, ip varchar(64) NOT NULL, PRIMARY KEY (id));
INSERT INTO tblticketfeedback VALUES (1, 3, 2, 9, 'Quick and friendly.', '2026-08-03 10:00:00', '198.51.100.11'), (2, 3, 0, 0, 'Good service overall.', '2026-08-03 10:00:00', '198.51.100.11');
CREATE TABLE tblticketspamfilters (id int(10) NOT NULL AUTO_INCREMENT, type enum('sender','subject','phrase') NOT NULL, content text NOT NULL, PRIMARY KEY (id));
INSERT INTO tblticketspamfilters VALUES (1, 'sender', 'spam@junk.example');

-- Knowledge base (Hosting, Email under it, a hidden Internal category), announcements, a network issue, the email log.
CREATE TABLE tblknowledgebasecats (id int(10) NOT NULL AUTO_INCREMENT, parentid int(10) NOT NULL DEFAULT 0, name text NOT NULL, description text NOT NULL, hidden text NOT NULL, catid int(10) NOT NULL DEFAULT 0, language text NOT NULL, PRIMARY KEY (id));
INSERT INTO tblknowledgebasecats VALUES (1, 0, 'Hosting', 'Hosting basics', '', 0, ''), (2, 1, 'Email', 'Mailboxes and forwarders', '', 0, ''), (3, 0, 'Internal', 'Staff notes', 'on', 0, '');
CREATE TABLE tblknowledgebase (id int(10) NOT NULL AUTO_INCREMENT, title text NOT NULL, article text NOT NULL, views int(10) NOT NULL DEFAULT 0, useful int(10) NOT NULL DEFAULT 0, votes int(10) NOT NULL DEFAULT 0, private text NOT NULL, `order` int(3) NOT NULL DEFAULT 0, parentid int(10) NOT NULL DEFAULT 0, language text NOT NULL, PRIMARY KEY (id));
INSERT INTO tblknowledgebase VALUES
  (1, 'Set up an email account', '<h2>In cPanel</h2><p>Open <strong>Email Accounts</strong> and click <em>Create</em>.</p><ol><li>Pick the domain</li><li>Type a name and password</li></ol><p>See the <a href="https://docs.cpanel.net/">cPanel docs</a>.</p>', 42, 5, 6, '', 1, 0, ''),
  (2, 'Upload files with FTP', '<p>Use any FTP client with your cPanel login.<br>Port 21, or 22 for SFTP.</p>', 17, 1, 1, '', 2, 0, ''),
  (3, 'Restart procedure', '<p>Only for staff.</p>', 0, 0, 0, '', 1, 0, '');
CREATE TABLE tblknowledgebaselinks (id int(10) NOT NULL AUTO_INCREMENT, categoryid int(10) NOT NULL, articleid int(10) NOT NULL, PRIMARY KEY (id));
INSERT INTO tblknowledgebaselinks VALUES (1, 2, 1), (2, 1, 2), (3, 3, 3);
CREATE TABLE tblannouncements (id int(10) NOT NULL AUTO_INCREMENT, date datetime NOT NULL, title text NOT NULL, announcement text NOT NULL, published tinyint(1) NOT NULL DEFAULT 0, parentid int(10) NOT NULL DEFAULT 0, language text NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblannouncements VALUES
  (1, '2026-06-01 09:00:00', 'New data centre in Frankfurt', '<p>We opened a <strong>new data centre</strong> in Frankfurt.</p><p>New servers go there from July.</p>', 1, 0, '', '2026-06-01 08:00:00', '2026-06-01 09:00:00'),
  (2, '2026-11-01 09:00:00', 'Price changes (draft)', '<p>Coming soon.</p>', 0, 0, '', '2026-10-01 08:00:00', '2026-10-01 08:00:00');
CREATE TABLE tblnetworkissues (id int(10) NOT NULL AUTO_INCREMENT, title varchar(45) NOT NULL, description text NOT NULL, type enum('Server','System','Other') NOT NULL, affecting varchar(100) DEFAULT NULL, server int(10) unsigned DEFAULT NULL, priority enum('Critical','Low','Medium','High') NOT NULL,
  startdate datetime NOT NULL, enddate datetime DEFAULT NULL, status enum('Reported','Investigating','In Progress','Outage','Scheduled','Resolved') NOT NULL, lastupdate datetime NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblnetworkissues VALUES (1, 'cPanel server reboot', 'Kernel update, about 10 minutes.', 'Server', NULL, 1, 'Medium', '2026-09-15 02:00:00', '2026-09-15 02:15:00', 'Resolved', '2026-09-15 02:20:00', '2026-09-10 08:00:00', '2026-09-15 02:20:00');
CREATE TABLE tblemails (id int(10) NOT NULL AUTO_INCREMENT, userid int(10) NOT NULL, subject text NOT NULL, message text NOT NULL, date datetime DEFAULT NULL, `to` text, cc text, bcc text, attachments text, pending tinyint(1) NOT NULL DEFAULT 0, message_data text, failed tinyint(1) NOT NULL DEFAULT 0, failure_reason text, retry_count int(10) NOT NULL DEFAULT 0, created_at timestamp NULL, updated_at timestamp NULL, campaign_id int(10) NOT NULL DEFAULT 0, PRIMARY KEY (id));
INSERT INTO tblemails (id, userid, subject, message, date, `to`, cc, bcc, attachments, pending, failed) VALUES
  (1, 1, 'Welcome to Demo Hosting', '<html><body><p>Dear Avery,</p><p>Thank you for signing up.</p></body></html>', '2023-04-01 09:05:00', 'avery@demo-client.example', '', '', '[]', 0, 0),
  (2, 1, 'Invoice Payment Confirmation', '<p>Dear Avery,</p><p>Thank you for your payment of <b>$18.00</b>.</p>', '2026-10-06 10:00:00', 'avery@demo-client.example', '', '', '["Invoice-1001.pdf"]', 0, 0),
  (3, 2, 'Support Ticket Reply', '<p>Dear Bruno,</p><p>A reply was added to your ticket.</p>', '2026-08-01 10:00:00', 'billing@keller.example', '', '', '[]', 0, 1);

-- Licensing (WHMCS import part 7): a licence product and service with WHMCS Licensing's licence, its
-- check log (WHMCS logs failed checks and reissues; lines of a deleted service and of unknown keys
-- are left out; one line is older than the 90 days kept) and bans (one is not a domain or IP address). Two modules without a mapper go
-- to the archive file: PayPal billing agreements (no primary key) and a payment module's orders.
INSERT INTO tblproducts (id, type, gid, name, slug, description, paytype, autosetup, servertype, servergroup, configoption1, freedomain, freedomainpaymentterms, freedomaintlds, `order`) VALUES
  (5, 'other', 1, 'Plugin licence', 'plugin-licence', 'One site licence for the Demo plugin.', 'recurring', 'payment', 'licensing', 0, '', '', '', '', 5);
INSERT INTO tblpricing (type, currency, relid, msetupfee, qsetupfee, ssetupfee, asetupfee, bsetupfee, tsetupfee, monthly, quarterly, semiannually, annually, biennially, triennially) VALUES
  ('product', 1, 5, 0, 0, 0, 0, 0, 0, 15.00, -1, -1, 150.00, -1, -1),
  ('product', 2, 5, 0, 0, 0, 0, 0, 0, 14.00, -1, -1, 140.00, -1, -1);
INSERT INTO tblhosting (id, userid, packageid, server, regdate, domain, paymentmethod, amount, billingcycle, nextduedate, termination_date, domainstatus, username, password, notes, subscriptionid, promoid, suspendreason, dedicatedip, assignedips) VALUES
  (6, 2, 5, 0, '2026-02-01', '', 'paypal', 15.00, 'Monthly', '2026-11-01', '0000-00-00', 'Active', '', '', '', '', 0, '', '', '');
INSERT INTO tbladdonmodules VALUES ('licensing', 'version', '3.1'), ('licensing', 'logprune', '90'), ('licensing', 'maxreissues', '2');
CREATE TABLE mod_licensing (id int(10) NOT NULL AUTO_INCREMENT, serviceid int(10) NOT NULL, addon_id int(10) NOT NULL DEFAULT 0, licensekey text NOT NULL, validdomain text NOT NULL, validip text NOT NULL, validdirectory text NOT NULL,
  reissues int(1) NOT NULL, status enum('Active','Reissued','Suspended','Expired') NOT NULL, lastaccess datetime NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (id));
INSERT INTO mod_licensing VALUES
  (1, 6, 0, 'DEMO-LIC-0001', 'demo-shop.example', '203.0.113.20', '/home/demo/public_html', 0, 'Active', '2026-10-18 09:00:00'),
  (2, 99, 0, 'DEMO-LIC-0002', 'gone.example', '203.0.113.21', '', 1, 'Expired', '2026-07-01 09:00:00');
CREATE TABLE mod_licensinglog (id int(10) NOT NULL AUTO_INCREMENT, licenseid int(10) NOT NULL, domain text NOT NULL, ip text NOT NULL, path text NOT NULL, message text NOT NULL, datetime datetime NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (id));
INSERT INTO mod_licensinglog (licenseid, domain, ip, path, message, datetime) VALUES
  (1, 'demo-shop.example', '203.0.113.20', '/home/demo/public_html', 'License Reissued', '2026-06-01 09:00:00'),
  (1, '', '198.51.100.40', '', 'IP Address Invalid', '2026-10-17 09:00:00'),
  (1, '', '198.51.100.40', '', 'IP Address Invalid', '2026-10-17 21:30:00'),
  (1, 'demo-shop.example', '203.0.113.20', '/home/demo/public_html', 'License Reissued', '2026-10-18 09:00:00'),
  (2, 'gone.example', '203.0.113.21', '', 'License Expired', '2026-07-01 09:00:00'),
  (0, '', '198.51.100.77', '', 'Invalid Key - DEMO-NO-SUCH-KEY', '2026-10-18 12:00:00');
CREATE TABLE mod_licensingbans (id int(10) NOT NULL AUTO_INCREMENT, type varchar(1) NOT NULL, value text NOT NULL, notes text NOT NULL, PRIMARY KEY (id));
INSERT INTO mod_licensingbans (type, value, notes) VALUES ('', 'www.banned-reseller.example', 'Chargeback 2026'), ('', '192.0.2.66', ''), ('', 'not valid!', 'typo');
CREATE TABLE mod_lcwsoftpaypalbillingagreements (userid int(11) NOT NULL, agreementID varchar(255) NOT NULL, KEY agreementID (agreementID));
INSERT INTO mod_lcwsoftpaypalbillingagreements VALUES (1, 'B-DEMO000000000001'), (2, 'B-DEMO000000000002');
CREATE TABLE paymobonline_orders (id int(10) NOT NULL AUTO_INCREMENT, invoice_id int(10) NOT NULL, status varchar(20) NOT NULL, PRIMARY KEY (id));
INSERT INTO paymobonline_orders VALUES (1, 1001, 'paid');
-- Affiliates (invented): Chen Wu (client 3) refers services 1 and 3 with a fixed 5.00 override; one cleared and one
-- pending commission, a withdrawal, visits; the stored balance (12.30) also holds a bonus with no history row.
-- Bruno Keller (client 2) joined but has no activity.
ALTER TABLE tblproducts ADD COLUMN affiliatepaytype text NOT NULL DEFAULT '', ADD COLUMN affiliatepayamount decimal(16,2) NOT NULL DEFAULT 0.00, ADD COLUMN affiliateonetime int(1) NOT NULL DEFAULT 0;
UPDATE tblproducts SET affiliatepaytype = 'percentage', affiliatepayamount = 15.00, affiliateonetime = 1 WHERE id = 1;
INSERT INTO tblconfiguration VALUES ('AffiliateEnabled', 'on'), ('AffiliateEarningPercent', '10'), ('AffiliateBonusDeposit', '5.00'), ('AffiliatePayout', '20.00'), ('AffiliatesDelayCommission', '30');
CREATE TABLE tblaffiliates (id int(10) NOT NULL AUTO_INCREMENT, date date DEFAULT NULL, clientid int(10) NOT NULL, visitors int(1) NOT NULL, paytype text NOT NULL, payamount decimal(16,2) NOT NULL, onetime int(1) NOT NULL, balance decimal(16,2) NOT NULL DEFAULT 0.00, withdrawn decimal(16,2) NOT NULL DEFAULT 0.00, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblaffiliates (id, date, clientid, visitors, paytype, payamount, onetime, balance, withdrawn) VALUES (7, '2025-05-01', 3, 12, 'fixed', 5.00, 0, 12.30, 10.00), (12, '2026-02-10', 2, 0, '', 0.00, 0, 0.00, 0.00);
CREATE TABLE tblaffiliatesaccounts (id int(10) NOT NULL AUTO_INCREMENT, affiliateid int(10) NOT NULL, relid int(10) NOT NULL, lastpaid date NOT NULL DEFAULT '2000-01-01', created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblaffiliatesaccounts (id, affiliateid, relid, lastpaid) VALUES (1, 7, 1, '2026-09-05'), (2, 7, 3, '2026-08-11');
CREATE TABLE tblaffiliatespending (id int(10) NOT NULL AUTO_INCREMENT, affaccid int(10) NOT NULL DEFAULT 0, invoice_id int(10) unsigned NOT NULL DEFAULT 0, amount decimal(16,2) NOT NULL, clearingdate date NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblaffiliatespending (affaccid, invoice_id, amount, clearingdate) VALUES (2, 3, 5.00, '2030-01-10');
CREATE TABLE tblaffiliateshistory (id int(10) NOT NULL AUTO_INCREMENT, affiliateid int(10) NOT NULL, date date NOT NULL, affaccid int(10) NOT NULL, invoice_id int(10) unsigned NOT NULL DEFAULT 0, description text NOT NULL, amount decimal(16,2) NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblaffiliateshistory (affiliateid, date, affaccid, invoice_id, description, amount) VALUES (7, '2026-10-05', 1, 1, '', 5.00), (7, '2026-10-20', 0, 0, 'Goodwill bonus', 12.30);
CREATE TABLE tblaffiliateswithdrawals (id int(10) NOT NULL AUTO_INCREMENT, affiliateid int(10) NOT NULL, date date NOT NULL, amount decimal(16,2) NOT NULL, created_at timestamp NULL, updated_at timestamp NULL, PRIMARY KEY (id));
INSERT INTO tblaffiliateswithdrawals (affiliateid, date, amount) VALUES (7, '2026-10-25', 10.00);
CREATE TABLE tblaffiliates_hits (id int(10) unsigned NOT NULL AUTO_INCREMENT, affiliate_id int(10) unsigned NOT NULL DEFAULT 0, referrer_id int(10) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY (id));
INSERT INTO tblaffiliates_hits (affiliate_id, referrer_id, created_at) VALUES (7, 1, '2026-09-01 10:00:00'), (7, 1, '2026-09-01 12:00:00'), (7, 0, '2026-09-03 08:00:00');
CREATE TABLE tblaffiliates_referrers (id int(10) unsigned NOT NULL AUTO_INCREMENT, affiliate_id int(10) unsigned NOT NULL DEFAULT 0, referrer varchar(500) NOT NULL DEFAULT '', created_at datetime NULL, updated_at datetime NULL, PRIMARY KEY (id));
INSERT INTO tblaffiliates_referrers (id, affiliate_id, referrer) VALUES (1, 7, 'https://blog.demo-client.example/review');
