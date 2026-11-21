<?php

declare(strict_types=1);

/**
 * ClientFence (CF-241): staff change a domain's registrar on the admin domain page (servicedomain/update), as WHMCS:
 * the record only, noted in the order history; the next registrar action goes to the new registrar.
 */

use ClientFence\Mod\Servicedomain\Service;

use function Tests\Helpers\container;

afterEach(function (): void {
    Mockery::close();
});

/** Registrars #1 Namesilo and #2 INWX (#3 Broken has no configuration); domain on #1 with order #7. */
function registrarChangeFixture(): array
{
    $registrars = [];
    foreach ([1 => 'Namesilo', 2 => 'INWX', 3 => 'Broken'] as $id => $name) {
        $registrar = new Model_TldRegistrar();
        $registrar->loadBean(new Tests\Helpers\DummyBean());
        $registrar->id = $id;
        $registrar->name = $name;
        $registrar->registrar = 'Custom';
        $registrars[$id] = $registrar;
    }
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 5;
    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 7;
    $domain = new Model_ServiceDomain();
    $domain->loadBean(new Tests\Helpers\DummyBean());
    $domain->id = 3;
    $domain->client_id = 5;
    $domain->sld = 'example';
    $domain->tld = '.com';
    $domain->tld_registrar_id = 1;
    $domain->sync_error = 'Namesilo: domain not found';

    $notes = new ArrayObject();
    $stored = new ArrayObject();
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('load')->andReturnUsing(static fn (string $name, $id) => match ($name) {
        'TldRegistrar' => $registrars[(int) $id] ?? null,
        'Client' => $client,
        default => null,
    });
    $db->shouldReceive('getExistingModelById')->andReturnUsing(static fn (string $name, $id, string $message) => $registrars[(int) $id] ?? throw new ClientFence\Exception($message));
    $db->shouldReceive('store')->andReturnUsing(static function ($model) use ($stored): int {
        $stored[] = $model->tld_registrar_id;

        return (int) $model->id;
    });
    $orders = Mockery::mock();
    $orders->shouldReceive('getServiceOrder')->andReturn($order);
    $orders->shouldReceive('getConfig')->andReturn([]);
    $orders->shouldReceive('saveStatusChange')->andReturnUsing(static function (Model_ClientOrder $o, string $note) use ($notes): void {
        $notes[] = [(int) $o->id, $note];
    });
    $orders->shouldReceive('updateOrderMeta')->andReturnUsing(static function ($o, array $meta) use ($notes): void {
        $notes[] = $meta;
    });
    $orders->shouldReceive('updateOrderConfig')->andReturnUsing(static function ($o, array $config) use ($notes): void {
        $notes[] = ['config' => $config];
    });
    $di = container();
    $di['db'] = $db;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $em = Mockery::mock();
    $em->shouldReceive('getReference')->andReturn(new stdClass());
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(static fn (string $name) => strtolower($name) === 'order' ? $orders : Mockery::mock()->shouldIgnoreMissing());

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarValidateConfiguration')->andReturnUsing(static function (Model_TldRegistrar $r): void {
        if ((int) $r->id === 3) {
            throw new ClientFence\InformationException('Registrar :registrar is missing required configuration: :field', [':registrar' => $r->name, ':field' => 'API key']);
        }
    });
    $service->setDi($di);

    return [$service, $domain, $notes, $stored];
}

test('staff change the registrar: saved, noted with both names, and the next action goes to the new registrar', function (): void {
    [$service, $domain, $notes, $stored] = registrarChangeFixture();

    expect($service->updateDomain($domain, ['order_id' => 7, 'tld_registrar_id' => '2', 'period' => 1]))->toBeTrue()
        ->and((int) $domain->tld_registrar_id)->toBe(2)
        ->and($domain->sync_error)->toBeNull()
        ->and($stored->getArrayCopy())->toBe([2])
        // The old registrar's renewal mark goes too (it would pass a failed renewal as done at the new one).
        ->and($notes->getArrayCopy())->toBe([[7, 'Registrar changed from Namesilo to INWX.'], ['domain_renewal_expiry' => null]]);

    // Lock runs through _getD(): the adapter is the one of the registrar now stored on the domain.
    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('lock')->once()->with(Mockery::on(static fn (Registrar_Domain $d) => $d->getName() === 'example.com'));
    $service->shouldReceive('registrarGetRegistrarAdapter')->once()
        ->with(Mockery::on(static fn (Model_TldRegistrar $r) => $r->name === 'INWX'), Mockery::any())
        ->andReturn($adapter);

    expect($service->lock($domain))->toBeTrue();
});

test('the registrar stays and nothing is noted when none or the same one is sent', function (array $data): void {
    [$service, $domain, $notes, $stored] = registrarChangeFixture();

    expect($service->updateDomain($domain, $data + ['order_id' => 7, 'period' => 1]))->toBeTrue()
        ->and((int) $domain->tld_registrar_id)->toBe(1)
        ->and($domain->sync_error)->toBe('Namesilo: domain not found')
        ->and($stored->getArrayCopy())->toBe([1])
        ->and($notes->getArrayCopy())->toBe([]);
})->with([
    'not sent' => [[]],
    'empty' => [['tld_registrar_id' => '']],
    'the same one' => [['tld_registrar_id' => '1']],
]);

test('an unknown or unconfigured registrar is refused and the domain is not saved', function (string $id, string $error): void {
    [$service, $domain, $notes, $stored] = registrarChangeFixture();

    $domain->period = 1;
    expect(fn () => $service->updateDomain($domain, ['order_id' => 7, 'tld_registrar_id' => $id, 'period' => 3]))->toThrow(ClientFence\Exception::class, $error)
        ->and((int) $domain->tld_registrar_id)->toBe(1)
        // Refused before anything changed: the other fields of the form are not saved either.
        ->and((int) $domain->period)->toBe(1)
        ->and($stored->getArrayCopy())->toBe([])
        ->and($notes->getArrayCopy())->toBe([]);
})->with([
    'unknown' => ['99', 'Registrar not found'],
    'not a number' => ['abc', 'Registrar not found'],
    'not configured' => ['3', 'missing required configuration'],
]);

test('a change ends the old registrar\'s transfer tracking and ignores the old registrar\'s domain fields', function (?string $action): void {
    [$service, $domain, $notes, $stored] = registrarChangeFixture();
    $domain->action = $action;
    $domain->transfer_since = $action === null ? null : '2026-11-01 10:00:00';

    expect($service->updateDomain($domain, ['order_id' => 7, 'tld_registrar_id' => '2', 'domain_fields' => ['eu_citizenship' => '']]))->toBeTrue()
        ->and($domain->action)->toBeNull()
        ->and($domain->transfer_since)->toBeNull()
        // The page reloads with the new registrar's fields: the old ones are neither checked nor saved.
        ->and(array_filter($notes->getArrayCopy(), static fn ($n) => isset($n['config'])))->toBe([]);
})->with(['tracked transfer' => ['transfer_pending'], 'failed transfer' => ['transfer_failed'], 'none' => [null]]);
