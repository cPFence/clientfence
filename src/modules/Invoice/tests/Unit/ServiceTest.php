<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Client\Service as ClientService;
use ClientFence\Mod\Currency\Entity\Currency as CurrencyEntity;
use ClientFence\Mod\Currency\Repository\CurrencyRepository;
use ClientFence\Mod\Currency\Service as CurrencyService;
use ClientFence\Mod\Email\Service as EmailService;
use ClientFence\Mod\Invoice\Service;
use ClientFence\Mod\Invoice\ServiceInvoiceItem;
use ClientFence\Mod\Invoice\ServicePayGateway;
use ClientFence\Mod\Invoice\ServiceSubscription;
use ClientFence\Mod\Invoice\ServiceTax;
use ClientFence\Mod\Order\Service as OrderService;
use ClientFence\Mod\Product\Entity\Product;
use ClientFence\Mod\Product\Service as ProductService;
use ClientFence\Mod\System\Service as SystemService;

use function Tests\Helpers\container;
use function Tests\Helpers\moduleService;

test('markAsPaid leaves a concurrently paid invoice and its items alone', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->client_id = 7;
    $invoice->status = Model_Invoice::STATUS_UNPAID;
    $item = new Model_InvoiceItem();
    $item->loadBean(new Tests\Helpers\DummyBean());
    $item->status = Model_InvoiceItem::STATUS_EXECUTED;
    $item->charged = true;

    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\MySQLPlatform());
    $dbal->shouldReceive('fetchOne')->once()->with('SELECT GET_LOCK(?, ?)', ['fb:credit_payment:client:7', 10])->ordered()->andReturn(1);
    $dbal->shouldReceive('fetchAssociative')->once()->with('SELECT * FROM invoice WHERE id = ?', [10])->ordered()
        ->andReturn(['id' => 10, 'client_id' => 7, 'status' => Model_Invoice::STATUS_PAID]);
    $dbal->shouldReceive('fetchOne')->once()->with('SELECT RELEASE_LOCK(?)', ['fb:credit_payment:client:7'])->ordered()->andReturn(1);
    $db = Mockery::mock(ClientFence\Database::class);
    $db->shouldNotReceive('find', 'store', 'dispense');
    $itemService = Mockery::mock(ServiceInvoiceItem::class);
    $itemService->shouldNotReceive('markAsPaid', 'creditInvoiceItem', 'executeTask');
    $di = container();
    $di['dbal'] = $dbal;
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(moduleService(['invoice:invoiceitem' => $itemService]));
    $service = new Service();
    $service->setDi($di);

    expect($service->markAsPaid($invoice))->toBeTrue()
        ->and($invoice->status)->toBe(Model_Invoice::STATUS_PAID)
        ->and($item->status)->toBe(Model_InvoiceItem::STATUS_EXECUTED)
        ->and($item->charged)->toBeTruthy()
        ->and($di['events_manager']->events)->toBe([])
        ->and($di['logger']->calls)->toBe([]);
});

test('settlement debits reconcile extended line tax to the invoice total', function (array $lines, array $expected): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    foreach (['id' => 10, 'client_id' => 7, 'currency' => 'USD', 'status' => 'unpaid', 'taxrate' => 20] as $key => $value) {
        $invoice->{$key} = $value;
    }
    $items = [];
    foreach ($lines as $key => [$price, $quantity]) {
        $item = new Model_InvoiceItem();
        $item->loadBean(new Tests\Helpers\DummyBean());
        foreach (['id' => $key + 1, 'invoice_id' => 10, 'type' => Model_InvoiceItem::TYPE_CUSTOM, 'title' => 'Line ' . $key, 'price' => $price, 'quantity' => $quantity, 'taxed' => 1, 'charged' => 0] as $column => $value) {
            $item->{$column} = $value;
        }
        $items[] = $item;
    }
    $debits = [];
    $di = container();
    $db = $di['db'];
    $db->shouldReceive('find')->with('InvoiceItem', Mockery::type('string'), Mockery::type('array'))->andReturn($items);
    $db->shouldReceive('dispense')->with('ClientBalance')->andReturnUsing(function (): Model_ClientBalance {
        $credit = new Model_ClientBalance();
        $credit->loadBean(new Tests\Helpers\DummyBean());

        return $credit;
    });
    $db->shouldReceive('store')->andReturnUsing(function ($model) use (&$debits): int {
        if ($model instanceof Model_ClientBalance) {
            $debits[] = -(float) $model->amount;
        }

        return 1;
    });
    $itemService = new ServiceInvoiceItem();
    $itemService->setDi($di);
    $repository = Mockery::mock(CurrencyRepository::class);
    $repository->shouldReceive('getRateByCode')->with('USD')->andReturn(1.0);
    $currency = Mockery::mock(CurrencyService::class);
    $currency->shouldReceive('getCurrencyRepository')->andReturn($repository);
    $di['mod_service'] = $di->protect(moduleService(['invoice:invoiceitem' => $itemService, 'currency' => $currency]));
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('countIncome', 'addNote');
    $service->setDi($di);
    $total = $service->getTotalWithTax($invoice);

    expect($service->markAsPaid($invoice, true, false, true))->toBeTrue()
        ->and($debits)->toBe($expected)
        ->and(round(array_sum($debits), 2))->toBe(round($total, 2));
})->with([
    'quantity tax' => [[[0.03, 1000]], [36.0]],
    'last line rounding' => [[[0.03, 1], [0.03, 1], [0.03, 1]], [0.04, 0.04, 0.03]],
]);

test('Manage requires Mark as Paid for Add Funds invoices', function (bool $deposit): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->status = Model_Invoice::STATUS_UNPAID;
    $item = new Model_InvoiceItem();
    $item->loadBean(new Tests\Helpers\DummyBean());
    $item->type = $deposit ? Model_InvoiceItem::TYPE_DEPOSIT : Model_InvoiceItem::TYPE_CUSTOM;
    $di = container();
    $di['db']->shouldReceive('find')->with('InvoiceItem', 'invoice_id = ?', [10])->andReturn([$item]);
    $di['db']->shouldNotReceive('dispense');
    $clientService = Mockery::mock(ClientService::class);
    $clientService->shouldNotReceive('addFunds');
    $di['mod_service'] = $di->protect(moduleService(['client' => $clientService]));
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('toApiArray')->andReturn([]);
    if ($deposit) {
        $di['db']->shouldNotReceive('store');
        $service->shouldNotReceive('markAsPaid');
    } else {
        $service->shouldReceive('markAsPaid')->once()->with($invoice, false)->andReturnUsing(function (Model_Invoice $model): bool {
            $model->status = Model_Invoice::STATUS_PAID;

            return true;
        });
    }
    $service->setDi($di);

    if ($deposit) {
        expect(fn () => $service->updateInvoice($invoice, ['status' => 'paid']))
            ->toThrow(ClientFence\InformationException::class, 'Use Mark as Paid to record payment for an Add Funds invoice.')
            ->and($invoice->status)->toBe(Model_Invoice::STATUS_UNPAID);
    } else {
        expect($service->updateInvoice($invoice, ['status' => 'paid']))->toBeTrue()
            ->and($invoice->status)->toBe(Model_Invoice::STATUS_PAID);
    }
})->with(['Add Funds' => true, 'ordinary invoice' => false]);

test('Mark as Paid records and credits only the unpaid deposit remainder', function (float $before, float $remaining): void {
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT)');
    $dbal->executeStatement('CREATE TABLE "transaction" (id INTEGER PRIMARY KEY, invoice_id INTEGER, amount REAL, status TEXT)');
    $dbal->insert('invoice', ['id' => 10, 'status' => 'unpaid']);
    $dbal->insert('"transaction"', ['id' => 1, 'invoice_id' => 10, 'amount' => $before, 'status' => 'processed']);
    $dbal->insert('"transaction"', ['id' => 2, 'invoice_id' => 10, 'amount' => 80, 'status' => 'received']);
    $dbal->insert('"transaction"', ['id' => 3, 'invoice_id' => 99, 'amount' => 90, 'status' => 'processed']);
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    foreach (['id' => 10, 'client_id' => 7, 'gateway_id' => 5, 'currency' => 'USD', 'status' => 'unpaid'] as $key => $value) {
        $invoice->{$key} = $value;
    }
    $gateway = new Model_PayGateway();
    $gateway->loadBean(new Tests\Helpers\DummyBean());
    foreach (['id' => 5, 'enabled' => 1, 'gateway' => 'Custom', 'name' => 'Bank transfer'] as $key => $value) {
        $gateway->{$key} = $value;
    }
    $item = new Model_InvoiceItem();
    $item->loadBean(new Tests\Helpers\DummyBean());
    $item->type = Model_InvoiceItem::TYPE_DEPOSIT;
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 7;
    $transaction = new Model_Transaction();
    $transaction->loadBean(new Tests\Helpers\DummyBean());
    $transaction->invoice_id = 10;
    $di = container();
    $di['dbal'] = $dbal;
    $di['db']->shouldReceive('getExistingModelById')->with('PayGateway', 5, Mockery::any())->andReturn($gateway);
    $di['db']->shouldReceive('getExistingModelById')->with('Client', 7, Mockery::any())->andReturn($client);
    $di['db']->shouldReceive('find')->with('InvoiceItem', 'invoice_id = ?', [10])->andReturn([$item]);
    $transactions = Mockery::mock(ClientFence\Mod\Invoice\ServiceTransaction::class);
    $clientService = Mockery::mock(ClientService::class);
    $balance = $before;
    if ($remaining > 0) {
        $transactions->shouldReceive('create')->once()->andReturn(4);
        $di['db']->shouldReceive('getExistingModelById')->with('Transaction', 4, Mockery::any())->andReturn($transaction);
        $di['db']->shouldReceive('store')->once()->with($transaction)->andReturn(4);
        $clientService->shouldReceive('addFunds')->once()->with($client, $remaining, Mockery::type('string'), ['type' => 'invoice', 'rel_id' => '10'])
            ->andReturnUsing(function ($client, float $amount) use (&$balance): bool {
                $balance += $amount;

                return true;
            });
    } else {
        $transactions->shouldNotReceive('create');
        $clientService->shouldNotReceive('addFunds');
        $di['db']->shouldNotReceive('store');
    }
    $di['mod_service'] = $di->protect(moduleService(['invoice:transaction' => $transactions, 'client' => $clientService]));
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getTotalWithTax')->once()->with($invoice)->andReturn(100.0);
    $service->shouldReceive('markAsPaid')->once()->with($invoice, false)->andReturnUsing(function (Model_Invoice $model): bool {
        $model->status = Model_Invoice::STATUS_PAID;

        return true;
    });
    $service->setDi($di);

    expect($service->markAsPaidByAdmin($invoice, ['transactionId' => 'bank-remainder']))->toBeTrue()
        ->and($balance)->toBe(100.0)
        ->and($invoice->status)->toBe(Model_Invoice::STATUS_PAID);
    if ($remaining > 0) {
        expect($transaction->amount)->toBe(60.0)->and($transaction->status)->toBe(Model_Transaction::STATUS_PROCESSED);
    }
})->with(['partly paid' => [40.0, 60.0], 'fully credited' => [100.0, 0.0]]);

test('gets dependency injection container', function (): void {
    $service = new Service();
    $di = container();
    $service->setDi($di);
    $getDi = $service->getDi();
    expect($getDi)->toBe($di);
});

test('gets search query with various parameters', function (array $data, string $expectedStr, array $expectedParams): void {
    $service = new Service();
    $di = container();

    $service->setDi($di);
    $result = $service->getSearchQuery($data);
    expect($result[0])->toBeString();
    expect($result[1])->toBeArray();

    expect(str_contains((string) $result[0], $expectedStr))->toBeTrue($result[0]);
    expect($result[1])->toMatchArray($expectedParams);
})->with([
    [[], 'FROM invoice p', []],
    [
        ['order_id' => '1'],
        'AND pi.type = :item_type AND pi.rel_id = :order_id',
        [
            'item_type' => Model_InvoiceItem::TYPE_ORDER,
            'order_id' => 1,
        ],
    ],
    [
        ['id' => 1],
        'AND p.id = :id',
        [
            'id' => 1,
        ],
    ],
    [
        ['nr' => 1],
        'AND (p.id = :id_nr OR p.nr = :id_nr)',
        [
            'id_nr' => 1,
        ],
    ],
    [
        ['approved' => true],
        'AND p.approved = :approved',
        [
            'approved' => 1,
        ],
    ],
    [
        ['status' => 'unpaid'],
        'AND p.status = :status',
        [
            'status' => 'unpaid',
        ],
    ],
    [
        ['currency' => 'usd'],
        'AND p.currency = :currency',
        [
            'currency' => 'usd',
        ],
    ],
    [
        ['client_id' => 1],
        'AND p.client_id = :client_id',
        [
            'client_id' => 1,
        ],
    ],
    [
        ['client' => 'John'],
        'AND (cl.first_name LIKE :client_search OR cl.last_name LIKE :client_search OR cl.id = :client OR cl.email = :client)',
        [
            'client_search' => 'John%',
            'client' => 'John',
        ],
    ],
    [
        ['created_at' => '2012-11-23 12:34:56'],
        "AND DATE_FORMAT(p.created_at, '%Y-%m-%d') = :created_at",
        [
            'created_at' => '2012-11-23',
        ],
    ],
    [
        ['date_from' => '2012-11-23 12:34:56'],
        'AND UNIX_TIMESTAMP(p.created_at) >= :date_from',
        [
            'date_from' => 1353674096,
        ],
    ],
    [
        ['date_to' => '2012-11-23 12:34:56'],
        'AND UNIX_TIMESTAMP(p.created_at) <= :date_to',
        [
            'date_to' => 1353674096,
        ],
    ],
    [
        ['paid_at' => '2012-11-23 12:34:56'],
        "AND DATE_FORMAT(p.paid_at, '%Y-%m-%d') = :paid_at",
        [
            'paid_at' => '2012-11-23',
        ],
    ],
    [
        ['search' => 'trend'],
        'AND (p.id = :search_numeric_id OR p.nr LIKE :search_like OR p.id LIKE :search OR pi.title LIKE :search_like)',
        [
            'search' => 'trend',
            'search_like' => '%trend%',
            'search_numeric_id' => 0,
        ],
    ],
    [
        ['search' => 'FOSS00040'],
        "OR pi.title LIKE :search_like OR (COALESCE(p.serie, '') = :search_serie AND p.nr = :search_nr))",
        [
            'search' => 'FOSS00040',
            'search_like' => '%FOSS00040%',
            'search_numeric_id' => 40,
            'search_serie' => 'FOSS',
            'search_nr' => '40',
        ],
    ],
    [
        ['search' => 'QAT1-00028'],
        "OR (COALESCE(p.serie, '') = :search_serie AND p.nr = :search_nr))",
        [
            'search_serie' => 'QAT1-',
            'search_nr' => '28',
        ],
    ],
]);

test('adds aggregated totals only to summary searches', function (): void {
    $service = new Service();
    $service->setDi(container());

    [$fullQuery] = $service->getSearchQuery([]);
    [$summaryQuery] = $service->getSearchQuery(['summary' => 1, 'order_id' => 42]);

    expect($fullQuery)->not->toContain('list_subtotal')
        ->and($summaryQuery)
        ->toContain('invoice_totals.invoice_id = p.id')
        ->toContain('list_subtotal')
        ->toContain('pi.rel_id = :order_id');
});

test('converts to api array', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->hash = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getCompany')
        ->atLeast()->once();
    $systemService->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturn(5);

    $subscriptionServiceMock = Mockery::mock(ServiceSubscription::class);
    $subscriptionServiceMock->shouldReceive('getSubscriptionPeriod')
        ->byDefault()
        ->andReturn('1W');
    $invoiceItemServiceMock = Mockery::mock(ServiceInvoiceItem::class);

    $modelToArrayResult = [
        'id' => 1,
        'serie' => 'BB',
        'nr' => '0001',
        'serie_nr' => 'BB0001',
        'hash' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        'gateway_id' => '',
        'taxname' => '',
        'taxrate' => '',
        'currency' => '',
        'status' => '',
        'notes' => '',
        'text_1' => '',
        'text_2' => '',
        'due_at' => '',
        'created_at' => '',
        'updated_at' => '',
        'buyer_first_name' => '',
        'buyer_last_name' => '',
        'buyer_company' => '',
        'buyer_company_vat' => '',
        'buyer_company_number' => '',
        'buyer_address' => '',
        'buyer_city' => '',
        'buyer_state' => '',
        'buyer_country' => '',
        'buyer_phone' => '',
        'buyer_email' => '',
        'buyer_zip' => '',
        'seller_company_vat' => '',
        'seller_company_number' => '',
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('toArray')
        ->atLeast()->once()
        ->andReturn($modelToArrayResult);
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$invoiceItemModel]);
    $dbMock->shouldReceive('getCell')
        ->byDefault()
        ->andReturn('1W');
    $subscriptionServiceMock->shouldReceive('getSubscriptionPeriod')
        ->byDefault()
        ->andReturn('1W');

    $periodMock = Mockery::mock('ClientFence\Period');
    $periodMock->shouldReceive('getUnit');
    $periodMock->shouldReceive('getQty');

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function ($serviceName, $sub = '') use ($systemService, $subscriptionServiceMock, $invoiceItemServiceMock) {
        $service = null;
        if ($sub == 'InvoiceItem') {
            $service = $invoiceItemServiceMock;
        }
        if (is_string($serviceName) && strtolower($serviceName) === 'system') {
            $service = $systemService;
        }
        if ($sub === 'Subscription') {
            $service = $subscriptionServiceMock;
        }

        return $service;
    });
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);

    $service->setDi($di);

    $result = $service->toApiArray($invoiceModel);
    expect($result)->toBeArray();
    expect($result['currency_rate'])->toBe(1);
    expect($result['paid_at'])->toBeNull();
    expect($result['buyer']['phone_cc'])->toBe('');
});

test('converts an aggregated invoice row to an api summary', function (): void {
    $service = new Service();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->once()
        ->with('invoice_number_padding')
        ->andReturn('5');

    $di = container();
    $di['db'] = static function (): never {
        throw new RuntimeException('Summary conversion must not access the database');
    };
    $di['mod_service'] = $di->protect(moduleService(['system' => $systemService]));
    $service->setDi($di);

    $result = $service->toApiSummaryArray([
        'id' => 42,
        'serie' => 'INV-',
        'nr' => '42',
        'client_id' => 7,
        'currency' => 'USD',
        'taxrate' => '20',
        'list_subtotal' => '25.00',
        'list_taxable_subtotal' => '10.00',
        'status' => 'unpaid',
        'due_at' => '2026-08-01 00:00:00',
        'paid_at' => null,
        'created_at' => '2026-07-19 00:00:00',
        'updated_at' => '2026-07-19 00:00:00',
        'buyer_first_name' => 'Ada',
        'buyer_last_name' => 'Lovelace',
        'buyer_email' => 'ada@example.com',
        'approved' => 1,
    ]);

    expect($result)
        ->toMatchArray([
            'id' => 42,
            'serie_nr' => 'INV-00042',
            'client' => ['id' => 7],
            'subtotal' => 25.0,
            'tax' => 2.0,
            'total' => 27.0,
            'buyer' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.com',
            ],
        ]);
});

test('guest invoice access requires staff invoice view when public hash access is off', function (bool $view, int $expected): void {
    $script = '<?php $view = ' . var_export($view, true) . ';' . <<<'PHP'
require 'src/vendor/autoload.php';
require 'tests/Helpers/Container.php';
require 'tests/Helpers/DummyBean.php';
define('DEBUG', false);
Mockery::mock('alias:ClientFence\Environment')->shouldReceive('isCLI')->andReturn(false);
Mockery::mock('alias:ClientFence\Config')->shouldReceive('getProperty')->andReturn(false);
$di = Tests\Helpers\container();
$di['auth'] = Mockery::mock(ClientFence\Authorization::class)
    ->shouldReceive('isAdminLoggedIn')->andReturn(true)
    ->shouldReceive('isClientLoggedIn')->andReturn(false)->getMock();
$admin = new Model_Admin();
$di['loggedin_admin'] = $admin;
$staff = Mockery::mock(ClientFence\Mod\Staff\Service::class)
    ->shouldReceive('hasPermission')->with($admin, 'invoice', 'view')->andReturn($view)->getMock();
$system = Mockery::mock(ClientFence\Mod\System\Service::class)
    ->shouldReceive('getParamValue')->with('invoice_accessible_from_hash', '0')->andReturn('0')->getMock();
$di['mod_service'] = $di->protect(static fn (string $name) => $name === 'staff' ? $staff : $system);
$invoice = new Model_Invoice();
$invoice->loadBean(new Tests\Helpers\DummyBean());
$invoice->client_id = 5;
$service = new ClientFence\Mod\Invoice\Service();
$service->setDi($di);
try {
    $service->checkInvoiceAuth($invoice);
    echo 200;
} catch (ClientFence\InformationException $exception) {
    echo $exception->getCode();
}
Mockery::close();
PHP;
    $process = new Symfony\Component\Process\PhpProcess($script, dirname(PATH_ROOT));
    $process->mustRun();

    expect((int) $process->getOutput())->toBe($expected);
})->with([
    'staff without invoice view' => [false, 403],
    'staff with invoice view' => [true, 200],
]);

test('checkInvoiceAuth applies invoice permissions to authorized users for reads and payments', function (?array $context, string $hashAccess, bool $expired, array $expected, bool $revoke = false): void {
    $script = '<?php $case = ' . var_export(compact('context', 'hashAccess', 'expired', 'revoke'), true) . ';' . <<<'PHP'
require 'src/vendor/autoload.php';
require 'tests/Helpers/Container.php';
require 'tests/Helpers/DummyBean.php';
define('DEBUG', false);
Mockery::mock('alias:ClientFence\Environment')->shouldReceive('isCLI')->andReturn(false);
Mockery::mock('alias:ClientFence\Config')->shouldReceive('getProperty')->andReturn(false);
$di = Tests\Helpers\container();
$di['auth'] = Mockery::mock(ClientFence\Authorization::class)
    ->shouldReceive('isAdminLoggedIn')->andReturn(false)
    ->shouldReceive('isClientLoggedIn')->andReturn(true)->getMock();
$client = new Model_Client();
$client->loadBean(new Tests\Helpers\DummyBean());
$client->id = 5;
$di['loggedin_client'] = $client;
$di['session'] = Mockery::mock(ClientFence\Session::class)
    ->shouldReceive('get')->with(ClientFence\Mod\Client\Users::SESSION)->andReturn($case['context'] === null ? null : ['user_id' => 7, 'client_id' => 5, 'version' => 1])
    ->shouldReceive('get')->with('client_id')->andReturn(5)->getMock();
$db = Mockery::mock(Doctrine\DBAL\Connection::class);
$db->shouldReceive('fetchAssociative')->with(Mockery::type('string'), [5, Model_Client::ACTIVE, 7])->andReturnUsing(function () use (&$case): array {
    return ['status' => 'active', 'session_version' => 1, 'owner' => $case['context']['owner'], 'permissions' => implode(',', $case['context']['permissions']), 'email' => 'user@example.com', 'first_name' => 'Test', 'last_name' => 'User'];
});
$di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldReceive('getConnection')->andReturn($db)->getMock();
$system = Mockery::mock(ClientFence\Mod\System\Service::class)
    ->shouldReceive('getParamValue')->with('invoice_accessible_from_hash', '0')->andReturn($case['hashAccess'])->getMock();
$di['mod_service'] = $di->protect(fn (string $name) => $system);
$invoice = new Model_Invoice();
$invoice->loadBean(new Tests\Helpers\DummyBean());
$invoice->client_id = 5;
$invoice->hash_expires_at = $case['expired'] ? '2000-01-01 00:00:00' : null;
$service = new ClientFence\Mod\Invoice\Service();
$service->setDi($di);
$results = [];
foreach ($case['revoke'] ? [false, true] : [false] as $revoked) {
    if ($revoked) {
        $case['context']['permissions'] = ['tickets'];
        ClientFence\Mod\Client\Users::forget();
    }
    foreach (ClientFence\Mod\Invoice\InvoiceOperation::cases() as $operation) {
        try {
            $service->checkInvoiceAuth($invoice, $operation);
            $results[] = 200;
        } catch (ClientFence\InformationException $exception) {
            $results[] = $exception->getCode();
        }
    }
}
Mockery::close();
echo json_encode($results, JSON_THROW_ON_ERROR);
PHP;
    $process = new Symfony\Component\Process\PhpProcess($script, dirname(PATH_ROOT));
    $process->mustRun();
    expect(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))->toBe($expected);
})->with([
    'restricted user with public hash access off' => [['owner' => false, 'permissions' => ['tickets']], '0', false, [403, 403]],
    'user with invoice permission' => [['owner' => false, 'permissions' => ['invoices']], '0', false, [200, 200]],
    'owner session' => [null, '0', false, [200, 200]],
    'owner link without an explicit invoice permission' => [['owner' => true, 'permissions' => []], '0', false, [200, 200]],
    'restricted user with public hash access on' => [['owner' => false, 'permissions' => ['tickets']], '1', false, [200, 200]],
    'restricted user cannot use an expired public link' => [['owner' => false, 'permissions' => ['tickets']], '1', true, [403, 403]],
    'owner session still uses an expired link' => [null, '0', true, [200, 200]],
    'permitted user still uses an expired link' => [['owner' => false, 'permissions' => ['invoices']], '0', true, [200, 200]],
    'invoice permission revocation applies on the next request' => [['owner' => false, 'permissions' => ['invoices']], '0', false, [200, 200, 403, 403], true],
]);

test('ensure valid hash is a no-op for modern hashes', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->hash = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('store');

    $di = container();
    $di['db'] = $dbMock;

    $service->setDi($di);
    $service->ensureValidHash($invoiceModel);

    expect($invoiceModel->hash)->toBe('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
});

test('ensure valid hash regenerates a missing hash', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_hash_lifetime_days', '90')
        ->andReturn('90');

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->once()
        ->with($invoiceModel);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($systemService) {
        if ($serviceName === 'system') {
            return $systemService;
        }

        return null;
    });

    $service->setDi($di);
    $service->ensureValidHash($invoiceModel);

    expect($invoiceModel->hash)->toBeString();
    expect(strlen($invoiceModel->hash))->toBeGreaterThanOrEqual(30);
    expect(strlen($invoiceModel->hash))->toBeLessThanOrEqual(60);
    expect($invoiceModel->hash)->toMatch('/^[a-f0-9]+$/');
    expect($invoiceModel->hash_expires_at)->toBeString();
});

test('ensure valid hash regenerates a legacy format hash', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->hash = 'AAAAAAAAC4C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8C8';

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_hash_lifetime_days', '90')
        ->andReturn('90');

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->once()
        ->with($invoiceModel);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($systemService) {
        if ($serviceName === 'system') {
            return $systemService;
        }

        return null;
    });

    $service->setDi($di);
    $service->ensureValidHash($invoiceModel);

    expect(strlen($invoiceModel->hash))->toBeGreaterThanOrEqual(30);
    expect(strlen($invoiceModel->hash))->toBeLessThanOrEqual(60);
    expect($invoiceModel->hash)->toMatch('/^[a-f0-9]+$/');
});

test('to api array self-heals invoice with missing hash', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getCompany')->atLeast()->once();
    $systemService->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturnUsing(function (string $param, string $default = '') {
            if ($param === 'invoice_hash_lifetime_days') {
                return '90';
            }

            return $default;
        });

    $subscriptionServiceMock = Mockery::mock(ServiceSubscription::class);
    $subscriptionServiceMock->shouldReceive('getSubscriptionPeriod')
        ->byDefault()
        ->andReturn('1W');
    $invoiceItemServiceMock = Mockery::mock(ServiceInvoiceItem::class);

    $modelToArrayResult = [
        'id' => 1,
        'serie' => 'BB',
        'nr' => '0001',
        'serie_nr' => 'BB0001',
        'hash' => null,
        'gateway_id' => '',
        'taxname' => '',
        'taxrate' => '',
        'currency' => '',
        'status' => '',
        'notes' => '',
        'text_1' => '',
        'text_2' => '',
        'due_at' => '',
        'created_at' => '',
        'updated_at' => '',
        'buyer_first_name' => '',
        'buyer_last_name' => '',
        'buyer_company' => '',
        'buyer_company_vat' => '',
        'buyer_company_number' => '',
        'buyer_address' => '',
        'buyer_city' => '',
        'buyer_state' => '',
        'buyer_country' => '',
        'buyer_phone' => '',
        'buyer_email' => '',
        'buyer_zip' => '',
        'seller_company_vat' => '',
        'seller_company_number' => '',
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('toArray')
        ->atLeast()->once()
        ->andReturnUsing(function () use ($invoiceModel, $modelToArrayResult): array {
            $modelToArrayResult['hash'] = $invoiceModel->hash;

            return $modelToArrayResult;
        });
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$invoiceItemModel]);
    $dbMock->shouldReceive('getCell')
        ->byDefault()
        ->andReturn('1W');
    $dbMock->shouldReceive('store')
        ->once()
        ->with($invoiceModel);

    $periodMock = Mockery::mock('ClientFence\Period');
    $periodMock->shouldReceive('getUnit');
    $periodMock->shouldReceive('getQty');

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function ($serviceName, $sub = '') use ($systemService, $subscriptionServiceMock, $invoiceItemServiceMock) {
        $service = null;
        if ($sub === 'InvoiceItem') {
            $service = $invoiceItemServiceMock;
        }
        if ($serviceName === 'system' || $serviceName === 'System') {
            $service = $systemService;
        }
        if ($sub === 'Subscription') {
            $service = $subscriptionServiceMock;
        }

        return $service;
    });
    $di['period'] = $di->protect(fn (): Mockery\MockInterface => $periodMock);

    $service->setDi($di);

    $result = $service->toApiArray($invoiceModel);

    expect($result)->toBeArray();
    expect($result['hash'])->toBeString();
    expect(strlen((string) $result['hash']))->toBeGreaterThanOrEqual(30);
    expect(strlen((string) $result['hash']))->toBeLessThanOrEqual(60);
});

test('handles after admin invoice payment received event', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $arr = [
        'total' => 1,
        'client' => [
            'id' => 0,
        ],
    ];
    $serviceMock->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn($arr);
    $serviceMock->shouldReceive('getInvoicePdfAttachment')
        ->atLeast()->once()
        ->andReturn(null);

    $eventMock = Mockery::mock('ClientFence\Event');
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once();

    $emailService = Mockery::mock(EmailService::class);
    $emailService->shouldReceive('sendTemplate')
        ->atLeast()->once();

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($invoiceModel);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName, $sub = '') use ($emailService, $serviceMock) {
        if ($serviceName === 'invoice') {
            return $serviceMock;
        }
        if ($serviceName === 'email') {
            return $emailService;
        }
    });
    $di['db'] = $dbMock;

    $service->setDi($di);
    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $result = $service->onAfterAdminInvoicePaymentReceived($eventMock);
    expect($result)->toBeBool()->toBeTrue();
});

test('handles after admin invoice reminder sent event', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $arr = [
        'total' => 1,
        'client' => [
            'id' => 1,
        ],
    ];
    $serviceMock->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn($arr);
    $serviceMock->shouldReceive('getInvoicePdfAttachment')
        ->atLeast()->once()
        ->andReturn(null);

    $eventMock = Mockery::mock('ClientFence\Event');
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once();

    $emailService = Mockery::mock(EmailService::class);
    $emailService->shouldReceive('sendTemplate')
        ->atLeast()->once();

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($invoiceModel);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName, $sub = '') use ($emailService, $serviceMock) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'system' || $serviceName == 'System') {
            $systemService = Mockery::mock(SystemService::class);
            $systemService->shouldReceive('getParamValue')
                ->with('invoice_hash_lifetime_days', '90')
                ->andReturn('90');

            return $systemService;
        }
        if ($serviceName == 'email' || $serviceName == 'Email') {
            return $emailService;
        }
    });
    $di['db'] = $dbMock;

    $service->setDi($di);
    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $service->onAfterAdminInvoiceReminderSent($eventMock);
});

test('handles after admin cron run event', function (): void {
    $eventMock = Mockery::mock('ClientFence\Event');

    $remove_after_days = 64;
    $systemServiceMock = Mockery::mock(SystemService::class);
    $systemServiceMock->shouldReceive('getParamValue')
        ->with('remove_after_days')
        ->atLeast()->once()
        ->andReturn($remove_after_days);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $invoiceServiceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $invoiceServiceMock->shouldReceive('findUnpaidOlderThan')
        ->with($remove_after_days)
        ->once()
        ->andReturn([$invoiceModel]);
    $invoiceServiceMock->shouldReceive('rmInvoice')
        ->once()
        ->with($invoiceModel)
        ->andReturn(true);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService([
        'system' => $systemServiceMock,
        'invoice' => $invoiceServiceMock,
    ]));

    $invoiceServiceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    Service::onAfterAdminCronRun($eventMock);
});

test('uses the client billing email for invoice notifications', function (): void {
    $service = new Service();
    $email = ['to_client' => 42, 'code' => 'mod_invoice_created'];
    $invoice = ['client' => ['billing_email' => ' billing@example.com ']];

    expect($service->withBillingRecipient($email, $invoice))->toBe([
        'to_client' => 42,
        'code' => 'mod_invoice_created',
        'client_billing_email' => 'billing@example.com',
    ]);
    expect($service->withBillingRecipient($email, ['client' => ['billing_email' => null]]))->toBe($email);
    expect($service->withBillingRecipient($email, ['client' => ['billing_email' => 'not-an-email']]))->toBe($email);
    expect($service->withBillingRecipient($email, ['client' => []]))->toBe($email);
});

test('handles event after invoice is due', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $arr = [
        'total' => 1,
        'client' => [
            'id' => 1,
        ],
    ];
    $serviceMock->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn($arr);
    $serviceMock->shouldReceive('getInvoicePdfAttachment')
        ->atLeast()->once()
        ->andReturn(null);

    $eventMock = Mockery::mock('ClientFence\Event');
    $params = ['days_passed' => 5, 'id' => 1];
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn($params);

    $emailService = Mockery::mock(EmailService::class);
    $emailService->shouldReceive('sendTemplate')
        ->atLeast()->once();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_after_due_days', '5')
        ->andReturn('1, 5, 7');

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('exec')
        ->once()
        ->with(Mockery::type('string'), [':id' => 1])
        ->andReturn(1);
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($invoiceModel);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailService, $serviceMock, $systemService) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'email') {
            return $emailService;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
    });
    $di['db'] = $dbMock;

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);
    $serviceMock->onEventAfterInvoiceIsDue($eventMock);
});

test('skips overdue invoice reminder when the invoice was already claimed', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('toApiArray')
        ->never();
    $serviceMock->shouldReceive('getInvoicePdfAttachment')
        ->never();

    $eventMock = Mockery::mock('ClientFence\Event');
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['days_passed' => 5, 'id' => 1]);

    $emailService = Mockery::mock(EmailService::class);
    $emailService->shouldReceive('sendTemplate')
        ->never();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_after_due_days', '5')
        ->andReturn('1, 5, 7');

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('exec')
        ->once()
        ->with(Mockery::type('string'), [':id' => 1])
        ->andReturn(0);
    $dbMock->shouldReceive('load')
        ->never();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailService, $serviceMock, $systemService) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'email') {
            return $emailService;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
    });
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $serviceMock->onEventAfterInvoiceIsDue($eventMock);
});

test('releases the claim when sending the overdue invoice email fails', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $arr = [
        'total' => 1,
        'client' => [
            'id' => 1,
        ],
    ];
    $serviceMock->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn($arr);
    $serviceMock->shouldReceive('getInvoicePdfAttachment')
        ->atLeast()->once()
        ->andReturn(null);

    $eventMock = Mockery::mock('ClientFence\Event');
    $params = ['days_passed' => 5, 'id' => 1];
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn($params);

    $emailService = Mockery::mock(EmailService::class);
    $emailService->shouldReceive('sendTemplate')
        ->atLeast()->once()
        ->andThrow(new Exception('SMTP timeout'));

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_after_due_days', '5')
        ->andReturn('1, 5, 7');

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('exec')
        ->once()
        ->with(Mockery::type('string'), [':id' => 1])
        ->andReturn(1);
    $dbMock->shouldReceive('exec')
        ->once()
        ->with('UPDATE invoice SET reminded_at = NULL WHERE id = :id', [':id' => 1]);
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($invoiceModel);

    $logger = new Tests\Helpers\TestLogger();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailService, $serviceMock, $systemService) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'email') {
            return $emailService;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
    });
    $di['db'] = $dbMock;
    $di['logger'] = $logger;

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);
    $serviceMock->onEventAfterInvoiceIsDue($eventMock);

    expect($logger->calls)->not->toBeEmpty();
});

test('releases the overdue reminder claim when invoice client data is unavailable', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('toApiArray')
        ->once()
        ->andReturn(['total' => 1, 'client' => null]);
    $serviceMock->shouldReceive('getInvoicePdfAttachment')
        ->never();

    $eventMock = Mockery::mock('ClientFence\Event');
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['days_passed' => 5, 'id' => 1]);

    $emailService = Mockery::mock(EmailService::class);
    $emailService->shouldReceive('sendTemplate')
        ->never();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_after_due_days', '5')
        ->andReturn('1, 5, 7');

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('exec')
        ->once()
        ->with(Mockery::type('string'), [':id' => 1])
        ->andReturn(1);
    $dbMock->shouldReceive('exec')
        ->once()
        ->with('UPDATE invoice SET reminded_at = NULL WHERE id = :id', [':id' => 1]);
    $dbMock->shouldReceive('load')
        ->once()
        ->with('Invoice', 1)
        ->andReturn($invoiceModel);

    $logger = new Tests\Helpers\TestLogger();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($emailService, $serviceMock, $systemService) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'email') {
            return $emailService;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
    });
    $di['db'] = $dbMock;
    $di['logger'] = $logger;

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);
    $serviceMock->onEventAfterInvoiceIsDue($eventMock);

    expect($logger->calls)->not->toBeEmpty();
});

test('handles event before invoice is due', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('sendInvoiceReminder')
        ->once()
        ->andReturnTrue();

    $eventMock = Mockery::mock('ClientFence\Event');
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['days_left' => 7, 'id' => 1]);

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_before_due_days', '')
        ->andReturn('14, 7, 1');

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('exec')
        ->once()
        ->with(Mockery::type('string'), [':id' => 1])
        ->andReturn(1);
    $dbMock->shouldReceive('load')
        ->once()
        ->with('Invoice', 1)
        ->andReturn($invoiceModel);

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($serviceMock, $systemService) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
    });
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $service->onEventBeforeInvoiceIsDue($eventMock);
});

test('releases the claim when sending the before-due invoice reminder fails', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('sendInvoiceReminder')
        ->once()
        ->andThrow(new Exception('DB write failed'));

    $eventMock = Mockery::mock('ClientFence\Event');
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['days_left' => 7, 'id' => 1]);

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_before_due_days', '')
        ->andReturn('14, 7, 1');

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('exec')
        ->once()
        ->with(Mockery::type('string'), [':id' => 1])
        ->andReturn(1);
    $dbMock->shouldReceive('exec')
        ->once()
        ->with('UPDATE invoice SET reminded_at = NULL WHERE id = :id', [':id' => 1]);
    $dbMock->shouldReceive('load')
        ->once()
        ->with('Invoice', 1)
        ->andReturn($invoiceModel);

    $logger = new Tests\Helpers\TestLogger();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($serviceMock, $systemService) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
    });
    $di['db'] = $dbMock;
    $di['logger'] = $logger;

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $service->onEventBeforeInvoiceIsDue($eventMock);

    expect($logger->calls)->not->toBeEmpty();
});

test('skips before due invoice reminder when the invoice was already claimed', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('sendInvoiceReminder')
        ->never();

    $eventMock = Mockery::mock('ClientFence\Event');
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['days_left' => 7, 'id' => 1]);

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_before_due_days', '')
        ->andReturn('14, 7, 1');

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('exec')
        ->once()
        ->with(Mockery::type('string'), [':id' => 1])
        ->andReturn(0);
    $dbMock->shouldReceive('load')
        ->never();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($serviceMock, $systemService) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
    });
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $service->onEventBeforeInvoiceIsDue($eventMock);
});

test('skips before due invoice reminder when interval does not match', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('sendInvoiceReminder')
        ->never();

    $eventMock = Mockery::mock('ClientFence\Event');
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['days_left' => 3, 'id' => 1]);

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_before_due_days', '')
        ->andReturn('14, 7, 1');

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->never();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($serviceMock, $systemService) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
    });
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $service->onEventBeforeInvoiceIsDue($eventMock);
});

test('skips before due invoice reminder when intervals are blank', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('sendInvoiceReminder')
        ->never();

    $eventMock = Mockery::mock('ClientFence\Event');
    $eventMock->shouldReceive('getParameters')
        ->atLeast()->once()
        ->andReturn(['days_left' => 7, 'id' => 1]);

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_before_due_days', '')
        ->andReturn('');

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->never();

    $di = container();
    $di['mod_service'] = $di->protect(function ($serviceName) use ($serviceMock, $systemService) {
        if ($serviceName == 'invoice') {
            return $serviceMock;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
    });
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $eventMock->shouldReceive('getDi')
        ->atLeast()->once()
        ->andReturn($di);

    $service->onEventBeforeInvoiceIsDue($eventMock);
});

test('marks invoice as paid', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('countIncome')
        ->atLeast()->once();

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());

    $itemInvoiceServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $itemInvoiceServiceMock->shouldReceive('markAsPaid')
        ->atLeast()->once();
    $itemInvoiceServiceMock->shouldReceive('executeTask')
        ->atLeast()->once();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->atLeast()->once();

    $currencyRepositoryMock = Mockery::mock(CurrencyRepository::class)->shouldIgnoreMissing();
    $currencyRepositoryMock->shouldReceive('getRateByCode')
        ->atLeast()->once()
        ->andReturn(1.0);

    $currencyServiceMock = Mockery::mock(CurrencyService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $currencyServiceMock->shouldReceive('getCurrencyRepository')
        ->atLeast()->once()
        ->andReturn($currencyRepositoryMock);

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->atLeast()->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$invoiceItemModel]);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $productServiceMock = Mockery::mock(ProductService::class)->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(function ($serviceName, $sub = '') use ($systemService, $itemInvoiceServiceMock, $currencyServiceMock, $productServiceMock) {
        if ($serviceName == 'system') {
            return $systemService;
        }
        if ($sub == 'InvoiceItem') {
            return $itemInvoiceServiceMock;
        }
        if ($serviceName == 'currency') {
            return $currencyServiceMock;
        }
        if (strtolower($serviceName) == 'product') {
            return $productServiceMock;
        }
    });
    $di['db'] = $dbMock;
    $di['events_manager'] = $eventManagerMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $result = $serviceMock->markAsPaid($invoiceModel, false);
    expect($result)->toBeBool()->toBeTrue();
});

test('admin mark as paid requires a transaction to finish an ordinary invoice with recorded payments', function (float $credited): void {
    $calls = $credited > 0 ? 0 : 1;
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('markAsPaid')
        ->times($calls)
        ->with(Mockery::type(Model_Invoice::class), false)
        ->andReturn(true);
    $serviceMock->shouldReceive('getTotalWithTax')
        ->once()
        ->with(Mockery::type(Model_Invoice::class))
        ->andReturn(42.50);
    $serviceMock->shouldReceive('isInvoiceTypeDeposit')
        ->once()
        ->with(Mockery::type(Model_Invoice::class))
        ->andReturn(false);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = 10;
    $invoiceModel->gateway_id = 5;
    $invoiceModel->currency = 'USD';
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $gatewayModel = new Model_PayGateway();
    $gatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $gatewayModel->id = 5;
    $gatewayModel->gateway = 'Custom';
    $gatewayModel->enabled = 1;
    $gatewayModel->name = 'Manual payment'; // ClientFence: pay_gateway has no title column; name is the label

    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());
    $transactionModel->invoice_id = 10;

    $transactionServiceMock = Mockery::mock(ClientFence\Mod\Invoice\ServiceTransaction::class);
    $transactionServiceMock->shouldReceive('create')
        ->times($calls)
        ->with(Mockery::on(fn (array $data): bool => $data['invoice_id'] === 10
            && $data['gateway_id'] === 5
            && $data['currency'] === 'USD'
            && $data['source'] === 'admin'
            && $data['post'] === ['invoice_id' => 10, 'txn_id' => 'manual-reference-1']
            && $data['txn_id'] === 'manual-reference-1'))
        ->andReturn(20);
    $transactionServiceMock->shouldNotReceive('processTransaction');

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('PayGateway', 5, 'Payment gateway not found')
        ->andReturn($gatewayModel);
    $dbMock->shouldReceive('getExistingModelById')
        ->times($calls)
        ->with('Transaction', 20, 'Transaction not found')
        ->andReturn($transactionModel);
    $dbMock->shouldReceive('store')
        ->times($calls)
        ->with($transactionModel)
        ->andReturn(20);

    $di = container();
    $di['db'] = $dbMock;
    $di['dbal']->shouldReceive('fetchOne')->once()
        ->with('SELECT COALESCE(SUM(amount), 0) FROM `transaction` WHERE invoice_id = ? AND status = ?', [10, Model_Transaction::STATUS_PROCESSED])
        ->andReturn($credited);
    $di['mod_service'] = $di->protect(moduleService([
        'invoice:transaction' => $transactionServiceMock,
    ]));

    $serviceMock->setDi($di);

    if ($credited > 0) {
        expect(fn () => $serviceMock->markAsPaidByAdmin($invoiceModel, ['transactionId' => 'manual-reference-1']))
            ->toThrow(ClientFence\InformationException::class, 'This invoice already has recorded payments. Record the remaining transfer as a transaction instead.')
            ->and($invoiceModel->status)->toBe(Model_Invoice::STATUS_UNPAID);

        return;
    }

    $result = $serviceMock->markAsPaidByAdmin($invoiceModel, [
        'execute' => false,
        'transactionId' => 'manual-reference-1',
    ]);

    expect($result)->toBeTrue()
        ->and($transactionModel->amount)->toBe(42.50)
        ->and($transactionModel->currency)->toBe('USD')
        ->and($transactionModel->status)->toBe(Model_Transaction::STATUS_PROCESSED)
        ->and($transactionModel->note)->toBe('Manual payment transaction No: manual-reference-1');
})->with(['no recorded payments' => 0.0, 'processed partial payment' => 20.0]);

test('admin mark as paid credits client balance for a deposit invoice', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('markAsPaid')
        ->once()
        ->with(Mockery::type(Model_Invoice::class), false)
        ->andReturn(true);
    $serviceMock->shouldReceive('getTotalWithTax')
        ->once()
        ->with(Mockery::type(Model_Invoice::class))
        ->andReturn(25.00);
    $serviceMock->shouldReceive('isInvoiceTypeDeposit')
        ->once()
        ->with(Mockery::type(Model_Invoice::class))
        ->andReturn(true);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = 11;
    $invoiceModel->client_id = 7;
    $invoiceModel->gateway_id = 5;
    $invoiceModel->currency = 'USD';
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $gatewayModel = new Model_PayGateway();
    $gatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $gatewayModel->id = 5;
    $gatewayModel->gateway = 'Custom';
    $gatewayModel->enabled = 1;
    $gatewayModel->name = 'Manual payment'; // ClientFence: pay_gateway has no title column; name is the label

    $depositItem = new Model_InvoiceItem();
    $depositItem->loadBean(new Tests\Helpers\DummyBean());
    $depositItem->type = Model_InvoiceItem::TYPE_DEPOSIT;

    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());
    $clientModel->id = 7;

    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());
    $transactionModel->invoice_id = 11;

    $transactionServiceMock = Mockery::mock(ClientFence\Mod\Invoice\ServiceTransaction::class);
    $transactionServiceMock->shouldReceive('create')
        ->once()
        ->andReturn(21);

    $clientServiceMock = Mockery::mock(ClientService::class);
    $clientServiceMock->shouldReceive('addFunds')
        ->once()
        ->with($clientModel, 25.00, 'Manual payment transaction No: ref-9', ['type' => 'invoice', 'rel_id' => '11'])
        ->andReturn(true);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('PayGateway', 5, 'Payment gateway not found')
        ->andReturn($gatewayModel);
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('Transaction', 21, 'Transaction not found')
        ->andReturn($transactionModel);
    $dbMock->shouldReceive('find')
        ->once()
        ->with('InvoiceItem', 'invoice_id = ?', [11])
        ->andReturn([$depositItem]);
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('Client', 7, 'Client not found')
        ->andReturn($clientModel);
    $dbMock->shouldReceive('store')
        ->once()
        ->with($transactionModel)
        ->andReturn(21);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService([
        'invoice:transaction' => $transactionServiceMock,
        'client' => $clientServiceMock,
    ]));

    $serviceMock->setDi($di);

    $result = $serviceMock->markAsPaidByAdmin($invoiceModel, [
        'execute' => true,
        'transactionId' => 'ref-9',
    ]);

    expect($result)->toBeTrue();
});

test('admin mark as paid with custom gateway rejects transaction linked to another invoice', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldNotReceive('markAsPaid');
    $serviceMock->shouldReceive('isInvoiceTypeDeposit')->once()->andReturn(false);
    $serviceMock->shouldReceive('getTotalWithTax')
        ->once()
        ->with(Mockery::type(Model_Invoice::class))
        ->andReturn(42.50);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = 10;
    $invoiceModel->gateway_id = 5;
    $invoiceModel->currency = 'USD';
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $gatewayModel = new Model_PayGateway();
    $gatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $gatewayModel->id = 5;
    $gatewayModel->gateway = 'Custom';
    $gatewayModel->enabled = 1;

    $transactionModel = new Model_Transaction();
    $transactionModel->loadBean(new Tests\Helpers\DummyBean());
    $transactionModel->invoice_id = 99;

    $transactionServiceMock = Mockery::mock(ClientFence\Mod\Invoice\ServiceTransaction::class);
    $transactionServiceMock->shouldReceive('create')
        ->once()
        ->andReturn(20);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('PayGateway', 5, 'Payment gateway not found')
        ->andReturn($gatewayModel);
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('Transaction', 20, 'Transaction not found')
        ->andReturn($transactionModel);
    $dbMock->shouldNotReceive('store');

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(moduleService([
        'invoice:transaction' => $transactionServiceMock,
    ]));

    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->markAsPaidByAdmin($invoiceModel, [
        'transactionId' => 'manual-reference-1',
    ]))->toThrow(ClientFence\InformationException::class, 'Transaction ID is already associated with another invoice.');
});

test('validateAdminMarkAsPaidRequest requires a gateway when neither request nor invoice has one', function (): void {
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('getExistingModelById');

    $di = container();
    $di['db'] = $dbMock;

    $service = new Service();
    $service->setDi($di);

    expect(fn () => $service->validateAdminMarkAsPaidRequest([], $invoiceModel))
        ->toThrow(ClientFence\InformationException::class, 'Payment gateway is required when marking an invoice as paid.');
});

test('validateAdminMarkAsPaidRequest rejects a disabled gateway', function (): void {
    $gatewayModel = new Model_PayGateway();
    $gatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $gatewayModel->id = 5;
    $gatewayModel->gateway = 'Stripe';
    $gatewayModel->enabled = 0;

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('PayGateway', 5, 'Payment gateway not found')
        ->andReturn($gatewayModel);

    $di = container();
    $di['db'] = $dbMock;

    $service = new Service();
    $service->setDi($di);

    expect(fn () => $service->validateAdminMarkAsPaidRequest(['gateway_id' => 5], $invoiceModel))
        ->toThrow(ClientFence\InformationException::class, 'Payment gateway is not enabled');
});

test('validateAdminMarkAsPaidRequest accepts an explicit gateway for a gateway-less invoice', function (): void {
    $gatewayModel = new Model_PayGateway();
    $gatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $gatewayModel->id = 7;
    $gatewayModel->gateway = 'Stripe';
    $gatewayModel->enabled = 1;

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->once()
        ->with('PayGateway', 7, 'Payment gateway not found')
        ->andReturn($gatewayModel);

    $di = container();
    $di['db'] = $dbMock;

    $service = new Service();
    $service->setDi($di);

    expect($service->validateAdminMarkAsPaidRequest(['gateway_id' => 7], $invoiceModel))->toBe($gatewayModel);
});

test('counts income', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->currency = 'USD';
    $invoiceModel->refund = 0;

    $currencyService = Mockery::mock(CurrencyService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $currencyService->shouldReceive('toBaseCurrency')
        ->atLeast()->once()
        ->andReturn(0.0);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([]);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $currencyService);

    $serviceMock->setDi($di);
    $serviceMock->countIncome($invoiceModel);
});

test('money received before the invoice came here counts as income', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    // 50 billed, 20 of it paid in WHMCS (a "received" line of -20): 30 due, 50 income.
    $serviceMock->shouldReceive('getTotal')->andReturn(30.0);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = 16;
    $invoiceModel->currency = 'USD';
    $invoiceModel->refund = null;

    $received = new Model_InvoiceItem();
    $received->loadBean(new Tests\Helpers\DummyBean());
    $received->price = -20.0;
    $received->quantity = 1;

    $currencyService = Mockery::mock(CurrencyService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $currencyService->shouldReceive('toBaseCurrency')->once()->with('USD', 50.0)->andReturn(50.0);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')->with('InvoiceItem', 'invoice_id = ? AND unit = ?', [16, ClientFence\Mod\Clientfence\Whmcs\Money::RECEIVED_UNIT])->andReturn([$received]);
    $dbMock->shouldReceive('store')->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $currencyService);

    $serviceMock->setDi($di);
    $serviceMock->countIncome($invoiceModel);
    expect($invoiceModel->base_income)->toBe(50.0);
});

test('prepares invoice with undefined currency', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('setInvoiceDefaults')
        ->atLeast()->once();

    $data = [
        'gateway_id' => '',
        'text_1' => '',
        'text_2' => '',
        'items' => [
            [
                'id' => 1,
            ],
        ],
        'approve',
    ];

    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $currencyModel = Mockery::mock(CurrencyEntity::class);
    $defaultCurrencyCode = 'USD';
    $currencyModel->shouldReceive('getCode')
        ->andReturn($defaultCurrencyCode);

    $currencyRepositoryMock = Mockery::mock(CurrencyRepository::class)->shouldIgnoreMissing();
    $currencyRepositoryMock->shouldReceive('findDefault')
        ->atLeast()->once()
        ->andReturn($currencyModel);

    $currencyServiceMock = Mockery::mock(CurrencyService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $currencyServiceMock->shouldReceive('getCurrencyRepository')
        ->atLeast()->once()
        ->andReturn($currencyRepositoryMock);

    $itemInvoiceServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $itemInvoiceServiceMock->shouldReceive('addNew')
        ->atLeast()->once();

    $newRecordId = 1;
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn($newRecordId);
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($invoiceModel);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function ($serviceName, $sub = '') use ($currencyServiceMock, $itemInvoiceServiceMock) {
        if ($serviceName == 'currency' || $serviceName == 'Currency') {
            return $currencyServiceMock;
        }
        if ($sub == 'InvoiceItem') {
            return $itemInvoiceServiceMock;
        }
    });
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $result = $serviceMock->prepareInvoice($clientModel, $data);
    expect($result)->toBeInstanceOf(Model_Invoice::class);
    expect($result->currency)->toBe($defaultCurrencyCode);
});

test('sets invoice defaults', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $buyer = [
        'first_name' => '',
        'last_name' => '',
        'company' => '',
        'company_vat' => '',
        'company_number' => '',
        'address_1' => '',
        'address_2' => '',
        'city' => '',
        'state' => '',
        'country' => '',
        'phone_cc' => '',
        'phone' => '',
        'email' => '',
        'postcode' => '',
    ];

    $clientService = Mockery::mock(ClientService::class);
    $clientService->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn($buyer);

    $systemService = Mockery::mock(SystemService::class);
    $seller = [
        'name' => '',
        'vat_number' => '',
        'number' => '',
        'address_1' => '',
        'address_2' => '',
        'address_3' => '',
        'tel' => '',
        'email' => '',
    ];
    $systemService->shouldReceive('getCompany')
        ->atLeast()->once()
        ->andReturn($seller);
    $systemService->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturn(1);
    $systemService->shouldReceive('reserveNextNumericParamValue')
        ->once()
        ->with('invoice_starting_number')
        ->andReturn(1);
    // Only reached by the fallback path now that the counter is claimed atomically.
    $systemService->shouldReceive('setParamValue')
        ->zeroOrMoreTimes();

    $serviceTaxMock = Mockery::mock(ServiceTax::class);
    $serviceTaxMock->shouldReceive('getTaxRateForClient');

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($clientModel);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function ($serviceName, $sub = '') use ($clientService, $systemService, $serviceTaxMock) {
        if ($serviceName == 'Client') {
            return $clientService;
        }
        if ($serviceName == 'system') {
            return $systemService;
        }
        if ($sub == 'Tax') {
            return $serviceTaxMock;
        }
    });

    $service->setDi($di);

    $service->setInvoiceDefaults($invoiceModel);
});

test('approves an invoice', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tryPayWithCredits')
        ->atLeast()->once();
    $serviceMock->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn(['id' => 1]);

    $data['use_credits'] = true;

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->atLeast()->once();

    // QA r6 M-08: only approved and updated_at are written, never the whole (possibly stale) invoice.
    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbalMock->shouldReceive('executeStatement')
        ->once()
        ->with('UPDATE invoice SET approved = 1, updated_at = ? WHERE id = ?', Mockery::any());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('store');

    $di = container();
    $di['db'] = $dbMock;
    $di['dbal'] = $dbalMock;
    $di['events_manager'] = $eventManagerMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);

    $result = $serviceMock->approveInvoice($invoiceModel, $data);
    expect($result)->toBeTrue();
});

test('account credit never pays an Add funds invoice', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->client_id = 20;
    $invoice->approved = 1;
    $invoice->status = Model_Invoice::STATUS_UNPAID;

    $dbalMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    expectCreditPaymentLock($dbalMock, 20, Model_Invoice::STATUS_UNPAID);
    $dbalMock->shouldReceive('fetchOne')
        ->with('SELECT 1 FROM invoice_item WHERE invoice_id = ? AND type = ? LIMIT 1', [10, Model_InvoiceItem::TYPE_DEPOSIT])
        ->once()
        ->andReturn(1);
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('load');
    $dbMock->shouldNotReceive('store');

    $di = container();
    $di['db'] = $dbMock;
    $di['dbal'] = $dbalMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service = new Service();
    $service->setDi($di);

    expect($service->tryPayWithCredits($invoice))->toBeFalse();
});

test('gets total with tax', function (): void {
    $service = new Service();
    $total = 10.0;
    $tax = 2.2;
    $expected = $total + $tax;
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getTotal')
        ->once()
        ->andReturn($total);
    $serviceMock->shouldReceive('getTax')
        ->once()
        ->andReturn($tax);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $result = $serviceMock->getTotalWithTax($invoiceModel);
    expect($result)->toBeFloat();
    expect($result)->toBe($expected);
});

function expectCreditPaymentLock(Mockery\MockInterface $dbalMock, int $clientId, string $status, array $row = []): void
{
    $lockName = 'fb:credit_payment:client:' . $clientId;
    $dbalMock->shouldReceive('fetchOne')
        ->once()
        ->with('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => $lockName])
        ->andReturn(1);
    $dbalMock->shouldReceive('fetchAssociative')
        ->once()
        ->with('SELECT * FROM invoice WHERE id = :id', Mockery::any())
        ->andReturn(['status' => $status] + $row);
    $dbalMock->shouldReceive('fetchOne')
        ->once()
        ->with('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lockName])
        ->andReturn(1);
    // Not an Add funds invoice (QA r6 M-02: credit never pays one).
    $dbalMock->shouldReceive('fetchOne')
        ->with('SELECT 1 FROM invoice_item WHERE invoice_id = ? AND type = ? LIMIT 1', Mockery::any())
        ->byDefault()
        ->andReturn(false);
    // The debit and the paid status are written in one transaction.
    $dbalMock->shouldReceive('transactional')->byDefault()->andReturnUsing(fn (callable $work) => $work($dbalMock));
}

test('pays a zero-total invoice without recording a balance transaction', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->client_id = 20;
    $invoice->approved = 1;
    $invoice->status = Model_Invoice::STATUS_UNPAID;

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 20;

    $balanceService = Mockery::mock(ClientFence\Mod\Client\ServiceBalance::class);
    $balanceService->shouldReceive('getClientBalance')->once()->with($client)->andReturn(0.0);

    $dbalMock = Mockery::mock();
    // QA r7 X-02: a staff save that took the lock first set 19%; the payment charges the invoice as it is now.
    $invoice->taxrate = '0';
    expectCreditPaymentLock($dbalMock, 20, Model_Invoice::STATUS_UNPAID, ['taxrate' => '19']);

    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('load')->once()->with('Client', 20)->andReturn($client);
    $db->shouldReceive('find')->once()->with('InvoiceItem', 'invoice_id = ?', [10])->andReturn([]);
    $db->shouldNotReceive('dispense');
    $db->shouldNotReceive('store');

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getTotalWithTax')->once()->with($invoice)->andReturn(0.0);
    $service->shouldReceive('markAsPaid')->once()->with($invoice, false, false, true)->andReturn(true);

    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbalMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $balanceService);
    $service->setDi($di);

    expect($service->tryPayWithCredits($invoice))->toBeTrue()
        ->and($invoice->taxrate)->toBe('19');
});

test('records a balance transaction for a one-cent invoice', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->client_id = 20;
    $invoice->approved = 1;
    $invoice->status = Model_Invoice::STATUS_UNPAID;

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 20;

    $balanceService = Mockery::mock(ClientFence\Mod\Client\ServiceBalance::class);
    $balanceService->shouldReceive('getClientBalance')->once()->with($client)->andReturn(0.01);

    $dbalMock = Mockery::mock();
    expectCreditPaymentLock($dbalMock, 20, Model_Invoice::STATUS_UNPAID);

    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('load')->once()->with('Client', 20)->andReturn($client);
    $db->shouldReceive('find')->once()->with('InvoiceItem', 'invoice_id = ?', [10])->andReturn([]);
    $balanceTransaction = new Model_ClientBalance();
    $balanceTransaction->loadBean(new Tests\Helpers\DummyBean());
    $db->shouldReceive('dispense')->once()->with('ClientBalance')->andReturn($balanceTransaction);
    $db->shouldReceive('store')->once();

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getTotalWithTax')->once()->with($invoice)->andReturn(0.01);
    $service->shouldReceive('markAsPaid')->once()->with($invoice, false, false, true)->andReturn(true);

    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbalMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $balanceService);
    $service->setDi($di);

    expect($service->tryPayWithCredits($invoice))->toBeTrue();
});

test('pays a fully funded invoice despite floating-point rounding dust', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->client_id = 20;
    $invoice->approved = 1;
    $invoice->status = Model_Invoice::STATUS_UNPAID;

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 20;

    $balanceService = Mockery::mock(ClientFence\Mod\Client\ServiceBalance::class);
    // 0.30 and 0.1 * 3 are equal at two-decimal scale but not as raw floats.
    $balanceService->shouldReceive('getClientBalance')->once()->with($client)->andReturn(0.30);

    $dbalMock = Mockery::mock();
    expectCreditPaymentLock($dbalMock, 20, Model_Invoice::STATUS_UNPAID);

    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('load')->once()->with('Client', 20)->andReturn($client);
    $db->shouldReceive('find')->once()->with('InvoiceItem', 'invoice_id = ?', [10])->andReturn([]);
    $balanceTransaction = new Model_ClientBalance();
    $balanceTransaction->loadBean(new Tests\Helpers\DummyBean());
    $db->shouldReceive('dispense')->once()->with('ClientBalance')->andReturn($balanceTransaction);
    $db->shouldReceive('store')->once();

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getTotalWithTax')->once()->with($invoice)->andReturn(0.1 * 3);
    $service->shouldReceive('markAsPaid')->once()->with($invoice, false, false, true)->andReturn(true);

    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbalMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $balanceService);
    $service->setDi($di);

    expect($service->tryPayWithCredits($invoice))->toBeTrue();
});

test('pays an invoice with credits and records a balance transaction', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->client_id = 20;
    $invoice->approved = 1;
    $invoice->status = Model_Invoice::STATUS_UNPAID;

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 20;

    $balanceService = Mockery::mock(ClientFence\Mod\Client\ServiceBalance::class);
    $balanceService->shouldReceive('getClientBalance')->once()->with($client)->andReturn(100.0);

    $dbalMock = Mockery::mock();
    expectCreditPaymentLock($dbalMock, 20, Model_Invoice::STATUS_UNPAID);

    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('load')->once()->with('Client', 20)->andReturn($client);
    $db->shouldReceive('find')->once()->with('InvoiceItem', 'invoice_id = ?', [10])->andReturn([]);
    $balanceTransaction = new Model_ClientBalance();
    $balanceTransaction->loadBean(new Tests\Helpers\DummyBean());
    $db->shouldReceive('dispense')->once()->with('ClientBalance')->andReturn($balanceTransaction);
    $db->shouldReceive('store')->once();

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getTotalWithTax')->once()->with($invoice)->andReturn(50.0);
    $service->shouldReceive('markAsPaid')->once()->with($invoice, false, false, true)->andReturn(true);

    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbalMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $balanceService);
    $service->setDi($di);

    expect($service->tryPayWithCredits($invoice))->toBeTrue();
});

test('does not pay an invoice from credit in another currency', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->client_id = 20;
    $invoice->currency = 'USD';
    $invoice->approved = 1;
    $invoice->status = Model_Invoice::STATUS_UNPAID;

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 20;
    $client->currency = 'EGP';

    $dbalMock = Mockery::mock();
    expectCreditPaymentLock($dbalMock, 20, Model_Invoice::STATUS_UNPAID);
    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('load')->once()->with('Client', 20)->andReturn($client);
    $db->shouldNotReceive('dispense');

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldNotReceive('markAsPaid');
    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbalMock;
    $service->setDi($di);

    expect($service->tryPayWithCredits($invoice))->toBeFalse();
});

test('does not deduct credits when the invoice was paid, refunded or canceled before the lock was acquired', function (string $status): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->client_id = 20;
    $invoice->approved = 1;
    $invoice->status = Model_Invoice::STATUS_UNPAID;

    $dbalMock = Mockery::mock();
    // Stand in for another request having paid this invoice while we waited on the lock.
    expectCreditPaymentLock($dbalMock, 20, $status);

    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldNotReceive('load');
    $db->shouldNotReceive('dispense');
    $db->shouldNotReceive('store');

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldNotReceive('getTotalWithTax');
    $service->shouldNotReceive('markAsPaid');

    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbalMock;
    $service->setDi($di);

    expect($service->tryPayWithCredits($invoice))->toBeFalse();
})->with([Model_Invoice::STATUS_PAID, Model_Invoice::STATUS_REFUNDED, Model_Invoice::STATUS_CANCELED]);

test('does not deduct credits when the locked balance is insufficient', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 10;
    $invoice->client_id = 20;
    $invoice->approved = 1;
    $invoice->status = Model_Invoice::STATUS_UNPAID;

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 20;

    $balanceService = Mockery::mock(ClientFence\Mod\Client\ServiceBalance::class);
    // Another request spent the credit first, so the locked read sees the reduced balance.
    $balanceService->shouldReceive('getClientBalance')->once()->with($client)->andReturn(10.0);

    $dbalMock = Mockery::mock();
    expectCreditPaymentLock($dbalMock, 20, Model_Invoice::STATUS_UNPAID);

    $db = Mockery::mock(\ClientFence\Database::class);
    $db->shouldReceive('load')->once()->with('Client', 20)->andReturn($client);
    $db->shouldNotReceive('dispense');
    $db->shouldNotReceive('store');

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('getTotalWithTax')->once()->with($invoice)->andReturn(50.0);
    $service->shouldNotReceive('markAsPaid');

    $di = container();
    $di['db'] = $db;
    $di['dbal'] = $dbalMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $balanceService);
    $service->setDi($di);

    expect($service->tryPayWithCredits($invoice))->toBeFalse();
});

test('gets total', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$invoiceItemModel]);

    $itemInvoiceServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $itemTotal = 10.0;
    $itemInvoiceServiceMock->shouldReceive('getTotal')
        ->atLeast()->once()
        ->andReturn($itemTotal);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $itemInvoiceServiceMock);

    $service->setDi($di);
    $result = $service->getTotal($invoiceModel);
    expect($result)->toBeFloat();
    expect($result)->toBe($itemTotal);
});

test('refunds invoice with negative invoice logic', function (): void {
    $service = new Service();
    $newId = 1;
    $total = 10.0;
    $tax = 2.2;
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getTotal')
        ->once()
        ->andReturn($total);
    $serviceMock->shouldReceive('getTax')
        ->once()
        ->andReturn($tax);
    $serviceMock->shouldReceive('countIncome')
        ->once();
    $serviceMock->shouldReceive('addNote')
        ->times(3);
    $serviceMock->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn(['id' => $newId]);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = $newId;
    $invoiceModel->status = Model_Invoice::STATUS_PAID;

    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->atLeast()->once();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturn('negative_invoice');
    $nextNumber = 1;
    $systemService->shouldReceive('reserveNextNumericParamValue')->with('invoice_starting_number')
        ->andReturnUsing(function () use (&$nextNumber): int {
            return $nextNumber++;
        });

    $dbMock = Mockery::mock('ClientFence\Database');
    $dispenseCount = 0;
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturnUsing(function () use (&$dispenseCount, $invoiceModel, $invoiceItemModel) {
            return ++$dispenseCount === 1 ? $invoiceModel : $invoiceItemModel;
        });
    $dbMock->shouldReceive('store')
        ->atLeast()->once();
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$invoiceItemModel]);
    $dbMock->shouldReceive('getAll')->andReturn([]);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $systemService);
    $di['events_manager'] = $eventManagerMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $result = $serviceMock->refundInvoice($invoiceModel, 'customNote');
    expect($result)->toBeInt()->toBe($newId)
        ->and($invoiceModel->nr)->toBe(1)
        ->and($serviceMock->getNextInvoiceNumber())->toBe(2);
});

// ClientFence (QA r4 F-01): the refund of an invoice of a downgraded service takes the downgrade's credit back, once.
test('a refund takes back the downgrade credit given from the invoice, or refuses when it was spent', function (float $balance, ?string $error): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 7;
    $invoice->client_id = 5;
    $invoice->currency = 'USD';
    $invoice->serie = 'INV-';
    $invoice->nr = 7;
    $invoice->status = Model_Invoice::STATUS_PAID;
    $invoice->paid_at = '2026-09-01 10:00:00';
    $invoice->taxrate = 19;
    $creditNote = new Model_Invoice();
    $creditNote->loadBean(new Tests\Helpers\DummyBean());
    $creditNote->id = 8;
    $new = new Model_Invoice();
    $new->loadBean(new Tests\Helpers\DummyBean());
    $new->id = 9;
    $balances = [];

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getTotalWithTax')->with($invoice)->andReturn(71.40);
    $serviceMock->shouldReceive('getTotalWithTax')->with($creditNote)->andReturn(-47.60);
    $serviceMock->shouldReceive('countIncome', 'addNote');
    $serviceMock->shouldReceive('toApiArray')->andReturn([]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')->with(Mockery::type('string'), [7, 7])->andReturn([['id' => '2'], ['id' => 2]]);
    $dbMock->shouldReceive('getAll')->with(Mockery::type('string'), [7, 'order', '2', 'hook_call'])->andReturn([['type' => 'order', 'rel_id' => '2', 'price' => 60, 'quantity' => 1, 'taxed' => 1]]);
    // Only the credit notes of the period this invoice paid: from its payment to the next paid invoice of the order.
    $dbMock->shouldReceive('getCell')->once()->with(Mockery::type('string'), ['2', 7, '2026-09-01 10:00:00', 2, 7, '2026-09-01 10:00:00'])->andReturn('2026-10-01 10:00:00');
    // QA r10 P-02: none was given between the invoice's issue and its payment.
    $dbMock->shouldReceive('getAll')->with(Mockery::pattern('/cn.created_at >= \? AND cn.created_at < \?/'), [Service::CREDIT_NOTE_TASK, '2', '', '2026-09-01 10:00:00'])->andReturn([]);
    $dbMock->shouldReceive('getAll')->with(Mockery::pattern('/cn.created_at >= \? AND cn.created_at < \?/'), [Service::CREDIT_NOTE_TASK, '2', '2026-09-01 10:00:00', '2026-10-01 10:00:00'])->andReturn([['id' => 8]]);
    $dbMock->shouldReceive('getAll')->with(Mockery::pattern('/cn.created_at >= \?/'), [Service::CREDIT_NOTE_TASK, '2', '2026-10-01 10:00:00'])->andReturn([]);
    $marked = [];
    $dbMock->shouldReceive('exec')->andReturnUsing(function (string $sql, array $values) use (&$marked) {
        $marked[] = [$sql, $values];

        return 1;
    });
    $dbMock->shouldReceive('load')->with('Invoice', 8)->andReturn($creditNote);
    $dbMock->shouldReceive('getExistingModelById')->with('Client', 5, Mockery::any())->andReturn(new Model_Client());
    $dbMock->shouldReceive('find')->andReturn([]);
    $dbMock->shouldReceive('dispense')->andReturnUsing(function (string $type) use ($new) {
        if ($type === 'Invoice') {
            return $new;
        }
        $bean = new Model_ClientBalance();
        $bean->loadBean(new Tests\Helpers\DummyBean());

        return $bean;
    });
    $dbMock->shouldReceive('store')->andReturnUsing(function ($bean) use (&$balances) {
        if ($bean instanceof Model_ClientBalance) {
            $balances[] = [$bean->amount, $bean->description];
        }

        return 1;
    });

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')->andReturn('negative_invoice');
    $systemService->shouldReceive('reserveNextNumericParamValue')->with('invoice_starting_number')->andReturn(1);
    $balanceService = Mockery::mock(ClientFence\Mod\Client\ServiceBalance::class);
    $balanceService->shouldReceive('getClientBalance')->andReturn($balance);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = '') => $name === 'Client' ? $balanceService : $systemService);
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldReceive('fire')->getMock();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    if ($error !== null) {
        expect(fn () => $serviceMock->refundInvoice($invoice))->toThrow(ClientFence\InformationException::class, $error);
        expect($invoice->status)->toBe(Model_Invoice::STATUS_PAID)->and($balances)->toBe([])->and($marked)->toBe([]);

        return;
    }
    expect($serviceMock->refundInvoice($invoice))->toBe(9)
        ->and($invoice->status)->toBe(Model_Invoice::STATUS_REFUNDED)
        ->and($balances)->toBe([[-47.6, 'Downgrade credit taken back with the refund of invoice INV-7']])
        ->and($marked)->toBe([['UPDATE invoice_item SET task = ? WHERE task = ? AND invoice_id IN (8)', [Service::CREDIT_NOTE_TAKEN_BACK_TASK, Service::CREDIT_NOTE_TASK]]]);
})->with([
    'credit still there' => [50.0, null],
    'credit spent' => [20.0, 'A downgrade already gave 47.60 USD of this invoice back as account credit'],
]);

test('a refund leaves uncertain downgrade credit to staff and keeps fully funded later credit', function (float $total, ?float $laterTotal, string $laterStatus, string $laterType, bool $takenBack, bool $refused, float $expectedTakeBack, ?float $priorRefundTotal = null, float $unrelated = 0.0, float $taxRate = 0.0, string $unrelatedType = 'custom'): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT, paid_at TEXT, created_at TEXT, taxrate REAL DEFAULT 0)');
    $db->executeStatement('CREATE TABLE invoice_item (invoice_id INTEGER, type TEXT, task TEXT, rel_id TEXT, price REAL, quantity INTEGER DEFAULT 1, taxed INTEGER DEFAULT 1)');
    $db->executeStatement('CREATE TABLE ' . ClientFence\Mod\Order\Upgrades::TABLE . ' (id INTEGER PRIMARY KEY, order_id INTEGER, invoice_id INTEGER)');
    $db->insert('invoice', ['id' => 7, 'status' => 'paid', 'paid_at' => '2026-10-01 10:00:00']);
    $db->insert('invoice_item', ['invoice_id' => 7, 'type' => 'order', 'rel_id' => '2']);
    $totals = [7 => $total, 8 => -7.10, 10 => -7.10];
    foreach ([8, 10] as $id) {
        $db->insert('invoice', ['id' => $id, 'status' => 'refunded', 'created_at' => '2026-10-02 10:00:00']);
        $db->insert('invoice_item', ['invoice_id' => $id, 'type' => 'custom', 'task' => $takenBack ? Service::CREDIT_NOTE_TAKEN_BACK_TASK : Service::CREDIT_NOTE_TASK, 'rel_id' => '2']);
    }
    if ($laterTotal !== null) {
        $totals[9] = $laterTotal;
        $db->insert('invoice', ['id' => 9, 'status' => $laterStatus, 'paid_at' => '2026-10-02 10:00:00']);
        if ($laterType !== 'upgrade') {
            $db->insert('invoice_item', ['invoice_id' => 9, 'type' => 'order', 'task' => 'renew', 'rel_id' => '2']);
            $db->insert('invoice_item', ['invoice_id' => 9, 'type' => 'order', 'task' => 'renew', 'rel_id' => '2']);
        }
        if ($laterType !== 'order') {
            $db->insert(ClientFence\Mod\Order\Upgrades::TABLE, ['order_id' => 2, 'invoice_id' => 9]);
        }
    }
    if ($priorRefundTotal !== null) {
        $totals[11] = $priorRefundTotal;
        $db->insert('invoice', ['id' => 11, 'status' => 'refunded', 'paid_at' => '2026-10-03 10:00:00']);
        $db->insert('invoice_item', ['invoice_id' => 11, 'type' => 'order', 'rel_id' => '2']);
        $db->update('invoice', ['created_at' => '2026-10-15 10:00:00'], ['id' => 8]);
        $db->update('invoice', ['created_at' => '2026-10-15 10:00:00'], ['id' => 10]);
    }

    foreach ($totals as $id => $amount) {
        $count = (int) $db->fetchOne('SELECT COUNT(*) FROM invoice_item WHERE invoice_id = ?', [$id]);
        if ($count > 0) {
            $db->update('invoice_item', ['price' => $amount / $count], ['invoice_id' => $id]);
        } else {
            $upgradeId = (int) $db->fetchOne('SELECT id FROM ' . ClientFence\Mod\Order\Upgrades::TABLE . ' WHERE invoice_id = ?', [$id]);
            $db->insert('invoice_item', ['invoice_id' => $id, 'type' => 'hook_call', 'rel_id' => json_encode(['upgrade_id' => $upgradeId]), 'price' => $amount]);
        }
        $db->update('invoice', ['taxrate' => $taxRate], ['id' => $id]);
        $totals[$id] = $amount + round($amount * $taxRate / 100, 2);
    }
    if ($unrelated > 0) {
        $id = $laterTotal === null ? 7 : 9;
        $relId = $unrelatedType === 'order' ? '3' : null;
        if ($unrelatedType === 'hook_call') {
            $db->insert(ClientFence\Mod\Order\Upgrades::TABLE, ['id' => 90, 'order_id' => 3, 'invoice_id' => $id]);
            $relId = json_encode(['upgrade_id' => 90]);
        }
        $db->insert('invoice_item', ['invoice_id' => $id, 'type' => $unrelatedType, 'rel_id' => $relId, 'price' => $unrelated]);
        $totals[$id] += $unrelated + round($unrelated * $taxRate / 100, 2);
    }

    $models = [];
    foreach ($db->fetchAllAssociative('SELECT * FROM invoice') as $row) {
        $model = new Model_Invoice();
        $model->loadBean(new Tests\Helpers\DummyBean());
        foreach ($row as $key => $value) {
            $model->{$key} = $value;
        }
        $models[(int) $model->id] = $model;
    }
    $invoice = $models[7];
    $invoice->client_id = 5;
    $invoice->currency = 'USD';
    $new = new Model_Invoice();
    $new->loadBean(new Tests\Helpers\DummyBean());
    $new->id = 99;
    $balances = [];

    $dbMock = Mockery::mock(ClientFence\Database::class);
    $dbMock->shouldReceive('getAll')->andReturnUsing(fn (string $sql, array $params): array => $db->fetchAllAssociative($sql, $params));
    $dbMock->shouldReceive('getCell')->andReturnUsing(fn (string $sql, array $params): mixed => $db->fetchOne($sql, $params));
    $dbMock->shouldReceive('load')->andReturnUsing(fn (string $type, int $id): Model_Invoice => $models[$id]);
    if ($refused) {
        $dbMock->shouldNotReceive('store', 'dispense', 'exec');
    } else {
        $dbMock->shouldReceive('getExistingModelById')->with('Client', 5, Mockery::any())->andReturn(new Model_Client());
        $dbMock->shouldReceive('find')->andReturn([]);
        $dbMock->shouldReceive('dispense')->andReturnUsing(function (string $type) use ($new) {
            if ($type === 'Invoice') {
                return $new;
            }
            $bean = new Model_ClientBalance();
            $bean->loadBean(new Tests\Helpers\DummyBean());

            return $bean;
        });
        $dbMock->shouldReceive('store')->andReturnUsing(function ($bean) use (&$balances): int {
            if ($bean instanceof Model_ClientBalance) {
                $balances[] = $bean->amount;
            }

            return 1;
        });
        $dbMock->shouldReceive('exec')->andReturnUsing(fn (string $sql, array $params): int => $db->executeStatement($sql, $params));
    }
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getTotalWithTax')->andReturnUsing(fn (Model_Invoice $model): float => $totals[(int) $model->id]);
    $service->shouldReceive('toApiArray')->andReturn([]);
    $service->shouldReceive('countIncome', 'addNote');
    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')->andReturn('negative_invoice');
    $systemService->shouldReceive('reserveNextNumericParamValue')->with('invoice_starting_number')->andReturn(1);
    $balanceService = Mockery::mock(ClientFence\Mod\Client\ServiceBalance::class);
    $balanceService->shouldReceive('getClientBalance')->andReturn(100.0);
    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = '') => $name === 'Client' ? $balanceService : $systemService);
    $service->setDi($di);

    if ($refused) {
        expect(fn () => $service->refundInvoice($invoice))->toThrow(ClientFence\InformationException::class, 'Refund this invoice by hand.');
        expect($invoice->status)->toBe(Model_Invoice::STATUS_PAID)->and($balances)->toBe([]);
    } else {
        expect($service->refundInvoice($invoice))->toBe(99)
            ->and($invoice->status)->toBe(Model_Invoice::STATUS_REFUNDED)
            ->and($balances)->toBe($expectedTakeBack > 0 ? [-$expectedTakeBack] : []);
    }
    $expectedTask = $takenBack || $expectedTakeBack > 0 ? Service::CREDIT_NOTE_TAKEN_BACK_TASK : Service::CREDIT_NOTE_TASK;
    expect($db->fetchFirstColumn('SELECT task FROM invoice_item WHERE invoice_id IN (8, 10)'))->toBe([$expectedTask, $expectedTask]);
})->with([
    'in-window credit exceeds the invoice total' => [1.30, null, 'paid', 'order', false, true, 0.0],
    'later option payment cannot fund all later credit' => [30.0, 1.30, 'paid', 'upgrade', false, true, 0.0],
    'later paid upgrade fully funds its credit' => [30.0, 89.90, 'paid', 'upgrade', false, false, 0.0],
    'later renewal exactly funds its credit' => [30.0, 14.20, 'paid', 'order', false, false, 0.0],
    'later refunded upgrade still counts' => [30.0, 14.20, 'refunded', 'upgrade', false, false, 0.0],
    'later credit already taken back is ignored' => [30.0, 1.30, 'paid', 'upgrade', true, false, 0.0],
    'an invoice in both sets is counted only once' => [30.0, 7.10, 'paid', 'both', false, true, 0.0],
    'in-window credit equal to the total is taken back' => [14.20, null, 'paid', 'order', false, false, 14.20],
    'a prior refund invoice does not reduce later payment funding' => [30.0, 30.0, 'paid', 'order', false, false, 0.0, -30.0],
    'a zero-total later invoice adds no payment funding' => [30.0, 14.20, 'paid', 'order', false, false, 0.0, 0.0],
    'mixed original invoice cannot fund in-window credit' => [1.30, null, 'paid', 'order', false, true, 0.0, null, 50.0],
    'mixed later upgrade cannot fund later credit' => [30.0, 1.30, 'paid', 'upgrade', false, true, 0.0, null, 50.0],
    'taxed mixed original invoice cannot fund in-window credit' => [1.30, null, 'paid', 'order', false, true, 0.0, null, 50.0, 19.0],
    'taxed mixed later upgrade cannot fund later credit' => [30.0, 1.30, 'paid', 'upgrade', false, true, 0.0, null, 50.0, 19.0],
    'another order line cannot fund later credit' => [30.0, 1.30, 'paid', 'upgrade', false, true, 0.0, null, 50.0, 0.0, 'order'],
    'another orders upgrade line cannot fund later credit' => [30.0, 1.30, 'paid', 'upgrade', false, true, 0.0, null, 50.0, 0.0, 'hook_call'],
    'taxed service funding is sufficient including its tax' => [30.0, 14.20, 'paid', 'upgrade', false, false, 0.0, null, 50.0, 19.0],
]);

// ClientFence (QA r10 D-03, D-04): a credit is funded only by payments made before it, and an invoice with no payment
// date cannot tell which credit it funded.
test('a refund leaves a downgrade credit to staff when no earlier payment funded it or the payment date is empty', function (string $paidAt, ?string $renewalPaidAt, bool $refused): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT, paid_at TEXT, created_at TEXT, taxrate REAL DEFAULT 0)');
    $db->executeStatement('CREATE TABLE invoice_item (invoice_id INTEGER, type TEXT, task TEXT, rel_id TEXT, price REAL, quantity INTEGER DEFAULT 1, taxed INTEGER DEFAULT 0)');
    $db->executeStatement('CREATE TABLE ' . ClientFence\Mod\Order\Upgrades::TABLE . ' (id INTEGER PRIMARY KEY, order_id INTEGER, invoice_id INTEGER)');
    // I1 (Pro 30), then an option upgrade U1 (1.30), then a downgrade credit note of 14.20.
    $totals = [7 => 30.0, 9 => 1.30, 8 => -14.20];
    $db->insert('invoice', ['id' => 7, 'status' => 'paid', 'paid_at' => $paidAt === '' ? null : $paidAt, 'created_at' => '2026-10-01 09:00:00']);
    $db->insert('invoice_item', ['invoice_id' => 7, 'type' => 'order', 'rel_id' => '2', 'price' => 30]);
    $db->insert('invoice', ['id' => 9, 'status' => 'paid', 'paid_at' => '2026-10-05 10:00:00']);
    $db->insert(ClientFence\Mod\Order\Upgrades::TABLE, ['id' => 1, 'order_id' => 2, 'invoice_id' => 9]);
    $db->insert('invoice_item', ['invoice_id' => 9, 'type' => 'hook_call', 'rel_id' => json_encode(['upgrade_id' => 1]), 'price' => 1.30]);
    $db->insert('invoice', ['id' => 8, 'status' => 'refunded', 'created_at' => '2026-10-10 10:00:00']);
    $db->insert('invoice_item', ['invoice_id' => 8, 'type' => 'custom', 'task' => Service::CREDIT_NOTE_TASK, 'rel_id' => '2', 'price' => -14.20]);
    if ($renewalPaidAt !== null) {
        // A renewal of 20: it funds the credit only when paid before it.
        $totals[11] = 20.0;
        $db->insert('invoice', ['id' => 11, 'status' => 'paid', 'paid_at' => $renewalPaidAt]);
        $db->insert('invoice_item', ['invoice_id' => 11, 'type' => 'order', 'task' => 'renew', 'rel_id' => '2', 'price' => 20]);
    }
    $models = [];
    foreach ($db->fetchAllAssociative('SELECT * FROM invoice') as $row) {
        $model = new Model_Invoice();
        $model->loadBean(new Tests\Helpers\DummyBean());
        foreach ($row as $key => $value) {
            $model->{$key} = $value;
        }
        $models[(int) $model->id] = $model;
    }
    $invoice = $models[7];
    $invoice->client_id = 5;
    $invoice->currency = 'USD';
    $new = new Model_Invoice();
    $new->loadBean(new Tests\Helpers\DummyBean());
    $new->id = 99;

    $dbMock = Mockery::mock(ClientFence\Database::class);
    $dbMock->shouldReceive('getAll')->andReturnUsing(fn (string $sql, array $params): array => $db->fetchAllAssociative($sql, $params));
    $dbMock->shouldReceive('getCell')->andReturnUsing(fn (string $sql, array $params): mixed => $db->fetchOne($sql, $params));
    $dbMock->shouldReceive('load')->andReturnUsing(fn (string $type, int $id): Model_Invoice => $models[$id]);
    if ($refused) {
        $dbMock->shouldNotReceive('store', 'dispense', 'exec');
    } else {
        $dbMock->shouldReceive('getExistingModelById')->andReturn(new Model_Client());
        $dbMock->shouldReceive('find')->andReturn([]);
        $dbMock->shouldReceive('dispense')->andReturn($new);
        $dbMock->shouldReceive('store')->andReturn(1);
        $dbMock->shouldReceive('exec')->andReturnUsing(fn (string $sql, array $params): int => $db->executeStatement($sql, $params));
    }
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getTotalWithTax')->andReturnUsing(fn (Model_Invoice $model): float => $totals[(int) $model->id]);
    $service->shouldReceive('toApiArray')->andReturn([]);
    $service->shouldReceive('countIncome', 'addNote');
    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')->andReturn('negative_invoice');
    $systemService->shouldReceive('reserveNextNumericParamValue')->with('invoice_starting_number')->andReturn(1);
    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = '') => $systemService);
    $service->setDi($di);

    if ($refused) {
        expect(fn () => $service->refundInvoice($invoice))->toThrow(ClientFence\InformationException::class, 'A downgrade already gave 14.20 USD back as account credit. Refund this invoice by hand.');
        expect($invoice->status)->toBe(Model_Invoice::STATUS_PAID);
    } else {
        expect($service->refundInvoice($invoice))->toBe(99)->and($invoice->status)->toBe(Model_Invoice::STATUS_REFUNDED);
    }
})->with([
    'D-03: renewal paid after the credit' => ['2026-10-01 10:00:00', '2026-11-01 10:00:00', true],
    'renewal paid before the credit funds it' => ['2026-10-01 10:00:00', '2026-10-08 10:00:00', false],
    'D-04: no payment date' => ['', null, true],
    'P-02: payment date moved past the credit' => ['2026-10-11 10:00:00', null, true],
]);

test('the issue and paid dates of a paid invoice cannot be moved or cleared (QA r10 C-6)', function (array $data, bool $moves): void {
    // R1-01, R1-02: moved dates changed which period a refund takes downgrade credit back from.
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->status = Model_Invoice::STATUS_PAID;
    $model->created_at = '2026-10-01 09:00:00';
    $model->paid_at = '2026-10-01 10:00:00';
    $service = new Service();

    expect((fn (): bool => $this->movesDates($model, $data))->call($service))->toBe($moves);
})->with([
    'an unchanged Manage save (dates alone)' => [['created_at' => '2026-10-01', 'paid_at' => '2026-10-01'], false],
    'fields not sent' => [['notes' => 'x'], false],
    'empty issue date (ignored by the save)' => [['created_at' => ''], false],
    'paid date moved' => [['paid_at' => '2026-10-12'], true],
    'paid date cleared' => [['paid_at' => ''], true],
    'issue date moved' => [['created_at' => '2026-10-12'], true],
    'same day, another time' => [['paid_at' => '2026-10-01 11:00:00'], true],
]);

test('updateInvoice refuses to move the paid date of a paid invoice', function (): void {
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->status = Model_Invoice::STATUS_PAID;
    $model->paid_at = '2026-10-01 10:00:00';
    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => Mockery::mock(ServiceInvoiceItem::class));
    $service = new Service();
    $service->setDi($di);

    expect(fn () => (fn (): bool => $this->updateInvoiceLocked($model, ['paid_at' => '2026-10-12']))->call($service))
        ->toThrow(ClientFence\InformationException::class, 'The issue and payment dates of a paid invoice cannot be changed.')
        ->and($model->paid_at)->toBe('2026-10-01 10:00:00');
});

test('an invoice marked paid on the Manage tab is paid when it is saved, whatever date is sent (QA r10 C-6)', function (string $posted): void {
    $model = new Model_Invoice();
    $model->loadBean(new Tests\Helpers\DummyBean());
    $model->id = 5;
    $model->status = Model_Invoice::STATUS_UNPAID;
    $model->paid_at = null;
    $db = Mockery::mock(ClientFence\Database::class)->shouldIgnoreMissing();
    $db->shouldReceive('find')->with('InvoiceItem', 'invoice_id = ?', [5])->andReturn([]);
    $di = container();
    $di['db'] = $db;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => Mockery::mock()->shouldIgnoreMissing());
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('toApiArray')->andReturn([]);
    $service->shouldReceive('markAsPaid')->once()->with($model, false)->andReturnUsing(static function (Model_Invoice $invoice): bool {
        expect($invoice->status)->toBe(Model_Invoice::STATUS_UNPAID);
        $invoice->status = Model_Invoice::STATUS_PAID;
        $invoice->paid_at = date('Y-m-d H:i:s');

        return true;
    });
    $service->setDi($di);
    $before = time();

    (new ReflectionMethod(Service::class, 'updateInvoiceLocked'))->invoke($service, $model, ['status' => 'paid', 'paid_at' => $posted]);
    expect($model->status)->toBe(Model_Invoice::STATUS_PAID)
        ->and(strtotime((string) $model->paid_at))->toBeGreaterThanOrEqual($before);
})->with(['a day before' => '2020-01-01', 'no date' => '', 'today, date only' => date('Y-m-d')]);

test('a date-only Manage save keeps the stored time on the same day (QA r10 P-01)', function (string $posted, ?string $stored, string $expected): void {
    // Midnight moved a renewal paid after a downgrade before it, so a refund counted it as funding that credit.
    $service = new Service();

    expect((fn (): string => $this->formTime($posted, $stored))->call($service))->toBe($expected);
})->with([
    'same day' => ['2026-10-24', '2026-10-24 18:10:35', '2026-10-24 18:10:35'],
    'another day' => ['2026-10-25', '2026-10-24 18:10:35', '2026-10-25 00:00:00'],
    'a full time is taken as sent' => ['2026-10-24 09:00:00', '2026-10-24 18:10:35', '2026-10-24 09:00:00'],
    'nothing stored' => ['2026-10-24', null, '2026-10-24 00:00:00'],
]);

// ClientFence (QA r3 M-04): a downgrade's account credit gets a numbered credit note with the paying invoice's buyer and tax.
test('creditNote copies the paying invoice and adds one negative account credit line', function (): void {
    $source = new Model_Invoice();
    $source->loadBean(new Tests\Helpers\DummyBean());
    $source->id = 70;
    $source->client_id = 5;
    $source->currency = 'USD';
    $source->taxname = 'VAT';
    $source->taxrate = '24';
    $source->buyer_email = 'client@example.com';

    $new = new Model_Invoice();
    $new->loadBean(new Tests\Helpers\DummyBean());
    $new->id = 90;
    $line = new Model_InvoiceItem();
    $line->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')->with('Invoice', 70, Mockery::any())->andReturn($source);
    $dbMock->shouldReceive('dispense')->with('Invoice')->andReturn($new);
    $dbMock->shouldReceive('dispense')->with('InvoiceItem')->andReturn($line);
    $dbMock->shouldReceive('store');

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')->with('invoice_cn_series', 'CN-')->andReturn('CN-');
    $systemService->shouldReceive('getParamValue')->with('invoice_hash_lifetime_days', '90')->andReturn('90');
    $systemService->shouldReceive('setParamValue')->with('invoice_cn_starting_number', 2, true)->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $systemService);

    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('addNote')->once()->with($new, 'Account credit for the downgrade of order #2.');
    $service->setDi($di);

    expect($service->creditNote(70, 'Credit for the unused time of order #2 (downgrade)', 218.564, true, 'Account credit for the downgrade of order #2.', 2))->toBe(90)
        ->and([$new->client_id, $new->currency, $new->taxname, $new->taxrate, $new->buyer_email, $new->status, $new->serie, $new->nr])
        ->toBe([5, 'USD', 'VAT', '24', 'client@example.com', Model_Invoice::STATUS_REFUNDED, 'CN-', 1])
        ->and([$line->invoice_id, $line->task, $line->price, $line->taxed, $line->status])
        ->toBe([90, Service::CREDIT_NOTE_TASK, -218.56, '1', Model_InvoiceItem::STATUS_EXECUTED])
        ->and($line->rel_id)->toBe(2);
});

test('refuses to refund an invoice that is not paid or already refunded', function (string $status): void {
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->status = $status;

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire');
    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')->andReturn('negative_invoice');
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('dispense');

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $systemService);
    $di['events_manager'] = $eventManagerMock;
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('toApiArray')->andReturn([]);
    $service->setDi($di);

    expect(fn () => $service->refundInvoice($invoiceModel))->toThrow(ClientFence\InformationException::class);
})->with([Model_Invoice::STATUS_REFUNDED, Model_Invoice::STATUS_UNPAID]);

test('refuses to mark a refunded invoice paid', function (): void {
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = 6;
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class)->shouldIgnoreMissing();
    $dbal->shouldReceive('fetchOne')->with('SELECT status FROM invoice WHERE id = ?', [6])->andReturn(Model_Invoice::STATUS_REFUNDED);
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('store');

    $di = container();
    $di['db'] = $dbMock;
    $di['dbal'] = $dbal;
    $service = new Service();
    $service->setDi($di);

    expect(fn () => $service->markAsPaidByAdmin($invoiceModel, ['gateway_id' => 1]))->toThrow(ClientFence\InformationException::class, 'only Refund marks');
});

test('refuses a second refund when the database already shows the invoice refunded', function (): void {
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = 5;
    $invoiceModel->status = Model_Invoice::STATUS_PAID;

    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class)->shouldIgnoreMissing();
    $dbal->shouldReceive('fetchOne')->with('SELECT status FROM invoice WHERE id = ?', [5])->andReturn(Model_Invoice::STATUS_REFUNDED);
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('dispense');

    $di = container();
    $di['db'] = $dbMock;
    $di['dbal'] = $dbal;
    $service = new Service();
    $service->setDi($di);

    expect(fn () => $service->refundInvoice($invoiceModel))->toThrow(ClientFence\InformationException::class, 'only once');
});

test('computes invoice tax from a numeric rate', function (): void {
    $service = new Service();

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = 1;
    $invoiceModel->taxrate = '20';

    $itemModel = new Model_InvoiceItem();
    $itemModel->loadBean(new Tests\Helpers\DummyBean());
    $itemModel->taxed = true;
    $itemModel->price = 100;
    $itemModel->quantity = 1;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')->once()->andReturn([$itemModel]);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    expect($service->getTax($invoiceModel))->toBe(20.0);
});

test('treats a non-numeric invoice tax rate as zero instead of crashing', function (): void {
    $service = new Service();

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = 1;
    $invoiceModel->taxrate = 'not-a-rate';

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')->zeroOrMoreTimes()->andReturn([]);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    expect($service->getTax($invoiceModel))->toBe(0.0);
});

test('updates an invoice', function (): void {
    $service = new Service();
    $data = [
        'gateway_id' => '',
        'taxname' => '',
        'taxrate' => '',
        'status' => '',
        'notes' => '',
        'text_1' => '',
        'text_2' => '',
        'due_at' => '',
        'paid_at' => '',
        'buyer_first_name' => '',
        'buyer_last_name' => '',
        'buyer_company' => '',
        'buyer_company_vat' => '',
        'buyer_company_number' => '',
        'buyer_address' => '',
        'buyer_city' => '',
        'buyer_state' => '',
        'buyer_country' => '',
        'buyer_phone' => '',
        'buyer_email' => '',
        'buyer_zip' => '',
        'seller_company' => '',
        'seller_address' => '',
        'seller_phone' => '',
        'seller_email' => '',
        'seller_company_vat' => '',
        'seller_company_number' => '',
        'approved' => '',
        'items' => [0 => []],
        'new_item' => ['title' => 'new Item'],
    ];

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->atLeast()->once();

    $itemInvoiceServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $itemInvoiceServiceMock->shouldReceive('addNew')
        ->atLeast()->once();
    $itemInvoiceServiceMock->shouldReceive('update')
        ->atLeast()->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once();
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($invoiceItemModel);
    // QA r10 C-1, D-02: the new line changes the amount, so the service it bills is marked.
    $dbMock->shouldReceive('getAll')->andReturn([['type' => 'order', 'rel_id' => '4']]);
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE client_order_meta (id INTEGER PRIMARY KEY, client_order_id INTEGER, name TEXT, value TEXT, created_at TEXT, updated_at TEXT)');
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($dbal);

    $di = container();
    $di['db'] = $dbMock;
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $itemInvoiceServiceMock);
    $di['events_manager'] = $eventManagerMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('toApiArray')
        ->atLeast()->once()
        ->andReturn(['id' => 1]);

    $serviceMock->setDi($di);

    $result = $serviceMock->updateInvoice($invoiceModel, $data);
    expect($result)->toBeTrue()
        ->and($dbal->fetchAllAssociative('SELECT client_order_id, name FROM client_order_meta'))->toBe([['client_order_id' => 4, 'name' => ClientFence\Mod\Order\Upgrades::MONEY_META]]);
});

test('ordersOf lists the services an invoice bills: its order lines and its plan-change lines', function (): void {
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE invoice_item (invoice_id INTEGER, type TEXT, rel_id TEXT)');
    $db->executeStatement('CREATE TABLE ' . ClientFence\Mod\Order\Upgrades::TABLE . ' (id INTEGER PRIMARY KEY, order_id INTEGER)');
    $db->insert(ClientFence\Mod\Order\Upgrades::TABLE, ['id' => 1, 'order_id' => 3]);
    foreach ([['order', '2'], ['hook_call', json_encode(['upgrade_id' => 1])], ['custom', '9'], ['order', '2']] as [$type, $rel]) {
        $db->insert('invoice_item', ['invoice_id' => 5, 'type' => $type, 'rel_id' => $rel]);
    }
    $db->insert('invoice_item', ['invoice_id' => 6, 'type' => 'order', 'rel_id' => '8']);
    $dbMock = Mockery::mock(ClientFence\Database::class);
    $dbMock->shouldReceive('getAll')->andReturnUsing(fn (string $sql, array $params): array => $db->fetchAllAssociative($sql, $params));
    $dbMock->shouldReceive('getCell')->andReturnUsing(fn (string $sql, array $params): mixed => $db->fetchOne($sql, $params));
    $di = container();
    $di['db'] = $dbMock;
    $service = new Service();
    $service->setDi($di);

    expect($service->ordersOf(5))->toBe([2, 3]);
});

test('removes an invoice', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('exec')
        ->atLeast()->once();
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$invoiceItemModel]);
    $dbMock->shouldReceive('trash')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;

    $service->setDi($di);

    $result = $service->rmInvoice($invoiceModel);
    expect($result)->toBeTrue();
});

test('deletes invoice by admin', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('rmInvoice')
        ->once();

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventManagerMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);

    $result = $serviceMock->deleteInvoiceByAdmin($invoiceModel);
    expect($result)->toBeTrue();
});

test('renews an invoice', function (): void {
    $service = new Service();
    $newId = 2;
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->id = $newId;

    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('approveInvoice')
        ->once();
    $serviceMock->shouldReceive('generateForOrder')
        ->once()
        ->andReturn($invoiceModel);

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->atLeast()->once();

    $di = container();
    $di['events_manager'] = $eventManagerMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $result = $serviceMock->renewInvoice($clientOrder, []);
    expect($result)->toBeInt()->toBe($newId);
});

test('a staff renewal right after a paid renewal of the order is refused', function (int $paidAgo, bool $refused): void {
    // QA r6 M-08: two staff Renew clicks (or staff and the client's Renew Now) charged one period twice.
    $dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $dbal->executeStatement('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT, paid_at TEXT)');
    $dbal->executeStatement('CREATE TABLE invoice_item (id INTEGER PRIMARY KEY, invoice_id INTEGER, type TEXT, rel_id TEXT)');
    $dbal->insert('invoice', ['id' => 1, 'status' => 'paid', 'paid_at' => date('Y-m-d H:i:s', time() - $paidAgo)]);
    $dbal->insert('invoice_item', ['invoice_id' => 1, 'type' => 'order', 'rel_id' => '7']);

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 7;
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 2;

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('generateForOrder')->times($refused ? 0 : 1)->andReturn($invoice);
    $serviceMock->shouldReceive('approveInvoice')->times($refused ? 0 : 1);
    $events = Mockery::mock('ClientFence\EventManager');
    $events->shouldReceive('fire');
    $di = container();
    $di['dbal'] = $dbal;
    $di['events_manager'] = $events;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $refused
        ? expect(fn () => $serviceMock->renewInvoice($order, []))->toThrow(ClientFence\InformationException::class, 'renewed a moment ago')
        : expect($serviceMock->renewInvoice($order, []))->toBe(2);
})->with([
    'paid 20 seconds ago' => [20, true],
    'paid two hours ago' => [7200, false],
]);

test('processes batch pay with credits', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('findAllUnpaid')
        ->atLeast()->once()
        ->andReturn([[]]);
    $serviceMock->shouldReceive('tryPayWithCredits')
        ->atLeast()->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($invoiceModel);

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['db'] = $dbMock;

    $serviceMock->setDi($di);
    $result = $serviceMock->doBatchPayWithCredits([]);
    expect($result)->toBeBool()->toBeTrue();
});

test('pays invoice with credits', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('tryPayWithCredits')
        ->atLeast()->once();

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $result = $serviceMock->payInvoiceWithCredits($invoiceModel);
    expect($result)->toBeBool()->toBeTrue();
});

test('returns existing invoice when generating for order with unpaid invoice', function (): void {
    $service = new Service();
    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());
    $clientOrder->unpaid_invoice_id = 2;

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->status = Model_Invoice::STATUS_UNPAID;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($invoiceModel);

    $di = container();
    $di['db'] = $dbMock;

    $service->setDi($di);
    $result = $service->generateForOrder($clientOrder);
    expect($result)->toBeInstanceOf(Model_Invoice::class);
});

test('clears stale paid invoice reference when generating for order', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('setInvoiceDefaults')
        ->once();

    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());
    $clientOrder->unpaid_invoice_id = 2;
    $clientOrder->price = 10;
    $clientOrder->quantity = 1;

    $paidInvoice = new Model_Invoice();
    $paidInvoice->loadBean(new Tests\Helpers\DummyBean());
    $paidInvoice->status = Model_Invoice::STATUS_PAID;

    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $newInvoice = new Model_Invoice();
    $newInvoice->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->with('Invoice', 2)
        ->once()
        ->andReturn($paidInvoice);
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($clientModel);
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($newInvoice);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $orderServiceMock = Mockery::mock(OrderService::class);
    $orderServiceMock->shouldReceive('unsetUnpaidInvoice')
        ->with($clientOrder)
        ->once();

    $invoiceItemServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $invoiceItemServiceMock->shouldReceive('generateFromOrder')
        ->with($newInvoice, $clientOrder, Model_InvoiceItem::TASK_RENEW, 10, Mockery::type('array'))
        ->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function (string $module, ?string $submodule = null) use ($orderServiceMock, $invoiceItemServiceMock): Mockery\MockInterface {
        if ($module === 'Order') {
            return $orderServiceMock;
        }

        if ($module === 'Invoice' && $submodule === 'InvoiceItem') {
            return $invoiceItemServiceMock;
        }

        throw new RuntimeException(sprintf('Unexpected mod_service request: module "%s", submodule "%s"', $module, (string) $submodule));
    });

    $serviceMock->setDi($di);

    $result = $serviceMock->generateForOrder($clientOrder);

    expect($result)->toBe($newInvoice);
});

test('generates invoice for order', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('setInvoiceDefaults')
        ->once();

    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $orderModel->price = 10;
    $orderModel->promo_recurring = true;

    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($clientModel);
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($invoiceModel);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $invoiceItemServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $invoiceItemServiceMock->shouldReceive('generateFromOrder')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $invoiceItemServiceMock);

    $serviceMock->setDi($di);
    $result = $serviceMock->generateForOrder($orderModel);
    expect($result)->toBeInstanceOf(Model_Invoice::class);
});

test('generates invoice for active order using the order price, not the product price', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('setInvoiceDefaults')
        ->once();

    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $orderModel->status = Model_ClientOrder::STATUS_ACTIVE;
    $orderModel->product_id = 5;
    $orderModel->currency = 'USD';
    $orderModel->price = 25;
    $orderModel->quantity = 1;

    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $product = Mockery::mock(Product::class)->makePartial();
    $product->shouldReceive('getType')->andReturn('hosting');

    $productService = Mockery::mock(ProductService::class);
    $productService->shouldReceive('findProductById')
        ->with(5)
        ->once()
        ->andReturn($product);
    $productService->shouldReceive('getProductRenewalLineConfig')
        ->never();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($clientModel);
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($invoiceModel);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $invoiceItemServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $invoiceItemServiceMock->shouldReceive('generateFromOrder')
        ->with($invoiceModel, $orderModel, Model_InvoiceItem::TASK_RENEW, 25, Mockery::on(fn ($line): bool => $line['price'] === 25 && $line['quantity'] === 1))
        ->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function (string $module) use ($productService, $invoiceItemServiceMock): Mockery\MockInterface {
        if ($module === 'Product') {
            return $productService;
        }

        return $invoiceItemServiceMock;
    });

    $serviceMock->setDi($di);
    $result = $serviceMock->generateForOrder($orderModel);
    expect($result)->toBeInstanceOf(Model_Invoice::class);
});

test('generates invoice for zero amount order', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('setInvoiceDefaults')
        ->once();

    $orderModel = new Model_ClientOrder();
    $orderModel->loadBean(new Tests\Helpers\DummyBean());
    $orderModel->price = 0;
    $orderModel->quantity = 1;
    $orderModel->currency = 'USD';

    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($clientModel);
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($invoiceModel);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $invoiceItemServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $invoiceItemServiceMock->shouldReceive('generateFromOrder')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $invoiceItemServiceMock);

    $serviceMock->setDi($di);
    $result = $serviceMock->generateForOrder($orderModel);
    expect($result)->toBeInstanceOf(Model_Invoice::class);
});

test('throws exception when generating invoice for negative amount order', function (): void {
    $service = new Service();
    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());
    $clientOrder->price = -1;
    $clientOrder->quantity = 1;
    $service->setDi(container());

    expect(fn () => $service->generateForOrder($clientOrder))
        ->toThrow(ClientFence\Exception::class, 'Invoices are not generated for negative amount orders.');
});

test('returns true when no expiring orders found', function (): void {
    $service = new Service();
    $orderService = Mockery::mock(OrderService::class);
    $orderService->shouldReceive('getSoonExpiringActiveOrders')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderService);

    $service->setDi($di);
    $result = $service->generateInvoicesForExpiringOrders();
    expect($result)->toBeBool()->toBeTrue();
});

test('generates invoices for expiring orders', function (): void {
    $service = new Service();
    $clientOrder = new Model_ClientOrder();
    $clientOrder->loadBean(new Tests\Helpers\DummyBean());

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $newId = 4;
    $invoiceModel->id = $newId;

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('approveInvoice')
        ->once();
    $serviceMock->shouldReceive('generateForOrder')
        ->once()
        ->andReturn($invoiceModel);

    $orderService = Mockery::mock(OrderService::class);
    $orderService->shouldReceive('getSoonExpiringActiveOrders')
        ->once()->with([])
        ->andReturn([['id' => 1]]);
    $orderService->shouldReceive('getSoonExpiringActiveOrders')
        ->once()->with(['id' => 1])
        ->andReturn([['id' => 1]]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($clientOrder);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $orderService);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['db'] = $dbMock;

    $serviceMock->setDi($di);
    $result = $serviceMock->generateInvoicesForExpiringOrders();
    expect($result)->toBeBool()->toBeTrue();
});

test('does not invoice or charge an order renewed since the expiring-order selection', function (): void {
    $locked = $recheckedUnderLock = false;
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('getDatabasePlatform')->andReturn(new Doctrine\DBAL\Platforms\MySQLPlatform());
    $connection->shouldReceive('fetchOne')->once()->with('SELECT GET_LOCK(?, ?)', ['fb:renewal:order:1', 10])
        ->andReturnUsing(function () use (&$locked): int {
            $locked = true;

            return 1;
        });
    $connection->shouldReceive('fetchOne')->once()->with('SELECT RELEASE_LOCK(?)', ['fb:renewal:order:1'])
        ->andReturnUsing(function () use (&$locked): int {
            $locked = false;

            return 1;
        });
    $orderService = Mockery::mock(OrderService::class);
    $orderService->shouldReceive('getSoonExpiringActiveOrders')->once()->with([])->andReturn([['id' => 1]]);
    $orderService->shouldReceive('getSoonExpiringActiveOrders')->once()->with(['id' => 1])
        ->andReturnUsing(function () use (&$locked, &$recheckedUnderLock): array {
            $recheckedUnderLock = $locked;

            return [];
        });

    $order = new Model_ClientOrder();
    $order->loadBean(new Tests\Helpers\DummyBean());
    $order->id = 1;
    $order->expires_at = date('Y-m-d H:i:s', strtotime('+1 month'));
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 4;
    $di = container();
    $di['dbal'] = $connection;
    $di['mod_service'] = $di->protect(fn (string $name): OrderService => $orderService);
    $di['db']->shouldReceive('getExistingModelById')->andReturn($order);

    $actions = [];
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('generateForOrder')->andReturnUsing(function () use (&$actions, $invoice): Model_Invoice {
        $actions[] = 'generate';

        return $invoice;
    });
    $service->shouldReceive('approveInvoice')->andReturnUsing(function () use (&$actions): bool {
        $actions[] = 'approve';

        return true;
    });
    $service->setDi($di);

    expect($service->generateInvoicesForExpiringOrders())->toBeTrue()
        ->and($recheckedUnderLock)->toBeTrue()
        ->and($actions)->toBe([])
        ->and($locked)->toBeFalse();
});

test('activates paid invoices in batch', function (): void {
    $service = new Service();
    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());

    $itemInvoiceServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $itemInvoiceServiceMock->shouldReceive('executeTask')
        ->with($invoiceItemModel);
    $itemInvoiceServiceMock->shouldReceive('getAllNotExecutePaidItems')
        ->atLeast()->once()
        ->andReturn([[]]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($invoiceItemModel);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $itemInvoiceServiceMock);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['db'] = $dbMock;

    $service->setDi($di);
    $result = $service->doBatchPaidInvoiceActivation();
    expect($result)->toBeBool()->toBeTrue();
});

test('handles exception during batch paid invoice activation', function (): void {
    $service = new Service();
    $invoiceItemModel = new Model_InvoiceItem();
    $invoiceItemModel->loadBean(new Tests\Helpers\DummyBean());

    $itemInvoiceServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $itemInvoiceServiceMock->shouldReceive('executeTask')
        ->with($invoiceItemModel)
        ->andThrow(new ClientFence\Exception('testing exception..'));
    $itemInvoiceServiceMock->shouldReceive('getAllNotExecutePaidItems')
        ->atLeast()->once()
        ->andReturn([[]]);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getExistingModelById')
        ->atLeast()->once()
        ->andReturn($invoiceItemModel);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $itemInvoiceServiceMock);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['db'] = $dbMock;

    $service->setDi($di);
    $result = $service->doBatchPaidInvoiceActivation();
    expect($result)->toBeBool()->toBeTrue();
});

test('sends reminders in batch at most once per day', function (): void {
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('doBatchInvokeDueEvent')
        ->once()
        ->with(['once_per_day' => true])
        ->andReturnTrue();

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->atLeast()->once();

    $logger = new Tests\Helpers\TestLogger();

    $di = container();
    $di['events_manager'] = $eventManagerMock;
    $di['logger'] = $logger;

    $service->setDi($di);
    $result = $service->doBatchRemindersSend();
    expect($result)->toBeBool()->toBeTrue();
    expect($logger->calls)->toContain([
        'method' => 'info',
        'params' => ['Executed action to send invoice payment reminders.'],
    ]);
});

test('does not log reminder batch as executed when it is throttled', function (): void {
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('doBatchInvokeDueEvent')
        ->once()
        ->with(['once_per_day' => true])
        ->andReturnFalse();
    $service->shouldReceive('doBatchInvokePendingReminderEvents')
        ->once()
        ->andReturnFalse();

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->once()
        ->with(['event' => 'onBeforeAdminInvoiceSendReminders']);

    $logger = new Tests\Helpers\TestLogger();

    $di = container();
    $di['events_manager'] = $eventManagerMock;
    $di['logger'] = $logger;

    $service->setDi($di);

    expect($service->doBatchRemindersSend())->toBeFalse()
        ->and($logger->calls)->toBeEmpty();
});

test('fires due events via the pending reminder fallback when the primary batch is throttled', function (): void {
    $service = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('doBatchInvokeDueEvent')
        ->once()
        ->with(['once_per_day' => true])
        ->andReturnFalse();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_before_due_days', '')
        ->andReturn('7');
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_after_due_days', '5')
        ->andReturn('5');

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->twice()
        ->andReturn([['id' => 2, 'days_left' => 7]], []);

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->once()
        ->with(['event' => 'onBeforeAdminInvoiceSendReminders']);
    $eventManagerMock->shouldReceive('fire')
        ->once()
        ->with(['event' => 'onEventBeforeInvoiceIsDue', 'params' => ['id' => 2, 'days_left' => 7, 'reminder_intervals' => [7]]]);

    $di = container();
    $di['db'] = $dbMock;
    $di['events_manager'] = $eventManagerMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $systemService);
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    expect($service->doBatchRemindersSend())->toBeTrue();
});

test('guards the primary reminder batch throttle while the fallback still dispatches', function (): void {
    $lastInvocation = date('Y-m-d H:i:s');

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->times(3)
        ->with('invoice_overdue_invoked')
        ->andReturn(null, $lastInvocation, $lastInvocation);
    $systemService->shouldReceive('getParamValue')
        ->twice()
        ->with('invoice_reminder_before_due_days', '')
        ->andReturn('7');
    $systemService->shouldReceive('getParamValue')
        ->twice()
        ->with('invoice_reminder_after_due_days', '5')
        ->andReturn('5');
    $systemService->shouldReceive('setParamValue')
        ->once()
        ->with('invoice_overdue_invoked', Mockery::type('string'));

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->times(4)
        ->andReturn([['id' => 1, 'days_left' => 7]], [], [], []);

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->twice()
        ->with(['event' => 'onBeforeAdminInvoiceSendReminders']);
    $eventManagerMock->shouldReceive('fire')
        ->once()
        ->with(['event' => 'onEventBeforeInvoiceIsDue', 'params' => ['id' => 1, 'days_left' => 7, 'reminder_intervals' => [7]]]);

    $di = container();
    $di['db'] = $dbMock;
    $di['events_manager'] = $eventManagerMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $systemService);
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service = new Service();
    $service->setDi($di);

    // Primary batch runs, then is throttled on the direct call, then the fallback still
    // dispatches due events (broadly, for any listener) even though the primary is throttled.
    expect($service->doBatchRemindersSend())->toBeTrue()
        ->and($service->doBatchInvokeDueEvent([]))->toBeFalse()
        ->and($service->doBatchRemindersSend())->toBeTrue();
});

test('invokes due event in batch', function (): void {
    $service = new Service();
    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_overdue_invoked')
        ->andReturn(null);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_before_due_days', '')
        ->andReturn('14, 7, 1');
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_reminder_after_due_days', '5')
        ->andReturn('5');
    $systemService->shouldReceive('setParamValue')
        ->atLeast()->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->atLeast()->once()
        ->andReturn([['id' => 1]]);

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['events_manager'] = $eventManagerMock;
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $systemService);
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $result = $service->doBatchInvokeDueEvent([]);
    expect($result)->toBeBool()->toBeTrue();
});

test('protects from sending reminders to paid invoices', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->status = Model_Invoice::STATUS_PAID;

    $result = $service->sendInvoiceReminder($invoiceModel);
    expect($result)->toBeBool()->toBeTrue();
});

test('parses invoice reminder intervals', function (): void {
    $service = new Service();

    expect($service->parseInvoiceReminderIntervals('14, 7 1,7, 0, no'))
        ->toBe([1, 7, 14]);
});

test('sends invoice reminder', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $eventManagerMock = Mockery::mock('ClientFence\EventManager');
    $eventManagerMock->shouldReceive('fire')
        ->atLeast()->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['events_manager'] = $eventManagerMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $result = $service->sendInvoiceReminder($invoiceModel);
    expect($result)->toBeBool()->toBeTrue();
});

test('counts invoices', function (): void {
    $service = new Service();
    $sqlResult = [
        ['status' => Model_Invoice::STATUS_PAID,
            'counter' => 2],
    ];
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->atLeast()->once()
        ->andReturn($sqlResult);

    $di = container();
    $di['db'] = $dbMock;

    $service->setDi($di);
    $result = $service->counter();
    expect($result)->toBeArray();
});

test('throws exception when generating funds invoice without active order', function (): void {
    $service = new Service();
    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());

    expect(fn () => $service->generateFundsInvoice($clientModel, 10))
        ->toThrow(ClientFence\Exception::class, 'You must have at least one active order before you can add funds so you cannot proceed at the current time!');
});

test('throws exception when generating funds invoice while the feature is disabled', function (): void {
    $service = new Service();
    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());
    $clientModel->currency = 'EUR';

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->once()
        ->with('funds_enabled', true)
        ->andReturn('0');

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $systemService);

    $service->setDi($di);

    expect(fn () => $service->generateFundsInvoice($clientModel, 10))
        ->toThrow(ClientFence\Exception::class, 'Adding funds to the account balance is currently disabled');
});

test('throws exception when generating funds invoice below minimum amount', function (): void {
    $service = new Service();
    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());
    $clientModel->currency = 'EUR';
    $fundsAmount = 2;

    $minAmount = 10;
    $maxAmount = 50;
    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')->with('funds_enabled', true)->andReturn(true);
    $systemService->shouldReceive('getParamValue')->with('funds_min_amount', null)->andReturn($minAmount);
    $systemService->shouldReceive('getParamValue')->with('funds_max_amount', null)->andReturn($maxAmount);

    $di = container();
    $currencyRepository = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepository->shouldReceive('getRateByCode')->andReturn(1.0);
    $currencyService = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyService->shouldReceive('getCurrencyRepository')->andReturn($currencyRepository);
    $di['mod_service'] = $di->protect(fn (string $name): Mockery\MockInterface => $name === 'currency' ? $currencyService : $systemService);

    $service->setDi($di);

    expect(fn () => $service->generateFundsInvoice($clientModel, $fundsAmount))
        ->toThrow(ClientFence\Exception::class, 'Amount must be at least ' . $minAmount);
});

test('throws exception when generating funds invoice above maximum amount', function (): void {
    $service = new Service();
    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());
    $clientModel->currency = 'EUR';
    $fundsAmount = 200;

    $minAmount = 10;
    $maxAmount = 50;
    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')->with('funds_enabled', true)->andReturn(true);
    $systemService->shouldReceive('getParamValue')->with('funds_min_amount', null)->andReturn($minAmount);
    $systemService->shouldReceive('getParamValue')->with('funds_max_amount', null)->andReturn($maxAmount);

    $di = container();
    $currencyRepository = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepository->shouldReceive('getRateByCode')->andReturn(1.0);
    $currencyService = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyService->shouldReceive('getCurrencyRepository')->andReturn($currencyRepository);
    $di['mod_service'] = $di->protect(fn (string $name): Mockery\MockInterface => $name === 'currency' ? $currencyService : $systemService);

    $service->setDi($di);

    expect(fn () => $service->generateFundsInvoice($clientModel, $fundsAmount))
        ->toThrow(ClientFence\Exception::class, 'Amount cannot exceed ' . $maxAmount);
});

test('generates funds invoice', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $clientModel = new Model_Client();
    $clientModel->loadBean(new Tests\Helpers\DummyBean());
    $clientModel->currency = 'EUR';
    $fundsAmount = 20;

    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('setInvoiceDefaults')
        ->atLeast()->once();

    $minAmount = 10;
    $maxAmount = 50;

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')->with('funds_enabled', true)->andReturn(true);
    $systemService->shouldReceive('getParamValue')->with('funds_min_amount', null)->andReturn($minAmount);
    $systemService->shouldReceive('getParamValue')->with('funds_max_amount', null)->andReturn($maxAmount);
    $systemService->shouldReceive('getParamValue')->with('invoice_auto_approval', true)->andReturn(true);

    $itemInvoiceServiceMock = Mockery::mock(ServiceInvoiceItem::class);
    $itemInvoiceServiceMock->shouldReceive('generateForAddFunds')
        ->atLeast()->once();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($invoiceModel);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $currencyRepository = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepository->shouldReceive('getRateByCode')->andReturn(1.0);
    $currencyService = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyService->shouldReceive('getCurrencyRepository')->andReturn($currencyRepository);
    $di['mod_service'] = $di->protect(function ($serviceName, $sub = '') use ($systemService, $itemInvoiceServiceMock, $currencyService) {
        if ($serviceName == 'system') {
            return $systemService;
        }
        if ($serviceName == 'currency') {
            return $currencyService;
        }
        if ($sub == 'InvoiceItem') {
            return $itemInvoiceServiceMock;
        }
    });
    $di['db'] = $dbMock;

    $serviceMock->setDi($di);

    $result = $serviceMock->generateFundsInvoice($clientModel, $fundsAmount);
    expect($result)->toBeInstanceOf(Model_Invoice::class);
});

test('isFundsEnabled defaults to true when the setting was never saved', function (): void {
    $service = new Service();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->once()
        ->with('funds_enabled', true)
        ->andReturn(true);

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $systemService);

    $service->setDi($di);

    expect($service->isFundsEnabled())->toBeTrue();
});

test('isFundsEnabled returns false when explicitly disabled', function (): void {
    $service = new Service();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->once()
        ->with('funds_enabled', true)
        ->andReturn('0');

    $di = container();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $systemService);

    $service->setDi($di);

    expect($service->isFundsEnabled())->toBeFalse();
});

test('throws exception when processing invoice not found', function (): void {
    $service = new Service();
    $data = [
        'hash' => 'hashString',
        'gateway_id' => 2,
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;

    $service->setDi($di);

    expect(fn (): array => $service->processInvoice($data))
        ->toThrow(ClientFence\InformationException::class, 'Invoice not found');
});

test('throws exception when processing invoice with gateway not found', function (): void {
    $service = new Service();
    $data = [
        'hash' => 'hashString',
        'gateway_id' => 2,
    ];

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($invoiceModel);
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;

    $service->setDi($di);

    expect(fn (): array => $service->processInvoice($data))
        ->toThrow(ClientFence\InformationException::class, 'Payment method not found');
});

test('throws exception when processing invoice with gateway not enabled', function (): void {
    $service = new Service();
    $data = [
        'hash' => 'hashString',
        'gateway_id' => 2,
    ];

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($invoiceModel);
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($payGatewayModel);

    $di = container();
    $di['db'] = $dbMock;

    $service->setDi($di);

    expect(fn (): array => $service->processInvoice($data))
        ->toThrow(ClientFence\Exception::class, 'Payment method not enabled');
});

test('processes an invoice', function (): void {
    $service = new Service();
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getPaymentInvoice')
        ->atLeast()->once()
        ->andReturn(new Payment_Invoice());

    $data = [
        'hash' => 'hashString',
        'gateway_id' => 2,
    ];

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->enabled = true;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->atLeast()->once()
        ->andReturn($invoiceModel);
    $dbMock->shouldReceive('load')
        ->atLeast()->once()
        ->andReturn($payGatewayModel);

    $subscribeService = Mockery::mock(ServiceSubscription::class);
    $subscribeService->shouldReceive('isSubscribable')
        ->atLeast()->once()
        ->andReturn(true);

    $payGatewayService = Mockery::mock(ServicePayGateway::class);
    $payGatewayService->shouldReceive('canPerformRecurrentPayment')
        ->atLeast()->once()
        ->andReturn(true);

    $adapterMock = Mockery::mock('\Payment_Adapter_Dummy');
    $adapterMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn([]);
    $adapterMock->shouldReceive('recurrentPayment')
        ->atLeast()->once()
        ->andReturn(['type' => 'html', 'result' => 'test']);
    $adapterMock->shouldReceive('getType')
        ->atLeast()->once()
        ->andReturn('html');
    $adapterMock->shouldReceive('getServiceURL')
        ->atLeast()->once()
        ->andReturn('http://example.com/payment');

    $payGatewayService->shouldReceive('getPaymentAdapter')
        ->atLeast()->once()
        ->andReturn($adapterMock);

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function ($serviceName, $sub = '') use ($payGatewayService, $subscribeService) {
        if ($sub == 'PayGateway') {
            return $payGatewayService;
        }
        if ($sub == 'Subscription') {
            return $subscribeService;
        }
    });
    $di['api_admin'] = new ClientFence\Api\Proxy(new Model_Admin());
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);
    $result = $serviceMock->processInvoice($data);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('type');
    expect($result)->toHaveKey('service_url');
    expect($result)->toHaveKey('subscription');
    expect($result)->toHaveKey('result');
});

test('paypal email html generation does not require admin api invoice access', function (): void {
    $adapter = new Payment_Adapter_PayPalEmail([
        'email' => 'payments@example.com',
        'test_mode' => false,
        'auto_redirect' => false,
        'thankyou_url' => 'https://example.com/invoice/thank-you/hash',
        'cancel_url' => 'https://example.com/invoice/hash',
        'notify_url' => 'https://example.com/ipn.php',
    ]);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')
        ->once()
        ->with('Invoice', 1)
        ->andReturn($invoiceModel);

    $invoiceService = Mockery::mock(Service::class);
    $invoiceService->shouldReceive('toApiArray')
        ->once()
        ->with($invoiceModel, true)
        ->andReturn([
            'id' => 1,
            'nr' => '1001',
            'serie' => 'INV-',
            'currency' => 'USD',
            'subtotal' => 10.00,
            'tax' => 0.00,
            'lines' => [
                ['title' => 'Hosting'],
            ],
        ]);

    $apiAdmin = Mockery::mock();
    $apiAdmin->shouldNotReceive('invoice_get');

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(function ($serviceName) use ($invoiceService) {
        if ($serviceName === 'Invoice') {
            return $invoiceService;
        }
    });

    $adapter->setDi($di);

    $html = $adapter->getHtml($apiAdmin, 1, false);

    expect($html)->toContain('https://www.paypal.com/cgi-bin/webscr');
    expect($html)->toContain('payments@example.com');
    expect($html)->toContain('Pay with PayPal');
});

test('adds note to invoice', function (): void {
    $service = new Service();
    $note = 'test Note';

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->addNote($invoiceModel, $note);
    expect($result)->toBeTrue();
});

test('finds all unpaid invoices', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $getAllResult = [
        [
            'id' => 1,
            'client_id' => 1,
            'serie' => 'BB',
            'nr' => '00',
        ],
    ];
    $dbMock->shouldReceive('getAll')
        ->atLeast()->once()
        ->andReturn($getAllResult);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->findAllUnpaid();
    expect($result)->toBeArray();
});

test('unpaid invoices are matched to the summed credit, not to one credit row', function (): void {
    $service = new Service();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')->once()
        ->with(Mockery::on(fn (string $sql): bool => !str_contains($sql, 'client_balance as cb') && str_contains($sql, 'SUM(b.amount)')), Mockery::any())
        ->andReturn([]);
    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    expect($service->findAllUnpaid())->toBe([]);
});

test('finds all paid invoices', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$invoiceModel]);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->findAllPaid();
    expect($result)->toBeArray();
    expect($result[0])->toBeInstanceOf(Model_Invoice::class);
});

test('gets unpaid invoices late for', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$invoiceModel]);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->getUnpaidInvoicesLateFor();
    expect($result)->toBeArray();
    expect($result[0])->toBeInstanceOf(Model_Invoice::class);
});

test('old unpaid invoice cleanup keeps live renewals and returns invoices without a live order', function (string $status): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('NOW', fn (): string => '2026-10-31 00:00:00', 0);
    $pdo->sqliteCreateFunction('DATEDIFF', fn (string $later, string $earlier): int => (int) floor((strtotime($later) - strtotime($earlier)) / 86400), 2);
    $pdo->exec('CREATE TABLE invoice (id INTEGER PRIMARY KEY, status TEXT, due_at TEXT)');
    $pdo->exec('CREATE TABLE client_order (id INTEGER PRIMARY KEY, unpaid_invoice_id INTEGER, status TEXT)');
    $pdo->exec("INSERT INTO invoice VALUES (1, 'unpaid', '2026-09-01'), (2, 'unpaid', '2026-09-01'), (3, 'unpaid', '2026-09-01')");
    $pdo->prepare('INSERT INTO client_order VALUES (1, 1, ?), (2, 3, ?)')->execute([$status, Model_ClientOrder::STATUS_CANCELED]);

    $models = [];
    foreach ([1, 2, 3] as $id) {
        $models[$id] = new Model_Invoice();
        $models[$id]->loadBean(new Tests\Helpers\DummyBean());
        $models[$id]->id = $id;
    }
    $dbMock = Mockery::mock(ClientFence\Database::class);
    $dbMock->shouldReceive('find')->once()->andReturnUsing(function (string $model, string $conditions, array $bindings) use ($pdo, $models): array {
        $query = $pdo->prepare('SELECT * FROM invoice WHERE ' . $conditions);
        foreach ($bindings as $index => $value) {
            $query->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->execute();

        return array_map(fn (array $row): Model_Invoice => $models[(int) $row['id']], $query->fetchAll(PDO::FETCH_ASSOC));
    });

    $di = container();
    $di['db'] = $dbMock;
    $service = new Service();
    $service->setDi($di);

    expect($service->findUnpaidOlderThan(30))->toBe([$models[2], $models[3]]);
})->with([Model_ClientOrder::STATUS_ACTIVE, Model_ClientOrder::STATUS_SUSPENDED]);

test('gets buyer', function (): void {
    $service = new Service();
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel->buyer_first_name = 'John';
    $invoiceModel->buyer_last_name = 'Doe';
    $invoiceModel->buyer_email = 'john@example.com';

    $result = $service->getBuyer($invoiceModel);
    expect($result)->toBeArray();
    expect($result)->toHaveKey('first_name');
    expect($result)->toHaveKey('last_name');
    expect($result)->toHaveKey('email');
    expect($result['first_name'])->toBe('John');
    expect($result['last_name'])->toBe('Doe');
    expect($result['email'])->toBe('john@example.com');
});

test('checks if invoice type is deposit', function (): void {
    $service = new Service();
    $di = container();

    $modelInvoiceItem = new Model_InvoiceItem();
    $modelInvoiceItem->loadBean(new Tests\Helpers\DummyBean());
    $modelInvoiceItem->type = Model_InvoiceItem::TYPE_DEPOSIT;

    $invoiceItems = [$modelInvoiceItem];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn($invoiceItems);

    $di['db'] = $dbMock;

    $modelInvoice = new Model_Invoice();
    $modelInvoice->loadBean(new Tests\Helpers\DummyBean());

    $service->setDi($di);
    $result = $service->isInvoiceTypeDeposit($modelInvoice);
    expect($result)->toBeTrue();
});

test('returns false when invoice type is not deposit', function (): void {
    $service = new Service();
    $di = container();

    $modelInvoiceItem = new Model_InvoiceItem();
    $modelInvoiceItem->loadBean(new Tests\Helpers\DummyBean());
    $modelInvoiceItem->type = Model_InvoiceItem::TYPE_ORDER;

    $invoiceItems = [$modelInvoiceItem];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn($invoiceItems);

    $di['db'] = $dbMock;
    $service->setDi($di);

    $modelInvoice = new Model_Invoice();
    $modelInvoice->loadBean(new Tests\Helpers\DummyBean());

    $result = $service->isInvoiceTypeDeposit($modelInvoice);
    expect($result)->toBeFalse();
});

test('returns false when checking deposit with empty items', function (): void {
    $service = new Service();
    $di = container();

    $invoiceItems = [];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn($invoiceItems);

    $di['db'] = $dbMock;
    $service->setDi($di);

    $modelInvoice = new Model_Invoice();
    $modelInvoice->loadBean(new Tests\Helpers\DummyBean());

    $result = $service->isInvoiceTypeDeposit($modelInvoice);
    expect($result)->toBeFalse();
});

test('validatePaymentAmount passes for exact match', function (): void {
    $service = new Service();
    $di = container();
    $service->setDi($di);

    $service->validatePaymentAmount(50.00, 50.00);
    expect(true)->toBeTrue();
});

test('validatePaymentAmount passes within epsilon tolerance', function (): void {
    $service = new Service();
    $di = container();
    $service->setDi($di);

    $service->validatePaymentAmount(49.99, 50.00);
    expect(true)->toBeTrue();
});

test('validatePaymentAmount throws on underpayment', function (): void {
    $service = new Service();
    $di = container();
    $service->setDi($di);

    expect(fn () => $service->validatePaymentAmount(40.00, 50.00))
        ->toThrow(ClientFence\Exception::class);
});

test('validatePaymentAmount logs warning on significant overpayment', function (): void {
    $service = new Service();
    $logger = new Tests\Helpers\TestLogger();
    $di = container();
    $di['logger'] = $logger;
    $service->setDi($di);

    $service->validatePaymentAmount(60.00, 50.00);

    $warnings = array_filter($logger->calls, fn ($c): bool => $c['method'] === 'warning');
    expect($warnings)->not->toBeEmpty();
});

test('validatePaymentAmount does not warn for minor overpayment within tolerance', function (): void {
    $service = new Service();
    $logger = new Tests\Helpers\TestLogger();
    $di = container();
    $di['logger'] = $logger;
    $service->setDi($di);

    $service->validatePaymentAmount(50.50, 50.00);

    $warnings = array_filter($logger->calls, fn ($c): bool => $c['method'] === 'warning');
    expect($warnings)->toBeEmpty();
});

test('generateRenewalInvoiceForSubscriptionPayment returns null when subscription not found', function (): void {
    $service = new Service();

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->with('Subscription', 'sid = :sid', Mockery::any())
        ->andReturn(null);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $result = $service->generateRenewalInvoiceForSubscriptionPayment('I-TEST123', 1);
    expect($result)->toBeNull();
});

test('generateRenewalInvoiceForSubscriptionPayment returns null when original order is not active', function (): void {
    $service = new Service();

    $subscription = new Model_Subscription();
    $subscription->loadBean(new Tests\Helpers\DummyBean());
    $subscription->rel_type = 'invoice';
    $subscription->rel_id = 82;

    $invoiceItem = new Model_InvoiceItem();
    $invoiceItem->loadBean(new Tests\Helpers\DummyBean());
    $invoiceItem->rel_id = 82;

    $originalOrder = new Model_ClientOrder();
    $originalOrder->loadBean(new Tests\Helpers\DummyBean());
    $originalOrder->status = Model_ClientOrder::STATUS_PENDING_SETUP;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->with('Subscription', 'sid = :sid', Mockery::any())
        ->andReturn($subscription);
    $dbMock->shouldReceive('findOne')
        ->with('InvoiceItem', Mockery::any(), Mockery::any())
        ->andReturn($invoiceItem);
    $dbMock->shouldReceive('load')
        ->with('ClientOrder', 82)
        ->andReturn($originalOrder);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $result = $service->generateRenewalInvoiceForSubscriptionPayment('I-TEST123', 1);
    expect($result)->toBeNull();
});

test('generateRenewalInvoiceForSubscriptionPayment uses the original order and not a product_id lookup', function (): void {
    $subscription = new Model_Subscription();
    $subscription->loadBean(new Tests\Helpers\DummyBean());
    $subscription->rel_type = 'invoice';
    $subscription->rel_id = 82;

    $invoiceItem = new Model_InvoiceItem();
    $invoiceItem->loadBean(new Tests\Helpers\DummyBean());
    $invoiceItem->rel_id = 82;

    $originalOrder = new Model_ClientOrder();
    $originalOrder->loadBean(new Tests\Helpers\DummyBean());
    $originalOrder->status = Model_ClientOrder::STATUS_ACTIVE;
    $originalOrder->product_id = 1;

    $renewalInvoice = new Model_Invoice();
    $renewalInvoice->loadBean(new Tests\Helpers\DummyBean());
    $renewalInvoice->id = 99;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->with('Subscription', 'sid = :sid', Mockery::any())
        ->andReturn($subscription);
    $dbMock->shouldReceive('findOne')
        ->with('InvoiceItem', Mockery::any(), Mockery::any())
        ->andReturn($invoiceItem);
    $dbMock->shouldReceive('load')
        ->with('ClientOrder', 82)
        ->andReturn($originalOrder);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('generateForOrder')
        ->with(Mockery::on(fn ($order): bool => $order === $originalOrder))
        ->once()
        ->andReturn($renewalInvoice);
    $serviceMock->shouldReceive('approveInvoice')
        ->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $result = $serviceMock->generateRenewalInvoiceForSubscriptionPayment('I-TEST123', 1);

    expect($result)->toBeInstanceOf(Model_Invoice::class);
    expect($result->id)->toBe(99);
});

test('generateRenewalInvoiceForSubscriptionPayment still renews an order the batch-suspend cron already suspended', function (string $status): void {
    // A gateway's subscription-payment IPN can legitimately arrive after the
    // batch-suspend cron has already suspended the order for missing its
    // expiry, or after a prior renewal attempt left it failed_renew.
    $subscription = new Model_Subscription();
    $subscription->loadBean(new Tests\Helpers\DummyBean());
    $subscription->rel_type = 'invoice';
    $subscription->rel_id = 82;

    $invoiceItem = new Model_InvoiceItem();
    $invoiceItem->loadBean(new Tests\Helpers\DummyBean());
    $invoiceItem->rel_id = 82;

    $originalOrder = new Model_ClientOrder();
    $originalOrder->loadBean(new Tests\Helpers\DummyBean());
    $originalOrder->status = $status;
    $originalOrder->product_id = 1;

    $renewalInvoice = new Model_Invoice();
    $renewalInvoice->loadBean(new Tests\Helpers\DummyBean());
    $renewalInvoice->id = 99;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('findOne')
        ->with('Subscription', 'sid = :sid', Mockery::any())
        ->andReturn($subscription);
    $dbMock->shouldReceive('findOne')
        ->with('InvoiceItem', Mockery::any(), Mockery::any())
        ->andReturn($invoiceItem);
    $dbMock->shouldReceive('load')
        ->with('ClientOrder', 82)
        ->andReturn($originalOrder);

    $serviceMock = Mockery::mock(Service::class)->makePartial();
    $serviceMock->shouldReceive('generateForOrder')
        ->with(Mockery::on(fn ($order): bool => $order === $originalOrder))
        ->once()
        ->andReturn($renewalInvoice);
    $serviceMock->shouldReceive('approveInvoice')
        ->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $serviceMock->setDi($di);

    $result = $serviceMock->generateRenewalInvoiceForSubscriptionPayment('I-TEST123', 1);

    expect($result)->toBeInstanceOf(Model_Invoice::class);
    expect($result->id)->toBe(99);
})->with([
    'suspended' => Model_ClientOrder::STATUS_SUSPENDED,
    'failed renew' => Model_ClientOrder::STATUS_FAILED_RENEW,
]);

test('markAsPaid transitions a deposit invoice to paid status', function (): void {
    $service = new Service();

    $depositInvoice = new Model_Invoice();
    $depositInvoice->loadBean(new Tests\Helpers\DummyBean());
    $depositInvoice->id = 89;
    $depositInvoice->status = Model_Invoice::STATUS_UNPAID;
    $depositInvoice->approved = true;
    $depositInvoice->currency = 'USD';

    $depositItem = new Model_InvoiceItem();
    $depositItem->loadBean(new Tests\Helpers\DummyBean());
    $depositItem->id = 96;
    $depositItem->type = Model_InvoiceItem::TYPE_DEPOSIT;
    $depositItem->task = 'void';
    $depositItem->charged = 1;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->with('InvoiceItem', 'invoice_id = ?', [89])
        ->andReturn([$depositItem]);
    // (countIncome: no money received before the invoice came here)
    $dbMock->shouldReceive('find')
        ->with('InvoiceItem', 'invoice_id = ? AND unit = ?', [89, ClientFence\Mod\Clientfence\Whmcs\Money::RECEIVED_UNIT])
        ->andReturn([]);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_series_paid')
        ->andReturn('CF');

    $currencyService = Mockery::mock(CurrencyService::class);
    $currencyService->shouldReceive('toBaseCurrency')
        ->andReturn(30.0);
    $currencyRepo = Mockery::mock(CurrencyRepository::class);
    $currencyRepo->shouldReceive('getRateByCode')
        ->with('USD')
        ->andReturn(1.0);
    $currencyService->shouldReceive('getCurrencyRepository')
        ->andReturn($currencyRepo);

    $invoiceItemService = Mockery::mock(ServiceInvoiceItem::class);
    $invoiceItemService->shouldReceive('markAsPaid')
        ->atLeast()->once();
    $invoiceItemService->shouldReceive('getTotal')
        ->andReturn(30.0);
    $invoiceItemService->shouldReceive('executeTask')->once()->with($depositItem);

    $eventsManager = Mockery::mock('ClientFence\EventManager');
    $eventsManager->shouldReceive('fire')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $productService = Mockery::mock(ProductService::class)->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn ($name, $sub = '') => match ([$name, $sub]) {
        ['system', ''] => $systemService,
        ['currency', ''] => $currencyService,
        ['Invoice', 'InvoiceItem'] => $invoiceItemService,
        ['Product', ''], ['product', ''] => $productService,
        default => throw new RuntimeException("Unexpected service: {$name}/{$sub}"),
    });
    $di['events_manager'] = $eventsManager;
    $service->setDi($di);

    $result = $service->markAsPaid($depositInvoice);

    expect($result)->toBeTrue();
    expect($depositInvoice->status)->toBe(Model_Invoice::STATUS_PAID);
    expect($depositInvoice->paid_at)->not->toBeNull();
});

test('getInvoicePdfAttachment returns null when the setting is disabled', function (): void {
    $service = new Service();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_email_attach_pdf')
        ->atLeast()->once()
        ->andReturn(false);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => match ($name) {
        'system' => $systemService,
        default => throw new RuntimeException("Unexpected service: {$name}"),
    });
    $service->setDi($di);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    expect($service->getInvoicePdfAttachment($invoiceModel))->toBeNull();
});

test('getInvoicePdfAttachment builds a sanitized PDF attachment when enabled', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_email_attach_pdf')
        ->atLeast()->once()
        ->andReturn(true);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => match ($name) {
        'system' => $systemService,
        default => throw new RuntimeException("Unexpected service: {$name}"),
    });
    $serviceMock->setDi($di);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock->shouldReceive('toApiArray')
        ->once()
        ->with($invoiceModel, false)
        ->andReturn(['serie_nr' => 'BB/2026/00042']);
    $serviceMock->shouldReceive('renderInvoicePdfContent')
        ->once()
        ->with($invoiceModel, ['serie_nr' => 'BB/2026/00042'])
        ->andReturn('%PDF-1.4 fake invoice contents');

    $result = $serviceMock->getInvoicePdfAttachment($invoiceModel);

    expect($result)->toBe([
        'content' => '%PDF-1.4 fake invoice contents',
        'name' => 'BB-2026-00042.pdf',
        'mime' => 'application/pdf',
    ]);
});

test('getInvoicePdfAttachment returns null and logs when PDF generation fails', function (): void {
    $serviceMock = Mockery::mock(Service::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')
        ->with('invoice_email_attach_pdf')
        ->atLeast()->once()
        ->andReturn(true);

    $di = container();
    $di['mod_service'] = $di->protect(fn ($name) => match ($name) {
        'system' => $systemService,
        default => throw new RuntimeException("Unexpected service: {$name}"),
    });
    $logger = new Tests\Helpers\TestLogger();
    $di['logger'] = $logger;
    $serviceMock->setDi($di);

    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock->shouldReceive('toApiArray')
        ->once()
        ->andThrow(new Exception('boom'));

    $result = $serviceMock->getInvoicePdfAttachment($invoiceModel);

    expect($result)->toBeNull();
    $errors = array_filter($logger->calls, fn ($c): bool => $c['method'] === 'error');
    expect($errors)->not->toBeEmpty();
});

test('exportCSV strips hash from numeric-array headers', function (): void {
    $service = new Service();

    $capturedHeaders = null;
    $factoryMock = Mockery::mock();
    $factoryMock->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (string $table, string $name, array $headers) use (&$capturedHeaders): Symfony\Component\HttpFoundation\Response {
            $capturedHeaders = $headers;

            return new Symfony\Component\HttpFoundation\Response();
        });

    $di = container();
    $di['csv_response_factory'] = $factoryMock;
    $service->setDi($di);

    $service->exportCSV(['hash', 'id', 'buyer_email']);

    expect($capturedHeaders)->not->toContain('hash')
        ->and($capturedHeaders)->toContain('id')
        ->and($capturedHeaders)->toContain('buyer_email');
});

test('exportCSV falls back to defaults when only hash is requested', function (): void {
    $service = new Service();

    $capturedHeaders = null;
    $factoryMock = Mockery::mock();
    $factoryMock->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (string $table, string $name, array $headers) use (&$capturedHeaders): Symfony\Component\HttpFoundation\Response {
            $capturedHeaders = $headers;

            return new Symfony\Component\HttpFoundation\Response();
        });

    $di = container();
    $di['csv_response_factory'] = $factoryMock;
    $service->setDi($di);

    $service->exportCSV(['hash']);

    expect($capturedHeaders)->toContain('id')
        ->and($capturedHeaders)->toContain('buyer_email')
        ->and($capturedHeaders)->not->toContain('hash');
});

test('a paid invoice cannot be deleted', function (string $status): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->status = $status;
    $service = new Service();
    $di = container();
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $service->setDi($di);

    expect(fn () => $service->deleteInvoiceByAdmin($invoice))->toThrow(ClientFence\InformationException::class);
})->with([Model_Invoice::STATUS_PAID, Model_Invoice::STATUS_REFUNDED]);

test('a paid invoice keeps its amount but its other details can change', function (): void {
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 5;
    $invoice->status = Model_Invoice::STATUS_PAID;
    $invoice->taxrate = '19';

    $item = new Model_InvoiceItem();
    $item->loadBean(new Tests\Helpers\DummyBean());
    $item->invoice_id = 5;
    $item->price = '10.00';
    $item->quantity = 2;
    $item->taxed = 1;

    $db = Mockery::mock('ClientFence\Database');
    $db->shouldReceive('load')->with('InvoiceItem', 7)->andReturn($item);
    $db->shouldReceive('store');
    $di = container();
    $di['db'] = $db;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => Mockery::mock()->shouldIgnoreMissing());
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('toApiArray')->andReturn([]);
    $service->setDi($di);

    expect(fn () => $service->updateInvoice($invoice, ['taxrate' => '24']))->toThrow(ClientFence\InformationException::class)
        ->and(fn () => $service->updateInvoice($invoice, ['items' => [7 => ['price' => '9.00', 'quantity' => 2, 'taxed' => 1]]]))->toThrow(ClientFence\InformationException::class)
        // The status cannot undo the payment (then lines could change, or the invoice be deleted).
        ->and(fn () => $service->updateInvoice($invoice, ['status' => 'unpaid']))->toThrow(ClientFence\InformationException::class, 'keeps its status')
        ->and(fn () => $service->updateInvoice($invoice, ['status' => 'canceled']))->toThrow(ClientFence\InformationException::class, 'keeps its status')
        ->and($service->updateInvoice($invoice, ['buyer_city' => 'Cairo', 'status' => 'paid', 'taxrate' => '19', 'items' => [7 => ['title' => 'New title', 'price' => '10', 'quantity' => 2, 'taxed' => 1]]]))->toBeTrue();
});

test('a save loaded before a credit payment cannot reopen the invoice that payment paid', function (): void {
    // The form was loaded while unpaid; a credit payment paid the invoice before the save arrived.
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 5;
    $invoice->client_id = 3;
    $invoice->status = Model_Invoice::STATUS_UNPAID;
    $invoice->paid_at = null;

    $db = Mockery::mock('ClientFence\Database');
    $db->shouldReceive('store')->never();
    $di = container();
    $di['db'] = $db;
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class)->shouldIgnoreMissing();
    $dbal->shouldReceive('fetchAssociative')->with('SELECT status, paid_at FROM invoice WHERE id = ?', [5])
        ->andReturn(['status' => Model_Invoice::STATUS_PAID, 'paid_at' => '2026-10-20 21:00:00']);
    $di['dbal'] = $dbal;
    $di['events_manager'] = Mockery::mock('ClientFence\EventManager')->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => Mockery::mock()->shouldIgnoreMissing());
    $service = new Service();
    $service->setDi($di);

    expect(fn () => $service->updateInvoice($invoice, ['status' => 'unpaid', 'paid_at' => '']))->toThrow(ClientFence\InformationException::class, 'keeps its status')
        ->and($invoice->status)->toBe(Model_Invoice::STATUS_PAID);
});

test('wallet top-up limits are converted to the client currency', function (): void {
    $systemService = Mockery::mock(SystemService::class);
    $systemService->shouldReceive('getParamValue')->with('funds_min_amount', null)->andReturn('10');
    $systemService->shouldReceive('getParamValue')->with('funds_max_amount', null)->andReturn('200');
    $currencyRepository = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepository->shouldReceive('getRateByCode')->with('EGP')->andReturn(51.8);
    $currencyService = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyService->shouldReceive('getCurrencyRepository')->andReturn($currencyRepository);
    $di = container();
    $di['mod_service'] = $di->protect(fn (string $name): Mockery\MockInterface => $name === 'currency' ? $currencyService : $systemService);
    $service = new Service();
    $service->setDi($di);

    expect($service->fundsLimits('EGP'))->toBe(['min' => 518.0, 'max' => 10360.0])
        ->and($service->fundsLimits())->toBe(['min' => 10.0, 'max' => 200.0]);
});
