<?php

declare(strict_types=1);

use ClientFence\Mod\Servicehosting\Panel\PanelManager;
use ClientFence\Mod\Servicehosting\Panel\Plans;
use Doctrine\DBAL\DriverManager;

/**
 * A container whose hosting plans live in SQLite and whose Enhance server $serverId lists one plan, numbered 1 as on
 * every new Enhance cluster.
 */
function plansTestDi(array $hostingPlans): Pimple\Container
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE service_hosting_hp (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, config TEXT)');
    $db->executeStatement('CREATE TABLE service_hosting_server (id INTEGER PRIMARY KEY, name TEXT)');
    $db->executeStatement("INSERT INTO service_hosting_server (id, name) VALUES (1, 'Cluster A'), (2, 'Cluster B')");
    foreach ($hostingPlans as $name => $config) {
        $db->insert('service_hosting_hp', ['name' => $name, 'config' => json_encode($config)]);
    }

    $di = new Pimple\Container();
    $di['em'] = Mockery::mock();
    $di['em']->allows('getConnection')->andReturn($db);
    $di['db'] = Mockery::mock();
    $di['db']->allows('load')->andReturnUsing(function (string $type, int $id) {
        $server = new Model_ServiceHostingServer();
        $server->loadBean(new Tests\Helpers\DummyBean());
        $server->id = $id;
        $server->manager = 'Enhance';

        return $server;
    });
    $manager = Mockery::mock(PanelManager::class);
    $manager->allows('listPackages')->andReturn([['id' => '1', 'name' => 'MyPackage', 'config' => ['plan_id' => '1']]]);
    $hosting = Mockery::mock();
    $hosting->allows('getServerManager')->andReturn($manager);
    $hosting->allows('createHp')->andReturnUsing(function (string $name, array $data) use ($db): int {
        $db->insert('service_hosting_hp', ['name' => $name, 'config' => json_encode($data['config'])]);

        return (int) $db->lastInsertId();
    });
    $hosting->allows('updateHp');
    $di['mod_service'] = $di->protect(fn () => $hosting);
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();

    return $di;
}

// Two Enhance clusters both number their first plan 1: a plan from cluster B never matches cluster A's hosting plan.
test('a panel plan matches only hosting plans made from its own server', function (array $existing, ?string $matched): void {
    $di = plansTestDi(['MyPackage' => $existing]);

    expect(Plans::list($di, 'enhance', 2)[0]['matched_by'])->toBe($matched);
    if ($matched === null) {
        Plans::import($di, 'enhance', 2, [], false);
        expect($di['em']->getConnection()->fetchAllKeyValue('SELECT name, config FROM service_hosting_hp'))
            ->toBe(['MyPackage' => json_encode($existing), 'MyPackage (Cluster B)' => '{"plan_id":"1","server_id":"2"}']);
    }
})->with([
    'made from cluster A' => [['plan_id' => '1', 'server_id' => '1'], null],
    'made from cluster B' => [['plan_id' => '1', 'server_id' => '2'], 'config'],
    'made before servers were kept (by name, as before)' => [['plan_id' => '1'], 'name'],
]);
