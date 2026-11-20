<?php

declare(strict_types=1);

use ClientFence\Http\CookieNames;
use ClientFence\Http\CookieQueue;
use ClientFence\Twig\TwigFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

test('configureCsrf expires the legacy cookie with matching attributes', function (): void {
    $di = Tests\Helpers\container();
    $request = Request::create('https://localhost/');
    $request->cookies->set(CookieNames::LEGACY_CSRF, 'legacy-token');
    $di['request'] = $request;
    $di['cookie_queue'] = new CookieQueue();

    $session = Mockery::mock(ClientFence\Session::class);
    $session->shouldReceive('get')->once()->with('csrf_token')->andReturn('current-token');
    $di['session'] = $session;

    (new TwigFactory($di))->configureCsrf();

    $response = new Response();
    $di['cookie_queue']->applyToResponse($response);
    $cookies = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie;
    }

    expect($cookies[CookieNames::CSRF]->getValue())->toBe('current-token')
        ->and($cookies[CookieNames::LEGACY_CSRF]->getExpiresTime())->toBeLessThan(time())
        ->and($cookies[CookieNames::LEGACY_CSRF]->isSecure())->toBeTrue()
        ->and($cookies[CookieNames::LEGACY_CSRF]->isHttpOnly())->toBeFalse()
        ->and($cookies[CookieNames::LEGACY_CSRF]->getSameSite())->toBe($cookies[CookieNames::CSRF]->getSameSite());
});

test('getDefaultCurrencyCode returns null instead of crashing when the currency table is missing patched columns', function (): void {
    $di = Tests\Helpers\container();

    $currencyRepository = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepository->shouldReceive('findDefault')
        ->once()
        ->andThrow(new class('Column not found: 1054 Unknown column t0.format_pattern') extends RuntimeException implements Doctrine\DBAL\Exception {});

    $di['em'] = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $di['em']->shouldReceive('getRepository')
        ->with(ClientFence\Mod\Currency\Entity\Currency::class)
        ->andReturn($currencyRepository);

    $factory = new TwigFactory($di);
    $result = (new ReflectionMethod($factory, 'getDefaultCurrencyCode'))->invoke($factory);

    expect($result)->toBeNull();
});

test('the email environment gives the company name and address in the email\'s language (GitHub #51)', function (): void {
    $di = Tests\Helpers\container();
    unset($di['em']);
    $di['mod_config'] = $di->protect(fn (string $name): array => ['signature' => 'Thanks']);
    $guest = Mockery::mock();
    $guest->expects('system_company')->with(['locale' => 'sr_Cyrl_RS'])->andReturn(['name' => 'Тесла', 'address_1' => 'Главна 1']);
    $di['api_guest'] = $guest;

    $twig = (new TwigFactory($di))->createEmailEnvironment('UTC', 'sr_Cyrl_RS');

    expect($twig->createTemplate('{{ guest.system_company.name }}, {{ guest.system_company.address_1 }}')->render())->toBe('Тесла, Главна 1');
});

test('a sign-in (password, passkey or Google) returns only to an address of its own area', function (?string $from, ClientFence\Twig\Enum\AppArea $area, ?string $expected): void {
    $di = Tests\Helpers\container();
    $session = Mockery::mock(ClientFence\Session::class);
    $session->allows('get')->with('redirect_uri')->andReturn('client/balance');
    $session->allows('get')->with('redirect_area')->andReturn($from);
    $di['session'] = $session;

    expect(TwigFactory::redirectUri($di, $area))->toBe($expected);
})->with([
    'a client page, then the staff sign-in' => ['client', ClientFence\Twig\Enum\AppArea::ADMIN, null],
    'a client page, then the client sign-in' => ['client', ClientFence\Twig\Enum\AppArea::CLIENT, 'client/balance'],
    'an admin page, then the client sign-in' => ['admin', ClientFence\Twig\Enum\AppArea::CLIENT, null],
    'saved before this change' => [null, ClientFence\Twig\Enum\AppArea::ADMIN, 'client/balance'],
]);
