<?php

declare(strict_types=1);

/**
 * ClientFence: the settings the admin Ctrl+K search finds (clientfence/settings_index). Every
 * settings page (extension/settings/<module>, template mod_<module>_settings and what it includes)
 * with its tabs, section titles and field labels, so an option is found by its own name and the
 * search opens its page and tab and points at it.
 *
 * settings-index.json is built from the templates by tools/build-settings-index.php; a unit test
 * fails when it is out of date. WORDS adds the short words people search for (2FA, SMTP…).
 */

namespace ClientFence\Mod\Clientfence;

final class SettingsIndex
{
    public const FILE = __DIR__ . '/settings-index.json';

    /** Extra search words, by "module" or "module#tab". English: they are names and acronyms. */
    public const WORDS = [
        'security#tab-signin' => '2FA two-factor MFA OTP PIN login lockout country countries GeoIP IP allowed IPs whitelist forgot password reset',
        'security#tab-proxies' => 'Cloudflare proxy reverse proxy load balancer X-Forwarded-For CF-Connecting-IP real IP visitor IP',
        'security#tab-access' => 'whitelist allow list rate limit API IP restriction',
        'security#tab-sessions' => 'session fingerprint hijacking cookie IP check',
        'system#tab-cache' => 'Redis Memcached flush clear',
        'system#tab-backups' => 'backup dump SFTP FTP',
        'system#tab-health' => 'health status check problems',
        'system#tab-licences' => 'license licence ban',
        'system#tab-whmcs' => 'import migrate migration Upmind Blesta ClientExec',
        'system#tab-localization' => 'language locale translation',
        'antispam' => 'captcha reCAPTCHA hCaptcha Turnstile spam bots honeypot block IP',
        'email' => 'SMTP mail sendmail mailer sender DKIM',
        'invoice' => 'tax VAT invoice number prefix due',
        'staff' => 'admin team',
        'cron' => 'scheduled tasks cron job',
        'currency' => 'exchange rate money',
        'client' => 'sign-up signup registration register',
        'client#tab-affiliates' => 'affiliate affiliates referral referrals commission payout partner',
    ];

    /**
     * Reads every module's settings templates under $modules (src/modules).
     *
     * @return list<array{mod: string, tab: string, kind: string, label: string}>
     */
    public static function build(string $modules): array
    {
        $templates = [];
        foreach (glob($modules . '/*/templates/admin/*.html.twig') ?: [] as $file) {
            $templates[basename($file)] = $file;
        }
        ksort($templates);
        $entries = [];
        foreach ($templates as $name => $file) {
            if (preg_match('/\Amod_([a-z0-9]+)_settings\.html\.twig\z/', $name, $m) === 1) {
                self::read($file, $m[1], '', $templates, $entries, []);
            }
        }
        $unique = [];
        foreach ($entries as $entry) {
            $unique[implode("\0", $entry)] = $entry;
        }

        return array_values($unique);
    }

    /**
     * The entries a staff member may open, in their language: label, kind (tab, section, field),
     * page and tab names, extra words and the address (relative to the admin area).
     *
     * @return list<array{label: string, kind: string, page: string, tab: string, words: string, url: string}>
     */
    public static function forAdmin(\Pimple\Container $di): array
    {
        $entries = json_decode((string) @file_get_contents(self::FILE), true);
        if (!is_array($entries)) {
            return [];
        }
        $extension = $di['mod_service']('extension');
        $pages = [];
        $tabs = [];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'tab') {
                $tabs[$entry['mod'] . '#' . $entry['tab']] ??= $entry['label'];
            }
        }
        $out = [];
        foreach ($entries as $entry) {
            $mod = $entry['mod'];
            if (!array_key_exists($mod, $pages)) {
                try {
                    $pages[$mod] = null;
                    if ($extension->isExtensionActive('mod', $mod)) {
                        $extension->hasManagePermission($mod);
                        $pages[$mod] = __trans((string) ($di['mod']($mod)->getManifest()['name'] ?? ucfirst($mod)));
                    }
                } catch (\Throwable) {
                    $pages[$mod] = null;
                }
            }
            if ($pages[$mod] === null) {
                continue;
            }
            $tab = $entry['tab'];
            $out[] = [
                'label' => __trans($entry['label']),
                'kind' => $entry['kind'],
                'page' => $pages[$mod],
                'tab' => $entry['kind'] !== 'tab' && isset($tabs[$mod . '#' . $tab]) ? __trans($tabs[$mod . '#' . $tab]) : '',
                'words' => trim((self::WORDS[$mod] ?? '') . ' ' . (self::WORDS[$mod . '#' . $tab] ?? '')),
                'url' => 'extension/settings/' . $mod . ($tab !== '' ? '#' . $tab : ''),
            ];
        }

        return $out;
    }

    /**
     * Walks one template in document order: tab links, tab panes (the tab of what is inside),
     * section titles, field labels and included templates (read inside the tab they are in).
     * Labels in a pop-up (modal: new records) or a search form (method get: list filters) are
     * not settings and are left out.
     */
    private static function read(string $file, string $mod, string $tab, array $templates, array &$entries, array $seen, bool $skip = false): void
    {
        if (in_array($file, $seen, true)) {
            return;
        }
        $seen[] = $file;
        $source = (string) file_get_contents($file);
        $label = '\{\{\s*\'(?<%s>(?:[^\'\\\\]|\\\\.)*)\'\s*\|\s*trans\s*\}\}';
        $pattern = '/(?<tablink><(?:a|button)\b[^>]*data-bs-toggle="tab"[^>]*>\s*(?:<[^>{]+>\s*)*' . sprintf($label, 'tabtext') . ')'
            . '|(?<include>(?:\{%-?\s*include\s+|\{\{-?\s*include\(\s*)[\'"](?<file>[a-z0-9_]+\.html\.twig)[\'"])'
            . '|(?<label><(?<tag>label|span|div|h[1-6])\b[^>]*class="[^"]*\b(?<class>form-label|form-check-label|col-form-label|card-title)\b[^"]*"[^>]*>\s*' . sprintf($label, 'text') . ')'
            . '|(?<open><(?<opentag>div|form)\b[^>]*>)|(?<close><\/(?:div|form)\s*>)/s';
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        // Open <div> and <form> elements: [tab or null, whether labels inside are left out].
        $stack = [];
        foreach ($matches as $m) {
            [$here, $hidden] = $stack === [] ? [$tab, $skip] : end($stack);
            if ($m['tablink'] !== null) {
                $id = self::targetId($m['tablink']);
                if ($id !== null && !$hidden) {
                    self::add($entries, $mod, $id, 'tab', $m['tabtext']);
                }
            } elseif ($m['include'] !== null) {
                if (isset($templates[$m['file']])) {
                    self::read($templates[$m['file']], $mod, $here, $templates, $entries, $seen, $hidden);
                }
            } elseif ($m['label'] !== null) {
                if (!$hidden) {
                    self::add($entries, $mod, $here, $m['class'] === 'card-title' ? 'section' : 'field', $m['text']);
                }
                if ($m['tag'] === 'div') {
                    $stack[] = [$here, $hidden];
                }
            } elseif ($m['open'] !== null) {
                $tag = $m['open'];
                if (preg_match('/\bclass="[^"]*\btab-pane\b/', $tag) === 1 && preg_match('/\bid="([A-Za-z][\w-]*)"/', $tag, $id) === 1) {
                    $here = $id[1];
                }
                $hidden = $hidden || preg_match('/\bclass="[^"]*\bmodal\b/', $tag) === 1
                    || ($m['opentag'] === 'form' && preg_match('/\bmethod="get"/i', $tag) === 1);
                $stack[] = [$here, $hidden];
            } elseif ($m['close'] !== null) {
                array_pop($stack);
            }
        }
    }

    private static function targetId(string $tag): ?string
    {
        return preg_match('/\b(?:href|data-bs-target)="#([A-Za-z][\w-]*)"/', $tag, $m) === 1 ? $m[1] : null;
    }

    /** A label with a placeholder (%name% or :name) reads wrong on its own and is left out. */
    private static function add(array &$entries, string $mod, string $tab, string $kind, string $label): void
    {
        $label = stripcslashes($label);
        if ($label === '' || preg_match('/%\w+%|:[a-z_]+/', $label) === 1) {
            return;
        }
        $entries[] = ['mod' => $mod, 'tab' => $tab, 'kind' => $kind, 'label' => $label];
    }
}
