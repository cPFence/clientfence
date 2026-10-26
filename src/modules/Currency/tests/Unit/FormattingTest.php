<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use ClientFence\Mod\Currency\Entity\Currency;
use ClientFence\Mod\Currency\Repository\CurrencyRepository;
use ClientFence\Mod\Currency\Service;
use ClientFence\InformationException;
use ClientFence\Twig\Extension\ClientFenceExtension;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

test('currency formatting delegates to IntlExtra when no override is configured', function (): void {
    $currency = new Currency('USD');
    $repository = Mockery::mock(CurrencyRepository::class);
    $repository->expects('findOneByCode')->with('USD')->once()->andReturn($currency);

    $em = Mockery::mock(Doctrine\ORM\EntityManager::class);
    $em->expects('getRepository')->with(Currency::class)->andReturn($repository);

    $di = new Pimple\Container();
    $di['em'] = $em;

    $service = new Service();
    $service->setDi($di);

    $expected = (new IntlExtension())->formatCurrency(1234, 'USD', locale: 'en_US');
    expect($service->formatCurrency(1234, 'USD', locale: 'en_US'))->toBe($expected);
});

test('currency formatting applies a locale-aware plain-text pattern and fraction digits', function (): void {
    $currency = (new Currency('THB'))
        ->setFormatPattern('{amount} บาท')
        ->setFractionDigits(0);
    $repository = Mockery::mock(CurrencyRepository::class);
    $repository->expects('findOneByCode')->with('THB')->once()->andReturn($currency);

    $em = Mockery::mock(Doctrine\ORM\EntityManager::class);
    $em->expects('getRepository')->with(Currency::class)->andReturn($repository);

    $di = new Pimple\Container();
    $di['em'] = $em;

    $service = new Service();
    $service->setDi($di);

    expect($service->formatCurrency(1234, 'THB', locale: 'th_TH'))->toBe('1,234 บาท')
        ->and($service->formatCurrency(-1234, 'THB', locale: 'en_US'))->toBe('-1,234 บาท');
});

test('explicit Twig fraction attributes take precedence over stored settings', function (): void {
    $currency = (new Currency('THB'))->setFractionDigits(0);
    $repository = Mockery::mock(CurrencyRepository::class);
    $repository->expects('findOneByCode')->with('THB')->once()->andReturn($currency);

    $em = Mockery::mock(Doctrine\ORM\EntityManager::class);
    $em->expects('getRepository')->with(Currency::class)->andReturn($repository);

    $di = new Pimple\Container();
    $di['em'] = $em;

    $service = new Service();
    $service->setDi($di);

    expect($service->formatCurrency(1234, 'THB', ['fraction_digit' => 2], 'th_TH'))->toBe('฿1,234.00');
});

test('ClientFence currency formatting decorates the IntlExtra Twig filter', function (): void {
    $currencyService = Mockery::mock(Service::class);
    $currencyService->expects('formatCurrency')
        ->with(1234, 'THB', [], null)
        ->andReturn('1,234 บาท');

    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(
        fn (string $module): Service => $currencyService,
    );

    $twig = new Environment(new ArrayLoader([
        'currency' => '{{ 1234|format_currency("THB") }}',
    ]));
    $twig->addExtension(new IntlExtension());
    $twig->addExtension(new AttributeExtension(ClientFenceExtension::class));
    $twig->addRuntimeLoader(new FactoryRuntimeLoader([
        ClientFenceExtension::class => fn (): ClientFenceExtension => new ClientFenceExtension($di),
    ]));

    expect($twig->render('currency'))->toBe('1,234 บาท');
});

test('currency formatting settings are normalized and can be reset', function (): void {
    $currency = new Currency('THB');
    $repository = Mockery::mock(CurrencyRepository::class);
    $repository->shouldReceive('forgetCached');
    $repository->expects('findOneByCode')->with('THB')->twice()->andReturn($currency);

    $em = Mockery::mock(Doctrine\ORM\EntityManager::class);
    $em->expects('getRepository')->with(Currency::class)->andReturn($repository);
    $em->expects('persist')->with($currency)->twice();
    $em->expects('flush')->twice();

    $di = new Pimple\Container();
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service = new Service();
    $service->setDi($di);

    expect($service->updateCurrency('THB', null, [
        'format_pattern' => ' {amount} บาท ',
        'fraction_digits' => '0',
    ]))->toBeTrue()
        ->and($currency->getFormatPattern())->toBe('{amount} บาท')
        ->and($currency->getFractionDigits())->toBe(0);

    expect($service->updateCurrency('THB', null, [
        'format_pattern' => '',
        'fraction_digits' => '',
    ]))->toBeTrue()
        ->and($currency->getFormatPattern())->toBeNull()
        ->and($currency->getFractionDigits())->toBeNull();
});

dataset('invalid currency formatting settings', [
    ['บาท', '0'],
    ['{amount} {amount}', '0'],
    ['{amount}' . PHP_EOL . 'บาท', '0'],
    ['{amount}', '-1'],
    ['{amount}', '7'],
    ['{amount}', '1.5'],
    ['{amount}', true],
    [123, '0'],
]);

test('invalid currency formatting settings are rejected', function (mixed $pattern, mixed $fractionDigits): void {
    $currency = new Currency('THB');
    $repository = Mockery::mock(CurrencyRepository::class);
    $repository->allows('findOneByCode')->with('THB')->andReturn($currency);

    $em = Mockery::mock(Doctrine\ORM\EntityManager::class);
    $em->expects('getRepository')->with(Currency::class)->andReturn($repository);
    $em->shouldNotReceive('persist');
    $em->shouldNotReceive('flush');

    $di = new Pimple\Container();
    $di['em'] = $em;

    $service = new Service();
    $service->setDi($di);

    expect(fn (): bool => $service->updateCurrency('THB', 2.0, [
        'format_pattern' => $pattern,
        'fraction_digits' => $fractionDigits,
    ]))
        ->toThrow(InformationException::class)
        ->and($currency->getConversionRate())->toBe(1.0);
})->with('invalid currency formatting settings');

// ClientFence: Arabic money in Latin digits (owner's choice); other languages as their locale formats them.
test('Arabic amounts are formatted in Latin digits', function (): void {
    $repository = Mockery::mock(CurrencyRepository::class);
    $repository->allows('findOneByCode')->andReturn(new Currency('USD'));
    $em = Mockery::mock(Doctrine\ORM\EntityManager::class);
    $em->allows('getRepository')->andReturn($repository);
    $di = new Pimple\Container();
    $di['em'] = $em;
    $service = new Service();
    $service->setDi($di);

    expect($service->formatCurrency(48, 'USD', locale: 'ar_SA'))->toContain('48')->not->toMatch('/[٠-٩]/u')
        ->and($service->formatCurrency(1200.5, 'USD', locale: 'de_DE'))->toBe((new IntlExtension())->formatCurrency(1200.5, 'USD', locale: 'de_DE'))
        ->and($service->formatCurrency(3.69, 'USD', locale: 'ar_SA'))->toBe('$3.69')
        ->and(Service::moneyLocale('ar_EG'))->toBe('en_US')
        ->and(Service::moneyLocale('fa_IR'))->toBe('fa_IR');
});
