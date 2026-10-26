<?php

declare(strict_types=1);

use ClientFence\Exception;

dataset('validTargets', fn (): array => [
    ['/new-page', '/new-page'],
    ['new-page', 'new-page'],
    ['http://example.com/page', 'http://example.com/page'],
    ['https://example.com/page', 'https://example.com/page'],
]);

dataset('invalidTargets', fn (): array => [
    [''],
    ['   '],
    ['javascript:alert(1)'],
    ['JaVaScRiPt:alert(1)'],
    ['data:text/html,<h1>test</h1>'],
    ['ftp://example.com/file'],
    ["/new-page\r\nLocation: https://evil.com"],
    ["/new-page\nLocation: https://evil.com"],
    ["/new-page\rLocation: https://evil.com"],
]);

dataset('validPaths', fn (): array => [
    ['/old-page/', 'old-page'],
    ['/some/nested/path/', 'some/nested/path'],
    ['old%20page', 'old%20page'],
]);

dataset('invalidPaths', fn (): array => [
    [''],
    ['/'],
    ['../config'],
    ['foo/../../etc/passwd'],
    ["old-page\r\nX-Header: evil"],
    ['/config/'],
    ['old page'],
    ['promo:spring'],
    ['page?id=1'],
    ['café'],
]);

test('validate target allows valid targets', function (string $input, string $expected): void {
    $service = new ClientFence\Mod\Redirect\Service();
    expect($service->validateTarget($input))->toBe($expected);
})->with('validTargets');

test('validate target rejects invalid targets', function (string $input): void {
    $service = new ClientFence\Mod\Redirect\Service();
    expect(fn (): string => $service->validateTarget($input))->toThrow(Exception::class);
})->with('invalidTargets');

test('validate target trims whitespace', function (): void {
    $service = new ClientFence\Mod\Redirect\Service();
    expect($service->validateTarget('  /new-page  '))->toBe('/new-page');
});

test('validate path strips slashes', function (string $input, string $expected): void {
    $service = new ClientFence\Mod\Redirect\Service();
    expect($service->validatePath($input))->toBe($expected);
})->with('validPaths');

test('validate path rejects invalid paths', function (string $input): void {
    $service = new ClientFence\Mod\Redirect\Service();
    expect(fn (): string => $service->validatePath($input))->toThrow(Exception::class);
})->with('invalidPaths');

test('the module settings row is not a redirect', function (): void {
    $settings = (new ClientFence\Mod\Extension\Entity\ExtensionMeta())->setExtension('mod_redirect')->setMetaKey('config')->setMetaValue(null);
    $redirect = (new ClientFence\Mod\Extension\Entity\ExtensionMeta())->setExtension('mod_redirect')->setMetaKey('old-page')->setMetaValue('{"target":"/new-page","type":302,"enabled":false}');
    $repository = Mockery::mock(ClientFence\Mod\Extension\Repository\ExtensionMetaRepository::class);
    $repository->shouldReceive('findByExtensionAndScope')->andReturn([$settings, $redirect]);
    $repository->shouldReceive('findOneByExtensionAndId')->andReturn($settings);
    $repository->shouldNotReceive('findOneByExtensionAndScope');
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->andReturn($repository);
    $di = new Pimple\Container();
    $di['em'] = $em;
    $service = new ClientFence\Mod\Redirect\Service();
    $service->setDi($di);

    expect($service->getRedirects())->toBe([['id' => null, 'path' => 'old-page', 'target' => '/new-page', 'type' => 302, 'enabled' => false]])
        ->and($service->getRedirectByPath('config'))->toBeNull()
        ->and(fn () => $service->get(1))->toThrow(Exception::class);
});

function redirectService(?ClientFence\Mod\Extension\Entity\ExtensionMeta $existing = null): ClientFence\Mod\Redirect\Service
{
    $repository = Mockery::mock(ClientFence\Mod\Extension\Repository\ExtensionMetaRepository::class);
    $repository->shouldReceive('findOneByExtensionAndScope')->andReturn($existing);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->andReturn($repository);
    $em->shouldReceive('persist', 'flush');
    $di = new Pimple\Container();
    $di['em'] = $em;
    $service = new ClientFence\Mod\Redirect\Service();
    $service->setDi($di);

    return $service;
}

function redirectRow(int $id, string $path, string $value): ClientFence\Mod\Extension\Entity\ExtensionMeta
{
    $redirect = (new ClientFence\Mod\Extension\Entity\ExtensionMeta())->setExtension('mod_redirect')->setMetaKey($path)->setMetaValue($value);
    (new ReflectionProperty($redirect, 'id'))->setValue($redirect, $id);

    return $redirect;
}

test('a path another redirect already uses is rejected', function (): void {
    $existing = redirectRow(5, 'old-page', '{"target":"new-page","type":301,"enabled":true}');
    $other = redirectRow(6, 'other', '{"target":"new-page","type":301,"enabled":true}');

    expect(fn () => redirectService($existing)->create('old-page', 'new-page'))->toThrow(Exception::class, 'A redirect for this path already exists.')
        ->and(fn () => redirectService($existing)->update($other, ['path' => 'old-page']))->toThrow(Exception::class, 'A redirect for this path already exists.')
        ->and(redirectService($existing)->update($existing, ['path' => 'old-page']))->toBeTrue();
});

test('the type and on/off are stored with the target, and an update keeps what it does not send', function (): void {
    $redirect = redirectRow(5, 'old-page', '{"target":"new-page","type":301,"enabled":true}');
    $service = redirectService($redirect);

    $service->update($redirect, ['enabled' => false]);
    expect($redirect->getMetaValue())->toBe('{"target":"new-page","type":301,"enabled":false}');

    $service->update($redirect, ['type' => '302', 'target' => 'https://example.com/a', 'enabled' => '1']);
    expect($redirect->getMetaValue())->toBe('{"target":"https://example.com/a","type":302,"enabled":true}')
        ->and($service->toApiArray($redirect))->toBe(['id' => 5, 'path' => 'old-page', 'target' => 'https://example.com/a', 'type' => 302, 'enabled' => true]);
});

test('a type other than 301 or 302, or an unreadable on/off, is rejected', function (mixed $type, mixed $enabled): void {
    expect(fn () => redirectService()->create('old-page', 'new-page', $type, $enabled))->toThrow(Exception::class);
})->with([
    [303, true],
    ['301abc', true],
    [301, 'maybe'],
]);
