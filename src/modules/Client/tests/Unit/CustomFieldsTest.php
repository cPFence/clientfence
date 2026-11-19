<?php

declare(strict_types=1);

/**
 * ClientFence: the client custom fields as WHMCS's Custom Client Fields (Client/CustomFields.php): settings from
 * before these options, the settings page's checks, the answers each area may give, what the client API shows,
 * and the copy a new invoice keeps.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Client\CustomFields;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;

/** A store's custom fields: an IVA Drop Down translated to Spanish, an admin only CUIT, a password, a link, a box. */
function customFieldsConfig(): array
{
    return ['custom_fields' => [
        'custom_1' => ['title' => 'IVA status', 'active' => '1', 'required' => '1', 'type' => 'dropdown', 'options' => 'Responsable Inscripto, Monotributista,Exento', 'order' => '2', 'show_invoice' => '1',
            'translations' => ['es_ES' => ['title' => 'Condición IVA', 'options' => 'Resp. Inscripto,Monotributo']]],
        'custom_2' => ['title' => 'CUIT', 'active' => '1', 'admin_only' => '1', 'show_invoice' => '1', 'regex' => '/^[0-9]{2}-[0-9]{8}-[0-9]$/', 'order' => '1'],
        'custom_3' => ['title' => 'Panel password', 'active' => '1', 'type' => 'password', 'show_invoice' => '1', 'order' => '3'],
        'custom_4' => ['title' => 'Website', 'active' => '1', 'type' => 'link', 'show_order' => '0', 'order' => '4'],
        'custom_5' => ['title' => 'Newsletter', 'active' => '1', 'type' => 'tickbox', 'show_invoice' => '1', 'order' => '5'],
        'custom_6' => ['title' => 'Unused', 'active' => '0', 'show_invoice' => '1'],
    ]];
}

test('settings saved before these options read as they worked: a shown Text Box, not admin only, not on invoices, by title', function (): void {
    $config = ['custom_fields' => [
        'custom_2' => ['title' => 'VAT Number', 'active' => '1', 'required' => '0'],
        'custom_1' => ['title' => 'Company Name', 'active' => '1', 'required' => '1'],
        'custom_3' => ['title' => 'Address', 'active' => '1'],
        'custom_4' => ['title' => 'Old', 'active' => '0'],
    ]];
    $fields = CustomFields::all($config);

    expect($fields['custom_1'])->toMatchArray(['type' => 'text', 'required' => true, 'admin_only' => false, 'show_order' => true, 'show_invoice' => false, 'regex' => '', 'order' => 0])
        // The client sees the active ones by title, as before; no invoice copy is made for them.
        ->and(array_keys(CustomFields::forClient($config)))->toBe(['custom_3', 'custom_1', 'custom_2'])
        ->and(CustomFields::invoiceCopy($config, ['custom_1' => 'Acme', 'custom_2' => 'X1'], 'en_US'))->toBeNull()
        // A sign-up and a profile save with plain answers keep working.
        ->and(CustomFields::input($config, ['custom_1' => 'Acme', 'custom_2' => ''], CustomFields::SIGNUP, [], true))->toBe(['custom_1' => 'Acme', 'custom_2' => ''])
        ->and(CustomFields::all(null))->toHaveCount(20)
        ->and(CustomFields::all(['custom_fields' => 'invalid']))->toHaveCount(20);
});

test('the settings page refuses a broken validation, a Drop Down without options and an unknown type', function (array $field, string $message): void {
    expect(fn () => CustomFields::clean(['custom_1' => ['title' => 'CUIT', 'active' => '1'] + $field]))->toThrow(InformationException::class, $message);
})->with([
    'pattern PHP cannot run' => [['regex' => '/[0-9'], 'The validation of CUIT is not a regular expression PHP can use'],
    'pattern without delimiters' => [['regex' => '^[0-9]+$'], 'The validation of CUIT is not a regular expression PHP can use'],
    'Drop Down without options' => [['type' => 'dropdown', 'options' => ' , '], 'Enter the options of CUIT, separated by commas.'],
    'unknown type' => [['type' => 'radio'], 'Choose a field type for CUIT.'],
]);

test('the settings page keeps the slots and installed languages only, as booleans and numbers', function (): void {
    $clean = CustomFields::clean(['custom_1' => ['title' => ' IVA ', 'active' => '1', 'admin_only' => '0', 'order' => '3', 'type' => 'dropdown', 'options' => 'A,B',
        'translations' => ['es_ES' => ['title' => 'IVA es', 'description' => '', 'options' => ''], 'xx_YY' => ['title' => 'nope'], 'de_DE' => ['title' => '']]],
        'custom_21' => ['title' => 'Too far'], 'other' => ['title' => 'Not a slot']]);

    expect(array_keys($clean))->toBe(['custom_1'])
        ->and($clean['custom_1'])->toMatchArray(['title' => 'IVA', 'active' => true, 'admin_only' => false, 'order' => 3, 'show_order' => true, 'translations' => ['es_ES' => ['title' => 'IVA es']]]);
});

test('a sign-up must answer the required fields on the order form; others it may not set', function (): void {
    $config = customFieldsConfig();

    expect(fn () => CustomFields::input($config, [], CustomFields::SIGNUP, [], true))->toThrow(InformationException::class, 'Field IVA status cannot be empty');

    $data = CustomFields::input($config, ['custom_1' => 'Exento', 'custom_2' => '20-12345678-9', 'custom_4' => 'https://example.com', 'custom_5' => 'on', 'custom_6' => 'x', 'email' => 'a@b.test'], CustomFields::SIGNUP, [], true);

    // Admin only, off-the-order-form and inactive fields are left out; the rest of the sign-up is untouched.
    expect($data)->toBe(['custom_1' => 'Exento', 'custom_5' => 'on', 'email' => 'a@b.test']);
});

test('answers must fit their type and validation', function (string $name, string $value, string $message): void {
    expect(fn () => CustomFields::input(customFieldsConfig(), [$name => $value], CustomFields::STAFF))->toThrow(InformationException::class, $message);
})->with([
    'not an option' => ['custom_1', 'Consumidor Final', 'Choose one of the options for IVA status.'],
    'script link' => ['custom_4', 'javascript:alert(1)', 'Enter a web address starting with http:// or https:// for Website.'],
    'not a link' => ['custom_4', 'example.com', 'Enter a web address starting with http:// or https:// for Website.'],
    'pattern' => ['custom_2', '20123456789', 'The value of CUIT is not valid.'],
]);

test('a pattern that backtracks without end refuses the answer at once instead of hanging the request', function (): void {
    $config = ['custom_fields' => ['custom_1' => ['title' => 'Code', 'active' => '1', 'regex' => '/^(a+)+$/']]];
    $started = microtime(true);

    expect(fn () => CustomFields::input($config, ['custom_1' => str_repeat('a', 5000) . '!'], CustomFields::CLIENT))->toThrow(InformationException::class, 'The value of Code is not valid.')
        ->and(microtime(true) - $started)->toBeLessThan(2.0)
        ->and(CustomFields::input($config, ['custom_1' => 'aaa'], CustomFields::CLIENT))->toBe(['custom_1' => 'aaa'])
        // The limit is put back for the rest of the request.
        ->and(ini_get('pcre.backtrack_limit'))->not->toBe('100000');
});

test('a client sets only the fields it sees, keeps a saved password with a blank or masked answer, and cannot empty a required field', function (): void {
    $config = customFieldsConfig();
    $saved = ['custom_1' => 'Exento', 'custom_2' => '20-12345678-9', 'custom_3' => 'hunter2'];

    $data = CustomFields::input($config, ['custom_1' => 'Exento', 'custom_2' => 'changed by the client', 'custom_3' => CustomFields::MASK, 'custom_4' => 'https://x.test', 'custom_6' => 'x'], CustomFields::CLIENT, $saved);
    expect($data)->toBe(['custom_1' => 'Exento', 'custom_4' => 'https://x.test'])
        ->and(CustomFields::input($config, ['custom_3' => ''], CustomFields::CLIENT, $saved))->toBe([])
        ->and(CustomFields::input($config, ['custom_3' => 'new-secret'], CustomFields::CLIENT, $saved))->toBe(['custom_3' => 'new-secret'])
        // The language menu sends only `lang`: nothing is checked or changed.
        ->and(CustomFields::input($config, ['lang' => 'es_ES'], CustomFields::CLIENT, $saved))->toBe(['lang' => 'es_ES'])
        ->and(fn () => CustomFields::input($config, ['custom_1' => ''], CustomFields::CLIENT, $saved))->toThrow(InformationException::class, 'Field IVA status cannot be empty');
});

test('staff set every field, an older answer is not checked again, and a tick box saves as on or blank', function (): void {
    $config = customFieldsConfig();
    $saved = ['custom_1' => 'Old option', 'custom_2' => 'imported-cuit'];

    expect(CustomFields::input($config, ['custom_1' => 'Old option', 'custom_2' => 'imported-cuit', 'custom_5' => '1', 'custom_6' => 'free text'], CustomFields::STAFF, $saved))
        ->toBe(['custom_1' => 'Old option', 'custom_2' => 'imported-cuit', 'custom_5' => 'on', 'custom_6' => 'free text'])
        ->and(CustomFields::input($config, ['custom_2' => '20-12345678-9', 'custom_5' => '', 'custom_1' => ''], CustomFields::STAFF, $saved))->toBe(['custom_2' => '20-12345678-9', 'custom_5' => '', 'custom_1' => ''])
        ->and(fn () => CustomFields::input($config, ['custom_2' => 'new-bad'], CustomFields::STAFF, $saved))->toThrow(InformationException::class, 'The value of CUIT is not valid.');
});

test('the client API leaves out admin only and inactive answers and masks a saved password', function (): void {
    $config = customFieldsConfig();
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 5, 'custom_1' => 'Exento', 'custom_2' => '20-12345678-9', 'custom_3' => 'hunter2', 'custom_6' => 'old']);
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'client' ? $config : []);
    $service = new ClientFence\Mod\Client\Service();
    $service->setDi($di);

    $di['mod_service'] = $di->protect(fn (string $name): object => new class {
        public function hasPermission(mixed $member, string $module, ?string $key = null): bool
        {
            return $member instanceof Model_Admin;
        }
    });
    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());

    $own = $service->toApiArray($client, false, $client);
    $staff = $service->toApiArray($client, false, $admin);

    expect($own)->toMatchArray(['custom_1' => 'Exento', 'custom_3' => CustomFields::MASK])
        ->and($own)->not->toHaveKeys(['custom_2', 'custom_6'])
        ->and((string) json_encode($own))->not->toContain('hunter2')
        ->and($staff)->toMatchArray(['custom_2' => '20-12345678-9', 'custom_3' => 'hunter2']);
});

test('the guest API lists the visible fields in the page language without the staff settings', function (): void {
    $config = customFieldsConfig();
    $di = container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $config);
    $guest = new ClientFence\Mod\Client\Api\Guest();
    $guest->setDi($di);

    $previous = Locale::getDefault();
    Locale::setDefault('es_ES');
    try {
        $fields = $guest->custom_fields();
    } finally {
        Locale::setDefault($previous);
    }

    expect(array_keys($fields))->toBe(['custom_1', 'custom_3', 'custom_4', 'custom_5'])
        ->and($fields['custom_1'])->toBe([
            'title' => 'Condición IVA', 'active' => true, 'required' => true, 'type' => 'dropdown', 'description' => '',
            // A translated option keeps the default text as its value; one without a translation shows the default.
            'options' => [['value' => 'Responsable Inscripto', 'label' => 'Resp. Inscripto'], ['value' => 'Monotributista', 'label' => 'Monotributo'], ['value' => 'Exento', 'label' => 'Exento']],
            'show_order' => true,
        ])
        ->and($fields['custom_4']['show_order'])->toBeFalse()
        ->and((string) json_encode($fields))->not->toContain('CUIT')->not->toContain('regex')->not->toContain('translations');
});

test('a new invoice copies the Show on Invoice answers in the client\'s language, never a password', function (): void {
    $config = customFieldsConfig();
    $values = ['custom_1' => 'Responsable Inscripto', 'custom_2' => '20-12345678-9', 'custom_3' => 'hunter2', 'custom_5' => 'on', 'custom_6' => 'inactive'];

    $copy = CustomFields::invoiceCopy($config, $values, 'es_ES');

    // By Display Order: the admin only CUIT goes on the invoice too, as WHMCS allows.
    expect(CustomFields::fromInvoice($copy))->toBe([
        ['title' => 'CUIT', 'value' => '20-12345678-9'],
        ['title' => 'Condición IVA', 'value' => 'Resp. Inscripto'],
        ['title' => 'Newsletter', 'value' => 'Yes'],
    ])
        ->and($copy)->not->toContain('hunter2')
        ->and(CustomFields::invoiceCopy($config, ['custom_1' => 'Exento'], 'en_US'))->toBe('[{"title":"IVA status","value":"Exento"}]')
        // An older 0 in a field now a Checkbox shows unticked, so it is not printed as Yes.
        ->and(CustomFields::invoiceCopy($config, ['custom_5' => '0'], 'en_US'))->toBeNull()
        ->and(CustomFields::fromInvoice(null))->toBe([])
        ->and(CustomFields::fromInvoice('not json'))->toBe([])
        ->and(CustomFields::fromInvoice('[{"title":"A"},{"title":["x"],"value":"y"},{"title":"B","value":2}]'))->toBe([['title' => 'B', 'value' => '2']]);
});

/** A stand-in for the install's encryption: reversible, and refuses what it did not write. */
function customFieldsCrypt(): object
{
    return new class {
        public function encrypt(string $text): string
        {
            return 'k1:' . strrev($text);
        }

        public function decrypt(?string $text): string|false
        {
            return is_string($text) && str_starts_with($text, 'k1:') ? strrev(substr($text, 3)) : false;
        }
    };
}

test('a Password answer is stored encrypted on every save path and read back in clear only for staff', function (): void {
    $config = customFieldsConfig();
    $di = container();
    $di['crypt'] = customFieldsCrypt();

    // Sign-up (and checkout), the client's profile and staff all store it sealed.
    $signup = CustomFields::input($config, ['custom_1' => 'Exento', 'custom_3' => ' p@ss word '], CustomFields::SIGNUP, [], true, $di);
    $profile = CustomFields::input($config, ['custom_3' => 'second'], CustomFields::CLIENT, ['custom_3' => $signup['custom_3']], false, $di);
    $staff = CustomFields::input($config, ['custom_3' => 'third'], CustomFields::STAFF, [], false, $di);

    expect($signup['custom_3'])->toStartWith(CustomFields::SEALED)->not->toContain('p@ss')
        // A password keeps its spaces.
        ->and(CustomFields::open($di, $signup['custom_3']))->toBe(' p@ss word ')
        ->and(CustomFields::open($di, $profile['custom_3']))->toBe('second')
        ->and(CustomFields::open($di, $staff['custom_3']))->toBe('third')
        // The client sending the mask or a blank keeps the sealed answer; staff saving the clear one they see re-seal it.
        ->and(CustomFields::input($config, ['custom_3' => CustomFields::MASK], CustomFields::CLIENT, ['custom_3' => $signup['custom_3']], false, $di))->toBe([])
        ->and(CustomFields::open($di, CustomFields::input($config, ['custom_3' => 'p@ss word'], CustomFields::STAFF, ['custom_3' => $signup['custom_3']], false, $di)['custom_3']))->toBe('p@ss word')
        // A typed value that looks sealed is sealed too (never stored in clear); a blank stays blank.
        ->and(CustomFields::open($di, CustomFields::input($config, ['custom_3' => CustomFields::SEALED . 'x'], CustomFields::CLIENT, [], false, $di)['custom_3']))->toBe(CustomFields::SEALED . 'x')
        ->and(CustomFields::seal($di['crypt']->encrypt(...), ''))->toBe('')
        // An older plain value reads as it is; a sealed one this key cannot read is null (blank for staff).
        ->and(CustomFields::open($di, 'plain-old'))->toBe('plain-old')
        ->and(CustomFields::open($di, CustomFields::SEALED . 'other-key'))->toBeNull()
        ->and(CustomFields::staffValues($di, $config, ['custom_3' => $signup['custom_3'], 'custom_1' => 'Exento']))->toBe(['custom_3' => ' p@ss word ', 'custom_1' => 'Exento'])
        ->and(CustomFields::staffValues($di, $config, ['custom_3' => CustomFields::SEALED . 'other-key']))->toBe(['custom_3' => ''])
        // The client only ever sees the mask, and no invoice gets it.
        ->and(CustomFields::clientValues($config, ['custom_3' => $signup['custom_3']]))->toBe(['custom_3' => CustomFields::MASK])
        ->and(CustomFields::maskPasswords($config, ['custom_3' => $signup['custom_3'], 'custom_1' => 'Exento']))->toBe(['custom_3' => CustomFields::MASK, 'custom_1' => 'Exento'])
        ->and(CustomFields::invoiceCopy($config, ['custom_3' => $signup['custom_3']], 'en_US'))->toBeNull();
});

test('staff API output opens the sealed password; the client\'s own output masks it', function (): void {
    $config = customFieldsConfig();
    $di = container();
    $di['crypt'] = customFieldsCrypt();
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'client' ? $config : []);
    $sealed = CustomFields::seal($di['crypt']->encrypt(...), 'hunter2');
    $client = createEntity(ClientFence\Mod\Client\Entity\Client::class, ['id' => 5, 'custom_3' => $sealed]);
    $service = new ClientFence\Mod\Client\Service();
    $service->setDi($di);

    $staff = new class {
        public bool $canView = true;

        public function hasPermission(mixed $member, string $module, ?string $key = null): bool
        {
            // The serializer's own identity is checked: a payment webhook has no signed-in admin to look up.
            if (!$member instanceof Model_Admin) {
                throw new RuntimeException('Admin is not logged in');
            }

            return $module === 'client' && $key === 'view' && $this->canView;
        }
    };
    $di['mod_service'] = $di->protect(fn (string $name): object => $staff);
    $admin = new Model_Admin();
    $admin->loadBean(new Tests\Helpers\DummyBean());

    expect($service->toApiArray($client, false, $admin)['custom_3'])->toBe('hunter2')
        ->and($service->toApiArray($client, false, $client)['custom_3'])->toBe(CustomFields::MASK);
    // Staff without client view (a support-only member reading a ticket) get it masked.
    $staff->canView = false;
    expect($service->toApiArray($client, false, $admin)['custom_3'])->toBe(CustomFields::MASK);
});

test('event logs mask client custom fields, which can hold a password before it is sealed', function (): void {
    $log = new ClientFence\Log();
    $mask = new ReflectionMethod($log, 'maskParams');

    expect($mask->invoke($log, [['custom_3' => 'hunter2', 'email' => 'a@client.test']]))->toBe([['custom_3' => '********', 'email' => 'a@client.test']]);
});
