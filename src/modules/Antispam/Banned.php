<?php

declare(strict_types=1);

namespace ClientFence\Mod\Antispam;

use ClientFence\InformationException;
use ClientFence\Tools;

/**
 * ClientFence: banned emails and phone numbers (System > Settings > Anti-Spam, CF-117), as WHMCS's Banned
 * Emails and HostBill's Registration Blacklist. The email list takes full addresses (`name@example.com`),
 * domains (`example.com`) and their subdomains (`*.example.com`); the phone list is compared as digits with
 * the country code, so spaces, dashes and `+` do not matter. Checked at sign-up (the form, Google and the
 * checkout sign-up), when a client changes their own email or phone, and at checkout. Staff are never
 * refused. The message never says which list matched. Empty lists cost nothing.
 */
final class Banned
{
    public const MESSAGE = 'This email address or phone number cannot be used. Please contact us if you think this is wrong.';

    /**
     * Refuses banned details. `$values`: the email, phone_cc and phone being used; with `$clientId`, only what
     * differs from the account's own (a profile change), or with `$values` null the account's own (checkout).
     */
    public static function refuse(\Pimple\Container $di, ?array $values, ?int $clientId = null): void
    {
        $config = $di['mod_config']('Antispam');
        $emails = self::entries($config['banned_emails'] ?? '');
        $phones = self::entries($config['banned_phones'] ?? '');
        if ($emails === [] && $phones === []) {
            return;
        }
        if ($clientId !== null) {
            $current = $di['dbal']->fetchAssociative('SELECT email, phone_cc, phone FROM client WHERE id = ?', [$clientId]) ?: [];
            $values = $values === null ? $current : self::changed($values, $current);
        }
        // A sign-up's values come from the visitor: only text counts.
        [$email, $countryCode, $phone] = array_map(static fn (string $k): string => is_scalar($values[$k] ?? null) ? (string) $values[$k] : '', ['email', 'phone_cc', 'phone']);
        if (($email !== '' && self::emailBanned($emails, $email)) || ($phone !== '' && self::phoneBanned($phones, $countryCode, $phone))) {
            $di['logger']->setChannel('security')->info('Anti-Spam: refused a banned email or phone number%s', $clientId !== null ? ' (client #' . $clientId . ')' : '');

            throw new InformationException(self::MESSAGE);
        }
    }

    /** Adds an email address to the banned list (staff refusing a held order). */
    public static function add(\Pimple\Container $di, string $email): void
    {
        $config = $di['mod_config']('Antispam');
        $email = strtolower(trim($email));
        if ($email === '' || in_array($email, self::entries($config['banned_emails'] ?? ''), true)) {
            return;
        }
        $config['banned_emails'] = ltrim(trim((string) ($config['banned_emails'] ?? '')) . PHP_EOL . $email);
        $config['ext'] = 'mod_antispam';
        $di['mod_service']('extension')->setConfig($config);
    }

    /** @return list<string> the lines of a list, lower case, without blanks and `#` comments */
    public static function entries(mixed $text): array
    {
        $lines = array_map(static fn (string $l): string => strtolower(trim($l)), preg_split('/\R/', (string) $text) ?: []);

        return array_values(array_filter($lines, static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#')));
    }

    /** @param list<string> $entries */
    public static function emailBanned(array $entries, string $email): bool
    {
        $email = strtolower(trim($email));
        $domain = substr((string) strrchr($email, '@'), 1);
        foreach ($entries as $entry) {
            $hit = match (true) {
                str_contains($entry, '@') => $entry === $email,
                str_starts_with($entry, '*.') => str_ends_with($domain, substr($entry, 1)),
                default => $entry === $domain,
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * A phone matches with its country code, or as typed when the client put the code in the number too.
     *
     * @param list<string> $entries
     */
    public static function phoneBanned(array $entries, string $countryCode, string $phone): bool
    {
        $banned = array_filter(array_map(self::digits(...), $entries));
        $number = self::digits($phone);

        return $number !== '' && array_intersect([self::digits($countryCode) . $number, $number], $banned) !== [];
    }

    private static function digits(string $text): string
    {
        return (string) preg_replace('/\D+/', '', Tools::latinDigits($text));
    }

    /** What a profile change changes: the email if it is a new one, the phone (with its code) if its digits are new. */
    private static function changed(array $values, array $current): array
    {
        $changed = [];
        if (isset($values['email']) && strcasecmp((string) $values['email'], (string) ($current['email'] ?? '')) !== 0) {
            $changed['email'] = $values['email'];
        }
        $countryCode = (string) ($values['phone_cc'] ?? $current['phone_cc'] ?? '');
        $phone = (string) ($values['phone'] ?? $current['phone'] ?? '');
        if ((isset($values['phone']) || isset($values['phone_cc'])) && self::digits($countryCode . $phone) !== self::digits(($current['phone_cc'] ?? '') . ($current['phone'] ?? ''))) {
            $changed += ['phone_cc' => $countryCode, 'phone' => $phone];
        }

        return $changed;
    }
}
