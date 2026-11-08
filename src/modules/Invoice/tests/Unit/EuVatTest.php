<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Client\Entity\Client;
use ClientFence\Mod\Invoice\EuVat;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function Tests\Helpers\container;

/** A container whose settings are $params and whose VIES answers are $answers (a userError each, or null for down). */
function euVatDi(array $params, array $answers = []): Pimple\Container
{
    $di = container();
    $params += ['tax_enabled' => '1', 'eu_vat_check' => '1', 'eu_vat_exempt' => '1', 'tax_home_country' => 'NL'];
    $system = Mockery::mock();
    $system->shouldReceive('getParamValue')->andReturnUsing(fn (string $key, $default = null) => $params[$key] ?? $default);
    $system->shouldReceive('setParamValue');
    $di['mod_service'] = $di->protect(fn () => $system);
    $di['logger'] = Mockery::mock()->shouldIgnoreMissing();
    $di['http_client'] = new MockHttpClient(array_map(
        fn (?string $answer) => $answer === null ? new MockResponse('', ['http_code' => 503]) : new MockResponse(json_encode(['userError' => $answer])),
        $answers
    ));

    return $di;
}

function euVatClient(string $country, ?string $vat, bool $exempt = false): Client
{
    return (new Client())->setCountry($country)->setCompanyVat($vat)->setTaxExempt($exempt);
}

test('a valid VAT number outside the tax home makes the client tax exempt', function (): void {
    $client = euVatClient('DE', 'de 123 456 789');
    EuVat::apply(euVatDi([], ['VALID']), $client, null, null, false);
    expect($client->isTaxExempt())->toBeTrue()->and($client->getCompanyVat())->toBe('DE123456789');
});

test('a valid VAT number in the tax home keeps VAT', function (): void {
    $client = euVatClient('NL', 'NL123456789B01', true);
    EuVat::apply(euVatDi([], ['VALID']), $client, null, null, false);
    expect($client->isTaxExempt())->toBeFalse();
});

test('a wrong new VAT number is refused', function (): void {
    EuVat::apply(euVatDi([], ['INVALID']), euVatClient('DE', '999'), null, 'DE', false);
})->throws(InformationException::class);

test('a saved number found invalid on a later save loses the exemption', function (): void {
    $client = euVatClient('DE', 'DE123456789', true);
    EuVat::apply(euVatDi([], ['INVALID']), $client, 'DE123456789', 'DE', true);
    expect($client->isTaxExempt())->toBeFalse();
});

test('when VIES does not answer, a new number is saved and VAT stays', function (): void {
    $client = euVatClient('FR', 'FR12345678901', true);
    EuVat::apply(euVatDi([], [null]), $client, null, 'FR', false);
    expect($client->isTaxExempt())->toBeFalse()->and($client->getCompanyVat())->toBe('FR12345678901');
});

test('a Greek number is checked as EL', function (string $typed): void {
    $client = euVatClient('GR', $typed);
    EuVat::apply(euVatDi([], ['VALID']), $client, null, null, false);
    expect($client->getCompanyVat())->toBe('EL094014201')->and($client->isTaxExempt())->toBeTrue();
})->with(['094014201', 'GR094014201', 'EL 094014201']);

test('a client leaving the EU loses the exemption', function (string $country): void {
    $client = euVatClient($country, '', true);
    EuVat::apply(euVatDi([]), $client, 'DE123456789', 'DE', true);
    expect($client->isTaxExempt())->toBeFalse();
})->with(['US', '']);

test('nothing is checked or changed', function (Client $client, array $params, bool $recheck): void {
    EuVat::apply(euVatDi($params), $client, 'DE123456789', $client->getCountry(), $recheck);
    expect($client->isTaxExempt())->toBeTrue();
})->with([
    'checks off' => [fn () => euVatClient('DE', 'DE999', true), ['eu_vat_check' => '0'], true],
    'tax off' => [fn () => euVatClient('DE', 'DE999', true), ['tax_enabled' => '0'], true],
    'outside the EU' => [fn () => euVatClient('US', '12-3456789', true), [], true],
    'unchanged and not sent' => [fn () => euVatClient('DE', 'DE123456789', true), [], false],
]);

test('a valid number changes no exemption without a tax home', function (): void {
    $client = euVatClient('DE', 'DE123456789');
    EuVat::apply(euVatDi(['tax_home_country' => ''], ['VALID']), $client, null, null, false);
    expect($client->isTaxExempt())->toBeFalse();
});

test('the reverse-charge note shows for an untaxed invoice to an EU business abroad', function (array $row, float $tax, bool $note): void {
    expect(EuVat::reverseCharge(euVatDi([]), $row + ['buyer_country' => 'DE', 'buyer_company_vat' => 'DE123456789'], $tax))->toBe($note);
})->with([
    'business abroad' => [[], 0.0, true],
    'taxed' => [[], 19.0, false],
    'no VAT number' => [['buyer_company_vat' => ''], 0.0, false],
    'home country' => [['buyer_country' => 'NL'], 0.0, false],
    'outside the EU' => [['buyer_country' => 'US'], 0.0, false],
]);

test('the daily recheck makes clients with a number VIES now finds valid tax exempt', function (): void {
    $di = euVatDi([], ['VALID', 'INVALID']);
    $dbal = Mockery::mock(Doctrine\DBAL\Connection::class);
    $dbal->shouldReceive('fetchAllAssociative')->once()->withArgs(fn (string $sql, array $args) => !in_array('NL', $args[0], true))
        ->andReturn([['id' => 7, 'country' => 'DE', 'company_vat' => 'DE123456789'], ['id' => 8, 'country' => 'FR', 'company_vat' => 'FR1']]);
    $dbal->shouldReceive('executeStatement')->once()->with('UPDATE client SET tax_exempt = 1 WHERE id = ?', [7]);
    $di['dbal'] = $dbal;
    EuVat::recheck($di);
});
