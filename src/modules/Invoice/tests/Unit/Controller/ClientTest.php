<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Invoice\Controller\Client;
use Symfony\Component\HttpFoundation\RedirectResponse;

use function Tests\Helpers\container;

test('invoice browser controller redirects denied invoice access to invoice list', function (): void {
    $api = new class {
        public function invoice_get(array $data): array
        {
            throw new ClientFence\InformationException('You do not have permission to perform this action', [], 403);
        }
    };

    $app = Mockery::mock(\ClientFence\App::class);
    $app->shouldReceive('redirect')
        ->once()
        ->with('invoice')
        ->andReturn(new RedirectResponse('/invoice'));

    $di = container();
    $di['api_guest'] = $api;
    $di['is_client_logged'] = true;

    $controller = new Client();
    $controller->setDi($di);

    $response = $controller->get_invoice($app, str_repeat('a', 32));

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->headers->get('Location'))->toBe('/invoice');
});

test('a denied invoice link asks a signed-out visitor to sign in, so they come back to that invoice', function (string $method): void {
    $api = new class {
        public function invoice_get(array $data): array
        {
            throw new ClientFence\InformationException('You do not have permission to perform this action', [], 403);
        }

        public function invoice_pdf(array $data): never
        {
            $this->invoice_get($data);
        }
    };
    $di = container();
    $di['api_guest'] = $api;
    // The app turns this into the sign-in page and keeps the link as the return address.
    $di['is_client_logged'] = fn () => throw new ClientFence\Security\AuthenticationRequiredException('client');
    $app = Mockery::mock(\ClientFence\App::class);
    $app->shouldNotReceive('redirect');
    $controller = new Client();
    $controller->setDi($di);

    expect(fn () => $controller->{$method}($app, str_repeat('a', 32)))->toThrow(ClientFence\Security\AuthenticationRequiredException::class);
})->with(['get_invoice', 'get_invoice_print', 'get_pdf']);

test('an authorized user who is not the owner gets a 403 page when saving a payment method', function (): void {
    $di = container();
    $di['is_client_logged'] = true;
    $di['session'] = Mockery::mock(ClientFence\Session::class)
        ->shouldReceive('get')->with(ClientFence\Mod\Client\Users::SESSION)->andReturn(['user_id' => 71, 'client_id' => 72, 'version' => 1])
        ->shouldReceive('get')->with('client_id')->andReturn(72)->getMock();
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldReceive('fetchAssociative')->andReturn(['status' => 'active', 'session_version' => 1, 'owner' => 0, 'permissions' => 'invoices', 'email' => 'user@example.com', 'first_name' => 'Test', 'last_name' => 'User']);
    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldReceive('getConnection')->andReturn($db)->getMock();
    $controller = new Client();
    $controller->setDi($di);

    try {
        expect(fn () => $controller->get_paytabs_save(Mockery::mock(\ClientFence\App::class)))
            ->toThrow(fn (ClientFence\InformationException $e) => expect($e->getCode())->toBe(403));
    } finally {
        ClientFence\Mod\Client\Users::forget();
    }
});

test('a recurring-only gateway that cannot pay the invoice once sends the client to the invoice page to pick another method', function (): void {
    $api = new class {
        public function invoice_get(array $data): array
        {
            return ['id' => 7, 'hash' => $data['hash']];
        }

        public function invoice_payment(array $data): never
        {
            throw new ClientFence\Exception('One-time payments are not enabled for the selected payment gateway', null, 815);
        }
    };
    $hash = str_repeat('b', 32);
    $app = Mockery::mock(\ClientFence\App::class);
    $app->shouldReceive('getRequest')->andReturn(Symfony\Component\HttpFoundation\Request::create('/'));
    $app->shouldReceive('redirect')->once()->with('invoice/' . $hash)->andReturn(new RedirectResponse('/invoice/' . $hash));
    $app->shouldNotReceive('render');

    $di = container();
    $di['api_guest'] = $api;
    $controller = new Client();
    $controller->setDi($di);

    expect($controller->get_banklink($app, $hash, 2)->headers->get('Location'))->toBe('/invoice/' . $hash);
});
