<?php

declare(strict_types=1);

/**
 * ClientFence: dates stay whole in right-to-left languages (RtlDateExtension) in the admin and
 * client areas.
 */

use ClientFence\Twig\Extension\RtlDateExtension;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\ArrayLoader;

function rtlDateRender(string $locale, string $template): string
{
    $twig = new Environment(new ArrayLoader(['t' => $template]));
    $twig->addExtension(new IntlExtension(new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, 'UTC')));
    $twig->addExtension(new RtlDateExtension($locale));

    return $twig->render('t', ['d' => '2026-10-15 09:30:00']);
}

test('dates in right-to-left languages are put in a right-to-left isolate', function (string $locale): void {
    foreach (['format_date', 'format_datetime', 'format_time'] as $filter) {
        $html = rtlDateRender($locale, '{{ d|' . $filter . ' }}');
        $plain = rtlDateRender($locale, '{{ d|' . $filter . '(locale="en_US") }}');

        expect($html)->toStartWith("\u{2067}")->toEndWith("\u{2069}")
            ->and(mb_strlen($html))->toBeGreaterThan(4)
            ->and($plain)->not->toContain("\u{2067}");
    }
})->with(['ar_SA', 'he_IL']);

test('dates in other languages are as IntlExtension writes them', function (): void {
    expect(rtlDateRender('en_US', '{{ d|format_date }}'))->toBe('Oct 15, 2026')
        ->and(rtlDateRender('en_US', '{{ d|format_datetime("short", "none") }}'))->toBe('10/15/26')
        ->and(rtlDateRender('en_US', '{{ d|format_date(locale="ar_SA") }}'))->toStartWith("\u{2067}");
});

test('right-to-left languages are told by their language code', function (): void {
    expect(RtlDateExtension::isRtl('ar_SA'))->toBeTrue()
        ->and(RtlDateExtension::isRtl('he'))->toBeTrue()
        ->and(RtlDateExtension::isRtl('fa_IR'))->toBeTrue()
        ->and(RtlDateExtension::isRtl('en_US'))->toBeFalse()
        ->and(RtlDateExtension::isRtl('de_DE'))->toBeFalse();
});

// CF-194: every page tells right-to-left languages from the one list, so Urdu and Yiddish pages read right to left too.
test('templates tell right-to-left languages with the rtl test', function (): void {
    $twig = new Environment(new ArrayLoader(['t' => '{{ ["ar_SA", "ur_PK", "yi", "fa", "en_US", "de", null]|map(l => l is rtl ? 1 : 0)|join }}']));
    $twig->addExtension(new Twig\Extension\AttributeExtension(ClientFence\Twig\Extension\ClientFenceExtension::class));
    $twig->addRuntimeLoader(new Twig\RuntimeLoader\FactoryRuntimeLoader([ClientFence\Twig\Extension\ClientFenceExtension::class => fn () => new ClientFence\Twig\Extension\ClientFenceExtension(null)]));
    expect($twig->render('t'))->toBe('1111000');

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PATH_MODS, FilesystemIterator::SKIP_DOTS));
    $themes = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PATH_THEMES, FilesystemIterator::SKIP_DOTS));
    $lists = [];
    foreach ([$files, $themes] as $tree) {
        foreach ($tree as $file) {
            if (str_ends_with($file->getFilename(), '.twig') && preg_match("/\\['ar', '(fa|he)'/", file_get_contents($file->getPathname()))) {
                $lists[] = $file->getPathname();
            }
        }
    }
    expect($lists)->toBe([]);
});
