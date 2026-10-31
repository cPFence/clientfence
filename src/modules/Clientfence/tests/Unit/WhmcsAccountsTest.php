<?php

declare(strict_types=1);

/**
 * ClientFence: the WHMCS import's account steps (Whmcs\Accounts, Run::compare) from a small invented
 * WHMCS database into a ClientFence database, both SQLite: WHMCS's column names only, no WHMCS code
 * or data.
 */

use ClientFence\Mod\Clientfence\Whmcs\Accounts;
use ClientFence\Mod\Clientfence\Whmcs\Cipher;
use ClientFence\Mod\Clientfence\Whmcs\Map;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use ClientFence\Mod\Extension\Entity\ExtensionMeta;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

const WHMCS_TEST_KEY = 'test-cc-encryption-hash';
const WHMCS_TEST_SECRET = 'JBSWY3DPEHPK3PXP';
// WHMCS 9's own encryption (User::aesEncryptValue) of {"secret":"JBSWY3DPEHPK3PXP","backupcode":"x"}
// with WHMCS_TEST_KEY as cc_encryption_hash: its two-factor settings after the 2FA storage migration.
const WHMCS_9_TOTP = '5e3e8a69ae177788e09c6b4181e20d82907982260c03b394dc2093b4adf24b7d6f458c9bcfea6bcf569f324f750a6d9d';

function whmcsAccountsSource(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
        'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY)',
        'CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT, prefix TEXT, suffix TEXT, format INTEGER, rate REAL, "default" INTEGER)',
        'CREATE TABLE tblclientgroups (id INTEGER PRIMARY KEY, groupname TEXT, groupcolour TEXT, discountpercent REAL, susptermexempt TEXT, separateinvoices TEXT)',
        'CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT, relid INTEGER, fieldname TEXT, fieldtype TEXT, adminonly TEXT, required TEXT, sortorder INTEGER)',
        'CREATE TABLE tblcustomfieldsvalues (id INTEGER PRIMARY KEY, fieldid INTEGER, relid INTEGER, value TEXT)',
        "CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phonenumber TEXT, tax_id TEXT, password TEXT, authmodule TEXT DEFAULT '', authdata TEXT DEFAULT '', currency INTEGER, taxexempt INTEGER DEFAULT 0, datecreated TEXT, notes TEXT, groupid INTEGER DEFAULT 0, ip TEXT, status TEXT, language TEXT, emailoptout INTEGER DEFAULT 0, marketing_emails_opt_in INTEGER DEFAULT 1, created_at TEXT)",
        "CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT, language TEXT, second_factor TEXT DEFAULT '', second_factor_config TEXT DEFAULT '', last_ip TEXT, last_login TEXT, created_at TEXT)",
        'CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INTEGER, client_id INTEGER, owner INTEGER, permissions TEXT, last_login TEXT, created_at TEXT)',
        'CREATE TABLE tblcontacts (id INTEGER PRIMARY KEY, userid INTEGER, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phonenumber TEXT, tax_id TEXT, subaccount INTEGER DEFAULT 0, password TEXT, permissions TEXT, generalemails INTEGER DEFAULT 0, invoiceemails INTEGER DEFAULT 0, productemails INTEGER DEFAULT 0, domainemails INTEGER DEFAULT 0, supportemails INTEGER DEFAULT 0, affiliateemails INTEGER DEFAULT 0, created_at TEXT)',
        'CREATE TABLE tblnotes (id INTEGER PRIMARY KEY, userid INTEGER, adminid INTEGER, created TEXT, modified TEXT, note TEXT, sticky INTEGER)',
        'CREATE TABLE tblauthn_account_links (id INTEGER PRIMARY KEY, provider TEXT, remote_user_id TEXT, user_id INTEGER, client_id INTEGER, contact_id INTEGER, metadata TEXT, created_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('tblconfiguration', ['setting' => 'Version', 'value' => '9.0.4-release.1']);
    $db->executeStatement('INSERT INTO tblcurrencies VALUES (1, \'USD\', \'$\', \'\', 2, 1, 1), (2, \'EGP\', \'\', \' EGP\', 4, 50, 0)');
    $db->insert('tblclientgroups', ['id' => 5, 'groupname' => 'VIP', 'discountpercent' => 10, 'susptermexempt' => '', 'separateinvoices' => '']);
    $db->insert('tblcustomfields', ['id' => 1, 'type' => 'client', 'fieldname' => 'Passport', 'fieldtype' => 'text', 'adminonly' => 'on', 'required' => '', 'sortorder' => 0]);
    $db->insert('tblcustomfields', ['id' => 2, 'type' => 'client', 'fieldname' => 'Referral', 'fieldtype' => 'text', 'adminonly' => '', 'required' => 'on', 'sortorder' => 1]);
    $db->insert('tblcustomfields', ['id' => 3, 'type' => 'product', 'fieldname' => 'Domain', 'fieldtype' => 'text', 'adminonly' => '', 'required' => '', 'sortorder' => 0]);
    $db->insert('tblcustomfieldsvalues', ['fieldid' => 1, 'relid' => 1, 'value' => 'P-123']);
    $db->insert('tblcustomfieldsvalues', ['fieldid' => 3, 'relid' => 1, 'value' => 'not a client field']);

    $bcrypt = password_hash('secret', PASSWORD_BCRYPT);
    $client = ['address1' => '1 Main St', 'city' => 'Town', 'country' => 'gb', 'datecreated' => '2024-01-02', 'notes' => '', 'ip' => '203.0.113.9', 'language' => ''];
    $db->insert('tblclients', ['id' => 1, 'firstname' => 'Ann', 'lastname' => 'Lee', 'companyname' => 'Ann Co', 'email' => 'ann@acct.test', 'phonenumber' => '+44.2071234567', 'currency' => 2, 'status' => 'Active', 'groupid' => 5, 'notes' => 'Pays late', 'marketing_emails_opt_in' => 0] + $client);
    $db->insert('tblclients', ['id' => 2, 'firstname' => 'Bob', 'lastname' => 'Ray', 'companyname' => '', 'email' => 'billing@bob.test', 'phonenumber' => '5550100', 'currency' => 1, 'status' => 'Inactive'] + $client);
    $db->insert('tblclients', ['id' => 3, 'firstname' => 'Ann', 'lastname' => 'Lee', 'companyname' => '', 'email' => 'ann-old@acct.test', 'phonenumber' => '', 'currency' => 1, 'status' => 'Closed'] + $client);
    $db->insert('tblclients', ['id' => 4, 'firstname' => 'Nobody', 'lastname' => 'Owner', 'companyname' => '', 'email' => 'nobody@acct.test', 'phonenumber' => '', 'currency' => 1, 'status' => 'Active'] + $client);
    $db->insert('tblnotes', ['userid' => 1, 'created' => '2025-03-04 10:00:00', 'note' => 'VIP since 2025', 'sticky' => 1]);

    $db->insert('tblusers', ['id' => 10, 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@acct.test', 'password' => $bcrypt, 'language' => 'arabic', 'second_factor' => 'totp', 'second_factor_config' => WHMCS_9_TOTP, 'created_at' => '2024-01-02 00:00:00']);
    $db->insert('tblusers', ['id' => 11, 'first_name' => 'Bob', 'last_name' => 'Ray', 'email' => 'bob@bob.test', 'password' => md5('old'), 'language' => 'english', 'created_at' => '2024-01-02 00:00:00']);
    $db->insert('tblusers', ['id' => 12, 'first_name' => 'Cy', 'last_name' => 'Helper', 'email' => 'cy@help.test', 'password' => $bcrypt, 'language' => '', 'second_factor' => 'totp', 'second_factor_config' => 'not-whmcs-encrypted', 'last_login' => '2026-01-01 09:00:00', 'created_at' => '2025-01-01 00:00:00']);
    $db->insert('tblusers', ['id' => 13, 'first_name' => 'No', 'last_name' => 'Account', 'email' => 'none@x.test', 'password' => $bcrypt, 'language' => '', 'created_at' => '2025-01-01 00:00:00']);
    foreach ([[10, 1, 1, ''], [11, 2, 1, ''], [10, 3, 1, ''], [12, 1, 0, 'products,invoices,bogus'], [10, 2, 0, 'tickets']] as [$user, $clientId, $owner, $permissions]) {
        $db->insert('tblusers_clients', ['auth_user_id' => $user, 'client_id' => $clientId, 'owner' => $owner, 'permissions' => $permissions, 'created_at' => '2025-01-01 00:00:00']);
    }
    $db->insert('tblauthn_account_links', ['provider' => 'google_signin', 'remote_user_id' => 'google-sub-10', 'user_id' => 10, 'metadata' => '{"email":"ann.g@gmail.test"}', 'created_at' => '2025-05-05 00:00:00']);

    $contact = ['lastname' => '', 'companyname' => '', 'address1' => '', 'address2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => 'GB', 'phonenumber' => '+44.1', 'tax_id' => ''];
    $db->insert('tblcontacts', $contact + ['id' => 1, 'userid' => 1, 'firstname' => 'Acc', 'email' => 'accounts@acct.test', 'invoiceemails' => 1, 'supportemails' => 1, 'affiliateemails' => 1]);
    $db->insert('tblcontacts', $contact + ['id' => 2, 'userid' => 1, 'firstname' => 'Bad', 'email' => 'not-an-email']);
    $db->insert('tblcontacts', $contact + ['id' => 3, 'userid' => 1, 'firstname' => 'Twice', 'email' => 'ACCOUNTS@acct.test']);

    return $db;
}

function whmcsAccountsTarget(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $custom = implode(', ', array_map(fn (int $i): string => "custom_$i TEXT", range(1, 20)));
    foreach ([
        'CREATE TABLE currency (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, is_default INTEGER DEFAULT 0, conversion_rate REAL DEFAULT 1, is_rate_manual INTEGER DEFAULT 0, format_pattern TEXT, fraction_digits INTEGER, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_group (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, created_at TEXT, updated_at TEXT)',
        "CREATE TABLE client (id INTEGER PRIMARY KEY, aid TEXT, client_group_id INTEGER, role TEXT, auth_type TEXT, email TEXT UNIQUE, billing_email TEXT, pass TEXT, salt TEXT, status TEXT, email_approved INTEGER, tax_exempt INTEGER, type TEXT, first_name TEXT, last_name TEXT, gender TEXT, birthday TEXT, phone_cc TEXT, phone TEXT, company TEXT, company_vat TEXT, company_number TEXT, address_1 TEXT, address_2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, notes TEXT, currency TEXT, lang TEXT, timezone TEXT, ip TEXT, api_token TEXT, referred_by TEXT, $custom, created_at TEXT, updated_at TEXT)",
        'CREATE TABLE client_user (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, first_name TEXT, last_name TEXT, pass TEXT, lang TEXT, status TEXT, session_version INTEGER, last_login_at TEXT, last_ip TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_user_account (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, client_id INTEGER, owner INTEGER, permissions TEXT, last_login_at TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_twofactor (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, subject_id INTEGER, secret TEXT, backup_hash TEXT, last_step INTEGER, enabled_at TEXT, updated_at TEXT)',
        'CREATE TABLE client_google (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, subject_id INTEGER, google_sub TEXT UNIQUE, email TEXT, created_at TEXT, last_used_at TEXT)',
        "CREATE TABLE client_contact (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, first_name TEXT, last_name TEXT, company TEXT, email TEXT, phone_cc TEXT, phone TEXT, address_1 TEXT, address_2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, tax_id TEXT, emails TEXT, created_at TEXT, updated_at TEXT)",
        'CREATE TABLE client_marketing_optout (client_id INTEGER PRIMARY KEY, source TEXT, opted_out_at TEXT)',
        'CREATE TABLE ' . Map::TABLE . ' (source TEXT, entity TEXT, source_id TEXT, target_id INTEGER, created_at TEXT, PRIMARY KEY (source, entity, source_id))',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('currency', ['id' => 1, 'code' => 'USD', 'is_default' => 1, 'conversion_rate' => 1]);
    $db->insert('client_group', ['id' => 1, 'title' => 'Default']);

    return $db;
}

/**
 * Runs every step to the end in batches of $batch, as Run::work does.
 *
 * @return array{0: Accounts, 1: array<string, mixed>}
 */
function whmcsImportAll(Connection $source, Connection $target, ?Cipher $cipher, int $batch = 2): array
{
    $config = [];
    $accounts = new Accounts(new Source($source), $target, new Map($target), $cipher, static fn (string $t): string => 'enc:' . $t, static function (string $ext, array $changes) use (&$config): void {
        $config[$ext] = array_replace_recursive($config[$ext] ?? [], $changes);
    }, '2026-10-19 00:00:00');
    foreach (array_keys(Accounts::STEPS) as $step) {
        $after = 0;
        do {
            [$after] = $accounts->batch($step, (int) $after, $batch);
        } while ($after !== null);
    }

    return [$accounts, $config];
}

test('WHMCS\'s cipher opens what it encrypted, and refuses another key', function (): void {
    $cipher = Cipher::withKey(WHMCS_TEST_KEY);
    $long = str_repeat('a long secret value, ', 5);

    expect($cipher->decrypt($cipher->encrypt($long, 'some-iv')))->toBe($long)
        ->and(Cipher::withKey('another key')->decrypt($cipher->encrypt($long, 'some-iv')))->toBeNull()
        ->and($cipher->decrypt('not base64 !'))->toBeNull()
        // One block is WHMCS's encryption of an empty value (a cPanel server's unused password).
        ->and($cipher->decrypt($cipher->encrypt('', 'some-iv')))->toBe('')
        // WHMCS 9's model encryption (two-factor settings): opens with the key, not with another, not the old cipher's values.
        ->and(Accounts::totpSecret($cipher->decryptModel(WHMCS_9_TOTP)))->toBe(WHMCS_TEST_SECRET)
        ->and(Cipher::withKey('another key')->decryptModel(WHMCS_9_TOTP))->toBeNull()
        ->and($cipher->decryptModel($cipher->encrypt($long, 'some-iv')))->toBeNull()
        // Older WHMCS's cipher still opens.
        ->and(Accounts::totpSecret($cipher->decrypt($cipher->encrypt('{"secret":"' . WHMCS_TEST_SECRET . '"}', 'some-iv'))))->toBe(WHMCS_TEST_SECRET)
        ->and(Accounts::totpSecret('{"secret":"jbsw y3dp ehpk 3pxp"}'))->toBe(WHMCS_TEST_SECRET)
        ->and(Accounts::totpSecret(serialize(['settings' => ['secret' => WHMCS_TEST_SECRET]])))->toBe(WHMCS_TEST_SECRET)
        ->and(Accounts::totpSecret('{"other":"JBSWY3DPEHPK3PXP"}'))->toBeNull()
        ->and(Accounts::totpSecret(null))->toBeNull();
});

test('clients keep their ids, details, currency, group, notes and custom fields', function (): void {
    $target = whmcsAccountsTarget();
    [, $config] = whmcsImportAll(whmcsAccountsSource(), $target, Cipher::withKey(WHMCS_TEST_KEY));

    $ann = $target->fetchAssociative('SELECT * FROM client WHERE id = 1');
    $vip = $target->fetchOne("SELECT id FROM client_group WHERE title = 'VIP'");
    expect($ann)->toMatchArray([
        'email' => 'ann@acct.test', 'billing_email' => null, 'status' => 'active', 'type' => 'company', 'company' => 'Ann Co',
        'phone_cc' => '44', 'phone' => '2071234567', 'country' => 'GB', 'currency' => 'EGP', 'lang' => 'ar_SA',
        'client_group_id' => (int) $vip, 'custom_1' => 'P-123', 'custom_2' => null, 'created_at' => '2024-01-02 00:00:00', 'email_approved' => 1,
    ])->and($ann['notes'])->toBe("Pays late\n\n[2025-03-04 10:00:00] VIP since 2025")
        ->and(password_verify('secret', $ann['pass']))->toBeTrue()
        ->and($target->fetchFirstColumn('SELECT status FROM client ORDER BY id'))->toBe(['active', 'active', 'canceled', 'active'])
        ->and($target->fetchFirstColumn('SELECT client_id FROM client_marketing_optout'))->toBe([1])
        ->and($config['mod_client']['custom_fields'])->toBe([
            'custom_1' => ['active' => 0, 'required' => 0, 'title' => 'Passport'],
            'custom_2' => ['active' => 1, 'required' => 1, 'title' => 'Referral'],
        ])
        ->and($target->fetchAllAssociative('SELECT code, is_default, conversion_rate, format_pattern, fraction_digits FROM currency ORDER BY code'))->toBe([
            ['code' => 'EGP', 'is_default' => 0, 'conversion_rate' => 50.0, 'format_pattern' => '{amount} EGP', 'fraction_digits' => 0],
            ['code' => 'USD', 'is_default' => 1, 'conversion_rate' => 1.0, 'format_pattern' => '${amount}', 'fraction_digits' => 2],
        ]);
});

test('owner logins sign in to their account; the others are named in warnings', function (): void {
    $target = whmcsAccountsTarget();
    [$accounts] = whmcsImportAll(whmcsAccountsSource(), $target, Cipher::withKey(WHMCS_TEST_KEY));
    $emails = $target->fetchAllKeyValue('SELECT id, email FROM client ORDER BY id');
    $bob = $target->fetchAssociative('SELECT email, billing_email, pass, lang FROM client WHERE id = 2');

    // Bob's login email differs from the account email: the login signs in, invoices keep going to the account email.
    expect($bob)->toMatchArray(['email' => 'bob@bob.test', 'billing_email' => 'billing@bob.test', 'lang' => 'en_US'])
        // An md5 WHMCS password cannot be kept.
        ->and(password_get_info($bob['pass'])['algo'])->not->toBeNull()
        ->and(password_verify('old', $bob['pass']))->toBeFalse()
        // Ann's login owns account 1; account 3 keeps its own email.
        ->and($emails)->toBe([1 => 'ann@acct.test', 2 => 'bob@bob.test', 3 => 'ann-old@acct.test', 4 => 'nobody@acct.test'])
        ->and(implode("\n", $accounts->warnings()))
        ->toContain('Client #2: the WHMCS password could not be kept')
        ->toContain('Client #3: its owner login ann@acct.test owns an earlier account')
        ->toContain('Client #4 has no owner login in WHMCS')
        ->toContain('Client group "VIP": its discount')
        // Named once: its password is not reported again.
        ->not->toContain('Client #3: the WHMCS password');
});

test('two-factor secrets and Google links come over; authorized users keep their WHMCS permissions', function (): void {
    $target = whmcsAccountsTarget();
    [$accounts] = whmcsImportAll(whmcsAccountsSource(), $target, Cipher::withKey(WHMCS_TEST_KEY));

    expect($target->fetchAllAssociative('SELECT kind, subject_id, secret FROM client_twofactor'))->toBe([['kind' => 'client', 'subject_id' => 1, 'secret' => 'enc:' . WHMCS_TEST_SECRET]])
        ->and($target->fetchAllAssociative('SELECT kind, subject_id, google_sub, email FROM client_google'))->toBe([['kind' => 'client', 'subject_id' => 1, 'google_sub' => 'google-sub-10', 'email' => 'ann.g@gmail.test']])
        ->and($target->fetchAllAssociative('SELECT u.email, a.client_id, a.owner, a.permissions, a.created_at FROM client_user_account a JOIN client_user u ON u.id = a.user_id'))->toBe([
            ['email' => 'cy@help.test', 'client_id' => 1, 'owner' => 0, 'permissions' => 'products,invoices', 'created_at' => '2025-01-01 00:00:00'],
        ])
        ->and($target->fetchOne('SELECT last_login_at FROM client_user'))->toBe('2026-01-01 09:00:00')
        ->and(implode("\n", $accounts->warnings()))
        // Cy's WHMCS two-factor setting does not decrypt: no two-factor, and a warning.
        ->toContain('Login cy@help.test used two-factor sign-in (totp) in WHMCS, which could not be carried over')
        // Ann's login is an owner: it cannot also be Bob's authorized user.
        ->toContain('Login ann@acct.test: it signs in to client #1, so its access to client #2 was not imported');
});

test('without the encryption key two-factor is not carried over, and says so; settings WHMCS never encrypted are', function (): void {
    $source = whmcsAccountsSource();
    // Before WHMCS 9's 2FA storage migration the settings are not encrypted.
    $source->update('tblusers', ['second_factor' => 'totp', 'second_factor_config' => serialize(['secret' => WHMCS_TEST_SECRET])], ['id' => 11]);
    $target = whmcsAccountsTarget();
    [$accounts] = whmcsImportAll($source, $target, null);

    expect($target->fetchAllAssociative('SELECT kind, subject_id FROM client_twofactor'))->toBe([['kind' => 'client', 'subject_id' => 2]])
        ->and(implode("\n", $accounts->warnings()))->toContain('Client #1 used two-factor sign-in (totp) in WHMCS, which could not be carried over (no WHMCS encryption key was entered)');
});

test('contacts come over with their email kinds; bad and repeated ones are named', function (): void {
    $target = whmcsAccountsTarget();
    [$accounts] = whmcsImportAll(whmcsAccountsSource(), $target, null);

    expect($target->fetchAllAssociative('SELECT client_id, first_name, email, phone_cc, phone, emails FROM client_contact'))->toBe([
        ['client_id' => 1, 'first_name' => 'Acc', 'email' => 'accounts@acct.test', 'phone_cc' => '44', 'phone' => '1', 'emails' => 'invoice,support'],
    ])->and(implode("\n", $accounts->warnings()))
        ->toContain('Contact #2 of client #1 has no valid email')
        ->toContain('Contact #3 of client #1: the account already has a contact with ACCOUNTS@acct.test');
});

test('a second run imports nothing twice, and the check compares WHMCS with the import', function (): void {
    $source = whmcsAccountsSource();
    // A login without an account (not imported) with two-factor and a Google link: the check
    // leaves both out of what WHMCS holds.
    $source->update('tblusers', ['second_factor' => 'totp', 'second_factor_config' => 'x'], ['id' => 13]);
    $source->insert('tblauthn_account_links', ['provider' => 'google_signin', 'remote_user_id' => 'google-sub-13', 'user_id' => 13, 'metadata' => '{}', 'created_at' => '2025-05-05 00:00:00']);
    $target = whmcsAccountsTarget();
    whmcsImportAll($source, $target, Cipher::withKey(WHMCS_TEST_KEY));
    $before = $target->fetchOne('SELECT COUNT(*) FROM ' . Map::TABLE);
    [$again] = whmcsImportAll($source, $target, Cipher::withKey(WHMCS_TEST_KEY), 50);

    $check = array_column(Run::compare(new Source($source), $target, ['warnings' => ['a', 'b']])['rows'], null, 'step');
    expect($target->fetchOne('SELECT COUNT(*) FROM ' . Map::TABLE))->toBe($before)
        ->and($again->warnings())->toBe([])
        ->and($target->fetchOne('SELECT COUNT(*) FROM client'))->toEqual(4)
        ->and($check['clients'])->toMatchArray(['whmcs' => 4, 'imported' => 4])
        ->and($check['users'])->toMatchArray(['whmcs' => 2, 'imported' => 1])
        ->and($check['contacts'])->toMatchArray(['whmcs' => 3, 'imported' => 1])
        ->and($check['fields'])->toMatchArray(['whmcs' => 2, 'imported' => 2])
        ->and($check['twofactor'])->toMatchArray(['whmcs' => 2, 'imported' => 1])
        ->and($check['google'])->toMatchArray(['whmcs' => 1, 'imported' => 1]);
});

test('import settings are saved and read back when an extension has no config row', function (): void {
    $target = whmcsAccountsTarget();
    $orm = Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/Extension/Entity'], true);
    $orm->setProxyDir(sys_get_temp_dir());
    $orm->setProxyNamespace('ClientFence\\Tests\\DoctrineProxies');
    $em = new Doctrine\ORM\EntityManager($target, $orm);
    (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema([$em->getClassMetadata(ExtensionMeta::class)]);

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['crypt'] = new ClientFence\Crypt();
    $di['cache'] = new Symfony\Component\Cache\Adapter\ArrayAdapter();
    $service = new ClientFence\Mod\Extension\Service();
    $service->setDi($di);
    $di['mod_service'] = $di->protect(static fn (string $ext): ClientFence\Mod\Extension\Service => $service);
    $changes = ['custom_fields' => ['custom_1' => ['title' => 'Passport', 'active' => 0, 'required' => 0]]];

    (new ReflectionMethod(Run::class, 'mergeConfig'))->invoke(null, $di, 'mod_client', $changes);
    $em->clear();

    expect($service->getConfig('mod_client'))->toBe(['ext' => 'mod_client'] + $changes);
});

test('a new run counts what each step reads', function (): void {
    $state = Run::start(new Source(whmcsAccountsSource()));

    expect(array_map(fn (array $s): int => $s['total'], $state['steps']))->toBe([
        'currencies' => 2, 'groups' => 1, 'fields' => 2, 'clients' => 4, 'users' => 2, 'contacts' => 3,
        // The catalogue steps: this WHMCS database has no catalogue.
        'categories' => 0, 'servers' => 0, 'products' => 0, 'addons' => 0, 'options' => 0, 'upgrades' => 0, 'promotions' => 0, 'tlds' => 0, 'taxes' => 0, 'bundles' => 0,
        'services' => 0, 'serviceaddons' => 0, 'domains' => 0,
        'invoices' => 0, 'transactions' => 0, 'credits' => 0, 'balances' => 4, 'notes' => 0,
        'staff' => 0, 'departments' => 0, 'predefined' => 0, 'tickets' => 0, 'replies' => 0, 'ticketnotes' => 0, 'feedback' => 0,
        'kbcategories' => 0, 'kbarticles' => 0, 'announcements' => 0, 'networkissues' => 0, 'emails' => 0,
        'licencebans' => 0, 'licencelog' => 0, 'archive' => 0,
    ])->and($state)->toMatchArray(['version' => '9.0.4-release.1', 'step' => 'currencies', 'finished_at' => null]);
});

/** A run's container on a target database: crypt, and the run state in mod_clientfence_state. */
function whmcsRunDi(Connection $target): Pimple\Container
{
    $target->executeStatement('CREATE TABLE IF NOT EXISTS mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
    $target->executeStatement('CREATE TABLE IF NOT EXISTS ' . Run::WARNINGS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, step TEXT, message TEXT, created_at TEXT)');
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($target);
    $di['em'] = $em;
    $crypt = Mockery::mock();
    $crypt->shouldReceive('encrypt')->andReturnUsing(fn (string $t): string => 'enc:' . $t);
    $di['crypt'] = $crypt;

    return $di;
}

test('a run keeps its warnings in their own table and its state small; a failed batch leaves no trace', function (): void {
    $source = whmcsAccountsSource();
    $source->executeStatement("DELETE FROM tblcustomfields WHERE type = 'client'");
    $target = whmcsAccountsTarget();
    $target->executeStatement('DROP TABLE client_contact');
    $di = whmcsRunDi($target);
    $state = Run::start(new Source($source));

    // Everything up to the contacts imports; the contacts step fails (no table) and is rolled back.
    expect(fn () => Run::work($di, $target, new Source($source), $state, 0, 2))->toThrow(ClientFence\InformationException::class, 'The import stopped at "Contacts"');
    $saved = Run::state($di);
    $warnings = Run::warnings($target);
    expect($saved['steps']['clients'])->toMatchArray(['done' => 4, 'finished' => true])
        ->and($saved['steps']['contacts'])->toMatchArray(['done' => 0, 'after' => 0, 'finished' => false])
        ->and($saved['error'])->toStartWith('Contacts: ')
        ->and($saved['warnings'])->toBe(count($warnings))
        ->and(implode("\n", $warnings))->toContain('Client #2: the WHMCS password could not be kept')->not->toContain('Contact #2')
        ->and(strlen((string) $target->fetchOne("SELECT value FROM mod_clientfence_state WHERE name = 'whmcs_import_run'")))->toBeLessThan(4500);

    // With the table back, the run goes on from the failed batch and finishes.
    $target->executeStatement("CREATE TABLE client_contact (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, first_name TEXT, last_name TEXT, company TEXT, email TEXT, phone_cc TEXT, phone TEXT, address_1 TEXT, address_2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, tax_id TEXT, emails TEXT, created_at TEXT, updated_at TEXT)");
    // (the credit balances step reads every client's balance)
    $target->executeStatement('CREATE TABLE client_balance (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, type TEXT, rel_id TEXT, invoice_item_id INTEGER, amount REAL, description TEXT, created_at TEXT, updated_at TEXT)');
    $done = Run::work($di, $target, new Source($source), $saved, 0, 2);
    expect($done['finished_at'])->not->toBeNull()
        ->and($done['error'])->toBeNull()
        ->and($target->fetchOne('SELECT COUNT(*) FROM client_contact'))->toEqual(1)
        ->and(Run::warningCount($target))->toBe($done['warnings'])
        ->and(implode("\n", Run::warnings($target)))->toContain('Contact #2 of client #1 has no valid email');
});
