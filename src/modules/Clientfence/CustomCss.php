<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\InformationException;

/**
 * Custom CSS for the client area, saved with the client theme's settings (custom_css, custom_css_enabled) by a
 * super administrator (Theme/Service.php updateSettings()) and served at /custom-css (Controller/Client.php),
 * after the theme's own stylesheets. Links may point to this site or be data: URLs only: a link to another
 * website would show every visitor's address to it.
 */
final class CustomCss
{
    public const int MAX_BYTES = 65536;
    /** The settings a staff member who is not a super administrator cannot change. */
    public const array KEYS = ['custom_css', 'custom_css_enabled', 'custom_css_version'];

    /**
     * The posted theme settings with the CSS checked and its version (the stylesheet link's ?v=) set.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $old    the saved settings
     *
     * @return array<string, mixed>
     */
    public static function prepare(\Pimple\Container $di, array $params, array $old): array
    {
        $css = trim(str_replace("\r\n", "\n", (string) $params['custom_css']));
        self::check($css);
        $params['custom_css'] = $css;
        $params['custom_css_version'] = $css === '' ? '' : substr(hash('sha256', $css), 0, 12);
        $on = in_array($params['custom_css_enabled'] ?? '0', ['1', 1, true, 'true', 'on'], true);
        $wasOn = in_array($old['custom_css_enabled'] ?? '0', ['1', 1, true, 'true', 'on'], true);
        if ($css !== (string) ($old['custom_css'] ?? '') || $on !== $wasOn) {
            // It can restyle or hide anything clients see, so each change is in the activity log.
            $di['logger']->info('Changed the client area custom CSS (%s, %d bytes)', $on ? 'on' : 'off', strlen($css));
        }

        return $params;
    }

    public static function check(string $css): void
    {
        if (strlen($css) > self::MAX_BYTES) {
            throw new InformationException('Custom CSS can be at most :size KB.', [':size' => self::MAX_BYTES / 1024]);
        }
        if (!mb_check_encoding($css, 'UTF-8') || str_contains($css, "\0")) {
            throw new InformationException('Custom CSS must be plain text.');
        }
        // CSS escapes (\69 for "i") and comments must not hide an @import or a link.
        $plain = (string) preg_replace('~/\*.*?(\*/|\z)~s', '', $css);
        $plain = (string) preg_replace_callback('~\\\\([0-9a-fA-F]{1,6})\s?~', static fn (array $m): string => mb_chr(hexdec($m[1]) ?: 0xFFFD, 'UTF-8') ?: '', $plain);
        $plain = (string) preg_replace('~\\\\(.)~s', '$1', $plain);
        if (stripos($plain, '@import') !== false) {
            throw new InformationException('Custom CSS cannot use @import: put the rules in the box.');
        }
        $links = [];
        preg_match_all('~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))~i', $plain, $found, PREG_SET_ORDER);
        foreach ($found as $m) {
            $links[] = ($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? '');
        }
        // Functions that take a link as a plain string.
        preg_match_all('~(?:-webkit-)?image-set\(([^()]*(?:\([^()]*\)[^()]*)*)\)|\b(?:image|src)\(([^()]*)\)~i', $plain, $found, PREG_SET_ORDER);
        foreach ($found as $m) {
            preg_match_all('~"([^"]*)"|\'([^\']*)\'~', ($m[1] ?? '') . ($m[2] ?? ''), $strings, PREG_SET_ORDER);
            foreach ($strings as $s) {
                $links[] = ($s[1] ?? '') . ($s[2] ?? '');
            }
        }
        foreach ($links as $link) {
            if (!self::allowed(trim($link))) {
                throw new InformationException('Custom CSS can only link to this site or use data: URLs, not :link.', [':link' => mb_substr(trim($link), 0, 80)]);
            }
        }
    }

    /** A relative link, a data: URL, or a link to this site. */
    private static function allowed(string $link): bool
    {
        if (preg_match('~\A[a-z][a-z0-9+.\-]*:~i', $link) !== 1 && !str_starts_with($link, '//')) {
            return true;
        }
        if (stripos($link, 'data:') === 0) {
            return true;
        }
        $url = parse_url(str_starts_with($link, '//') ? 'https:' . $link : $link) ?: [];

        return in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true) && isset($url['host'])
            && strcasecmp($url['host'], (string) parse_url(SYSTEM_URL, PHP_URL_HOST)) === 0;
    }
}
