<?php

declare(strict_types=1);

test('registrar HTTP clients default to an 80 second total duration', function (): void {
    $client = (new Registrar_Adapter_Custom([]))->getHttpClient();
    $defaults = (new ReflectionProperty($client, 'defaultOptions'))->getValue($client);

    expect($defaults['max_duration'])->toEqual(80);
});
