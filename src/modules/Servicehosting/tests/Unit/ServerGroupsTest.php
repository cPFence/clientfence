<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Servicehosting\ServerGroups;
use Doctrine\DBAL\DriverManager;
use RedBeanPHP\Facade;

beforeEach(function (): void {
    $this->previousToolboxes = Facade::$toolboxes;
    $this->previousDatabase = Facade::$currentDB;
    Facade::$toolboxes = [];
    $db = $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $di = $this->di = require PATH_ROOT . '/di.php';
    $di['dbal'] = $db;
    $di['pdo'] = $db->getNativeConnection();
    $di['db'];
    Facade::freeze(true);

    foreach ([
        'CREATE TABLE service_hosting_server_group (id INTEGER PRIMARY KEY, name TEXT, fill_type TEXT, servers TEXT, created_at TEXT, updated_at TEXT)',
        'CREATE TABLE service_hosting_server (id INTEGER PRIMARY KEY, name TEXT, active INTEGER, max_accounts INTEGER)',
        'CREATE TABLE service_hosting_hp (id INTEGER PRIMARY KEY, config TEXT)',
        'CREATE TABLE service_hosting (id INTEGER PRIMARY KEY, service_hosting_server_id INTEGER)',
        'CREATE TABLE client_order (service_type TEXT, service_id INTEGER, status TEXT)',
        'CREATE TABLE product (title TEXT, config TEXT)',
        "INSERT INTO service_hosting_server VALUES (1, 'Web 1', 1, 2), (2, 'Web 2', 1, 4), (3, 'Web 3', 1, 8), (4, 'Web 4', 1, 1), (5, 'Web 5', 0, 10)",
    ] as $sql) {
        $db->executeStatement($sql);
    }
});

afterEach(function (): void {
    Facade::close();
    $this->connection->close();
    Facade::$toolboxes = $this->previousToolboxes;
    Facade::$currentDB = '';
    if ($this->previousDatabase !== '') {
        Facade::selectDatabase($this->previousDatabase);
    }
});

test('a server group picks an eligible server for the plan', function (string $fillType, array $servers, ?int $boundTo, ?int $expected): void {
    $this->connection->insert('service_hosting_hp', ['id' => 1, 'config' => json_encode(['server_id' => $boundTo])]);
    $this->connection->executeStatement('INSERT INTO service_hosting VALUES (1, 1), (2, 1), (3, 2), (4, 3), (5, 3), (6, 4)');
    $this->connection->executeStatement("INSERT INTO client_order VALUES ('hosting', 1, 'active'), ('hosting', 2, 'canceled'), ('hosting', 3, 'active'), ('hosting', 4, 'active'), ('hosting', 5, 'suspended'), ('hosting', 6, 'active')");
    $group = ['fill_type' => $fillType, 'servers' => $servers];

    if ($expected === null) {
        expect(fn () => ServerGroups::pick($this->di, $group, 1))
            ->toThrow(InformationException::class, 'This hosting plan cannot be ordered right now. Please contact us.');
    } else {
        expect((int) ServerGroups::pick($this->di, $group, 1)->id)->toBe($expected);
    }
})->with([
    'least picks the lowest percentage with ties in group order' => ['least', [5, 3, 2, 1], null, 3],
    'fill picks the first non-full server in group order' => ['fill', [4, 3, 2, 1], null, 3],
    'a panel plan fits its own server' => ['fill', [3, 1, 2], 2, 2],
    'no eligible server' => ['least', [4, 5, 99], null, null],
]);

test('saving a server group orders servers by position then ID', function (): void {
    $id = ServerGroups::save($this->di, ['name' => 'Web', 'servers' => [1 => 3, 4 => 1, 2 => 1, 3 => 2, 5 => '', 99 => '']]);

    expect($this->connection->fetchOne('SELECT servers FROM service_hosting_server_group WHERE id = ?', [$id]))->toBe('[2,4,3,1]');
});

test('saving a server group refuses a server without a maximum account count', function (?int $maximum): void {
    $this->connection->update('service_hosting_server', ['max_accounts' => $maximum], ['id' => 1]);

    expect(fn () => ServerGroups::save($this->di, ['name' => 'Web', 'servers' => [1 => 1]]))
        ->toThrow(InformationException::class, 'Server Web 1 has no maximum number of accounts. Set one to add it to a group.');
})->with(['unset' => [null], 'zero' => [0]]);

test('saving a server group with every position empty asks for a server', function (): void {
    expect(fn () => ServerGroups::save($this->di, ['name' => 'Web', 'servers' => [1 => '', 2 => '']]))
        ->toThrow(InformationException::class, 'Choose at least one server for the group.');
});

test('saving a server group refuses an unknown server', function (): void {
    expect(fn () => ServerGroups::save($this->di, ['name' => 'Web', 'servers' => [1 => 1, 99 => 2]]))
        ->toThrow(InformationException::class, 'Server not found');
});

test('deleting a server group names the product that uses it', function (): void {
    $id = ServerGroups::save($this->di, ['name' => 'Web', 'servers' => [1 => 1]]);
    $this->connection->insert('product', ['title' => 'Web Pro', 'config' => json_encode(['server_group_id' => (string) $id])]);

    expect(fn () => ServerGroups::delete($this->di, $id))
        ->toThrow(InformationException::class, 'Server group is used by product Web Pro.');
});
