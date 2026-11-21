<?php

declare(strict_types=1);

namespace ClientFence\Mod\Email;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * ClientFence: one email layout for every email, as WHMCS has it (its global email header, footer and CSS). A
 * template holds only its message; the layout adds the page around it. Staff change it in Email Settings > Email
 * Layout; an empty setting is the built-in default (templates/layout), so a default the release changes reaches
 * every install that kept it.
 *
 * The message runs first, so the header and footer can read what it sets: email_title (the page title),
 * email_preheader (the preview line of inboxes), email_audience ('client', 'affiliate', 'guest' or 'staff': the footer links)
 * and email_reply_line (ticket emails: the reply line on top).
 */
final class Layout
{
    /** Email settings keys (mod_email config). */
    public const KEYS = ['layout_header', 'layout_footer', 'layout_css'];

    /**
     * The built-in header and footer, and no CSS.
     *
     * @return array{layout_header: string, layout_footer: string, layout_css: string}
     */
    public static function defaults(): array
    {
        $dir = Path::join(PATH_MODS, 'Email', 'templates', 'layout');
        $files = new Filesystem();

        return [
            'layout_header' => $files->readFile(Path::join($dir, 'header.html.twig')),
            'layout_footer' => $files->readFile(Path::join($dir, 'footer.html.twig')),
            'layout_css' => '',
        ];
    }

    /**
     * The layout from the Email settings: an empty or missing key is the default.
     *
     * @return array{layout_header: string, layout_footer: string, layout_css: string}
     */
    public static function fromConfig(array $config): array
    {
        $set = array_filter(array_intersect_key($config, array_flip(self::KEYS)), static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return array_merge(self::defaults(), $set);
    }

    /** A template that is a whole page of its own (a built-in email edited before 1.0.62) is sent as it is. */
    public static function isPage(string $content): bool
    {
        return preg_match('/<html[\s>]/i', $content) === 1;
    }

    /** The Twig source of $content inside the layout. */
    public static function wrap(string $content, array $layout): string
    {
        if (self::isPage($content)) {
            return $content;
        }
        $css = trim($layout['layout_css'] ?? '');

        return '{% set email_message %}' . $content . "{% endset %}\n"
            . "<!DOCTYPE html>\n<html lang=\"{{ locale }}\" dir=\"{{ dir }}\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<meta name=\"x-apple-disable-message-reformatting\">\n"
            . "<title>{{ email_title|default(guest.system_company.name) }}</title>\n"
            . ($css !== '' ? "<style>\n" . $css . "\n</style>\n" : '')
            . "</head>\n" . $layout['layout_header'] . "{{ email_message }}\n" . $layout['layout_footer'] . "</html>\n";
    }
}
