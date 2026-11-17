<?php

declare(strict_types=1);

// ClientFence: the guest languages call takes the template's bool and the HTTP API's array.
test('guest languages accept an API array as well as a bool', function (): void {
    $api = apiEndpoint(new ClientFence\Mod\Extension\Api\Guest());

    expect($api->languages(['details' => '1']))->toBe(ClientFence\i18n::getLocales(true))
        ->and($api->languages(['foo' => 'bar']))->toBe(ClientFence\i18n::getLocales())
        ->and($api->languages(true))->toBe(ClientFence\i18n::getLocales(true));
});
