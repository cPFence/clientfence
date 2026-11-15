<?php

declare(strict_types=1);

/**
 * ClientFence: the low balance alert (Client/BalanceAlert.php) on an in-memory database, with stand-ins for the
 * System (the staff switch), Invoice (free credit) and Email services.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Client\Api\Client as ClientApi;
use ClientFence\Mod\Client\BalanceAlert;
use ClientFence\Mod\Client\Users;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function Tests\Helpers\client;
use function Tests\Helpers\container;

/** Client 7 (USD, active) with a balance of 5.00; client 8 with an alert of its own. */
function balanceAlertDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, currency TEXT, status TEXT)');
    $db->executeStatement('CREATE TABLE client_balance (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, amount NUMERIC)');
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, client_id INTEGER, status TEXT)');
    $db->executeStatement('CREATE TABLE ' . BalanceAlert::TABLE . ' (client_id INTEGER PRIMARY KEY, threshold NUMERIC NOT NULL, currency TEXT, alerted_at TEXT, updated_at TEXT NOT NULL)');
    $db->executeStatement('CREATE TABLE mod_clientfence_notification (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT, recipient_id INTEGER, kind TEXT, params TEXT, url TEXT, dedupe TEXT, created_at TEXT, read_at TEXT)');
    $db->insert('client', ['id' => 7, 'currency' => 'USD', 'status' => 'active']);
    $db->insert('client', ['id' => 8, 'currency' => 'USD', 'status' => 'active']);
    $db->insert('client_balance', ['client_id' => 7, 'amount' => 5]);
    $db->insert(BalanceAlert::TABLE, ['client_id' => 8, 'threshold' => 50, 'currency' => 'USD', 'updated_at' => '2026-11-01 10:00:00']);

    return $db;
}

/** @param array<string, list<mixed>> $calls what the stand-in services were asked */
function balanceAlertDi(Connection $db, array &$calls, bool $on = true): Pimple\Container
{
    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($db);
    $di['em'] = $em;
    $di['dbal'] = $db;
    $services = new class($on, $calls) {
        /** Free credit per client, when a test sets it (an unpaid invoice holds some aside). */
        public array $free = [];

        public function __construct(private bool $on, private array &$calls)
        {
        }

        public function getParamValue(string $param, mixed $default = null): mixed
        {
            return $param === BalanceAlert::SETTING ? ($this->on ? '1' : '0') : $default;
        }

        public function getFreeCredit(int $clientId): float
        {
            $this->calls['free'][] = $clientId;

            return $this->free[$clientId];
        }

        public function sendTemplate(array $email): bool
        {
            $this->calls['email'][] = [$email['code'], $email['to_client'], $email['balance'], $email['threshold'], $email['currency']];

            return true;
        }
    };
    $di['services'] = $services;
    $di['mod_service'] = $di->protect(static fn (): object => $services);

    return $di;
}

function balanceAlertApi(Pimple\Container $di, int $clientId = 7): ClientApi
{
    $api = new ClientApi();
    $api->setDi($di);
    $api->setIdentity(client(['id' => $clientId]));

    return $api;
}

function balanceAlertedAt(Connection $db, int $clientId = 7): mixed
{
    return $db->fetchOne('SELECT alerted_at FROM ' . BalanceAlert::TABLE . ' WHERE client_id = ?', [$clientId]);
}

test('off: the cron reads nothing and the Wallet card is not shown', function (): void {
    $calls = [];
    $di = balanceAlertDi(balanceAlertDb(), $calls, false);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldNotReceive('getConnection');
    $di['em'] = $em;

    BalanceAlert::run($di);

    expect(balanceAlertApi($di)->balance_alert_get())->toBeNull()
        ->and(fn () => balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]))->toThrow(InformationException::class)
        ->and($calls)->toBe([]);
});

test('an amount of 0 or less, not a number or too large is refused', function (mixed $amount): void {
    $calls = [];
    $db = balanceAlertDb();

    expect(fn () => balanceAlertApi(balanceAlertDi($db, $calls))->balance_alert_save(['enabled' => 1, 'amount' => $amount]))->toThrow(InformationException::class)
        ->and(BalanceAlert::threshold(balanceAlertDi($db, $calls), 7))->toBeNull();
})->with(['zero' => [0], 'negative' => ['-5'], 'rounds to 0' => ['0.001'], 'not a number' => ['abc'], 'empty' => [''], 'missing' => [null], 'too large' => ['1000000000'], 'a list' => [[10]]]);

test('the client saves the alert of the signed-in account only, and turns it off', function (): void {
    $calls = [];
    $db = balanceAlertDb();
    $di = balanceAlertDi($db, $calls);

    balanceAlertApi($di)->balance_alert_save(['enabled' => '1', 'amount' => '25.5']);

    expect(balanceAlertApi($di)->balance_alert_get())->toBe(['threshold' => 25.5])
        ->and($db->fetchOne('SELECT currency FROM ' . BalanceAlert::TABLE . ' WHERE client_id = 7'))->toBe('USD')
        ->and(BalanceAlert::threshold($di, 8))->toBe(50.0);

    balanceAlertApi($di)->balance_alert_save(['enabled' => '0']);

    expect(BalanceAlert::threshold($di, 7))->toBeNull()
        ->and(BalanceAlert::threshold($di, 8))->toBe(50.0);
});

test('below the amount: one email and one bell notice, alerted_at set (B12: already below when switched on)', function (): void {
    $calls = [];
    $db = balanceAlertDb();
    $di = balanceAlertDi($db, $calls);
    balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]);

    BalanceAlert::run($di);

    // Client 8 has no credit at all: below its 50 too.
    expect($calls['email'])->toHaveCount(2)
        ->and($calls['email'])->toContain([BalanceAlert::EMAIL, 7, 5.0, 10.0, 'USD'], [BalanceAlert::EMAIL, 8, 0.0, 50.0, 'USD'])
        ->and(balanceAlertedAt($db))->not->toBeNull()
        ->and($db->fetchAllNumeric('SELECT recipient_id, kind, url FROM mod_clientfence_notification WHERE recipient_id = 7'))->toBe([[7, 'balance_low', 'client/balance']]);
});

test('still below at the next run: no second email (B13)', function (): void {
    $calls = [];
    $db = balanceAlertDb();
    $di = balanceAlertDi($db, $calls);
    balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]);

    BalanceAlert::run($di);
    BalanceAlert::run($di);

    expect(array_filter($calls['email'], static fn (array $email): bool => $email[1] === 7))->toHaveCount(1);
});

test('back at or above the amount re-arms it, and the next drop emails again', function (): void {
    $calls = [];
    $db = balanceAlertDb();
    $di = balanceAlertDi($db, $calls);
    balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]);
    BalanceAlert::run($di);

    $db->insert('client_balance', ['client_id' => 7, 'amount' => 5]);
    BalanceAlert::run($di);
    expect(balanceAlertedAt($db))->toBeNull();

    $db->insert('client_balance', ['client_id' => 7, 'amount' => -2]);
    BalanceAlert::run($di);

    expect(array_values(array_filter($calls['email'], static fn (array $email): bool => $email[1] === 7)))->toBe([[BalanceAlert::EMAIL, 7, 5.0, 10.0, 'USD'], [BalanceAlert::EMAIL, 7, 8.0, 10.0, 'USD']]);
});

test('an inactive client gets no alert', function (): void {
    $calls = [];
    $db = balanceAlertDb();
    $di = balanceAlertDi($db, $calls);
    balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]);
    $db->update('client', ['status' => 'suspended'], ['id' => 7]);

    BalanceAlert::run($di);

    expect(array_filter($calls['email'], static fn (array $email): bool => $email[1] === 7))->toBe([])
        ->and(balanceAlertedAt($db))->toBeNull();
});

test('a change of the client currency re-arms the alert', function (): void {
    $calls = [];
    $db = balanceAlertDb();
    $di = balanceAlertDi($db, $calls);
    balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]);
    BalanceAlert::run($di);

    $db->update('client', ['currency' => 'EUR'], ['id' => 7]);
    BalanceAlert::run($di);

    expect(array_values(array_filter($calls['email'], static fn (array $email): bool => $email[1] === 7)))->toBe([[BalanceAlert::EMAIL, 7, 5.0, 10.0, 'USD'], [BalanceAlert::EMAIL, 7, 5.0, 10.0, 'EUR']])
        ->and($db->fetchOne('SELECT currency FROM ' . BalanceAlert::TABLE . ' WHERE client_id = 7'))->toBe('EUR');
});

test('turning the alert off and on again re-arms it', function (): void {
    $calls = [];
    $db = balanceAlertDb();
    $di = balanceAlertDi($db, $calls);
    balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]);
    BalanceAlert::run($di);

    balanceAlertApi($di)->balance_alert_save(['enabled' => 0]);
    balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]);
    BalanceAlert::run($di);

    expect(array_filter($calls['email'], static fn (array $email): bool => $email[1] === 7))->toHaveCount(2);
});

test('with an unpaid invoice the balance is the free credit the Wallet shows', function (): void {
    $calls = [];
    $db = balanceAlertDb();
    $di = balanceAlertDi($db, $calls);
    $db->insert('client_balance', ['client_id' => 7, 'amount' => 45]);
    $db->insert('invoice', ['id' => 1, 'client_id' => 7, 'status' => 'unpaid']);
    $di['services']->free[7] = 4.0;
    balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]);

    BalanceAlert::run($di);

    expect($calls['free'])->toBe([7])
        ->and(array_values(array_filter($calls['email'], static fn (array $email): bool => $email[1] === 7)))->toBe([[BalanceAlert::EMAIL, 7, 4.0, 10.0, 'USD']]);
});

test('the alert is erased with the client', function (): void {
    $calls = [];
    $db = balanceAlertDb();
    foreach (['client_user_account (client_id INTEGER, user_id INTEGER)', 'client_user_invite (client_id INTEGER)', 'client_user_invite_log (client_id INTEGER)', 'support_ticket_author (client_id INTEGER)'] as $table) {
        $db->executeStatement('CREATE TABLE ' . $table);
    }
    $di = balanceAlertDi($db, $calls);
    balanceAlertApi($di)->balance_alert_save(['enabled' => 1, 'amount' => 10]);

    Users::clientDeleted($di, 7);

    expect(BalanceAlert::threshold($di, 7))->toBeNull()
        ->and(BalanceAlert::threshold($di, 8))->toBe(50.0);
});
