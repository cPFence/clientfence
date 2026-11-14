<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Sanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class BrowserHtmlSanitizer
{
    private static ?HtmlSanitizer $adapterSanitizer = null;
    private static ?HtmlSanitizer $themeSettingsSanitizer = null;

    public static function sanitizeAdapterHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        self::$adapterSanitizer ??= new HtmlSanitizer(self::createBaseConfig());

        return trim(self::$adapterSanitizer->sanitize(self::preSanitize($html)));
    }

    public static function sanitizeThemeSettingsHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        self::$themeSettingsSanitizer ??= new HtmlSanitizer(self::createThemeSettingsConfig());

        return trim(self::$themeSettingsSanitizer->sanitize(self::preSanitize($html)));
    }

    private static function createThemeSettingsConfig(): HtmlSanitizerConfig
    {
        return self::createBaseConfig()
            ->allowElement('input', [
                'type', 'name', 'value', 'id', 'checked', 'placeholder', 'accept',
                'multiple', 'min', 'max', 'step', 'readonly', 'disabled', 'required',
                'autocomplete', 'size', 'maxlength', 'minlength', 'pattern',
            ])
            ->allowElement('select', [
                'name', 'id', 'multiple', 'disabled', 'required', 'size',
            ])
            ->allowElement('option', [
                'value', 'selected', 'disabled', 'label',
            ])
            ->allowElement('optgroup', [
                'label', 'disabled',
            ])
            ->allowElement('textarea', [
                'name', 'id', 'rows', 'cols', 'placeholder', 'readonly', 'disabled',
                'required', 'maxlength', 'minlength', 'wrap',
            ])
            ->allowAttribute('class', '*')
            ->allowAttribute('id', '*')
            ->allowAttribute('title', '*')
            ->allowAttribute('for', ['label'])
            // Field names and hints for screen readers.
            ->allowAttribute('aria-labelledby', '*')
            ->allowAttribute('aria-describedby', '*');
    }

    private static function createBaseConfig(): HtmlSanitizerConfig
    {
        // ClientFence: these are the server's own templates (theme settings pages, adapter forms).
        // Symfony's default limit (20 000 bytes) silently cut longer forms, so the end of a
        // theme's settings page never showed and could not be set.
        return (new HtmlSanitizerConfig())
            ->withMaxInputLength(1_000_000)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https']);
    }

    private static function preSanitize(string $html): string
    {
        $html = str_replace("\0", '', $html);
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', (string) $html);
        $html = preg_replace('#<script\b[^>]*/?>#is', '', (string) $html);
        $html = preg_replace('#<style\b[^>]*/?>#is', '', (string) $html);

        return (string) $html;
    }
}
