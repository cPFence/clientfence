<?php

declare(strict_types=1);

/**
 * ClientFence: ticket email import (Support\MailImport) and the email reader (Support\Mail\MimeMessage):
 * parsing, quote cutting, ticket numbers, auto-replies, spam lists and the WHMCS-style rules of
 * decide() on an in-memory SQLite copy of the tables they read.
 */

use ClientFence\Mod\Support\MailImport;
use ClientFence\Mod\Support\Mail\ImapClient;
use ClientFence\Mod\Support\Mail\MailException;
use ClientFence\Mod\Support\Mail\MimeMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

function mailImportEmail(string $from, string $subject, string $body, array $headers = []): string
{
    $lines = ['From: ' . $from, 'To: tickets@clientfence.test', 'Subject: ' . $subject, 'Message-ID: <' . md5($from . $subject . $body) . '@test>'];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }

    return implode("\r\n", $lines) . "\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . $body;
}

function mailImportDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE client (id INTEGER PRIMARY KEY, email TEXT, status TEXT)',
        'CREATE TABLE client_user (id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT, status TEXT)',
        'CREATE TABLE client_user_account (id INTEGER PRIMARY KEY, user_id INTEGER, client_id INTEGER, owner INTEGER, permissions TEXT)',
        'CREATE TABLE support_helpdesk (id INTEGER PRIMARY KEY, name TEXT, email TEXT, can_reopen INTEGER)',
        'CREATE TABLE support_ticket (id INTEGER PRIMARY KEY, support_helpdesk_id INTEGER, client_id INTEGER, author_email TEXT, status TEXT)',
        'CREATE TABLE admin (id INTEGER PRIMARY KEY, email TEXT)',
        'CREATE TABLE ' . MailImport::MAILBOX_TABLE . " (helpdesk_id INTEGER PRIMARY KEY, enabled INTEGER, host TEXT, port INTEGER, encryption TEXT, verify_tls INTEGER, username TEXT, password TEXT, folder TEXT, clients_only INTEGER DEFAULT 0, replies_only INTEGER DEFAULT 0, max_per_run INTEGER, last_run_at TEXT, last_ok INTEGER, last_result TEXT, processing_uid INTEGER, processing_tries INTEGER DEFAULT 0, updated_at TEXT)",
        'CREATE TABLE ' . MailImport::LOG_TABLE . " (id INTEGER PRIMARY KEY AUTOINCREMENT, helpdesk_id INTEGER, created_at TEXT, from_email TEXT, from_name TEXT, to_email TEXT, subject TEXT, message_id TEXT, status TEXT, note TEXT, ticket_id INTEGER, raw BLOB, imported_by INTEGER, imported_at TEXT)",
    ] as $sql) {
        $db->executeStatement($sql);
    }
    $db->insert('client', ['id' => 5, 'email' => 'alice@client.test', 'status' => 'active']);
    $db->insert('client', ['id' => 6, 'email' => 'bob@client.test', 'status' => 'active']);
    $db->insert('client_user', ['id' => 1, 'email' => 'dev@agency.test', 'first_name' => 'Dana', 'last_name' => 'Dev', 'status' => 'active']);
    $db->insert('client_user_account', ['user_id' => 1, 'client_id' => 5, 'owner' => 0, 'permissions' => 'invoices,tickets']);
    $db->insert('client_user', ['id' => 2, 'email' => 'billing@agency.test', 'first_name' => 'Bill', 'last_name' => 'Ing', 'status' => 'active']);
    $db->insert('client_user_account', ['user_id' => 2, 'client_id' => 5, 'owner' => 0, 'permissions' => 'invoices']);
    $db->insert('support_helpdesk', ['id' => 1, 'name' => 'Support', 'email' => 'support@clientfence.test', 'can_reopen' => 1]);
    $db->insert('support_helpdesk', ['id' => 2, 'name' => 'Sales', 'email' => 'sales@clientfence.test', 'can_reopen' => 0]);
    $db->insert('support_ticket', ['id' => 12, 'support_helpdesk_id' => 1, 'client_id' => 5, 'author_email' => null, 'status' => 'open']);
    $db->insert('support_ticket', ['id' => 13, 'support_helpdesk_id' => 1, 'client_id' => null, 'author_email' => 'guest@else.test', 'status' => 'closed']);
    $db->insert('support_ticket', ['id' => 14, 'support_helpdesk_id' => 2, 'client_id' => 5, 'author_email' => null, 'status' => 'closed']);
    $db->insert('admin', ['id' => 1, 'email' => 'staff@clientfence.test']);
    $db->insert(MailImport::MAILBOX_TABLE, ['helpdesk_id' => 1, 'enabled' => 1, 'username' => 'tickets@clientfence.test', 'folder' => 'INBOX']);

    return $db;
}

function mailImportContainer(Connection $db, array $config = [], bool $guestTickets = true): Pimple\Container
{
    $di = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['mod_config'] = $di->protect(fn (string $mod): array => $mod === 'support' ? $config : []);
    $support = Mockery::mock();
    $support->shouldReceive('guestTicketsEnabled')->andReturn($guestTickets);
    $system = Mockery::mock();
    $system->shouldReceive('getParamValue')->with('company_email')->andReturn('hello@clientfence.test');
    $di['mod_service'] = $di->protect(fn (string $mod): ?object => match ($mod) {
        'support' => $support,
        'system' => $system,
        default => null,
    });

    return $di;
}

function mailImportDecide(Connection $db, string $raw, int $helpdeskId = 1, array $config = [], bool $guestTickets = true, bool $anyway = false): array
{
    return MailImport::decide(mailImportContainer($db, $config, $guestTickets), MimeMessage::parse($raw), $helpdeskId, $anyway);
}

test('the reader decodes headers, parameters, parts and HTML', function (): void {
    $raw = "From: =?UTF-8?B?w4VsaWNl?= <Alice@Client.TEST>\r\nTo: \"Support, Team\" <support@clientfence.test>, other@x.test\r\n"
        . "Subject: =?ISO-8859-1?Q?Gr=F6=DFe?= [Ticket #12]\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
        . "--b1\r\nContent-Type: multipart/alternative; boundary=b2\r\n\r\n"
        . "--b2\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nH=E9llo\r\n"
        . "--b2\r\nContent-Type: text/html\r\n\r\n<p>ignored</p>\r\n--b2--\r\n"
        . "--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . base64_encode('%PDF-1.4') . "\r\n--b1--\r\n";
    $message = MimeMessage::parse($raw);

    expect($message->from)->toBe(['email' => 'alice@client.test', 'name' => 'Ålice'])
        ->and($message->to)->toBe([['email' => 'support@clientfence.test', 'name' => 'Support, Team'], ['email' => 'other@x.test', 'name' => '']])
        ->and($message->subject)->toBe('Größe [Ticket #12]')
        ->and($message->text)->toBe('Héllo')
        ->and($message->attachments)->toBe([['name' => 'résumé.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4']])
        ->and(MimeMessage::htmlToText('<style>p{}</style><div>One<br>Two</div><p>A &amp; B</p><script>x()</script>'))->toBe("One\nTwo\nA & B");

    // An HTML-only email gives its text; nesting is limited.
    $html = MimeMessage::parse("From: a@b.test\r\nContent-Type: text/html\r\n\r\n<p>Hi <b>there</b></p>");
    expect($html->text)->toBe('Hi there');
    $deep = "From: a@b.test\r\n";
    for ($i = 0; $i < 30; ++$i) {
        $deep .= "Content-Type: multipart/mixed; boundary=x{$i}\r\n\r\n--x{$i}\r\n";
    }
    expect(MimeMessage::parse($deep . "Content-Type: text/plain\r\n\r\ndeep")->text)->toBe('');
});

test('quoted earlier messages are cut', function (): void {
    $lines = MailImport::BREAK_LINES;

    expect(MailImport::stripQuoted("Thanks, fixed now.\n\n====== Please reply above this line ======\nTicket Replied", $lines))->toBe('Thanks, fixed now.')
        ->and(MailImport::stripQuoted("Yes please.\n\nOn Tue, 14 Oct 2026 at 10:00, Example Support <support@x.test>\nwrote:\n> old text", $lines))->toBe('Yes please.')
        ->and(MailImport::stripQuoted("Done\r\n\nFrom: Support <s@x.test>\nSent: Tuesday\nold", $lines))->toBe('Done')
        ->and(MailImport::stripQuoted("Answer\n> quoted\n>more\n", $lines))->toBe('Answer')
        ->and(MailImport::stripQuoted("Line one\n> not trailing\nLine three", $lines))->toBe("Line one\n> not trailing\nLine three")
        ->and(MailImport::breakLines(['mail_break_lines' => "\n  *****  \n"]))->toBe(['*****'])
        ->and(MailImport::breakLines(['mail_break_lines' => '']))->toBe(MailImport::BREAK_LINES);
});

test('a too long email is shortened to fit a ticket message', function (): void {
    $short = MailImport::shorten(str_repeat('ب', 1000));
    $long = MailImport::shorten(str_repeat('ب', 300000));

    expect($short)->toBe(str_repeat('ب', 1000))
        ->and(mb_strlen($long))->toBeLessThan(ClientFence\Mod\Support\SupportTicketValidator::MAX_CONTENT - 1900)
        ->and($long)->toEndWith('[The email was longer and has been shortened.]');
    expect(fn () => ClientFence\Mod\Support\SupportTicketValidator::validateContent(str_repeat('x', 100001)))
        ->toThrow(ClientFence\InformationException::class);
});

test('ticket numbers, auto-replies and spam lists are recognised', function (): void {
    expect(MailImport::ticketIdFromSubject('Re: [Company] [Ticket #12] Hosting down'))->toBe(12)
        ->and(MailImport::ticketIdFromSubject('RE: [Ticket ID: 345] old WHMCS'))->toBe(345)
        ->and(MailImport::ticketIdFromSubject('Ticket 12 please'))->toBeNull();

    $auto = static fn (array $headers): bool => MailImport::isAutoReply(MimeMessage::parse(mailImportEmail('a@b.test', 's', 'x', $headers)));
    expect($auto(['Auto-Submitted' => 'auto-replied']))->toBeTrue()
        ->and($auto(['Auto-Submitted' => 'no']))->toBeFalse()
        ->and($auto(['Precedence' => 'bulk']))->toBeTrue()
        ->and($auto(['X-Autoreply' => 'yes']))->toBeTrue()
        ->and($auto([]))->toBeFalse();

    $settings = MailImport::DEFAULTS + [];
    $settings['mail_block_senders'] = "spam@bad.test\n@junk.test\nevil.test";
    $settings['mail_block_subjects'] = 'Cheap pills';
    $settings['mail_block_phrases'] = 'crypto offer';
    $spam = static fn (string $from, string $subject = 'Hello', string $body = 'Hi') => MailImport::spamMatch($settings, MimeMessage::parse(mailImportEmail($from, $subject, $body)));
    expect($spam('spam@bad.test'))->not->toBeNull()
        ->and($spam('x@junk.test'))->not->toBeNull()
        ->and($spam('x@evil.test'))->not->toBeNull()
        ->and($spam('x@notevil.test'))->toBeNull()
        ->and($spam('ok@fine.test', 'CHEAP PILLS here'))->not->toBeNull()
        ->and($spam('ok@fine.test', 'Hello', 'A Crypto Offer for you'))->not->toBeNull()
        ->and($spam('ok@fine.test'))->toBeNull();
});

test('replies reach their ticket only from the people on it', function (): void {
    $db = mailImportDb();

    expect(mailImportDecide($db, mailImportEmail('Alice@client.test', 'Re: [Ticket #12] Down', 'ok')))
        ->toMatchArray(['status' => MailImport::REPLIED, 'ticket_id' => 12, 'client_id' => 5])
        ->not->toHaveKey('user');
    // An authorized user with ticket access replies as themself; one without it is blocked.
    expect(mailImportDecide($db, mailImportEmail('dev@agency.test', 'Re: [Ticket #12] Down', 'ok')))
        ->toMatchArray(['status' => MailImport::REPLIED, 'client_id' => 5, 'user' => ['user_id' => 1, 'email' => 'dev@agency.test', 'first_name' => 'Dana', 'last_name' => 'Dev', 'client_id' => 5, 'owner' => 0, 'permissions' => 'invoices,tickets']]);
    expect(mailImportDecide($db, mailImportEmail('billing@agency.test', 'Re: [Ticket #12] Down', 'ok'))['status'])->toBe(MailImport::BLOCKED_SENDER)
        ->and(mailImportDecide($db, mailImportEmail('bob@client.test', 'Re: [Ticket #12] Down', 'ok'))['status'])->toBe(MailImport::BLOCKED_SENDER);
    // Allow insecure imports: anyone, marked as not on the ticket.
    expect(mailImportDecide($db, mailImportEmail('bob@client.test', 'Re: [Ticket #12] Down', 'ok'), 1, ['mail_insecure_imports' => '1']))
        ->toMatchArray(['status' => MailImport::REPLIED, 'stranger' => true]);
    // A closed guest ticket reopens for its guest unless email reopening is prevented.
    expect(mailImportDecide($db, mailImportEmail('guest@else.test', 'Re: [Ticket #13] Q', 'more'))['status'])->toBe(MailImport::REPLIED)
        ->and(mailImportDecide($db, mailImportEmail('guest@else.test', 'Re: [Ticket #13] Q', 'more'), 1, ['mail_prevent_reopen' => '1'])['status'])->toBe(MailImport::REJECTED_CLOSED)
        // ... and always in a help desk where clients cannot reopen tickets.
        ->and(mailImportDecide($db, mailImportEmail('alice@client.test', 'Re: [Ticket #14] Q', 'more'))['status'])->toBe(MailImport::REJECTED_CLOSED);
    // An unknown ticket number is a new ticket.
    expect(mailImportDecide($db, mailImportEmail('alice@client.test', 'Re: [Ticket #999] Q', 'x')))->toMatchArray(['status' => MailImport::IMPORTED, 'ticket_id' => null, 'client_id' => 5]);
});

test('a reply to a merged ticket goes to the ticket it was merged into', function (): void {
    $db = mailImportDb();
    $db->executeStatement('CREATE TABLE ' . ClientFence\Mod\Support\TicketMerge::TABLE . ' (ticket_id INTEGER PRIMARY KEY, merged_into INTEGER, access_hash TEXT, subject TEXT, merged_by INTEGER, merged_at TEXT)');
    $db->insert(ClientFence\Mod\Support\TicketMerge::TABLE, ['ticket_id' => 11, 'merged_into' => 12, 'subject' => 'Old', 'merged_at' => '2026-10-16 10:00:00']);

    expect(mailImportDecide($db, mailImportEmail('alice@client.test', 'Re: [Ticket #11] Old', 'ok')))
        ->toMatchArray(['status' => MailImport::REPLIED, 'ticket_id' => 12, 'client_id' => 5]);
});

test('new tickets follow the help desk rules', function (): void {
    $db = mailImportDb();

    expect(mailImportDecide($db, mailImportEmail('alice@client.test', 'Help', 'x')))->toMatchArray(['status' => MailImport::IMPORTED, 'client_id' => 5, 'helpdesk_id' => 1])
        ->and(mailImportDecide($db, mailImportEmail('dev@agency.test', 'Help', 'x')))->toMatchArray(['status' => MailImport::IMPORTED, 'client_id' => 5])
        ->and(mailImportDecide($db, mailImportEmail('Stranger <new@else.test>', 'Help', 'x')))->toMatchArray(['status' => MailImport::IMPORTED, 'client_id' => 0])
        // Guest tickets off (Support settings) or a clients-only help desk: refused.
        ->and(mailImportDecide($db, mailImportEmail('new@else.test', 'Help', 'x'), 1, [], false)['status'])->toBe(MailImport::REJECTED_CLIENTS_ONLY);

    $db->update(MailImport::MAILBOX_TABLE, ['clients_only' => 1], ['helpdesk_id' => 1]);
    expect(mailImportDecide($db, mailImportEmail('new@else.test', 'Help', 'x'))['status'])->toBe(MailImport::REJECTED_CLIENTS_ONLY)
        ->and(mailImportDecide($db, mailImportEmail('alice@client.test', 'Help', 'x'))['status'])->toBe(MailImport::IMPORTED)
        // Staff import it anyway.
        ->and(mailImportDecide($db, mailImportEmail('new@else.test', 'Help', 'x'), 1, [], true, true))->toMatchArray(['status' => MailImport::IMPORTED, 'client_id' => 0]);

    $db->update(MailImport::MAILBOX_TABLE, ['replies_only' => 1], ['helpdesk_id' => 1]);
    expect(mailImportDecide($db, mailImportEmail('alice@client.test', 'Help', 'x'))['status'])->toBe(MailImport::REJECTED_REPLIES_ONLY)
        ->and(mailImportDecide($db, mailImportEmail('alice@client.test', 'Re: [Ticket #12] Help', 'x'))['status'])->toBe(MailImport::REPLIED);
});

test('loops, own addresses, auto-replies, spam and duplicates are blocked first', function (): void {
    $db = mailImportDb();

    expect(mailImportDecide($db, mailImportEmail('support@clientfence.test', 'New ticket', 'x'))['status'])->toBe(MailImport::BLOCKED_OWN)
        ->and(mailImportDecide($db, mailImportEmail('staff@clientfence.test', 'Re: [Ticket #12]', 'x'))['status'])->toBe(MailImport::BLOCKED_OWN)
        ->and(mailImportDecide($db, mailImportEmail('hello@clientfence.test', 'x', 'x'))['status'])->toBe(MailImport::BLOCKED_OWN)
        ->and(mailImportDecide($db, mailImportEmail('alice@client.test', 'Out of office', 'x', ['Auto-Submitted' => 'auto-replied']))['status'])->toBe(MailImport::BLOCKED_AUTO)
        ->and(mailImportDecide($db, mailImportEmail('alice@client.test', 'Win', 'x'), 1, ['mail_block_subjects' => 'win'])['status'])->toBe(MailImport::BLOCKED_SPAM)
        ->and(mailImportDecide($db, "Subject: none\r\n\r\nno sender")['status'])->toBe(MailImport::FAILED);

    // The 3rd email in 15 minutes with a limit of 2 is blocked; older ones do not count.
    $db->insert(MailImport::LOG_TABLE, ['from_email' => 'alice@client.test', 'created_at' => date('Y-m-d H:i:s', time() - 60), 'status' => 'imported', 'message_id' => 'a']);
    $db->insert(MailImport::LOG_TABLE, ['from_email' => 'alice@client.test', 'created_at' => date('Y-m-d H:i:s', time() - 3600), 'status' => 'imported', 'message_id' => 'b']);
    expect(mailImportDecide($db, mailImportEmail('alice@client.test', 'Help', 'x'), 1, ['mail_reply_limit' => '2'])['status'])->toBe(MailImport::IMPORTED);
    $db->insert(MailImport::LOG_TABLE, ['from_email' => 'alice@client.test', 'created_at' => date('Y-m-d H:i:s', time() - 30), 'status' => 'imported', 'message_id' => 'c']);
    expect(mailImportDecide($db, mailImportEmail('alice@client.test', 'Help', 'x'), 1, ['mail_reply_limit' => '2'])['status'])->toBe(MailImport::BLOCKED_LOOP);

    // The same Message-ID imported before.
    $raw = mailImportEmail('bob@client.test', 'Once', 'x');
    $db->insert(MailImport::LOG_TABLE, ['from_email' => 'other@x.test', 'created_at' => date('Y-m-d H:i:s'), 'status' => 'replied', 'message_id' => trim(MimeMessage::parse($raw)->header('message-id'), '<>')]);
    expect(mailImportDecide($db, $raw)['status'])->toBe(MailImport::DUPLICATE);
});

test('the log lists entries without their copies and survives a missing table', function (): void {
    $db = mailImportDb();
    $db->insert(MailImport::LOG_TABLE, ['helpdesk_id' => 1, 'from_email' => 'a@x.test', 'created_at' => '2026-10-15 10:00:00', 'status' => 'replied', 'ticket_id' => 12, 'message_id' => '1']);
    $db->insert(MailImport::LOG_TABLE, ['helpdesk_id' => 1, 'from_email' => 'b@x.test', 'created_at' => '2026-10-15 11:00:00', 'status' => 'blocked_sender', 'raw' => gzcompress('raw'), 'message_id' => '2']);
    $di = mailImportContainer($db);

    $all = MailImport::getLog($di, 'all', 1, 25);
    expect($all['total'])->toBe(2)
        ->and($all['list'][0])->toMatchArray(['from_email' => 'b@x.test', 'helpdesk_name' => 'Support', 'has_copy' => true, 'imported' => false])
        ->and($all['list'][0])->not->toHaveKey('raw')
        ->and(MailImport::getLog($di, 'imported', 1, 25)['list'][0]['from_email'])->toBe('a@x.test')
        ->and(MailImport::getLog($di, 'not_imported', 1, 25)['total'])->toBe(1)
        ->and(MailImport::getLog(mailImportContainer(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])), 'all', 1, 25)['total'])->toBe(0);
});

test('mailbox settings are checked and the password never comes back', function (): void {
    $db = mailImportDb();
    $di = mailImportContainer($db);
    $crypt = Mockery::mock();
    $crypt->shouldReceive('encrypt')->andReturnUsing(fn (string $p): string => 'enc:' . strrev($p));
    $di['crypt'] = $crypt;

    MailImport::saveMailbox($di, 2, ['enabled' => '1', 'host' => 'mail.example.com', 'port' => '993', 'encryption' => 'ssl', 'verify_tls' => '1', 'username' => 'sales@x.test', 'password' => 's3cret', 'folder' => '', 'max_per_run' => '10']);
    $view = MailImport::mailboxForStaff($di, 2);
    expect($view)->toMatchArray(['enabled' => true, 'host' => 'mail.example.com', 'folder' => 'INBOX', 'has_password' => true, 'max_per_run' => 10])
        ->and($view)->not->toHaveKey('password')
        ->and($db->fetchOne('SELECT password FROM ' . MailImport::MAILBOX_TABLE . ' WHERE helpdesk_id = 2'))->toBe('enc:terc3s');

    // An empty password keeps the saved one for the same server and user.
    MailImport::saveMailbox($di, 2, ['enabled' => '1', 'host' => 'mail.example.com', 'port' => '993', 'encryption' => 'ssl', 'username' => 'sales@x.test', 'password' => '']);
    expect($db->fetchOne('SELECT password FROM ' . MailImport::MAILBOX_TABLE . ' WHERE helpdesk_id = 2'))->toBe('enc:terc3s')
        ->and(MailImport::mailboxForStaff($di, 2)['verify_tls'])->toBeFalse();

    // Another server or user needs the password again: the saved one is never sent elsewhere.
    $mailbox = ['enabled' => '1', 'host' => 'mail.example.com', 'port' => '993', 'encryption' => 'ssl', 'username' => 'sales@x.test', 'password' => ''];
    foreach ([['host' => 'evil.example.net'], ['port' => '143'], ['encryption' => 'none'], ['username' => 'other@x.test']] as $change) {
        expect(fn () => MailImport::saveMailbox($di, 2, array_merge($mailbox, $change)))->toThrow(ClientFence\InformationException::class, 'Enter the password again');
    }
    MailImport::saveMailbox($di, 2, array_merge($mailbox, ['host' => 'imap.example.net', 'password' => 'n3w']));
    expect($db->fetchOne('SELECT password FROM ' . MailImport::MAILBOX_TABLE . ' WHERE helpdesk_id = 2'))->toBe('enc:w3n');

    expect(fn () => MailImport::saveMailbox($di, 3, ['port' => '993']))->toThrow(ClientFence\InformationException::class, 'Help desk not found')
        ->and(fn () => MailImport::saveMailbox($di, 2, ['host' => 'bad host/x', 'port' => '993']))->toThrow(ClientFence\InformationException::class, 'host')
        ->and(fn () => MailImport::saveMailbox($di, 2, ['host' => 'x', 'port' => '993', 'encryption' => 'rot13']))->toThrow(ClientFence\InformationException::class, 'encryption')
        ->and(fn () => MailImport::saveMailbox($di, 2, ['host' => 'x', 'port' => '993', 'username' => "a\r\nLOGOUT"]))->toThrow(ClientFence\InformationException::class, 'line breaks')
        ->and(fn () => MailImport::saveMailbox($di, 1, ['enabled' => '1', 'host' => '', 'port' => '993']))->toThrow(ClientFence\InformationException::class, 'before turning');
});

test('IMAP strings are quoted and cannot carry a second command', function (): void {
    expect(ImapClient::quote('pa"ss\\word'))->toBe('"pa\\"ss\\\\word"')
        ->and(fn () => ImapClient::quote("x\r\nB1 DELETE INBOX"))->toThrow(MailException::class)
        ->and(fn () => (new ImapClient(2))->connect('127.0.0.1', 1, 'none'))->toThrow(MailException::class, 'Could not connect');
});

test('ticket emails ask for replies to the imported mailbox, and its alerts go to staff', function (): void {
    $db = mailImportDb();
    $di = mailImportContainer($db);

    // Help desk 1 has a mailbox row but no server yet; help desk 2 has none.
    expect(MailImport::replyAddress($di, 1))->toBeNull()
        ->and(MailImport::replyAddress($di, 2))->toBeNull()
        ->and(MailImport::isImportedAddress($di, 'tickets@clientfence.test'))->toBeFalse();

    // The address being read comes first.
    $db->update(MailImport::MAILBOX_TABLE, ['host' => 'mail.clientfence.test'], ['helpdesk_id' => 1]);
    expect(MailImport::replyAddress($di, 1))->toBe('tickets@clientfence.test')
        ->and(MailImport::mailboxForStaff($di, 1))->toMatchArray(['reply_to' => 'tickets@clientfence.test', 'desk_email' => 'support@clientfence.test'])
        ->and(MailImport::isImportedAddress($di, ' Tickets@ClientFence.test '))->toBeTrue()
        ->and(MailImport::isImportedAddress($di, 'support@clientfence.test'))->toBeFalse();

    // A login that is not an address: the help desk email, which is then the imported address.
    $db->update(MailImport::MAILBOX_TABLE, ['username' => 'tickets'], ['helpdesk_id' => 1]);
    expect(MailImport::replyAddress($di, 1))->toBe('support@clientfence.test')
        ->and(MailImport::isImportedAddress($di, 'support@clientfence.test'))->toBeTrue();

    // Turned off: the Email settings' Reply-To stays and alerts go to the help desk email again.
    $db->update(MailImport::MAILBOX_TABLE, ['enabled' => 0], ['helpdesk_id' => 1]);
    expect(MailImport::replyAddress($di, 1))->toBeNull()
        ->and(MailImport::isImportedAddress($di, 'support@clientfence.test'))->toBeFalse();

    // A broken database never stops the ticket email.
    $broken = new Pimple\Container();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andThrow(new RuntimeException('down'));
    $broken['em'] = $em;
    expect(MailImport::replyAddress($broken, 1))->toBeNull()
        ->and(MailImport::isImportedAddress($broken, 'support@clientfence.test'))->toBeFalse();
});

test('a sender without a valid DKIM signature is still matched, with the reason for staff', function (): void {
    $db = mailImportDb();
    // A forged Authentication-Results header counts for nothing.
    $forged = ['Authentication-Results' => 'mx.host.test; dkim=pass header.d=client.test; dmarc=pass header.from=client.test'];

    expect(mailImportDecide($db, mailImportEmail('alice@client.test', 'Re: [Ticket #12] Down', 'ok', $forged)))
        ->toMatchArray(['status' => MailImport::REPLIED, 'ticket_id' => 12, 'client_id' => 5, 'unverified' => 'the email has no DKIM signature'])
        ->and(mailImportDecide($db, mailImportEmail('alice@client.test', 'Help', 'x', $forged)))
        ->toMatchArray(['status' => MailImport::IMPORTED, 'client_id' => 5, 'unverified' => 'the email has no DKIM signature'])
        ->and(mailImportDecide($db, mailImportEmail('guest@else.test', 'Re: [Ticket #13] Q', 'more'))['unverified'])->toBe('the email has no DKIM signature');

    // A signed email: no flag.
    $di = mailImportContainer($db);
    $di['dkim'] = new class {
        public function verify(string $raw, string $from): array
        {
            return [true, ''];
        }
    };
    expect(MailImport::decide($di, MimeMessage::parse(mailImportEmail('alice@client.test', 'Help', 'x')), 1))
        ->toMatchArray(['status' => MailImport::IMPORTED, 'client_id' => 5, 'unverified' => null]);

    // A verifier that breaks never lets the email through as verified.
    $di['dkim'] = new class {
        public function verify(string $raw, string $from): array
        {
            throw new RuntimeException('DNS down');
        }
    };
    expect(MailImport::verifySender($di, MimeMessage::parse(mailImportEmail('alice@client.test', 'Help', 'x'))))
        ->toBe([false, 'its DKIM signature could not be checked (DNS down)']);
});

test('staff see the unverified flags of the messages now on a ticket', function (): void {
    $db = mailImportDb();
    $db->executeStatement('CREATE TABLE support_ticket_message (id INTEGER PRIMARY KEY, support_ticket_id INTEGER, content TEXT)');
    $db->executeStatement('CREATE TABLE ' . MailImport::UNVERIFIED_TABLE . ' (message_id INTEGER PRIMARY KEY, ticket_id INTEGER, sender TEXT, reason TEXT, created_at TEXT)');
    $db->insert('support_ticket_message', ['id' => 40, 'support_ticket_id' => 12, 'content' => 'Change my nameservers']);
    $db->insert('support_ticket_message', ['id' => 41, 'support_ticket_id' => 12, 'content' => 'Thanks']);
    $di = mailImportContainer($db);
    $flag = new ReflectionMethod(MailImport::class, 'flagUnverified');

    $flag->invoke(null, $di, 12, 40, 'alice@client.test', 'the email has no DKIM signature');
    expect(MailImport::unverifiedMessages($di, 12))->toBe([40 => ['sender' => 'alice@client.test', 'reason' => 'the email has no DKIM signature']]);

    // Merged into ticket 14: the flag follows the message.
    $db->update('support_ticket_message', ['support_ticket_id' => 14], ['id' => 40]);
    expect(MailImport::unverifiedMessages($di, 12))->toBe([])
        ->and(array_keys(MailImport::unverifiedMessages($di, 14)))->toBe([40]);

    // Without the table the warning goes into the message itself.
    $db->executeStatement('DROP TABLE ' . MailImport::UNVERIFIED_TABLE);
    $flag->invoke(null, $di, 12, 41, 'alice@client.test', 'the email has no DKIM signature');
    expect((string) $db->fetchOne('SELECT content FROM support_ticket_message WHERE id = 41'))
        ->toStartWith('[Unverified sender: this email may not come from alice@client.test (the email has no DKIM signature).]')
        ->and(MailImport::unverifiedMessages($di, 12))->toBe([]);
});
