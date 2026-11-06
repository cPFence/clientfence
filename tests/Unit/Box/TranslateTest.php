<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

test('set locale', function (): void {
    $locale = 'en_US';
    $translateObj = new \ClientFence\Translate();
    $translateObj->setLocale($locale);
    $result = $translateObj->getLocale();

    expect($result)->toEqual($locale);
});

test('domain setter and getter', function (): void {
    $translateObj = new \ClientFence\Translate();

    $default = 'messages';
    $result = $translateObj->getDomain();
    expect($result)->toEqual($default);

    $newDomain = 'admin';
    $result = $translateObj->setDomain($newDomain)->getDomain();
    expect($result)->toEqual($newDomain);
});

test('translate', function (): void {
    $text = 'Translate ME';
    $translateObj = new \ClientFence\Translate();
    $translateObj->setup();
    $result = __trans($text);

    expect($result)->toEqual($text);
});

test('setup configures the default locale used by Twig Intl filters', function (): void {
    $defaultLocale = Locale::getDefault();
    Locale::setDefault('en_US_POSIX');

    try {
        $translateObj = new \ClientFence\Translate();
        $translateObj->setLocale('en_US');
        $translateObj->setup();

        $intlExtension = new Twig\Extra\Intl\IntlExtension();

        expect(Locale::getDefault())->toBe('en_US')
            ->and($intlExtension->formatCurrency(5, 'USD'))->toBe('$5.00');
    } finally {
        Locale::setDefault($defaultLocale);
    }
});

/**
 * A minimal gettext .mo file.
 *
 * @param array<string, string> $entries
 */
function writeTestMo(string $file, array $entries): void
{
    ksort($entries, SORT_STRING);
    $count = count($entries);
    $ids = $strs = $table = $translations = '';
    $start = 28 + 16 * $count;
    foreach ($entries as $id => $str) {
        $table .= pack('VV', strlen((string) $id), $start + strlen($ids));
        $ids .= $id . "\0";
    }
    foreach ($entries as $str) {
        $translations .= pack('VV', strlen($str), $start + strlen($ids) + strlen($strs));
        $strs .= $str . "\0";
    }
    file_put_contents($file, pack('V7', 0x950412DE, 0, $count, 28, 28 + 8 * $count, 0, 0) . $table . $translations . $ids . $strs);
}

test('setup adds a module\'s own catalogue and keeps the main translation of a shared text', function (): void {
    $dir = PATH_MODS . '/Zztranslatetest/locale/ar_SA/LC_MESSAGES';
    mkdir($dir, 0o755, true);
    writeTestMo($dir . '/messages.mo', ['Hello from a module' => 'مرحبا من وحدة', 'Dashboard' => 'module wording']);

    try {
        $translate = new \ClientFence\Translate();
        $translate->setLocale('ar_SA');
        $translate->setup();

        // The tests stub __trans(), so this asks the catalogue it reads.
        expect(_gettext('Hello from a module'))->toBe('مرحبا من وحدة')
            ->and(_gettext('Dashboard'))->not->toBe('module wording')
            ->and(_gettext('Dashboard'))->not->toBe('Dashboard');
    } finally {
        unlink($dir . '/messages.mo');
        rmdir($dir);
        rmdir(dirname($dir));
        rmdir(dirname($dir, 2));
        rmdir(dirname($dir, 3));
        (new \ClientFence\Translate())->setLocale('en_US')->setup();
    }
});
