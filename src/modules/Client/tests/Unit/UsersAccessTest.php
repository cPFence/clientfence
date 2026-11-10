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
    $this->db = Mockery::mock(Connection::class);
    $this->db->shouldReceive('fetchAssociative')->with(Mockery::type('string'), [5, Model_Client::ACTIVE, 7])->byDefault()->andReturn([
        'email' => 'user@example.com', 'first_name' => 'Test', 'last_name' => 'User',
        'status' => 'active', 'session_version' => 1, 'owner' => 0, 'permissions' => 'products,invoices',
    ]);
    $this->di['em']->shouldReceive('getConnection')->andReturn($this->db);
});

afterEach(fn () => Users::forget());

test('authorized users can call product actions with their assigned permission', function (string $module, string $method, string $permission): void {
    $this->db->shouldReceive('fetchAssociative')->with(Mockery::type('string'), [5, Model_Client::ACTIVE, 7])->andReturn([
        'email' => 'user@example.com', 'first_name' => 'Test', 'last_name' => 'User',
        'status' => 'active', 'session_version' => 1, 'owner' => 0, 'permissions' => $permission,
    ]);

    expect(Users::checkCall($this->di, $this->client, $module, $method))->toBeNull();
})->with([
    'API key allowed IPs' => ['serviceapikey', 'update_ips', 'manageproducts'],
    'order options quote' => ['order', 'upgrade_options_quote', 'products'],
    'new Support PIN' => ['client', 'support_pin_new', 'profile'],
]);

test('authorized users see the Support PIN but need the profile permission for a new one', function (): void {
    expect(Users::checkCall($this->di, $this->client, 'client', 'support_pin'))->toBeNull()
        ->and(fn () => Users::checkCall($this->di, $this->client, 'client', 'support_pin_new'))
        ->toThrow(InformationException::class, 'Ask the account owner for access.');
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
