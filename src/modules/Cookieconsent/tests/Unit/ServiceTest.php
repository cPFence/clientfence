<?php

declare(strict_types=1);

use ClientFence\Mod\Cookieconsent\Service;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

function cookieconsentService(array $config, ?object $extension = null): Service
{
    $di = new Pimple\Container();
    $di['mod_config'] = $di->protect(fn (string $name): array => $config);
    $di['mod_service'] = $di->protect(fn (string $name): ?object => $extension);
    $di['cache'] = new Symfony\Component\Cache\Adapter\ArrayAdapter();
    $service = new Service();
    $service->setDi($di);

    return $service;
}

test('an empty saved message shows the default message, not an empty banner', function (): void {
    expect(cookieconsentService(['message' => '  '])->getSettings())->toBe([
        'message' => 'This website uses cookies. By continuing to use this website, you consent to our use of these cookies.',
        'button' => 'Accept',
        'privacy_url' => '',
        'position' => 'bar',
    ]);
});

test('a privacy policy address that is not a web address is not linked', function (): void {
    expect(cookieconsentService(['privacy_url' => 'javascript:alert(1)'])->getSettings()['privacy_url'])->toBe('');
});

test('the settings are saved', function (): void {
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('setConfig')->once()->with([
        'ext' => 'mod_cookieconsent',
        'message' => 'We use cookies.',
        'button' => 'Got it',
        'privacy_url' => 'https://example.com/privacy',
        'position' => 'card',
        'translations' => ['de_DE' => ['message' => 'Wir verwenden Cookies.', 'button' => '']],
    ]);

    cookieconsentService([], $extension)->saveSettings([
        'message' => ' We use cookies. ',
        'button' => 'Got it',
        'privacy_url' => 'https://example.com/privacy',
        'position' => 'card',
        // A language left blank is dropped: it shows the default texts.
        'translations' => ['de_DE' => ['message' => ' Wir verwenden Cookies. ', 'button' => ' '], 'fr_FR' => ['message' => '', 'button' => '']],
    ]);
});

test('each language shows its own text, else the default one, else the built-in one', function (): void {
    $previous = Locale::getDefault();
    $service = cookieconsentService(['message' => 'We use cookies.', 'button' => '', 'translations' => ['de_DE' => ['message' => 'Wir verwenden Cookies.', 'button' => '']]]);
    try {
        Locale::setDefault('de_DE');
        $german = $service->getSettings();
        Locale::setDefault('fr_FR');
        $french = $service->getSettings();
    } finally {
        Locale::setDefault($previous);
    }

    expect([$german['message'], $german['button']])->toBe(['Wir verwenden Cookies.', 'Accept'])
        ->and([$french['message'], $french['button']])->toBe(['We use cookies.', 'Accept']);
});

test('the built-in texts the form used to save count as blank, own texts stay', function (): void {
    $legacy = cookieconsentService(['message' => 'This website uses cookies. By continuing to use this website, you consent to our use of these cookies.', 'button' => 'Accept'])->getConfig();
    $own = cookieconsentService(['message' => 'We use cookies.', 'button' => 'Got it'])->getConfig();

    expect([$legacy['message'], $legacy['button']])->toBe(['', ''])
        ->and([$own['message'], $own['button']])->toBe(['We use cookies.', 'Got it']);
});

test('invalid settings are refused', function (array $data): void {
    expect(fn () => cookieconsentService([])->saveSettings($data + ['message' => 'We use cookies.', 'position' => 'bar']))
        ->toThrow(ClientFence\InformationException::class);
})->with([
    'message too long' => [['message' => str_repeat('a', 1001)]],
    'unknown language' => [['translations' => ['xx_XX' => ['message' => 'Hi']]]],
    'javascript link' => [['privacy_url' => 'javascript:alert(1)']],
    'ftp link' => [['privacy_url' => 'ftp://example.com/privacy']],
    'not an address' => [['privacy_url' => 'privacy policy']],
    'unknown position' => [['position' => 'top']],
]);

test('the banner renders hidden with an accept button and the privacy link only when set', function (): void {
    $twig = new Environment(new FilesystemLoader(PATH_MODS . '/Cookieconsent/templates/client'), ['autoescape' => 'html']);
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $render = fn (array $settings): string => $twig->render('mod_cookieconsent_index.html.twig', [
        'guest' => ['cookieconsent_settings' => $settings + ['message' => 'We use <cookies>.', 'button' => 'Got it', 'position' => 'card']],
        'csp_nonce' => 'n0nce',
    ]);

    $html = $render(['privacy_url' => 'https://example.com/privacy']);
    expect($html)->toContain('class="cf-cookie cf-cookie-card" role="region" aria-label="Cookie notice" hidden>')
        ->toContain('We use &lt;cookies&gt;.')
        ->toContain('<a href="https://example.com/privacy">Privacy Policy</a>')
        ->toContain('>Got it</button>')
        ->toContain('<script nonce="n0nce">')
        ->not->toContain('btn-close')
        ->and($render(['privacy_url' => '']))->not->toContain('Privacy Policy');
});
