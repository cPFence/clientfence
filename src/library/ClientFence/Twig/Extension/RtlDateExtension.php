<?php

declare(strict_types=1);

namespace ClientFence\Twig\Extension;

use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extra\Intl\IntlExtension;
use Twig\TwigFilter;

/**
 * ClientFence: format_date, format_datetime and format_time for the admin and client areas, as
 * Twig's IntlExtension formats them, kept whole in right-to-left languages.
 *
 * ICU writes Arabic dates with right-to-left marks between the parts ("15‏/10‏/2026") and Hebrew
 * dates with a Hebrew month name; inside left-to-right content (the admin page body, a
 * left-to-right table cell) the browser reorders the parts ("152026/10/"). In those languages
 * the date is put in a right-to-left isolate (U+2067 … U+2069), which lays it out as one
 * right-to-left unit wherever it stands. Other languages are unchanged.
 *
 * Registered after IntlExtension, so these filters replace its three (Twig keeps the last one).
 * Not used for emails or PDFs, whose readers may not support isolates.
 */
final class RtlDateExtension extends AbstractExtension
{
    /** Languages written right to left. */
    public const RTL_LANGUAGES = ['ar', 'he', 'fa', 'ur', 'yi'];

    public function __construct(private readonly string $locale)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_datetime', $this->formatDateTime(...), ['needs_environment' => true]),
            new TwigFilter('format_date', $this->formatDate(...), ['needs_environment' => true]),
            new TwigFilter('format_time', $this->formatTime(...), ['needs_environment' => true]),
        ];
    }

    public function formatDateTime(Environment $env, mixed $date, ?string $dateFormat = null, ?string $timeFormat = null, string $pattern = '', mixed $timezone = null, ?string $calendar = null, ?string $locale = null): string
    {
        return $this->isolate($env->getExtension(IntlExtension::class)->formatDateTime($env, $date, $dateFormat, $timeFormat, $pattern, $timezone, $calendar, $locale), $locale);
    }

    public function formatDate(Environment $env, mixed $date, ?string $dateFormat = null, string $pattern = '', mixed $timezone = null, ?string $calendar = null, ?string $locale = null): string
    {
        return $this->isolate($env->getExtension(IntlExtension::class)->formatDate($env, $date, $dateFormat, $pattern, $timezone, $calendar, $locale), $locale);
    }

    public function formatTime(Environment $env, mixed $date, ?string $timeFormat = null, string $pattern = '', mixed $timezone = null, ?string $calendar = null, ?string $locale = null): string
    {
        return $this->isolate($env->getExtension(IntlExtension::class)->formatTime($env, $date, $timeFormat, $pattern, $timezone, $calendar, $locale), $locale);
    }

    public static function isRtl(string $locale): bool
    {
        return in_array(strtolower(substr($locale, 0, 2)), self::RTL_LANGUAGES, true);
    }

    private function isolate(string $text, ?string $locale): string
    {
        return $text !== '' && self::isRtl($locale ?? $this->locale) ? "\u{2067}" . $text . "\u{2069}" : $text;
    }
}
