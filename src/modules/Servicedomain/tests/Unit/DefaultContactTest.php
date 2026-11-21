<?php

declare(strict_types=1);

/**
 * ClientFence (CF-240): "Use the client's details" and the default domain contact (System > Domain Registration > Contacts).
 * On, the default, all four roles are the client as before; off, admin, tech and billing are the default contact and
 * the registrant stays the client (as WHMCS).
 */

use ClientFence\Mod\Servicedomain\Service;

use function Tests\Helpers\client;
use function Tests\Helpers\container;
use function Tests\Helpers\model;
use function Tests\Helpers\moduleService;

/** The settings of a saved default contact with "Use the client's details" off. */
function defaultContactParams(): array
{
    return [
        'servicedomain_use_client_details' => '0',
        'servicedomain_contact_first_name' => 'Sam',
        'servicedomain_contact_last_name' => 'Host',
        'servicedomain_contact_company' => 'Host Ltd',
        'servicedomain_contact_email' => 'domains@host.example',
        'servicedomain_contact_address1' => '5 Port Road',
        'servicedomain_contact_city' => 'Leeds',
        'servicedomain_contact_postcode' => 'LS1 4AP',
        'servicedomain_contact_country' => 'GB',
        'servicedomain_contact_phone_cc' => '44',
        'servicedomain_contact_phone' => '1134960000',
    ];
}

/** A Servicedomain service whose settings live in $params, for client Ann's domain example.com. */
function defaultContactService(array &$params, ?object $adapter = null): Service
{
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->andReturnUsing(static function (string $key, $default = null) use (&$params) {
        return $params[$key] ?? $default;
    });
    $system->shouldReceive('setParamValue')->andReturnUsing(static function (string $key, $value) use (&$params): bool {
        $params[$key] = $value;

        return true;
    });
    $orders = Mockery::mock(ClientFence\Mod\Order\Service::class);
    $orders->shouldReceive('getServiceOrder')->andReturnNull();
    $clients = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clients->shouldReceive('resolveDocumentNumber')->andReturnNull();
    $ann = client(['id' => 7, 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@client.example', 'address_1' => '12 Main St',
        'city' => 'London', 'postcode' => 'N1 1AA', 'country' => 'GB', 'phone_cc' => '44', 'phone' => '2079460000']);
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldReceive('load')->andReturnUsing(static fn (string $type) => $type === 'Client' ? $ann : model(Model_TldRegistrar::class));
    $db->shouldReceive('store')->andReturn(1);

    $di = container();
    $di['db'] = $db;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(moduleService(['system' => $system, 'order' => $orders, 'client' => $clients]));
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('registrarGetRegistrarAdapter')->andReturn($adapter ?? Mockery::mock(Registrar_Adapter_Custom::class));
    $service->setDi($di);

    return $service;
}

function defaultContactDomain(): Model_ServiceDomain
{
    return model(Model_ServiceDomain::class, ['id' => 3, 'client_id' => 7, 'tld_registrar_id' => 1, 'sld' => 'example', 'tld' => '.com', 'period' => 1]);
}

afterEach(function (): void {
    Mockery::close();
});

test('with Use the client\'s details on, the default, all four roles are the client as before', function (array $params): void {
    $service = defaultContactService($params);
    $model = defaultContactDomain();
    [$domain] = (fn () => $this->_getD($model))->call($service);

    expect($domain->getContactRegistrar()->getEmail())->toBe('ann@client.example')
        ->and($domain->getContactAdmin())->toBe($domain->getContactRegistrar())
        ->and($domain->getContactTech())->toBe($domain->getContactRegistrar())
        ->and($domain->getContactBilling())->toBe($domain->getContactRegistrar());
})->with([
    'nothing saved' => [[]],
    'on, with a default contact saved' => [['servicedomain_use_client_details' => '1'] + defaultContactParams()],
]);

test('with it off, admin, tech and billing are the default contact and the registrant stays the client', function (): void {
    $params = defaultContactParams();
    $service = defaultContactService($params);
    $model = defaultContactDomain();
    [$domain] = (fn () => $this->_getD($model))->call($service);
    $admin = $domain->getContactAdmin();

    expect([$domain->getContactRegistrar()->getFirstName(), $domain->getContactRegistrar()->getEmail(), $domain->getContactRegistrar()->getCity()])->toBe(['Ann', 'ann@client.example', 'London'])
        ->and([$admin->getFirstName(), $admin->getLastName(), $admin->getCompany(), $admin->getEmail(), $admin->getAddress1(), $admin->getCity(), $admin->getZip(), $admin->getCountry(), $admin->getTelCc(), $admin->getTel()])
        ->toBe(['Sam', 'Host', 'Host Ltd', 'domains@host.example', '5 Port Road', 'Leeds', 'LS1 4AP', 'GB', '44', '1134960000'])
        ->and($domain->getContactTech())->toBe($admin)
        ->and($domain->getContactBilling())->toBe($admin);
});

test('a contact update with it off changes the registrant only: the other roles keep the default contact', function (): void {
    $params = defaultContactParams();
    $sent = null;
    $adapter = Mockery::mock(Registrar_Adapter_Custom::class);
    $adapter->shouldReceive('modifyContact')->once()->andReturnUsing(static function (Registrar_Domain $domain) use (&$sent): bool {
        $sent = $domain;

        return true;
    });
    $model = defaultContactDomain();
    $contact = ['first_name' => 'Bea', 'last_name' => 'Lee', 'email' => 'bea@client.example', 'address1' => '1 New St', 'country' => 'GB',
        'city' => 'York', 'state' => 'Yorks', 'postcode' => 'YO1 7HH', 'phone_cc' => '44', 'phone' => '1904000000'];

    expect(defaultContactService($params, $adapter)->updateContacts($model, ['contact' => $contact]))->toBeTrue()
        ->and([$sent->getContactRegistrar()->getFirstName(), $sent->getContactRegistrar()->getEmail()])->toBe(['Bea', 'bea@client.example'])
        ->and([$sent->getContactAdmin()->getEmail(), $sent->getContactTech()->getEmail(), $sent->getContactBilling()->getEmail()])
        ->toBe(['domains@host.example', 'domains@host.example', 'domains@host.example'])
        ->and($params['servicedomain_contact_email'])->toBe('domains@host.example');
});

test('saving keeps Use the client\'s details on by default and stores the contact as typed', function (): void {
    $params = [];
    expect(defaultContactService($params)->defaultContactUpdate(['first_name' => ' Sam ', 'email' => 'not checked while on']))->toBeTrue()
        ->and($params['servicedomain_use_client_details'])->toBe('1')
        ->and($params['servicedomain_contact_first_name'])->toBe('Sam')
        ->and($params['servicedomain_contact_email'])->toBe('not checked while on')
        ->and($params['servicedomain_contact_city'])->toBe('');
});

test('turning it off saves a complete contact, with the country in capitals and the phone in digits', function (): void {
    $params = [];
    $data = ['use_client_details' => '0', 'first_name' => 'Sam', 'last_name' => 'Host', 'company' => 'Host Ltd', 'email' => 'domains@host.example',
        'address1' => '5 Port Road', 'city' => 'Leeds', 'postcode' => 'LS1 4AP', 'country' => 'gb', 'phone_cc' => '+44', 'phone' => '0113 496 0000'];

    expect(defaultContactService($params)->defaultContactUpdate($data))->toBeTrue()
        ->and($params['servicedomain_use_client_details'])->toBe('0')
        ->and($params['servicedomain_contact_country'])->toBe('GB')
        ->and($params['servicedomain_contact_phone_cc'])->toBe('44')
        ->and($params['servicedomain_contact_phone'])->toBe('01134960000');
});

test('turning it off without what registrars need is refused and saves nothing', function (array $change, string $error): void {
    $params = ['servicedomain_use_client_details' => '1'];
    $data = ['use_client_details' => '0', 'first_name' => 'Sam', 'last_name' => 'Host', 'email' => 'domains@host.example', 'address1' => '5 Port Road',
        'city' => 'Leeds', 'postcode' => 'LS1 4AP', 'country' => 'GB', 'phone_cc' => '44', 'phone' => '1134960000'];

    $service = defaultContactService($params);

    expect(fn () => $service->defaultContactUpdate($change + $data))->toThrow(ClientFence\InformationException::class, $error)
        ->and($params)->toBe(['servicedomain_use_client_details' => '1']);
})->with([
    'no first name' => [['first_name' => ' '], 'Enter First Name for the default contact'],
    'no last name' => [['last_name' => ''], 'Enter Last Name for the default contact'],
    'no email' => [['email' => ''], 'Enter Email for the default contact'],
    'no address' => [['address1' => ''], 'Enter Address Line 1 for the default contact'],
    'no city' => [['city' => ''], 'Enter City for the default contact'],
    'no postcode' => [['postcode' => ''], 'Enter Zip for the default contact'],
    'no country' => [['country' => ''], 'Enter Country for the default contact'],
    'no phone code' => [['phone_cc' => ''], 'Enter Phone Country Code for the default contact'],
    'no phone' => [['phone' => ''], 'Enter Phone Number for the default contact'],
    'a bad email' => [['email' => 'domains@'], 'Email address is invalid'],
    'an unknown country' => [['country' => 'XX'], 'Invalid country code: XX'],
    'a bad phone code' => [['phone_cc' => '44a'], 'The phone country code is not valid.'],
    'a bad phone' => [['phone' => '+44 113'], 'Please use the separate field for the phone country code.'],
]);

test('the default contact is saved only with the settings permission', function (): void {
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staff->shouldReceive('checkPermissionsAndThrowException')->with('system', 'update_params', null, null)->once()->andThrow(new ClientFence\InformationException('You do not have permission to perform this action', [], 403));
    $service = Mockery::mock(Service::class);
    $service->shouldReceive('defaultContactUpdate')->never();
    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['staff' => $staff]));
    $api = new ClientFence\Mod\Servicedomain\Api\Admin();
    $api->setDi($di);
    $api->setService($service);

    expect(fn () => $api->default_contact_update(['use_client_details' => '0']))->toThrow(ClientFence\InformationException::class);
});
