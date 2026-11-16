<?php

declare(strict_types=1);

use ClientFence\Mod\Invoice\Controller\Admin;

use function Tests\Helpers\container;

test('the new invoice line starts taxed only when the setting is on and the client pays tax', function (?bool $setting, bool $taxable, bool $clientFound, bool $expected): void {
    $api = new class {
        public function invoice_get(array $data): array
        {
            return ['id' => 5, 'client_id' => 7];
        }
    };
    $client = new Model_Client();
    $db = Mockery::mock('Box_Database');
    $db->shouldReceive('load')->with('Client', 7)->andReturn($clientFound ? $client : null);
    $system = Mockery::mock();
    $system->shouldReceive('getParamValue')->with('tax_staff_lines', false)->andReturn($setting === null ? false : ($setting ? '1' : '0'));
    $clients = Mockery::mock();
    $clients->shouldReceive('isClientTaxable')->with($client)->andReturn($taxable);

    $di = container();
    $di['api_admin'] = $api;
    $di['db'] = $db;
    $di['mod_service'] = $di->protect(fn (string $name) => $name === 'system' ? $system : $clients);

    $app = Mockery::mock(\ClientFence\App::class);
    $app->shouldReceive('render')->once()->with('mod_invoice_invoice', ['invoice' => ['id' => 5, 'client_id' => 7], 'tax_new_line' => $expected])->andReturn('ok');

    $controller = new Admin();
    $controller->setDi($di);

    expect($controller->get_invoice($app, 5))->toBe('ok');
})->with([
    'on, taxable client' => [true, true, true, true],
    'on, exempt client or tax off' => [true, false, true, false],
    'off' => [false, true, true, false],
    'never saved' => [null, true, true, false],
    'client gone' => [true, true, false, false],
]);
