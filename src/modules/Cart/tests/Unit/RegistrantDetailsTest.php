<?php

declare(strict_types=1);

/**
 * ClientFence (CF-239): a domain registered or transferred in the client's name needs the client's address, city,
 * postcode, phone and country (the registrar contact, Servicedomain\Service::_getD()). The checkout and staff orders
 * refuse without them, before any order or invoice is made.
 */

use ClientFence\Mod\Cart\Entity\Cart;
use ClientFence\Mod\Cart\Service;
use ClientFence\Mod\Client\Users;

use function Tests\Helpers\container;

/** @param array<string, string|null> $fields */
function registrantClient(array $fields = []): Model_Client
{
    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    foreach ($fields + ['address_1' => '1 Main St', 'city' => 'Springfield', 'postcode' => '12345', 'phone' => '5551234', 'country' => 'US'] as $name => $value) {
        $client->{$name} = $value;
    }

    return $client;
}

/** Runs the check as createFromCart() does, for these cart items. */
function registrantCheck(array $items, Model_Client $client, bool $staff = false): void
{
    $service = new Service();
    $service->setDi(container());
    (new ReflectionMethod($service, 'assertRegistrantDetails'))->invoke($service, $items, $client, $staff);
}

dataset('registrant fields', [
    'address' => ['address_1', 'Address Line 1'],
    'city' => ['city', 'City'],
    'postcode' => ['postcode', 'Zip/Postal Code'],
    'phone' => ['phone', 'Phone Number'],
    'country' => ['country', 'Country'],
]);

test('a domain registration or transfer is refused without each registrant detail, naming it', function (string $field, string $label, string $action): void {
    $items = [['type' => 'domain', 'action' => $action]];

    expect(fn () => registrantCheck($items, registrantClient([$field => null])))
        ->toThrow(ClientFence\InformationException::class, "Add your {$label} to your profile: the domain is registered in your name and the registry needs them.")
        // Spaces only are no detail either.
        ->and(fn () => registrantCheck($items, registrantClient([$field => "  \t "])))
        ->toThrow(ClientFence\InformationException::class, "Add your {$label} to your profile")
        // Nor a no-break space, which PHP's trim() keeps.
        ->and(fn () => registrantCheck($items, registrantClient([$field => "\u{00A0} \u{2007}"])))
        ->toThrow(ClientFence\InformationException::class, "Add your {$label} to your profile");
})->with('registrant fields')->with(['register', 'transfer']);

test('every missing detail is named in one message, in the profile\'s order', function (): void {
    expect(fn () => registrantCheck([['type' => 'domain', 'action' => 'register']], registrantClient(['phone' => '', 'address_1' => null, 'postcode' => ' '])))
        ->toThrow(ClientFence\InformationException::class, 'Add your Address Line 1, Zip/Postal Code, Phone Number to your profile');
});

test('a client with every registrant detail registers or transfers a domain', function (string $action): void {
    registrantCheck([['type' => 'domain', 'action' => $action]], registrantClient());
    // The state and the second address line are not asked (many countries have none).
    registrantCheck([['type' => 'domain', 'action' => $action]], registrantClient(['state' => null, 'address_2' => null]));
    expect(true)->toBeTrue();
})->with(['register', 'transfer']);

test('a cart without a domain registration or transfer is never refused', function (array $items): void {
    registrantCheck($items, registrantClient(['address_1' => null, 'city' => null, 'postcode' => null, 'phone' => null, 'country' => null]));
    expect(true)->toBeTrue();
})->with([
    'hosting on the client\'s own domain' => [[['type' => 'hosting', 'domain' => ['action' => 'owndomain', 'owndomain_sld' => 'mine', 'owndomain_tld' => '.com']]]],
    'hosting on a subdomain' => [[['type' => 'hosting', 'domain' => ['action' => 'subdomain']]]],
    'hosting without a domain' => [[['type' => 'hosting']]],
    'a domain line without an action' => [[['type' => 'domain']]],
    'other products' => [[['type' => 'custom'], ['type' => 'license', 'action' => 'register']]],
    'nothing' => [[]],
]);

test('hosting with the domain it registers or transfers is refused without the details', function (string $action): void {
    // CF-146: the plan's registered or transferred domain is a cart line of its own (getDomainProductFromConfig()).
    $items = [
        ['type' => 'hosting', 'domain' => ['action' => $action]],
        ['type' => 'domain', 'action' => $action],
    ];

    expect(fn () => registrantCheck($items, registrantClient(['city' => ''])))
        ->toThrow(ClientFence\InformationException::class, 'Add your City to your profile');
})->with(['register', 'transfer']);

test('a staff order that registers a domain is refused with the staff text; an existing domain (Already set up) is not', function (): void {
    $client = registrantClient(['phone' => null]);

    expect(fn () => registrantCheck([['type' => 'domain', 'action' => 'register']], $client, true))
        ->toThrow(ClientFence\InformationException::class, "The client's profile has no Phone Number. A domain is registered in the client's name, so add them to the profile first.");
    // An imported domain gets no registrar action (Servicedomain\Service::action_activate()).
    registrantCheck([['type' => 'domain', 'action' => 'register', 'import' => true]], $client, true);
    registrantCheck([['type' => 'domain', 'action' => 'transfer', 'import' => '1']], $client, true);
});

test('createFromCart refuses before it makes an order, for a checkout and for a staff order and its quote', function (?array $staff, string $text): void {
    $cart = new Cart();
    $client = registrantClient(['postcode' => null]);
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('toApiArray')->with($cart, false, $client)->andReturn(['items' => [['id' => 1, 'type' => 'domain', 'action' => 'register']], 'total' => 10]);
    $service->shouldNotReceive('getCartProducts');
    $di = container();
    // Nothing is priced, written or set up: the currency service is the first step after the check.
    $di['mod_service'] = $di->protect(fn (string $name) => throw new RuntimeException("{$name} was called"));
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldNotReceive('wrapInTransaction');
    $di['em'] = $em;
    $service->setDi($di);

    expect(fn () => $service->createFromCart($client, null, $cart, $staff))->toThrow(ClientFence\InformationException::class, $text);
})->with([
    'client checkout' => [null, 'Add your Zip/Postal Code to your profile'],
    'staff order' => [['invoice' => true, 'activate' => true], "The client's profile has no Zip/Postal Code."],
    'staff quote' => [['invoice' => true, 'quote' => true], "The client's profile has no Zip/Postal Code."],
]);

test('the texts are translated with the field names as placeholders', function (): void {
    $source = (string) file_get_contents(PATH_MODS . '/Cart/Service.php');

    // Literal msgids (tools/locales.py extract reads them), the labels through __trans() (in the catalogue already).
    expect($source)->toContain("new \\ClientFence\\InformationException('Add your :fields to your profile: the domain is registered in your name and the registry needs them.', [':fields' => \$fields])")
        ->toContain("new \\ClientFence\\InformationException('The client\\'s profile has no :fields. A domain is registered in the client\\'s name, so add them to the profile first.', [':fields' => \$fields])")
        ->toContain('__trans(self::REGISTRANT_FIELDS[$field])');
});

test('the checkout pages read the missing details with the orders permission of an authorized user', function (): void {
    $service = new Service();
    $service->setDi(container());
    $api = new ClientFence\Mod\Cart\Api\Client();
    $api->setService($service);
    $api->setIdentity(registrantClient(['address_1' => ' ', 'phone' => null]));

    expect($api->registrant_missing([]))->toBe(['address_1', 'phone'])
        ->and(Users::CALLS['cart']['registrant_missing'])->toBe('orders');
    $api->setIdentity(registrantClient());
    expect($api->registrant_missing([]))->toBe([]);
});
