<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Invoice\ServicePayGateway;

use function Tests\Helpers\container;

test('gets dependency injection container', function (): void {
    $service = new ServicePayGateway();
    $di = container();
    $service->setDi($di);
    $getDi = $service->getDi();
    expect($getDi)->toBe($di);
});

test('gets search query', function (): void {
    $service = new ServicePayGateway();
    $di = container();

    $service->setDi($di);
    $data = [];
    $result = $service->getSearchQuery($data);
    expect($result)->toBeArray();
    expect($result[0])->toBeString();
    expect($result[1])->toBeArray()->toBe([]);
});

test('gets search query with additional params', function (): void {
    $service = new ServicePayGateway();
    $di = container();

    $service->setDi($di);
    $data = ['search' => 'keyword'];
    $expectedParams = [':search' => "%$data[search]%"];

    $result = $service->getSearchQuery($data);
    expect($result)->toBeArray();
    expect($result[0])->toBeString();
    expect(strpos((string) $result[0], 'AND (name LIKE :search OR gateway LIKE :search)') > 0)->toBeTrue();
    expect($result[1])->toBeArray();
    expect($result[1])->toBe($expectedParams);
});

test('gets pairs', function (): void {
    $service = new ServicePayGateway();
    $expected = [
        1 => 'Custom',
    ];

    $queryResult = [
        [
            'id' => 1,
            'name' => 'Custom',
        ],
    ];

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->atLeast()->once()
        ->andReturn($queryResult);

    $di = container();
    $di['db'] = $dbMock;

    $service->setDi($di);

    $result = $service->getPairs();
    expect($result)->toBeArray();
    expect($result)->toBe($expected);
});

test('gets available gateways', function (): void {
    $service = new ServicePayGateway();
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('getAll')
        ->atLeast()->once()
        ->andReturn([]);

    $di = container();
    $di['db'] = $dbMock;
    $service->setDi($di);

    $result = $service->getAvailable();
    expect($result)->toBeArray();
});

test('installs a pay gateway with its display name and payment kinds', function (): void {
    $service = new ServicePayGateway();
    $code = 'PayPalAutoPay';

    $serviceMock = Mockery::mock(ServicePayGateway::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getAvailable')
        ->atLeast()->once()
        ->andReturn([$code]);

    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($payGatewayModel);
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $serviceMock->setDi($di);

    $result = $serviceMock->install($code);
    expect($result)->toBeBool()->toBeTrue()
        ->and($payGatewayModel->name)->toBe('PayPal Auto-Pay')
        ->and($payGatewayModel->allow_single)->toBe(1)
        ->and($payGatewayModel->allow_recurrent)->toBe(0);
});

test('throws exception when installing unavailable gateway', function (): void {
    $service = new ServicePayGateway();
    $code = 'PP';

    $serviceMock = Mockery::mock(ServicePayGateway::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getAvailable')
        ->atLeast()->once()
        ->andReturn([]);

    expect(fn () => $serviceMock->install($code))
        ->toThrow(ClientFence\Exception::class, 'Payment gateway is not available for installation.');
});

test('converts to api array', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(ServicePayGateway::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getAdapterConfig')
        ->atLeast()->once()
        ->andReturn([]);
    $serviceMock->shouldReceive('getAcceptedCurrencies');
    $serviceMock->shouldReceive('getFormElements');
    $serviceMock->shouldReceive('getDescription');

    $expected = [
        'id' => null,
        'code' => null,
        'title' => null,
        'allow_single' => null,
        'allow_recurrent' => null,
        'accepted_currencies' => [],
        'supports_one_time_payments' => false,
        'supports_subscriptions' => false,
        'config' => [],
        'secret_fields' => [],
        'form' => [],
        'description' => null,
        'enabled' => null,
        'test_mode' => null,
        'callback' => SYSTEM_URL . 'ipn.php?',
        'unpaid_invoices' => 0,
    ];

    $di = container();
    $connectionMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connectionMock->shouldReceive('fetchOne')->andReturn(0);
    $di['em']->shouldReceive('getConnection')->andReturn($connectionMock);

    $serviceMock->setDi($di);

    $result = $serviceMock->toApiArray($payGatewayModel, false, new Model_Admin());
    expect($result)->toBeArray();
    expect($result)->toBe($expected);
});

test('copies a gateway', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('dispense')
        ->atLeast()->once()
        ->andReturn($payGatewayModel);

    $expected = 2;
    $dbMock->shouldReceive('store')
        ->atLeast()->once()
        ->andReturn($expected);

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $result = $service->copy($payGatewayModel);
    expect($result)->toBeInt()->toBe($expected);
});

test('updates a gateway', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')
        ->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $data = [
        'title' => '',
        'config' => '',
        'accepted_currencies' => [],
        'enabled' => '',
        'allow_single' => '',
        'allow_recurrent' => '',
        'test_mode' => '',
    ];
    $result = $service->update($payGatewayModel, $data);
    expect($result)->toBeTrue();
});

test('turning a gateway off moves its unpaid invoices to an enabled replacement, and needs one', function (?int $replacementId, bool $moves): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->id = 5;
    $payGatewayModel->enabled = 1;
    $replacement = new Model_PayGateway();
    $replacement->loadBean(new Tests\Helpers\DummyBean());
    $replacement->enabled = 1;
    $connectionMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connectionMock->shouldReceive('fetchOne')->with(Mockery::pattern('/FROM invoice/'), [5, 'unpaid'])->andReturn(2);
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')->with('PayGateway', 6)->andReturn($replacement);
    if ($moves) {
        $connectionMock->shouldReceive('executeStatement')->once()->with('UPDATE invoice SET gateway_id = ? WHERE gateway_id = ? AND status = ?', [6, 5, 'unpaid']);
        $dbMock->shouldReceive('store')->once();
    } else {
        $connectionMock->shouldNotReceive('executeStatement');
        $dbMock->shouldNotReceive('store');
    }
    $di = container();
    $di['db'] = $dbMock;
    $di['em']->shouldReceive('getConnection')->andReturn($connectionMock);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $data = ['enabled' => '0', 'replacement_id' => $replacementId === null ? '' : (string) $replacementId];
    if ($moves) {
        expect($service->update($payGatewayModel, $data))->toBeTrue()->and((bool) $payGatewayModel->enabled)->toBeFalse();
    } else {
        expect(fn () => $service->update($payGatewayModel, $data))->toThrow(ClientFence\InformationException::class, 'Choose an enabled gateway for the unpaid invoices of this gateway.');
    }
})->with(['replacement' => [6, true], 'none chosen' => [null, false]]);

test('converts to api array masks secrets for an admin', function (): void {
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->gateway = 'Stripe';
    $payGatewayModel->accepted_currencies = json_encode(['USD']);
    $payGatewayModel->config = json_encode([
        'pub_key' => 'pk_live_visible',
        'api_key' => 'sk_live_secret',
        'webhook_secret' => 'whsec_secret',
    ]);

    $service = new ServicePayGateway();
    $di = container();
    $connectionMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connectionMock->shouldReceive('fetchOne')->andReturn(0);
    $di['em']->shouldReceive('getConnection')->andReturn($connectionMock);
    $service->setDi($di);

    $result = $service->toApiArray($payGatewayModel, false, new Model_Admin());

    expect($result['secret_fields'])->toContain('api_key');
    expect($result['secret_fields'])->toContain('webhook_secret');
    expect($result['secret_fields'])->toContain('test_api_key');
    expect($result['secret_fields'])->toContain('test_webhook_secret');
    expect($result['secret_fields'])->not->toContain('pub_key');
    expect($result['config']['pub_key'])->toBe('pk_live_visible');
    expect($result['config']['api_key'])->toBeNull();
    expect($result['config']['api_key_set'])->toBeTrue();
    expect($result['config']['webhook_secret'])->toBeNull();
    expect($result['config']['webhook_secret_set'])->toBeTrue();
    expect($result['config']['test_api_key_set'])->toBeFalse();
});

test('update keeps the existing secret gateway value when the incoming value is blank', function (): void {
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->gateway = 'Stripe';
    $payGatewayModel->config = json_encode(['api_key' => 'sk_live_existing', 'pub_key' => 'pk_live_existing']);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['loggedin_admin'] = new Model_Admin();

    $service = new ServicePayGateway();
    $service->setDi($di);

    $result = $service->update($payGatewayModel, [
        'config' => [
            'api_key' => ServicePayGateway::CREDENTIAL_KEEP_SENTINEL,
            'pub_key' => 'pk_live_new',
        ],
    ]);

    expect($result)->toBeTrue();
    $config = json_decode((string) $payGatewayModel->config, true);
    expect($config['api_key'])->toBe('sk_live_existing');
    expect($config['pub_key'])->toBe('pk_live_new');
});

test('update rotates a secret gateway value when a new value is submitted', function (): void {
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->gateway = 'Stripe';
    $payGatewayModel->config = json_encode(['api_key' => 'sk_live_old']);

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('store')->atLeast()->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['loggedin_admin'] = new Model_Admin();

    $service = new ServicePayGateway();
    $service->setDi($di);

    $result = $service->update($payGatewayModel, ['config' => ['api_key' => 'sk_live_new']]);

    expect($result)->toBeTrue();
    $config = json_decode((string) $payGatewayModel->config, true);
    expect($config['api_key'])->toBe('sk_live_new');
});

test('deletes a gateway', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->id = 5;

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('trash')
        ->atLeast()->once();

    $connectionMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connectionMock->shouldReceive('fetchOne')->times(3)->andReturn(0);
    $connectionMock->shouldReceive('executeStatement')
        ->once()
        ->with('DELETE FROM pay_gateway_customer WHERE pay_gateway_id = :id', ['id' => 5]);
    $connectionMock->shouldReceive('executeStatement')
        ->once()
        ->with('DELETE FROM pay_gateway_product WHERE pay_gateway_id = :id', ['id' => 5]);

    $di = container();
    $di['db'] = $dbMock;
    $di['em']->shouldReceive('getConnection')->andReturn($connectionMock);
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $result = $service->delete($payGatewayModel);
    expect($result)->toBeTrue();
});

test('deleting a gateway is refused while subscriptions or saved methods would stop paying', function (int $subscriptions, int $saved, string $message): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->id = 5;
    $connectionMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connectionMock->shouldReceive('fetchOne')->with(Mockery::pattern('/FROM subscription/'), [5])->andReturn($subscriptions);
    $connectionMock->shouldReceive('fetchOne')->with(Mockery::pattern('/FROM pay_gateway_customer/'), [5])->andReturn($saved);
    $connectionMock->shouldNotReceive('executeStatement');
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldNotReceive('trash');
    $di = container();
    $di['db'] = $dbMock;
    $di['em']->shouldReceive('getConnection')->andReturn($connectionMock);
    $service->setDi($di);

    expect(fn () => $service->delete($payGatewayModel, 6))->toThrow(ClientFence\InformationException::class, $message);
})->with([
    'subscriptions' => [1, 0, 'This gateway has active subscriptions. Disable it instead of deleting it.'],
    'saved methods' => [0, 2, 'Clients have saved payment methods on this gateway. Disable it instead of deleting it.'],
]);

test('deleting a gateway moves its unpaid invoices to an enabled replacement, and needs one', function (?int $replacementId, bool $enabled, bool $moves): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->id = 5;
    $replacement = new Model_PayGateway();
    $replacement->loadBean(new Tests\Helpers\DummyBean());
    $replacement->enabled = $enabled ? 1 : 0;
    $connectionMock = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connectionMock->shouldReceive('fetchOne')->with(Mockery::pattern('/FROM (subscription|pay_gateway_customer)/'), [5])->andReturn(0);
    $connectionMock->shouldReceive('fetchOne')->with(Mockery::pattern('/FROM invoice/'), [5, 'unpaid'])->andReturn(3);
    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('load')->with('PayGateway', 6)->andReturn($replacement);
    if ($moves) {
        $connectionMock->shouldReceive('executeStatement')->once()->with('UPDATE invoice SET gateway_id = ? WHERE gateway_id = ? AND status = ?', [6, 5, 'unpaid']);
        $connectionMock->shouldReceive('executeStatement')->twice();
        $dbMock->shouldReceive('trash')->once();
    } else {
        $connectionMock->shouldNotReceive('executeStatement');
        $dbMock->shouldNotReceive('trash');
    }
    $di = container();
    $di['db'] = $dbMock;
    $di['em']->shouldReceive('getConnection')->andReturn($connectionMock);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    if ($moves) {
        expect($service->delete($payGatewayModel, $replacementId))->toBeTrue();
    } else {
        expect(fn () => $service->delete($payGatewayModel, $replacementId))->toThrow(ClientFence\InformationException::class, 'Choose an enabled gateway for the unpaid invoices of this gateway.');
    }
})->with([
    'enabled replacement' => [6, true, true],
    'disabled replacement' => [6, false, false],
    'no replacement' => [null, true, false],
    'itself' => [5, true, false],
]);

test('gets active gateways', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());

    $dbMock = Mockery::mock('ClientFence\Database');
    $dbMock->shouldReceive('find')
        ->atLeast()->once()
        ->andReturn([$payGatewayModel]);

    $di = container();
    $di['db'] = $dbMock;

    $service->setDi($di);

    $data = ['format' => 'pairs'];
    $result = $service->getActive($data);
    expect($result)->toBeArray();
});

test('checks if can perform recurrent payment', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());

    $expected = true;
    $payGatewayModel->allow_recurrent = $expected;

    $result = $service->canPerformRecurrentPayment($payGatewayModel);
    expect($result)->toBeBool()->toBe($expected);
});

test('gets payment adapter', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());
    $expected = 'Payment_Adapter_Custom';

    $serviceMock = Mockery::mock(ServicePayGateway::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getAdapterClassName')
        ->atLeast()->once()
        ->andReturn($expected);

    $urlMock = Mockery::mock('ClientFence\Url');
    $urlMock->shouldReceive('link')
        ->atLeast()->once();

    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('url')
        ->atLeast()->once()
        ->andReturn('http://example.com/');

    $di = container();
    $di['url'] = $urlMock;
    $di['tools'] = $toolsMock;
    $serviceMock->setDi($di);

    $optional = [
        'auto_redirect' => '',
    ];
    $result = $serviceMock->getPaymentAdapter($payGatewayModel, $invoiceModel, $optional);
    expect($result)->toBeInstanceOf($expected);
});

test('throws exception when payment gateway is not found', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $invoiceModel = new Model_Invoice();
    $invoiceModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(ServicePayGateway::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getAdapterClassName')
        ->atLeast()->once()
        ->andReturn('');

    $urlMock = Mockery::mock('ClientFence\Url');
    $urlMock->shouldReceive('link')
        ->atLeast()->once();

    $toolsMock = Mockery::mock(ClientFence\Tools::class);
    $toolsMock->shouldReceive('url')
        ->atLeast()->once()
        ->andReturn('http://example.com/');

    $di = container();
    $di['url'] = $urlMock;
    $di['tools'] = $toolsMock;
    $serviceMock->setDi($di);

    expect(fn () => $serviceMock->getPaymentAdapter($payGatewayModel, $invoiceModel))
        ->toThrow(ClientFence\Exception::class, 'Payment gateway  was not found.');
});

test('gets adapter config', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->gateway = 'Custom';

    $expected = '\Payment_Adapter_Custom';
    $filesystemMock = Mockery::mock(Symfony\Component\Filesystem\Filesystem::class);
    $filesystemMock->shouldReceive('exists')
        ->zeroOrMoreTimes()
        ->andReturn(true);

    $serviceMock = Mockery::mock(ServicePayGateway::class, [$filesystemMock])->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getAdapterClassName')
        ->atLeast()->once()
        ->andReturn($expected);

    $result = $serviceMock->getAdapterConfig($payGatewayModel);
    expect($result)->toBeArray();
});

test('throws exception when adapter class does not exist', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->gateway = 'Custom';

    $expected = 'Payment_Adapter_ClassDoesNotExists';
    $filesystemMock = Mockery::mock(Symfony\Component\Filesystem\Filesystem::class);
    $filesystemMock->shouldReceive('exists')
        ->zeroOrMoreTimes()
        ->andReturn(true);

    $serviceMock = Mockery::mock(ServicePayGateway::class, [$filesystemMock])->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getAdapterClassName')
        ->atLeast()->once()
        ->andReturn($expected);

    expect(fn () => $serviceMock->getAdapterConfig($payGatewayModel))
        ->toThrow(ClientFence\Exception::class, sprintf('Payment gateway %s was not found', $payGatewayModel->gateway));
});

test('throws exception when adapter does not exist', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->gateway = 'Unknown';

    $filesystemMock = Mockery::mock(Symfony\Component\Filesystem\Filesystem::class);
    $filesystemMock->shouldReceive('exists')
        ->zeroOrMoreTimes()
        ->andReturn(false);

    $serviceMock = Mockery::mock(ServicePayGateway::class, [$filesystemMock])->makePartial()->shouldAllowMockingProtectedMethods();
    $serviceMock->shouldReceive('getAdapterClassName')
        ->atLeast()->once();

    expect(fn () => $serviceMock->getAdapterConfig($payGatewayModel))
        ->toThrow(ClientFence\Exception::class, sprintf('Payment gateway %s was not found', $payGatewayModel->gateway));
});

test('gets adapter class name', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->gateway = 'Custom';

    $expected = 'Payment_Adapter_Custom';

    $result = $service->getAdapterClassName($payGatewayModel);
    expect($result)->toBeString()->toBe($expected);
});

test('gets accepted currencies', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());
    $payGatewayModel->accepted_currencies = '{}';

    $result = $service->getAcceptedCurrencies($payGatewayModel);
    expect($result)->toBeArray();
});

test('gets form elements', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(ServicePayGateway::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $config = ['form' => []];
    $serviceMock->shouldReceive('getAdapterConfig')
        ->atLeast()->once()
        ->andReturn($config);

    $result = $serviceMock->getFormElements($payGatewayModel);
    expect($result)->toBeArray();
});

test('returns empty array when form config is empty', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(ServicePayGateway::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $config = [];
    $serviceMock->shouldReceive('getAdapterConfig')
        ->atLeast()->once()
        ->andReturn($config);

    $result = $serviceMock->getFormElements($payGatewayModel);
    expect($result)->toBeArray()->toBe([]);
});

test('gets description', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(ServicePayGateway::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $config = ['description' => ''];
    $serviceMock->shouldReceive('getAdapterConfig')
        ->atLeast()->once()
        ->andReturn($config);

    $result = $serviceMock->getDescription($payGatewayModel);
    expect($result)->toBeString();
});

test('returns null when description is empty', function (): void {
    $service = new ServicePayGateway();
    $payGatewayModel = new Model_PayGateway();
    $payGatewayModel->loadBean(new Tests\Helpers\DummyBean());

    $serviceMock = Mockery::mock(ServicePayGateway::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $config = [];
    $serviceMock->shouldReceive('getAdapterConfig')
        ->atLeast()->once()
        ->andReturn($config);

    $result = $serviceMock->getDescription($payGatewayModel);
    expect($result)->toBeNull();
});
