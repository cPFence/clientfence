<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Client\Users;
use Doctrine\DBAL\Connection;

use function Tests\Helpers\container;

beforeEach(function (): void {
    Users::forget();
    $this->di = container();
    $this->client = new Model_Client();
    $this->client->loadBean(new Tests\Helpers\DummyBean());
    $this->client->id = 5;
    $this->di['session']->shouldReceive('get')->with(Users::SESSION)->andReturn(['user_id' => 7, 'client_id' => 5, 'version' => 1]);
    $this->di['session']->shouldReceive('get')->with('client_id')->andReturn(5);
    $this->di['session']->shouldReceive('get')->with(ClientFence\Mod\Client\TwoFactor::REQUIRED)->andReturnNull();
    $this->db = Mockery::mock(Connection::class);
    $this->db->shouldReceive('fetchAssociative')->with(Mockery::type('string'), [5, ...Model_Client::OPEN, 7])->byDefault()->andReturn([
        'email' => 'user@example.com', 'first_name' => 'Test', 'last_name' => 'User',
        'status' => 'active', 'session_version' => 1, 'owner' => 0, 'permissions' => 'products,invoices',
    ]);
    $this->di['em']->shouldReceive('getConnection')->andReturn($this->db);
});

afterEach(fn () => Users::forget());

test('authorized users can call product actions with their assigned permission', function (string $module, string $method, string $permission): void {
    $this->db->shouldReceive('fetchAssociative')->with(Mockery::type('string'), [5, ...Model_Client::OPEN, 7])->andReturn([
        'email' => 'user@example.com', 'first_name' => 'Test', 'last_name' => 'User',
        'status' => 'active', 'session_version' => 1, 'owner' => 0, 'permissions' => $permission,
    ]);

    expect(Users::checkCall($this->di, $this->client, $module, $method))->toBeNull();
})->with([
    'API key allowed IPs' => ['serviceapikey', 'update_ips', 'manageproducts'],
    'API key IP add' => ['serviceapikey', 'ip_add', 'manageproducts'],
    'licence IP remove' => ['servicelicense', 'ip_remove', 'manageproducts'],
    'licence IP request withdraw' => ['servicelicense', 'ip_request_withdraw', 'manageproducts'],
    'order options quote' => ['order', 'upgrade_options_quote', 'products'],
    'new Support PIN' => ['client', 'support_pin_new', 'profile'],
    // Declared by the module in its manifest ("client_calls"), not in Users::CALLS.
    'DNS zone' => ['servicednshosting', 'zone', 'products'],
    'DNS record add' => ['servicednshosting', 'add', 'manageproducts'],
]);

test('a service module\'s own call declarations keep calls closed to users without the permission', function (): void {
    // The user has products and invoices: viewing the zone is allowed, changing it is not, nor an undeclared call.
    expect(Users::checkCall($this->di, $this->client, 'servicednshosting', 'zone'))->toBeNull()
        ->and(fn () => Users::checkCall($this->di, $this->client, 'servicednshosting', 'del'))
        ->toThrow(InformationException::class, 'You do not have permission to do this.')
        ->and(fn () => Users::checkCall($this->di, $this->client, 'servicednshosting', 'nosuchcall'))
        ->toThrow(InformationException::class, 'You do not have permission to do this.')
        ->and(fn () => Users::checkCall($this->di, $this->client, 'service', 'zone'))
        ->toThrow(InformationException::class, 'You do not have permission to do this.');
});

test('a module\'s declared pages and calls gate authorized users while it is switched on', function (bool $on): void {
    // A module that is not a product type, as read from its manifest; the user has products and invoices.
    $this->di['request_cache'] = new ArrayObject(['module_manifests' => ['metering' => [
        'client_pages' => ['meters' => 'emails', 'meter-help' => null, 'Meter-Report' => 'emails'],
        'client_calls' => ['Report' => 'invoices', 'export' => 'emails'],
    ]]]);
    $this->di['mod_service'] = $this->di->protect(fn (): object => new class($on) {
        public function __construct(private readonly bool $on)
        {
        }

        public function isExtensionActive(string $type, string $id): bool
        {
            return $this->on;
        }
    });

    expect(Users::pageDenied($this->di, '/meters'))->toBe($on ? 'emails' : null)
        ->and(Users::pageDenied($this->di, '/meters/5'))->toBe($on ? 'emails' : null)
        ->and(Users::pageDenied($this->di, '/meter-help'))->toBeNull()
        ->and(Users::pageDenied($this->di, '/meter-report'))->toBe($on ? 'emails' : null)
        ->and(fn () => Users::checkCall($this->di, $this->client, 'metering', 'export'))
        ->toThrow(InformationException::class, 'You do not have permission to do this.');
    if ($on) {
        expect(Users::checkCall($this->di, $this->client, 'metering', 'report'))->toBeNull();
    } else {
        expect(fn () => Users::checkCall($this->di, $this->client, 'metering', 'report'))
            ->toThrow(InformationException::class, 'You do not have permission to do this.');
    }
})->with(['switched on' => [true], 'switched off' => [false]]);

test('the hosting page needs the products permission (declared by the hosting module)', function (): void {
    $this->db->shouldReceive('fetchAssociative')->with(Mockery::type('string'), [5, ...Model_Client::OPEN, 7])->andReturn([
        'email' => 'user@example.com', 'first_name' => 'Test', 'last_name' => 'User',
        'status' => 'active', 'session_version' => 1, 'owner' => 0, 'permissions' => 'invoices',
    ]);

    expect(Users::pageDenied($this->di, '/hosting/12'))->toBe('products')
        ->and(Users::PAGES)->not->toHaveKey('hosting');
});

test('authorized users see the Support PIN but need the profile permission for a new one', function (): void {
    expect(Users::checkCall($this->di, $this->client, 'client', 'support_pin'))->toBeNull()
        ->and(fn () => Users::checkCall($this->di, $this->client, 'client', 'support_pin_new'))
        ->toThrow(InformationException::class, 'Ask the account owner for access.');
});

test('the payment methods pages are the owner\'s, like their saved card calls', function (): void {
    // The user has invoices: the page used to open and fail on its card call with a "page not found" heading.
    expect(Users::pageDenied($this->di, '/payment-methods'))->toBe('owner')
        ->and(Users::pageDenied($this->di, '/payment-methods/paypal'))->toBe('owner')
        ->and(fn () => Users::checkCall($this->di, $this->client, 'invoice', 'card_list'))
        ->toThrow(InformationException::class, 'Ask the account owner for access.');
});

test('the services and email history list pages need their permissions, as their detail pages', function (): void {
    // These paths opened and failed on their first call with a "page not found" heading.
    // The user has products and invoices, not emails.
    expect(Users::pageDenied($this->di, '/emails'))->toBe('emails')
        ->and(Users::PAGES['service'] ?? null)->toBe('products')
        ->and(Users::pageDenied($this->di, '/service/manage/5'))->toBeNull();
});

test('invoice access does not allow a sub-user to list or accept quotes', function (): void {
    expect(fn () => Users::checkCall($this->di, $this->client, 'invoice', 'quote_get_list'))
        ->toThrow(InformationException::class, 'Ask the account owner for access.');
    foreach (['/invoice/quotes', '/quote/' . str_repeat('a', 40), '/quote/' . str_repeat('a', 40) . '/pdf'] as $path) {
        expect(Users::pageDenied($this->di, $path))->toBe('quotes');
    }

    $schema = Mockery::mock(Doctrine\DBAL\Schema\AbstractSchemaManager::class);
    $schema->shouldReceive('listTableNames')->andReturn(array_keys(ClientFence\Mod\Clientfence\Service::tables()));
    $this->db->shouldReceive('createSchemaManager')->andReturn($schema);
    $hash = str_repeat('a', 40);
    $quote = [
        'id' => 1, 'client_id' => 5, 'hash' => $hash, 'stage' => 'delivered', 'taxrate' => 0,
        'valid_until' => null, 'invoice_id' => null,
    ] + array_fill_keys([
        'first_name', 'last_name', 'company', 'email', 'country', 'currency', 'subject', 'proposal',
        'customer_notes', 'admin_notes', 'taxname', 'sent_at', 'accepted_at', 'declined_at', 'created_at', 'updated_at',
    ], '');
    $this->db->shouldReceive('fetchAssociative')->with('SELECT * FROM invoice_quote WHERE hash = ?', [$hash])->andReturn($quote);
    $this->db->shouldReceive('fetchAssociative')->with('SELECT id, first_name, last_name, company, email, country FROM client WHERE id = ?', [5])->andReturn(false);
    $this->db->shouldReceive('fetchAllAssociative')->with('SELECT * FROM invoice_quote_item WHERE quote_id = ? ORDER BY position, id', [1])->andReturn([]);
    $api = new ClientFence\Mod\Invoice\Api\Guest();
    $api->setDi($this->di);

    expect(fn () => $api->quote_accept(['hash' => $hash]))->toThrow(
        fn (InformationException $exception) => expect([$exception->getCode(), $exception->getMessage()])
            ->toBe([403, 'You do not have permission to do this. Ask the account owner for access.'])
    );
});

test('product access does not expose the owner profile through orders', function (string $module, string $method): void {
    $profile = [
        'id' => 5, 'first_name' => 'Owner', 'email' => 'owner@example.com', 'phone' => '1234567',
        'birthday' => '1990-01-01', 'address_1' => 'Private street', 'address_2' => 'Private flat',
        'city' => 'City', 'state' => 'State', 'postcode' => '12345', 'country' => 'US',
    ];
    $order = ['id' => 11, 'title' => 'Hosting', 'client' => $profile];
    $result = match ($module . '/' . $method) {
        'order/get' => $order,
        'order/get_list' => ['list' => [$order], 'total' => 1],
        'order/addons' => [$order],
        'index/get_dashboard' => ['profile' => $profile, 'recent_orders' => [$order]],
    };

    $result = Users::filterResult($this->di, $this->client, $module, $method, $result);
    $filtered = match ($module . '/' . $method) {
        'order/get' => $result,
        'order/get_list' => $result['list'][0],
        'order/addons' => $result[0],
        'index/get_dashboard' => $result['recent_orders'][0],
    };

    expect($filtered)->toBe(['id' => 11, 'title' => 'Hosting', 'client' => ['id' => 5, 'first_name' => 'Owner']]);
})->with([
    'order details' => ['order', 'get'],
    'order list' => ['order', 'get_list'],
    'order addons' => ['order', 'addons'],
    'dashboard orders' => ['index', 'get_dashboard'],
]);
