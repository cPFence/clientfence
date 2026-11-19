<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\Config;
use ClientFence\i18n;
use ClientFence\InformationException;
use ClientFence\Mod\Client\Entity\Client;
use ClientFence\Tools;

/**
 * ClientFence: the client custom fields, as WHMCS's Custom Client Fields, on the client's 20 slots
 * custom_1 to custom_20. Each slot's settings are in mod_client `custom_fields[custom_N]` (Client
 * settings, Custom Fields tab): title, active, required, type (TYPES), options (Drop Down: a comma
 * list), description (help text under the field), regex (Validation: a PHP pattern with its
 * delimiters, checked when a value changes), order (Display Order), admin_only (only staff see and
 * edit it), show_order (on the sign-up and checkout), show_invoice (copied onto each new invoice)
 * and translations[lang] (title, description, options). Settings saved before these options existed
 * read as a Text Box on the sign-up, not admin only and not on invoices, as before. Password answers
 * are stored encrypted (seal(), as WHMCS does); staff see them in clear (staffValues()), the client
 * only MASK, and they never go on an invoice.
 */
final class CustomFields
{
    public const SLOTS = 20;
    public const TYPES = ['text', 'link', 'password', 'dropdown', 'tickbox', 'textarea'];
    /** What the client is shown for a saved password, and may send back to keep it. */
    public const MASK = '********';
    /** Marks a Password answer stored encrypted with this install's key (seal()); one without it is an older plain value. */
    public const SEALED = 'cfsecret:';
    /** Sign-up and checkout: the fields on the order form. */
    public const SIGNUP = 'signup';
    /** The client's own profile: every field that is not admin only. */
    public const CLIENT = 'client';
    /** Staff and the admin API: every field. */
    public const STAFF = 'staff';
    /** Backtracking a staff pattern may do on one value (PCRE's own default is ten times more). */
    private const BACKTRACK_LIMIT = 100000;

    /**
     * Every slot's settings with their defaults, by Display Order, then title (the order before
     * Display Order existed), then slot.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(mixed $config): array
    {
        $raw = is_array($config) && is_array($config['custom_fields'] ?? null) ? $config['custom_fields'] : [];
        $fields = [];
        for ($i = 1; $i <= self::SLOTS; ++$i) {
            $fields['custom_' . $i] = self::field($raw['custom_' . $i] ?? null);
        }
        uksort($fields, static fn (string $a, string $b): int => ($fields[$a]['order'] <=> $fields[$b]['order'])
            ?: strnatcasecmp($fields[$a]['title'], $fields[$b]['title'])
            ?: ((int) substr($a, 7) <=> (int) substr($b, 7)));

        return $fields;
    }

    /**
     * The fields a client sees (active, not admin only) in $locale (the page language when null),
     * without the staff settings: title, description, type, options [{value, label}], required,
     * show_order and active (always true, for themes that check it).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forClient(mixed $config, ?string $locale = null): array
    {
        $out = [];
        foreach (self::all($config) as $name => $field) {
            if (!$field['active'] || $field['admin_only']) {
                continue;
            }
            $field = self::localize($field, $locale);
            $out[$name] = [
                'title' => $field['title'],
                'active' => true,
                'required' => $field['required'],
                'type' => $field['type'],
                'description' => $field['description'],
                'options' => $field['choices'],
                'show_order' => $field['show_order'],
            ];
        }

        return $out;
    }

    /**
     * The client's own answers as the client and guest API show them: active fields that are not
     * admin only and have a value; a saved password shows as MASK.
     *
     * @param array<string, string> $values custom_N => value (values())
     *
     * @return array<string, string>
     */
    public static function clientValues(mixed $config, array $values): array
    {
        $out = [];
        foreach (self::all($config) as $name => $field) {
            $value = (string) ($values[$name] ?? '');
            if ($field['active'] && !$field['admin_only'] && $value !== '') {
                $out[$name] = $field['type'] === 'password' ? self::MASK : $value;
            }
        }

        return $out;
    }

    /**
     * A client's answers: custom_N => value ('' when empty), from the entity or the RedBean model.
     *
     * @return array<string, string>
     */
    public static function values(object $client): array
    {
        $values = [];
        for ($i = 1; $i <= self::SLOTS; ++$i) {
            $value = $client instanceof Client ? $client->{'getCustom' . $i}() : ($client->{'custom_' . $i} ?? null);
            $values['custom_' . $i] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }

    /**
     * The settings the Custom Fields tab saves, checked: a type it knows, a Drop Down with
     * options, a Validation pattern PHP can run, translations of installed languages only.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws InformationException
     */
    public static function clean(mixed $fields): array
    {
        $out = [];
        foreach (is_array($fields) ? $fields : [] as $name => $raw) {
            if (preg_match('/^custom_([1-9]|1[0-9]|20)$/', (string) $name) !== 1) {
                continue;
            }
            $field = self::field($raw);
            $label = $field['title'] !== '' ? $field['title'] : self::slotLabel((string) $name);
            if (!in_array(is_array($raw) ? ($raw['type'] ?? 'text') : 'text', self::TYPES, true)) {
                throw new InformationException('Choose a field type for :field.', [':field' => $label]);
            }
            if ($field['type'] === 'dropdown' && self::options($field['options']) === []) {
                throw new InformationException('Enter the options of :field, separated by commas.', [':field' => $label]);
            }
            if ($field['regex'] !== '' && !self::validRegex($field['regex'])) {
                throw new InformationException('The validation of :field is not a regular expression PHP can use, for example /^[0-9]+$/.', [':field' => $label]);
            }
            $out[(string) $name] = $field;
        }

        return $out;
    }

    /** Whether PHP can run $regex (a pattern with its delimiters, as WHMCS's Validation). */
    public static function validRegex(string $regex): bool
    {
        return @preg_match($regex, '') !== false;
    }

    /**
     * Checks the custom field answers in $data for $area (SIGNUP, CLIENT or STAFF) and returns
     * $data with them cleaned: a field the area may not set is left out (a client cannot set an
     * admin only, inactive or, at sign-up, off-the-order-form field), a tick box becomes 'on' or '',
     * and an empty or MASK password from a client keeps the saved one (left out). A changed value
     * must match its type (a Drop Down option, an http(s) link) and its Validation; a value as
     * saved before is not checked again, so staff can save a profile with older answers. A required
     * field cannot be emptied by the client; with $complete (the sign-up form) it must be there.
     * With $di (every save path) a Password answer is compared with the saved one in clear and
     * returned encrypted (seal()).
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $current the saved answers (values()), empty for a new client
     *
     * @return array<string, mixed>
     *
     * @throws InformationException
     */
    public static function input(mixed $config, array $data, string $area, array $current = [], bool $complete = false, ?\Pimple\Container $di = null): array
    {
        foreach (self::all($config) as $name => $field) {
            $sent = array_key_exists($name, $data);
            if ($area !== self::STAFF && (!$field['active'] || $field['admin_only'] || ($area === self::SIGNUP && !$field['show_order']))) {
                unset($data[$name]);

                continue;
            }
            if (!$sent && !($complete && $field['required'])) {
                continue;
            }
            $field = self::localize($field, null);
            $label = $field['title'] !== '' ? $field['title'] : self::slotLabel($name);
            // A password keeps its spaces (a WHMCS one may have them); other answers are trimmed.
            $value = $sent && is_scalar($data[$name]) ? ($field['type'] === 'password' ? (string) $data[$name] : trim((string) $data[$name])) : '';
            if ($field['type'] === 'tickbox') {
                $value = in_array(strtolower($value), ['', '0', 'off', 'false', 'no'], true) ? '' : 'on';
            }
            $saved = (string) ($current[$name] ?? '');
            $secret = $field['type'] === 'password' && $di !== null;
            if ($secret) {
                $saved = (string) self::open($di, $saved);
            }
            if ($field['type'] === 'password' && $area !== self::STAFF && ($value === '' || $value === self::MASK)) {
                if ($field['required'] && $saved === '') {
                    throw new InformationException('Field :field cannot be empty', [':field' => $label]);
                }
                unset($data[$name]);

                continue;
            }
            if ($value === '') {
                if ($field['required'] && $area !== self::STAFF) {
                    throw new InformationException('Field :field cannot be empty', [':field' => $label]);
                }
            } elseif ($value !== $saved && $field['active']) {
                self::check($field, $value, $label);
            }
            $data[$name] = $secret ? self::seal($di['crypt']->encrypt(...), $value) : $value;
        }

        return $data;
    }

    /**
     * A Password answer as it is stored: encrypted with $encrypt (this install's key), base64 first
     * (the install's decryption trims what it returns), marked SEALED; blank stays blank. A typed value that
     * already starts with SEALED is sealed too, so it is never stored in clear.
     *
     * @param \Closure(string): string $encrypt
     */
    public static function seal(\Closure $encrypt, string $value): string
    {
        return $value === '' ? '' : self::SEALED . $encrypt(base64_encode($value));
    }

    /** A stored Password answer in clear, for staff only: an older plain value as it is, null when this install's key cannot read it. */
    public static function open(\Pimple\Container $di, string $value): ?string
    {
        if (!str_starts_with($value, self::SEALED)) {
            return $value;
        }
        $encoded = $di['crypt']->decrypt(substr($value, strlen(self::SEALED)));
        $plain = is_string($encoded) ? base64_decode($encoded, true) : false;

        return is_string($plain) ? $plain : null;
    }

    /**
     * $details (a client's API array or row, for staff) with its Password answers in clear; one
     * this install's key cannot read shows blank.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    public static function staffValues(\Pimple\Container $di, mixed $config, array $details): array
    {
        foreach (self::all($config) as $name => $field) {
            if ($field['type'] === 'password' && is_string($details[$name] ?? null)) {
                $details[$name] = self::open($di, $details[$name]) ?? '';
            }
        }

        return $details;
    }

    /**
     * $details (a client's row for the client's own eyes) with each saved Password answer as MASK.
     *
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    public static function maskPasswords(mixed $config, array $details): array
    {
        foreach (self::all($config) as $name => $field) {
            if ($field['type'] === 'password' && (string) ($details[$name] ?? '') !== '') {
                $details[$name] = self::MASK;
            }
        }

        return $details;
    }

    /**
     * The copy of the client's Show on Invoice answers a new invoice keeps (JSON list of title and
     * value, in the client's language $locale), or null when there is none. Passwords never go on
     * an invoice.
     *
     * @param array<string, string> $values custom_N => value (values())
     */
    public static function invoiceCopy(mixed $config, array $values, string $locale): ?string
    {
        $lines = [];
        foreach (self::all($config) as $name => $field) {
            $value = trim((string) ($values[$name] ?? ''));
            if (!$field['active'] || !$field['show_invoice'] || $field['type'] === 'password' || $value === '') {
                continue;
            }
            $field = self::localize($field, $locale);
            if ($field['type'] === 'tickbox') {
                // As the form shows it: an older 0 or off is unticked.
                if (in_array(strtolower($value), ['0', 'off', 'false', 'no'], true)) {
                    continue;
                }
                $value = __trans('Yes');
            } elseif ($field['type'] === 'dropdown') {
                $labels = array_column($field['choices'], 'label', 'value');
                $value = (string) ($labels[$value] ?? $value);
            }
            $lines[] = ['title' => $field['title'] !== '' ? $field['title'] : self::slotLabel($name), 'value' => $value];
        }

        return $lines === [] ? null : json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * An invoice's copy (invoiceCopy()) as a list of {title, value}; [] for an invoice without one.
     *
     * @return list<array{title: string, value: string}>
     */
    public static function fromInvoice(mixed $json): array
    {
        $lines = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        $out = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_array($line) && is_scalar($line['title'] ?? null) && is_scalar($line['value'] ?? null)) {
                $out[] = ['title' => (string) $line['title'], 'value' => (string) $line['value']];
            }
        }

        return $out;
    }

    /**
     * The languages a field's texts can be written in: the turned-on ones except the default.
     *
     * @return array<string, string> locale => name
     */
    public static function languages(): array
    {
        $default = (string) Config::getProperty('i18n.locale', 'en_US');
        $out = [];
        foreach (i18n::getLocales(true) as $locale) {
            if ($locale['locale'] !== $default) {
                $out[(string) $locale['locale']] = (string) $locale['title'];
            }
        }

        return $out;
    }

    /**
     * One slot's settings with their defaults.
     *
     * @return array<string, mixed>
     */
    private static function field(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $text = static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
        $translations = [];
        $installed = array_merge(i18n::getLocales(), i18n::getLocales(false, true));
        foreach (is_array($raw['translations'] ?? null) ? $raw['translations'] : [] as $lang => $texts) {
            if (!is_array($texts) || !in_array((string) $lang, $installed, true)) {
                continue;
            }
            // A blank text shows the default one.
            $texts = array_filter(['title' => $text($texts['title'] ?? ''), 'description' => $text($texts['description'] ?? ''), 'options' => $text($texts['options'] ?? '')], static fn (string $v): bool => $v !== '');
            if ($texts !== []) {
                $translations[(string) $lang] = $texts;
            }
        }

        return [
            'title' => $text($raw['title'] ?? ''),
            'active' => Tools::normalizeBoolean($raw['active'] ?? false),
            'required' => Tools::normalizeBoolean($raw['required'] ?? false),
            'type' => in_array($raw['type'] ?? null, self::TYPES, true) ? $raw['type'] : 'text',
            'options' => $text($raw['options'] ?? ''),
            'description' => $text($raw['description'] ?? ''),
            'regex' => $text($raw['regex'] ?? ''),
            'order' => is_numeric($raw['order'] ?? null) ? (int) $raw['order'] : 0,
            'admin_only' => Tools::normalizeBoolean($raw['admin_only'] ?? false),
            'show_order' => Tools::normalizeBoolean($raw['show_order'] ?? true, true),
            'show_invoice' => Tools::normalizeBoolean($raw['show_invoice'] ?? false),
            'translations' => $translations,
        ];
    }

    /**
     * The field in $locale (the page language when null): its title (or "Custom 1"), description
     * and `choices`, the Drop Down options [{value, label}] where the value is the default text and
     * the label the translated one at the same place.
     *
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private static function localize(array $field, ?string $locale): array
    {
        $texts = $field['translations'][$locale ?? \Locale::getDefault()] ?? [];
        $labels = self::options($texts['options'] ?? '');
        $field['choices'] = [];
        foreach (self::options($field['options']) as $i => $option) {
            $field['choices'][] = ['value' => $option, 'label' => $labels[$i] ?? $option];
        }
        $field['title'] = ($texts['title'] ?? '') ?: $field['title'];
        $field['description'] = ($texts['description'] ?? '') ?: $field['description'];

        return $field;
    }

    /** @return list<string> the options of a comma list, trimmed, without blanks */
    private static function options(string $list): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $list)), static fn (string $o): bool => $o !== ''));
    }

    /**
     * Refuses a value that does not fit its field.
     *
     * @param array<string, mixed> $field localized
     *
     * @throws InformationException
     */
    private static function check(array $field, string $value, string $title): void
    {
        if ($field['type'] === 'dropdown' && !in_array($value, array_column($field['choices'], 'value'), true)) {
            throw new InformationException('Choose one of the options for :field.', [':field' => $title]);
        }
        if ($field['type'] === 'link' && (filter_var($value, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new InformationException('Enter a web address starting with http:// or https:// for :field.', [':field' => $title]);
        }
        if ($field['regex'] !== '' && !self::matches($field['regex'], $value)) {
            throw new InformationException('The value of :field is not valid.', [':field' => $title]);
        }
    }

    /** Whether $value matches the staff pattern; a pattern that fails to run (too much backtracking) does not match. */
    private static function matches(string $regex, string $value): bool
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        try {
            return @preg_match($regex, $value) === 1;
        } finally {
            ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
        }
    }

    private static function slotLabel(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }
}
