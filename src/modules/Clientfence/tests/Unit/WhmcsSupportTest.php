<?php

declare(strict_types=1);

/**
 * ClientFence: the WHMCS import's support steps (Whmcs\Support) from a small invented WHMCS
 * database into a ClientFence database, both SQLite: WHMCS's column names only, no WHMCS code or
 * data. Attachments are read from and written to temporary folders.
 */

use ClientFence\Mod\Clientfence\Whmcs\Cipher;
use ClientFence\Mod\Clientfence\Whmcs\Map;
use ClientFence\Mod\Clientfence\Whmcs\Source;
use ClientFence\Mod\Clientfence\Whmcs\Support;
use ClientFence\Mod\Clientfence\Whmcs\Text;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

const WHMCS_SUPPORT_KEY = 'support-test-key';

function whmcsSupportSource(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE tblconfiguration (setting TEXT, value TEXT)',
        "CREATE TABLE tbladmins (id INTEGER PRIMARY KEY, roleid INTEGER, username TEXT, passwordhash TEXT, authmodule TEXT DEFAULT '', firstname TEXT, lastname TEXT, email TEXT, signature TEXT DEFAULT '', disabled INTEGER DEFAULT 0, created_at TEXT)",
        "CREATE TABLE tblticketdepartments (id INTEGER PRIMARY KEY, name TEXT, description TEXT DEFAULT '', email TEXT, clientsonly TEXT DEFAULT '', piperepliesonly TEXT DEFAULT '', noautoresponder TEXT DEFAULT '', hidden TEXT DEFAULT '', \"order\" INTEGER DEFAULT 0, host TEXT DEFAULT '', port TEXT DEFAULT '', login TEXT DEFAULT '', password TEXT DEFAULT '', mail_auth_config TEXT, feedback_request INTEGER DEFAULT 1)",
        'CREATE TABLE tblticketpredefinedcats (id INTEGER PRIMARY KEY, parentid INTEGER, name TEXT)',
        'CREATE TABLE tblticketpredefinedreplies (id INTEGER PRIMARY KEY, catid INTEGER, name TEXT, reply TEXT)',
        "CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, did INTEGER, userid INTEGER, contactid INTEGER DEFAULT 0, requestor_id INTEGER DEFAULT 0, name TEXT DEFAULT '', email TEXT DEFAULT '', cc TEXT DEFAULT '', c TEXT DEFAULT '', date TEXT, title TEXT, message TEXT, status TEXT, urgency TEXT, admin TEXT DEFAULT '', attachment TEXT DEFAULT '', attachments_removed INTEGER DEFAULT 0, lastreply TEXT, flag INTEGER DEFAULT 0, service TEXT DEFAULT '', merged_ticket_id INTEGER DEFAULT 0, editor TEXT DEFAULT 'markdown')",
        "CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INTEGER, userid INTEGER DEFAULT 0, contactid INTEGER DEFAULT 0, requestor_id INTEGER DEFAULT 0, name TEXT DEFAULT '', email TEXT DEFAULT '', date TEXT, message TEXT, admin TEXT DEFAULT '', attachment TEXT DEFAULT '', attachments_removed INTEGER DEFAULT 0, rating INTEGER DEFAULT 0, editor TEXT DEFAULT 'markdown')",
        "CREATE TABLE tblticketnotes (id INTEGER PRIMARY KEY, ticketid INTEGER, admin TEXT, date TEXT, message TEXT, attachments TEXT DEFAULT '', attachments_removed INTEGER DEFAULT 0, editor TEXT DEFAULT 'markdown')",
        "CREATE TABLE tblticketfeedback (id INTEGER PRIMARY KEY, ticketid INTEGER, adminid INTEGER, rating INTEGER, comments TEXT, datetime TEXT, ip TEXT DEFAULT '')",
        'CREATE TABLE tblticketstatuses (id INTEGER PRIMARY KEY, title TEXT, color TEXT, sortorder INTEGER, showactive INTEGER, showawaiting INTEGER, autoclose INTEGER)',
        "CREATE TABLE tblknowledgebasecats (id INTEGER PRIMARY KEY, parentid INTEGER DEFAULT 0, name TEXT, description TEXT DEFAULT '', hidden TEXT DEFAULT '', catid INTEGER DEFAULT 0, language TEXT DEFAULT '')",
        "CREATE TABLE tblknowledgebase (id INTEGER PRIMARY KEY, title TEXT, article TEXT, views INTEGER DEFAULT 0, useful INTEGER DEFAULT 0, votes INTEGER DEFAULT 0, private TEXT DEFAULT '', \"order\" INTEGER DEFAULT 0, parentid INTEGER DEFAULT 0, language TEXT DEFAULT '')",
        'CREATE TABLE tblknowledgebaselinks (id INTEGER PRIMARY KEY, categoryid INTEGER, articleid INTEGER)',
        "CREATE TABLE tblannouncements (id INTEGER PRIMARY KEY, date TEXT, title TEXT, announcement TEXT, published INTEGER, parentid INTEGER DEFAULT 0, language TEXT DEFAULT '', created_at TEXT, updated_at TEXT)",
        'CREATE TABLE tblnetworkissues (id INTEGER PRIMARY KEY, title TEXT, description TEXT, type TEXT, affecting TEXT, server INTEGER, priority TEXT, startdate TEXT, enddate TEXT, status TEXT, lastupdate TEXT, created_at TEXT, updated_at TEXT)',
        "CREATE TABLE tblemails (id INTEGER PRIMARY KEY, userid INTEGER, subject TEXT, message TEXT, date TEXT, \"to\" TEXT, cc TEXT DEFAULT '', bcc TEXT DEFAULT '', attachments TEXT DEFAULT '', pending INTEGER DEFAULT 0, failed INTEGER DEFAULT 0)",
        'CREATE TABLE tblticketspamfilters (id INTEGER PRIMARY KEY, type TEXT, content TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->executeStatement("INSERT INTO tblconfiguration VALUES ('Version', '9.0.4-release.1'), ('CloseInactiveTickets', '72'), ('TicketRatingEnabled', 'on')");
    $db->executeStatement("INSERT INTO tblticketspamfilters VALUES (1, 'sender', 'spam@bad.test')");
    // Staff: 1 is already staff here (same email), 2 a support operator, 3 a sales operator without a usable hash.
    $db->insert('tbladmins', ['id' => 1, 'roleid' => 1, 'username' => 'owner', 'passwordhash' => password_hash('x', PASSWORD_BCRYPT), 'firstname' => 'Olive', 'lastname' => 'Owner', 'email' => 'Owner@Host.test']);
    $db->insert('tbladmins', ['id' => 2, 'roleid' => 3, 'username' => 'sam', 'passwordhash' => '$2y$10$abcdefghijklmnopqrstuuS6Qe2cS6x1kQnqtkpHgV5Q5V7r6m9n2', 'authmodule' => 'totp', 'firstname' => 'Sam', 'lastname' => 'Support', 'email' => 'sam@host.test', 'signature' => 'Sam from support', 'created_at' => '2025-01-02 03:04:05']);
    $db->insert('tbladmins', ['id' => 3, 'roleid' => 2, 'username' => 'sally', 'passwordhash' => 'not-a-hash', 'firstname' => 'Sally', 'lastname' => 'Sales', 'email' => 'sally@host.test']);
    // Departments: 1 takes over the fresh install's help desk 1; 2 is hidden, with an IMAP mailbox; 3 reads POP3.
    $cipher = Cipher::withKey(WHMCS_SUPPORT_KEY);
    $db->insert('tblticketdepartments', ['id' => 1, 'name' => 'Support', 'email' => 'support@host.test', 'feedback_request' => 1]);
    $db->insert('tblticketdepartments', ['id' => 2, 'name' => 'Abuse', 'email' => 'abuse@host.test', 'hidden' => 'on', 'clientsonly' => 'on', 'piperepliesonly' => 'on', 'host' => 'imap.host.test', 'port' => '993', 'login' => 'abuse@host.test', 'password' => $cipher->encrypt('mailbox-secret', 'abcdefghijklmnopqrst'), 'feedback_request' => 0]);
    $db->insert('tblticketdepartments', ['id' => 3, 'name' => 'Billing', 'email' => 'billing@host.test', 'host' => 'pop.host.test', 'port' => '995', 'login' => 'billing@host.test', 'mail_auth_config' => '{"provider":"google"}']);
    $db->executeStatement("INSERT INTO tblticketpredefinedcats VALUES (1, 0, 'Sales'), (2, 1, 'Upgrades')");
    $db->executeStatement("INSERT INTO tblticketpredefinedreplies VALUES (1, 2, 'Upgrade done', 'Hi,\nYour upgrade is done.'), (2, 0, 'Thanks', 'Thank you!')");
    $db->executeStatement("INSERT INTO tblticketstatuses VALUES (1, 'Open', '', 1, 1, 1, 0), (2, 'Answered', '', 2, 1, 0, 1), (3, 'Closed', '', 3, 0, 0, 0), (4, 'Waiting on vendor', '', 4, 1, 0, 0), (5, 'Archived', '', 5, 0, 0, 0)");

    // 5: client 1's ticket about service 7, answered, assigned to Sam; with two files (one missing).
    $db->insert('tbltickets', ['id' => 5, 'tid' => '5', 'did' => 1, 'userid' => 1, 'requestor_id' => 0, 'date' => '2026-01-10 09:00:00', 'title' => 'Site down', 'message' => "My site is down.\nPlease help.", 'status' => 'Answered', 'urgency' => 'High', 'attachment' => '111111_screen shot.png|222222_missing.log', 'lastreply' => '2026-01-11 10:00:00', 'flag' => 2, 'service' => 'S7']);
    // 6: a guest's, with a WHMCS ticket number unlike its id and a CC; 7: opened by staff for client 2.
    $db->insert('tbltickets', ['id' => 6, 'tid' => 'ABC-123456', 'did' => 2, 'userid' => 0, 'name' => 'Gina Guest', 'email' => 'gina@guest.test', 'cc' => 'boss@guest.test', 'date' => '2026-02-01 08:00:00', 'title' => 'Question', 'message' => 'Do you sell VPS?', 'status' => 'Waiting on vendor', 'urgency' => 'Low', 'attachment' => '333333_guest.txt', 'lastreply' => '2026-02-01 08:00:00']);
    $db->insert('tbltickets', ['id' => 7, 'tid' => '7', 'did' => 3, 'userid' => 2, 'date' => '2026-03-01 08:00:00', 'title' => 'Invoice question', 'message' => 'We noticed a payment problem.', 'status' => 'Archived', 'urgency' => 'Medium', 'admin' => 'Sam Support', 'lastreply' => '2026-03-02 08:00:00']);
    // 8: merged into 5 (its message moved by WHMCS); 9: of a client that was not imported; 12: merged into 9.
    $db->insert('tbltickets', ['id' => 8, 'tid' => '8', 'did' => 1, 'userid' => 1, 'date' => '2026-01-10 09:30:00', 'title' => 'Site down again', 'message' => '', 'status' => 'Closed', 'urgency' => 'Medium', 'lastreply' => '2026-01-10 09:30:00', 'merged_ticket_id' => 5]);
    $db->insert('tbltickets', ['id' => 9, 'tid' => '9', 'did' => 1, 'userid' => 99, 'date' => '2026-01-01', 'title' => 'Lost', 'message' => 'x', 'status' => 'Closed', 'urgency' => 'Medium', 'lastreply' => '2026-01-01']);
    $db->insert('tbltickets', ['id' => 12, 'tid' => '12', 'did' => 1, 'userid' => 99, 'date' => '2026-01-01', 'title' => 'Lost too', 'message' => 'x', 'status' => 'Closed', 'urgency' => 'Medium', 'lastreply' => '2026-01-01', 'merged_ticket_id' => 9]);
    // 15: merged into 5 by an older WHMCS that left its message on it.
    $db->insert('tbltickets', ['id' => 15, 'tid' => '15', 'did' => 1, 'userid' => 1, 'date' => '2026-01-10 09:40:00', 'title' => 'Third time', 'message' => 'Still down', 'status' => 'Closed', 'urgency' => 'Medium', 'lastreply' => '2026-01-10 09:40:00', 'merged_ticket_id' => 5]);

    // Replies on 5: Sam (rated 4, a file WHMCS removed), the authorized user 40, piped mail from
    // another address, a former admin; on 6: the guest with an HTML file named .png.
    $db->insert('tblticketreplies', ['id' => 1, 'tid' => 5, 'date' => '2026-01-10 10:00:00', 'message' => "Looking.\nOne moment.", 'admin' => 'Sam Support', 'rating' => 4, 'attachment' => '444444_old.pdf', 'attachments_removed' => 1]);
    $db->insert('tblticketreplies', ['id' => 2, 'tid' => 5, 'userid' => 1, 'requestor_id' => 40, 'date' => '2026-01-10 11:00:00', 'message' => 'Thanks from Uma']);
    $db->insert('tblticketreplies', ['id' => 3, 'tid' => 5, 'userid' => 0, 'name' => 'Stranger', 'email' => 'stranger@else.test', 'date' => '2026-01-10 12:00:00', 'message' => 'Me too']);
    $db->insert('tblticketreplies', ['id' => 4, 'tid' => 5, 'date' => '2026-01-11 10:00:00', 'message' => 'Fixed.', 'admin' => 'Fred Former']);
    $db->insert('tblticketreplies', ['id' => 5, 'tid' => 6, 'date' => '2026-02-01 09:00:00', 'message' => 'Hello?', 'attachment' => '555555_page.png']);
    $db->insert('tblticketreplies', ['id' => 6, 'tid' => 9, 'date' => '2026-01-01', 'message' => 'lost reply']);
    $db->insert('tblticketreplies', ['id' => 7, 'tid' => 5, 'userid' => 1, 'date' => '2026-01-11 11:00:00', 'message' => 'Here it is', 'attachment' => '666666_page.png']);
    $db->insert('tblticketnotes', ['id' => 1, 'ticketid' => 5, 'admin' => 'Sam Support', 'date' => '2026-01-10 10:05:00', 'message' => 'Checked the server.', 'attachments' => 'note.png']);
    $db->insert('tblticketfeedback', ['id' => 1, 'ticketid' => 5, 'adminid' => 2, 'rating' => 9, 'comments' => 'Quick', 'datetime' => '2026-01-12 09:00:00', 'ip' => '203.0.113.5']);
    $db->insert('tblticketfeedback', ['id' => 2, 'ticketid' => 5, 'adminid' => 0, 'rating' => 0, 'comments' => 'Great service', 'datetime' => '2026-01-12 09:00:00']);

    // Knowledge base: 1 Hosting with Email under it, 3 hidden, 4 a translation.
    $db->executeStatement("INSERT INTO tblknowledgebasecats (id, parentid, name, description, hidden, catid, language) VALUES (1, 0, 'Hosting', 'About hosting', '', 0, ''), (2, 1, 'Email', '', '', 0, ''), (3, 0, 'Internal', '', 'on', 0, ''), (4, 0, 'Hébergement', '', '', 1, 'french')");
    $db->insert('tblknowledgebase', ['id' => 1, 'title' => 'Set up mail', 'article' => '<h2>Steps</h2><p>Open <strong>Mail</strong> &amp; add an <a href="https://host.test/mail">account</a>.</p><ul><li>One</li><li>Two</li></ul><script>alert(1)</script>', 'views' => 12]);
    $db->insert('tblknowledgebase', ['id' => 2, 'title' => 'Staff only', 'article' => 'Plain text', 'views' => 0]);
    $db->insert('tblknowledgebase', ['id' => 3, 'title' => 'Configurer', 'article' => 'x', 'parentid' => 1, 'language' => 'french']);
    $db->insert('tblknowledgebase', ['id' => 4, 'title' => 'Set up mail', 'article' => 'Members only', 'private' => 'on']);
    $db->executeStatement('INSERT INTO tblknowledgebaselinks VALUES (1, 2, 1), (2, 3, 2), (3, 1, 4), (4, 2, 4)');
    $db->insert('tblannouncements', ['id' => 1, 'date' => '2026-04-01 12:00:00', 'title' => 'New data centre', 'announcement' => '<p>We opened a <em>new</em> data centre.</p>', 'published' => 1, 'created_at' => '2026-04-01 11:00:00']);
    $db->insert('tblannouncements', ['id' => 2, 'date' => '2026-05-01 12:00:00', 'title' => 'Draft news', 'announcement' => 'Soon', 'published' => 0]);
    $db->insert('tblnetworkissues', ['id' => 1, 'title' => 'Server reboot', 'description' => 'Kernel update', 'type' => 'Server', 'affecting' => 'web1', 'server' => 3, 'priority' => 'High', 'startdate' => '2026-06-01 01:00:00', 'enddate' => '2026-06-01 02:00:00', 'status' => 'In Progress', 'lastupdate' => '2026-06-01 01:30:00']);
    $db->insert('tblemails', ['id' => 1, 'userid' => 1, 'subject' => 'Welcome', 'message' => '<p onclick="steal()">Hi <b>Avery</b></p><script>steal()</script>', 'date' => '2026-01-01 10:00:00', '"to"' => 'avery@client.test', 'cc' => 'copy@client.test', 'attachments' => '["Invoice-10.pdf"]']);
    $db->insert('tblemails', ['id' => 2, 'userid' => 1, 'subject' => 'Failed', 'message' => 'x', 'date' => '2026-01-02', '"to"' => 'avery@client.test', 'failed' => 1]);
    $db->insert('tblemails', ['id' => 3, 'userid' => 99, 'subject' => 'Lost', 'message' => 'x', 'date' => '2026-01-02', '"to"' => 'x@lost.test']);

    return $db;
}

function whmcsSupportTarget(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE ' . Map::TABLE . ' (source TEXT, entity TEXT, source_id TEXT, target_id INTEGER, created_at TEXT, PRIMARY KEY (source, entity, source_id))',
        'CREATE TABLE client (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT)',
        'CREATE TABLE client_order (id INTEGER PRIMARY KEY, client_id INTEGER)',
        'CREATE TABLE client_user (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT)',
        'CREATE TABLE client_user_account (id INTEGER PRIMARY KEY, user_id INTEGER, client_id INTEGER)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY AUTOINCREMENT, system_name TEXT, email TEXT UNIQUE, pass TEXT, salt TEXT, name TEXT, signature TEXT, status TEXT, api_token TEXT, timezone TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE admin_group (id INTEGER PRIMARY KEY, name TEXT, system_name TEXT)',
        'CREATE TABLE admin_group_member (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER, admin_group_id INTEGER, created_at TEXT)',
        'CREATE TABLE support_helpdesk (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, close_after INTEGER, can_reopen INTEGER, signature TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE support_helpdesk_feedback (helpdesk_id INTEGER PRIMARY KEY, feedback_request INTEGER NOT NULL DEFAULT 1, updated_at TEXT NOT NULL)',
        'CREATE TABLE support_helpdesk_hidden (helpdesk_id INTEGER PRIMARY KEY, hidden_at TEXT NOT NULL)',
        "CREATE TABLE support_helpdesk_mail (helpdesk_id INTEGER PRIMARY KEY, enabled INTEGER, host TEXT, port INTEGER, encryption TEXT, verify_tls INTEGER, username TEXT, password TEXT, folder TEXT, clients_only INTEGER, replies_only INTEGER, max_per_run INTEGER, last_run_at TEXT, last_ok INTEGER, last_result TEXT, processing_uid INTEGER, processing_tries INTEGER DEFAULT 0, updated_at TEXT)",
        'CREATE TABLE support_pr_category (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE support_pr (id INTEGER PRIMARY KEY AUTOINCREMENT, support_pr_category_id INTEGER, title TEXT, content TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE support_ticket (id INTEGER PRIMARY KEY AUTOINCREMENT, support_helpdesk_id INTEGER, client_id INTEGER, access_hash TEXT, author_name TEXT, author_email TEXT, priority INTEGER, subject TEXT, status TEXT, rel_type TEXT, rel_id INTEGER, rel_task TEXT, rel_new_value TEXT, rel_status TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE support_ticket_message (id INTEGER PRIMARY KEY AUTOINCREMENT, support_ticket_id INTEGER, client_id INTEGER, admin_id INTEGER, content TEXT, attachment TEXT, ip TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE support_ticket_note (id INTEGER PRIMARY KEY AUTOINCREMENT, support_ticket_id INTEGER, admin_id INTEGER, note TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE support_ticket_file (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE, ticket_id INTEGER, message_id INTEGER, client_id INTEGER NOT NULL, uploader_kind TEXT, uploader_id INTEGER, session_hash TEXT, name TEXT, mime TEXT, size INTEGER, created_at TEXT, attached_at TEXT, removed_at TEXT, removed_by INTEGER, removed_reason TEXT)',
        'CREATE TABLE support_ticket_author (message_id INTEGER PRIMARY KEY, ticket_id INTEGER, client_id INTEGER, user_id INTEGER, name TEXT, created_at TEXT)',
        'CREATE TABLE support_ticket_assignment (ticket_id INTEGER PRIMARY KEY, admin_id INTEGER, assigned_by INTEGER, assigned_at TEXT)',
        'CREATE TABLE support_ticket_merge (ticket_id INTEGER PRIMARY KEY, merged_into INTEGER, access_hash TEXT, subject TEXT, merged_by INTEGER, merged_at TEXT)',
        'CREATE TABLE support_ticket_message_rating (message_id INTEGER PRIMARY KEY, ticket_id INTEGER, admin_id INTEGER, rating INTEGER, rated_by_kind TEXT, rated_by_id INTEGER, ip TEXT, rated_at TEXT)',
        'CREATE TABLE support_ticket_message_unverified (message_id INTEGER PRIMARY KEY, ticket_id INTEGER, sender TEXT, reason TEXT, created_at TEXT)',
        'CREATE TABLE support_ticket_feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER, admin_id INTEGER, rating INTEGER, comments TEXT, ip TEXT, created_at TEXT)',
        'CREATE TABLE support_ticket_feedback_request (ticket_id INTEGER PRIMARY KEY, token TEXT UNIQUE, sent_at TEXT, answered_at TEXT)',
        'CREATE TABLE support_kb_article_category (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, description TEXT, slug TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE support_kb_category_hidden (category_id INTEGER PRIMARY KEY, hidden_at TEXT NOT NULL)',
        'CREATE TABLE support_kb_article (id INTEGER PRIMARY KEY AUTOINCREMENT, kb_article_category_id INTEGER, views INTEGER, title TEXT, content TEXT, slug TEXT, status TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE post (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER, title TEXT, description TEXT, content TEXT, slug TEXT, status TEXT, image TEXT, section TEXT, publish_at TEXT, published_at TEXT, expires_at TEXT, created_at TEXT, updated_at TEXT)',
        "CREATE TABLE support_network_issue (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, description TEXT, type TEXT, affecting TEXT, server_id INTEGER, priority TEXT, status TEXT, start_at TEXT, end_at TEXT, emailed_at TEXT, emailed_count INTEGER, created_by INTEGER, created_at TEXT, updated_at TEXT)",
        'CREATE TABLE activity_client_email (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, sender TEXT, recipients TEXT, subject TEXT, content_html TEXT, content_text TEXT, attachment_name TEXT, attachment_content BLOB, attachment_mime TEXT, created_at TEXT, updated_at TEXT)',
    ] as $sql) {
        $db->executeStatement($sql);
    }
    // A fresh install: its staff member, staff groups, help desks 1 (General) and 9 (Sales), a sample KB category.
    $db->insert('admin', ['email' => 'owner@host.test', 'pass' => 'x', 'name' => 'Owner', 'status' => 'active']);
    $db->executeStatement("INSERT INTO admin_group VALUES (1, 'Super Administrator', 'super_admin'), (2, 'Support Lead', 'support_lead'), (3, 'Support Staff', 'support_staff')");
    $db->insert('support_helpdesk', ['id' => 1, 'name' => 'General', 'email' => 'info@yourcompany.com', 'close_after' => 24, 'can_reopen' => 0, 'signature' => 'Bye']);
    $db->insert('support_helpdesk', ['id' => 9, 'name' => 'Sales', 'close_after' => 24, 'can_reopen' => 0]);
    $db->insert('support_kb_article_category', ['title' => 'Hosting', 'slug' => 'hosting']);
    // As after the client and service steps: clients 1 and 2, authorized user 40 of client 1 (user 7 here), service 7 (order 70), server 3 (11).
    $db->insert('client', ['id' => 1, 'email' => 'avery@client.test', 'first_name' => 'Avery', 'last_name' => 'Stone']);
    $db->insert('client', ['id' => 2, 'email' => 'omar@client.test', 'first_name' => 'Omar', 'last_name' => 'Said']);
    $db->insert('client_order', ['id' => 70, 'client_id' => 1]);
    $db->insert('client_user', ['id' => 7, 'email' => 'uma@client.test', 'first_name' => 'Uma', 'last_name' => 'User']);
    $db->insert('client_user_account', ['user_id' => 7, 'client_id' => 1]);
    $map = new Map($db);
    $map->put('client', 1, 1);
    $map->put('client', 2, 2);
    $map->put('user', 40, 7);
    $map->put('service', 7, 70);
    $map->put('server', 3, 11);

    return $db;
}

/** A temporary folder, removed at the end of the test run. */
function whmcsSupportFolder(string $name): string
{
    $dir = sys_get_temp_dir() . '/cf-whmcs-support-' . bin2hex(random_bytes(4)) . '-' . $name;
    mkdir($dir, 0700, true);
    register_shutdown_function(static function () use ($dir): void {
        exec('rm -rf ' . escapeshellarg($dir));
    });

    return $dir;
}

/**
 * Runs the support steps to the end in batches of $batch.
 *
 * @param array<string, array<string, mixed>> $config the extension settings it merged
 */
function whmcsSupportAll(Connection $source, Connection $target, string $attachments, string $files, array &$config = [], int $batch = 2, int $keepDays = 0): Support
{
    $support = new Support(
        new Source($source),
        $target,
        new Map($target),
        Cipher::withKey(WHMCS_SUPPORT_KEY),
        static fn (string $text): string => 'enc:' . $text,
        static function (string $ext, array $changes) use (&$config): void {
            $config[$ext] = ($config[$ext] ?? []) + $changes;
        },
        $attachments,
        $files,
        $keepDays,
        '2026-10-19 00:00:00',
    );
    foreach (array_keys(Support::STEPS) as $step) {
        $after = 0;
        do {
            [$after] = $support->batch($step, (int) $after, $batch);
        } while ($after !== null);
    }

    return $support;
}

/** The WHMCS attachments folder of the fixture: a PNG, an HTML page named .png, the guest's text. */
function whmcsSupportAttachments(): string
{
    $dir = whmcsSupportFolder('attachments');
    file_put_contents($dir . '/111111_screen shot.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    file_put_contents($dir . '/555555_page.png', 'guest text');
    file_put_contents($dir . '/666666_page.png', '<html><body><script>alert(1)</script></body></html>');
    file_put_contents($dir . '/333333_guest.txt', 'guest file');

    return $dir;
}

test('staff come over switched off, matched by email, by role, and replies keep former staff', function (): void {
    $target = whmcsSupportTarget();
    $support = whmcsSupportAll(whmcsSupportSource(), $target, '', whmcsSupportFolder('files'));
    $map = new Map($target);

    expect($map->get('staff', 1))->toBe(1)
        ->and($target->fetchOne('SELECT status FROM admin WHERE id = 1'))->toBe('active');
    $sam = $target->fetchAssociative('SELECT * FROM admin WHERE id = ?', [$map->get('staff', 2)]);
    expect($sam)->toMatchArray(['email' => 'sam@host.test', 'name' => 'Sam Support', 'status' => 'inactive', 'signature' => 'Sam from support', 'created_at' => '2025-01-02 03:04:05'])
        ->and($sam['pass'])->toBe('$2y$10$abcdefghijklmnopqrstuuS6Qe2cS6x1kQnqtkpHgV5Q5V7r6m9n2')
        ->and((int) $target->fetchOne('SELECT admin_group_id FROM admin_group_member WHERE admin_id = ?', [$sam['id']]))->toBe(3);
    $sally = $target->fetchAssociative('SELECT * FROM admin WHERE id = ?', [$map->get('staff', 3)]);
    expect($sally['status'])->toBe('inactive')
        ->and(password_get_info($sally['pass'])['algo'])->not->toBeNull();
    $fred = $target->fetchAssociative("SELECT * FROM admin WHERE name = 'Fred Former'");
    expect($fred['status'])->toBe('inactive')
        ->and($fred['email'])->toEndWith('@staff.invalid')
        ->and((int) $target->fetchOne('SELECT admin_id FROM support_ticket_message WHERE content = ?', ['Fixed.']))->toBe((int) $fred['id']);

    $warnings = implode("\n", $support->warnings());
    expect($warnings)->toContain('Staff Sally Sales: the WHMCS role was not Support Operator')
        ->toContain('Staff Sally Sales: the WHMCS password could not be carried over')
        ->toContain('Staff Sam Support used two-factor sign-in')
        ->toContain('Replies signed "Fred Former" match no WHMCS admin');
});

test('departments become help desks with their ids, hidden ones hidden, mailboxes switched off', function (): void {
    $target = whmcsSupportTarget();
    $config = [];
    $support = whmcsSupportAll(whmcsSupportSource(), $target, '', whmcsSupportFolder('files'), $config);

    expect($target->fetchAssociative('SELECT name, email, close_after, can_reopen, signature FROM support_helpdesk WHERE id = 1'))
        ->toBe(['name' => 'Support', 'email' => 'support@host.test', 'close_after' => 72, 'can_reopen' => 1, 'signature' => null])
        ->and($target->fetchFirstColumn('SELECT helpdesk_id FROM support_helpdesk_hidden'))->toBe([2])
        ->and($target->fetchAllKeyValue('SELECT helpdesk_id, feedback_request FROM support_helpdesk_feedback ORDER BY helpdesk_id'))->toBe([1 => 1, 2 => 0, 3 => 1]);
    $mail = $target->fetchAssociative('SELECT * FROM support_helpdesk_mail WHERE helpdesk_id = 2');
    expect($mail)->toMatchArray(['enabled' => 0, 'host' => 'imap.host.test', 'port' => 993, 'encryption' => 'ssl', 'username' => 'abuse@host.test', 'password' => 'enc:mailbox-secret', 'clients_only' => 1, 'replies_only' => 1])
        ->and($target->fetchOne('SELECT password FROM support_helpdesk_mail WHERE helpdesk_id = 3'))->toBe('')
        ->and($config['mod_support'])->toBe(['ticket_rating' => '1']);

    $warnings = implode("\n", $support->warnings());
    expect($warnings)->toContain('Help desk "Sales" of this install has no WHMCS department')
        ->toContain('Help desk "Billing": WHMCS signed in to its mailbox with OAuth')
        ->toContain('Help desk "Billing": WHMCS read its mailbox over POP3 (port 995)')
        ->toContain('Help desk "Abuse": its mailbox came over switched off')
        ->toContain('WHMCS has 1 spam filters; they were not imported')
        ->not->toContain('mailbox-secret');
});

test('tickets keep their ids, authors, relations and statuses; merged ones point to their ticket', function (): void {
    $target = whmcsSupportTarget();
    $support = whmcsSupportAll(whmcsSupportSource(), $target, '', whmcsSupportFolder('files'));
    $map = new Map($target);
    $sam = $map->get('staff', 2);

    expect($target->fetchAssociative('SELECT support_helpdesk_id, client_id, access_hash, author_name, author_email, priority, subject, status, rel_type, rel_id, rel_status, created_at, updated_at FROM support_ticket WHERE id = 5'))
        ->toBe(['support_helpdesk_id' => 1, 'client_id' => 1, 'access_hash' => null, 'author_name' => 'Avery Stone', 'author_email' => 'avery@client.test', 'priority' => 150, 'subject' => 'Site down', 'status' => 'on_hold', 'rel_type' => 'order', 'rel_id' => 70, 'rel_status' => 'complete', 'created_at' => '2026-01-10 09:00:00', 'updated_at' => '2026-01-11 10:00:00'])
        ->and((int) $target->fetchOne('SELECT admin_id FROM support_ticket_assignment WHERE ticket_id = 5'))->toBe($sam);
    $guest = $target->fetchAssociative('SELECT * FROM support_ticket WHERE id = 6');
    expect($guest)->toMatchArray(['client_id' => null, 'author_name' => 'Gina Guest', 'author_email' => 'gina@guest.test', 'priority' => 50, 'status' => 'on_hold', 'support_helpdesk_id' => 2])
        ->and(strlen((string) $guest['access_hash']))->toBe(48);
    // Opened by staff for client 2: the first message is Sam's; a custom status WHMCS does not count as active is closed.
    expect($target->fetchAssociative('SELECT client_id, admin_id FROM support_ticket_message WHERE support_ticket_id = 7'))->toBe(['client_id' => null, 'admin_id' => $sam])
        ->and($target->fetchOne('SELECT status FROM support_ticket WHERE id = 7'))->toBe('closed');

    // 8 and 15 open ticket 5; 15's leftover message is on 5; 9 and 12 are out with their reply.
    expect($target->fetchAllKeyValue('SELECT ticket_id, merged_into FROM support_ticket_merge ORDER BY ticket_id'))->toBe([8 => 5, 15 => 5])
        ->and($map->get('ticket', 8))->toBe(5)
        ->and($target->fetchOne("SELECT support_ticket_id FROM support_ticket_message WHERE content = 'Still down'"))->toBe(5)
        ->and($target->fetchOne('SELECT COUNT(*) FROM support_ticket WHERE id IN (8, 9, 12, 15)'))->toBe(0)
        ->and($target->fetchOne("SELECT COUNT(*) FROM support_ticket_message WHERE content = 'lost reply'"))->toBe(0);
    // The next new ticket never takes a WHMCS ticket's id, merged 15 included.
    $target->insert('support_ticket', ['subject' => 'New', 'status' => 'open']);
    expect((int) $target->lastInsertId())->toBe(16);

    $warnings = implode("\n", $support->warnings());
    expect($warnings)->toContain('Ticket #9 was not imported: its client was not imported.')
        ->toContain('Ticket #12 was merged into ticket #9, which was not imported')
        ->toContain('Ticket #6 was numbered ABC-123456 in WHMCS; it is #6 now.')
        ->toContain('Ticket #6 had CC recipients');
});

test('replies keep their author, rating and line breaks; notes and feedback come over', function (): void {
    $target = whmcsSupportTarget();
    $support = whmcsSupportAll(whmcsSupportSource(), $target, '', whmcsSupportFolder('files'));
    $map = new Map($target);
    $sam = $map->get('staff', 2);

    $first = $target->fetchAssociative('SELECT * FROM support_ticket_message WHERE support_ticket_id = 5 ORDER BY created_at LIMIT 1');
    expect($first['content'])->toBe("My site is down.  \nPlease help.")
        ->and($first['client_id'])->toBe(1);
    $samReply = $map->get('reply', 1);
    expect($target->fetchAssociative('SELECT admin_id, client_id, content FROM support_ticket_message WHERE id = ?', [$samReply]))->toBe(['admin_id' => $sam, 'client_id' => null, 'content' => "Looking.  \nOne moment."])
        ->and($target->fetchAssociative('SELECT admin_id, rating, rated_by_kind, rated_by_id FROM support_ticket_message_rating WHERE message_id = ?', [$samReply]))->toBe(['admin_id' => $sam, 'rating' => 4, 'rated_by_kind' => 'client', 'rated_by_id' => 1])
        ->and($target->fetchAssociative('SELECT user_id, name FROM support_ticket_author WHERE message_id = ?', [$map->get('reply', 2)]))->toBe(['user_id' => 7, 'name' => 'Uma User'])
        ->and($target->fetchOne('SELECT sender FROM support_ticket_message_unverified WHERE message_id = ?', [$map->get('reply', 3)]))->toBe('Stranger <stranger@else.test>')
        ->and($target->fetchAssociative('SELECT admin_id, note FROM support_ticket_note WHERE support_ticket_id = 5'))->toBe(['admin_id' => $sam, 'note' => 'Checked the server.']);

    expect($target->fetchAllAssociative('SELECT admin_id, rating, comments, ip FROM support_ticket_feedback ORDER BY id'))->toBe([
        ['admin_id' => $sam, 'rating' => 9, 'comments' => 'Quick', 'ip' => '203.0.113.5'],
        ['admin_id' => null, 'rating' => null, 'comments' => 'Great service', 'ip' => ''],
    ])->and($target->fetchOne('SELECT answered_at FROM support_ticket_feedback_request WHERE ticket_id = 5'))->toBe('2026-01-12 09:00:00')
        ->and(implode("\n", $support->warnings()))->toContain('Ticket #5: a staff note had attachments in WHMCS');
});

test('attachments are copied from the WHMCS folder; removed, missing and unsafe ones are not', function (): void {
    $target = whmcsSupportTarget();
    $files = whmcsSupportFolder('files');
    $config = [];
    $support = whmcsSupportAll(whmcsSupportSource(), $target, whmcsSupportAttachments(), $files, $config, 2, 180);

    $rows = $target->fetchAllAssociative('SELECT * FROM support_ticket_file ORDER BY id');
    $byName = array_column($rows, null, 'name');
    expect(array_keys($byName))->toBe(['screen shot.png', 'missing.log', 'old.pdf'])
        ->and($byName['screen shot.png'])->toMatchArray(['ticket_id' => 5, 'client_id' => 1, 'mime' => 'image/png', 'uploader_kind' => 'whmcs', 'removed_at' => null, 'created_at' => '2026-01-10 09:00:00'])
        ->and(file_get_contents($files . '/1/' . $byName['screen shot.png']['token']))->toStartWith("\x89PNG")
        ->and(decoct(fileperms($files . '/1/' . $byName['screen shot.png']['token']) & 0777))->toBe('600')
        ->and($byName['missing.log'])->toMatchArray(['removed_reason' => 'expired', 'size' => 0])
        ->and($byName['old.pdf'])->toMatchArray(['removed_reason' => 'expired', 'uploader_kind' => 'whmcsstaff', 'message_id' => (new Map($target))->get('reply', 1)]);

    // What WHMCS still has: 5 names (old.pdf was removed in WHMCS).
    expect($support->attachmentTotal())->toBe(5);

    $warnings = implode("\n", $support->warnings());
    expect($warnings)->toContain('Ticket #5: attachment missing.log was not found in the WHMCS attachments folder')
        ->not->toContain('old.pdf')
        ->toContain('Ticket #5: attachment page.png (text/html) is a type ClientFence does not keep')
        ->toContain("Ticket #6 (a guest's) has 1 attachment(s)")
        ->toContain('1 imported ticket files are older than "Keep ticket files" (180 days)');
});

test('a batch that rolls back takes its copied files with it, and a second run copies nothing again', function (): void {
    $source = whmcsSupportSource();
    $target = whmcsSupportTarget();
    $files = whmcsSupportFolder('files');
    $attachments = whmcsSupportAttachments();
    $support = whmcsSupportAll($source, $target, $attachments, $files);
    $support->discard();
    expect(glob($files . '/1/*'))->toBe([]);

    $target2 = whmcsSupportTarget();
    $files2 = whmcsSupportFolder('files2');
    whmcsSupportAll($source, $target2, $attachments, $files2);
    $count = (int) $target2->fetchOne('SELECT COUNT(*) FROM support_ticket_message');
    $again = whmcsSupportAll($source, $target2, $attachments, $files2);
    expect((int) $target2->fetchOne('SELECT COUNT(*) FROM support_ticket_message'))->toBe($count)
        ->and(glob($files2 . '/1/*'))->toHaveCount(1)
        ->and($again->warnings())->toBe([]);
});

test('predefined replies, the knowledge base, announcements, network issues and the email log', function (): void {
    $target = whmcsSupportTarget();
    $support = whmcsSupportAll(whmcsSupportSource(), $target, '', whmcsSupportFolder('files'));
    $map = new Map($target);

    expect($target->fetchAllAssociative('SELECT c.title AS category, r.title, r.content FROM support_pr r JOIN support_pr_category c ON c.id = r.support_pr_category_id ORDER BY r.id'))->toBe([
        ['category' => 'Sales / Upgrades', 'title' => 'Upgrade done', 'content' => "Hi,  \nYour upgrade is done."],
        ['category' => 'WHMCS', 'title' => 'Thanks', 'content' => 'Thank you!'],
    ]);

    expect($target->fetchAllKeyValue('SELECT title, slug FROM support_kb_article_category ORDER BY id'))->toBe(['Hosting' => 'hosting-2', 'Hosting / Email' => 'hosting-email', 'Internal' => 'internal'])
        ->and($map->has('kbcategory', 4))->toBeFalse();
    $article = $target->fetchAssociative('SELECT * FROM support_kb_article WHERE id = ?', [$map->get('kbarticle', 1)]);
    expect($article)->toMatchArray(['kb_article_category_id' => $map->get('kbcategory', 2), 'views' => 12, 'title' => 'Set up mail', 'slug' => 'set-up-mail', 'status' => 'active'])
        ->and($article['content'])->toBe("## Steps\n\nOpen **Mail** & add an [account](https://host.test/mail).\n\n- One\n- Two");
    expect($target->fetchOne('SELECT status FROM support_kb_article WHERE id = ?', [$map->get('kbarticle', 2)]))->toBe('active')
        ->and($target->fetchFirstColumn('SELECT category_id FROM support_kb_category_hidden'))->toBe([$map->get('kbcategory', 3)])
        ->and($target->fetchAssociative('SELECT slug, status, kb_article_category_id FROM support_kb_article WHERE id = ?', [$map->get('kbarticle', 4)]))->toBe(['slug' => 'set-up-mail-2', 'status' => 'draft', 'kb_article_category_id' => $map->get('kbcategory', 1)])
        ->and($map->has('kbarticle', 3))->toBeFalse();

    expect($target->fetchAssociative('SELECT title, description, content, slug, status, publish_at, published_at, created_at FROM post WHERE id = ?', [$map->get('announcement', 1)]))->toBe([
        'title' => 'New data centre', 'description' => 'We opened a new data centre.', 'content' => 'We opened a *new* data centre.', 'slug' => 'new-data-centre',
        'status' => 'active', 'publish_at' => '2026-04-01 12:00:00', 'published_at' => '2026-04-01 12:00:00', 'created_at' => '2026-04-01 11:00:00',
    ])->and($target->fetchAssociative('SELECT status, published_at FROM post WHERE id = ?', [$map->get('announcement', 2)]))->toBe(['status' => 'draft', 'published_at' => null]);

    expect($target->fetchAssociative('SELECT title, type, affecting, server_id, priority, status, start_at, end_at FROM support_network_issue'))->toBe([
        'title' => 'Server reboot', 'type' => 'server', 'affecting' => 'web1', 'server_id' => 11, 'priority' => 'high', 'status' => 'in_progress', 'start_at' => '2026-06-01 01:00:00', 'end_at' => '2026-06-01 02:00:00',
    ]);

    $emails = $target->fetchAllAssociative('SELECT * FROM activity_client_email');
    expect($emails)->toHaveCount(1)
        ->and($emails[0])->toMatchArray(['client_id' => 1, 'recipients' => 'avery@client.test, copy@client.test', 'subject' => 'Welcome', 'content_text' => 'Hi Avery', 'created_at' => '2026-01-01 10:00:00'])
        ->and($emails[0]['content_html'])->toContain('<b>Avery</b>')->not->toContain('script')->not->toContain('onclick');

    $warnings = implode("\n", $support->warnings());
    expect($warnings)->not->toContain('Staff only')
        ->toContain('Knowledge base article "Set up mail" was for signed-in clients only in WHMCS')
        ->toContain('Knowledge base article "Set up mail" was in 2 WHMCS categories; it is in the first.')
        ->toContain('Knowledge base category "Hébergement" is a WHMCS translation')
        ->toContain('Emails WHMCS had not sent (pending or failed) were left out')
        ->toContain('WHMCS keeps only the names of email attachments');
});

test('converted ticket replies, notes and predefined replies keep their final line beyond 64 KB', function (): void {
    $source = whmcsSupportSource();
    $long = str_repeat(str_repeat('é', 5) . "\n", 5500) . 'Final resolution.';
    expect(strlen($long))->toBeLessThan(65535)
        ->and(strlen(Text::breaks($long)))->toBeGreaterThan(70000);
    $source->update('tblticketreplies', ['message' => $long], ['id' => 1]);
    $source->insert('tblticketnotes', ['id' => 2, 'ticketid' => 5, 'admin' => 'Sam Support', 'date' => '2026-01-10 10:06:00', 'message' => $long]);
    $source->insert('tblticketpredefinedreplies', ['id' => 3, 'catid' => 0, 'name' => 'Log', 'reply' => $long]);
    $target = whmcsSupportTarget();
    whmcsSupportAll($source, $target, '', whmcsSupportFolder('files'));
    $map = new Map($target);

    foreach ([['support_ticket_message', 'content', 'reply', 1], ['support_ticket_note', 'note', 'ticketnote', 2], ['support_pr', 'content', 'predefined', 3]] as [$table, $column, $entity, $id]) {
        $text = (string) $target->fetchOne("SELECT {$column} FROM {$table} WHERE id = ?", [$map->get($entity, $id)]);
        expect($text)->toEndWith("\nFinal resolution.")
            ->and(strlen($text))->toBeGreaterThan(70000)
            ->and(mb_check_encoding($text, 'UTF-8'))->toBeTrue();
    }
});

test('WHMCS HTML becomes Markdown and message line breaks stay', function (): void {
    expect(Text::markdown('Just text *with* stars'))->toBe('Just text *with* stars')
        ->and(Text::markdown('<p>A&nbsp;<b>bold</b> <i>move</i><br>next line</p><pre>code *here*</pre><blockquote>quoted</blockquote>'))
        ->toBe("A **bold** *move*\\\nnext line\n\n```\ncode *here*\n```\n\n> quoted")
        ->and(Text::markdown('<ol><li>First<ul><li>inner</li></ul></li><li>Second</li></ol>'))->toBe("1. First\n     - inner\n2. Second")
        ->and(Text::markdown('<table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2|3</td></tr></table>'))->toBe("| A | B |\n| --- | --- |\n| 1 | 2\\|3 |")
        ->and(Text::markdown('<p>2 * 3 and <a href="javascript:alert(1)">bad</a> <img src="https://host.test/a.png" alt="pic"></p>'))->toBe('2 \\* 3 and bad ![pic](https://host.test/a.png)')
        ->and(Text::breaks("one\ntwo\n\nthree\n```\ncode\nmore\n```\nafter"))->toBe("one  \ntwo\n\nthree\n```\ncode\nmore\n```\nafter");
});
