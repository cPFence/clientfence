<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\Config;
use ClientFence\InformationException;
use Symfony\Component\Intl\Countries;

/**
 * Sign-in security (System → Settings → System → Sign-in security; Super Administrators only),
 * all off by default:
 *
 * - Staff sign-in from chosen countries only: the staff login (core patch in Staff/Api/Guest.php)
 *   looks up the country of the address in the GeoIP country database and refuses any other, or
 *   one without a known country. An address on the Staff "Allowed IPs" list always passes (a way
 *   back in from a VPN or abroad). Saving a list that would refuse the saving admin is refused.
 *
 * - An email PIN for staff, clients or both: after the right password the sign-in waits in the
 *   session (PENDING) and a 6-digit PIN goes to the login's own email, sent at once, never queued
 *   or kept in the email log. It works once, for PIN_SECONDS, MAX_TRIES wrong tries (then the
 *   password again); a new one after RESEND_SECONDS, MAX_SENDS per sign-in, plus the FOSSBilling
 *   rate limits per login and address. Clients with authenticator two-factor sign-in, Google
 *   sign-in, a sign-up's own sign-in, invitations and staff "Login as client" are not asked.
 *   Turning the staff PIN on needs a PIN sent to the saving admin (proves email works).
 *
 * - "Trust this browser for 30 days": a signed cookie per area (staff, client) naming the login;
 *   it stops working when the login's password changes (the signature covers the password hash).
 *
 * Locked out: `php console.php clientfence:signin-security-off` turns the features off.
 * Settings are kept in mod_clientfence_state (STATE).
 */
final class SignInSecurity
{
    public const STATE = 'signin_security';
    public const PENDING = 'clientfence_pin_pending';
    public const CONFIRM = 'clientfence_pin_confirm';
    public const PIN_MODES = ['off', 'staff', 'clients', 'both'];
    public const PIN_SECONDS = 600;
    public const MAX_TRIES = 5;
    public const RESEND_SECONDS = 60;
    public const MAX_SENDS = 5;
    public const TRUST_DAYS = 30;
    public const COOKIES = ['staff' => 'clientfence_trust_staff', 'client' => 'clientfence_trust_client'];
    private const TEMPLATES = ['staff' => 'mod_staff_clientfence_signin_pin', 'client' => 'mod_client_clientfence_signin_pin'];

    /**
     * The client a sign-up created in this request (core patch in Client/Api/Guest.php create()):
     * its own sign-in right after is not asked for a PIN. Never set for a sign-up that names an
     * existing account, which would otherwise sign in with that account's password alone.
     */
    public static ?int $newClientId = null;

    /** @return array{pin: string, countries_on: bool, countries: list<string>} */
    public static function settings(\Pimple\Container $di): array
    {
        $saved = self::state($di) ?? [];
        $pin = in_array($saved['pin'] ?? null, self::PIN_MODES, true) ? (string) $saved['pin'] : 'off';
        $countries = is_array($saved['countries'] ?? null) ? array_values(array_filter($saved['countries'], fn ($c): bool => is_string($c) && Countries::exists($c))) : [];

        return ['pin' => $pin, 'countries_on' => !empty($saved['countries_on']) && $countries !== [], 'countries' => $countries];
    }

    /** Whether sign-ins of an area ('staff' or 'client') ask for an email PIN. */
    public static function pinOn(\Pimple\Container $di, string $area): bool
    {
        $mode = self::settings($di)['pin'];

        return $mode === 'both' || ($area === 'staff' ? $mode === 'staff' : $mode === 'clients');
    }

    /** The settings tab: the settings, every country, and the saving admin's address and country. */
    public static function forPage(\Pimple\Container $di, string $ip): array
    {
        return self::settings($di) + [
            'all_countries' => Countries::getNames(),
            'ip' => $ip,
            'ip_country' => self::countryOf($di, $ip),
            'ip_listed' => self::ipListed($di, $ip),
            'geoip_ready' => self::countryOf($di, '8.8.8.8') !== null,
        ];
    }

    /**
     * Saves the settings. Turning the staff PIN on first sends a PIN to the saving admin and
     * returns {confirm: true, email}; the same save with `confirm_pin` then finishes it.
     * $admin: the saving admin {id, email, name, timezone}.
     *
     * @return array{saved: bool, confirm?: bool, email?: string}
     */
    public static function save(\Pimple\Container $di, array $data, array $admin, string $ip): array
    {
        $old = self::settings($di);
        $pin = (string) ($data['pin'] ?? 'off');
        if (!in_array($pin, self::PIN_MODES, true)) {
            throw new InformationException('Choose who is asked for an email PIN.');
        }
        $countries = $data['countries'] ?? [];
        $countries = is_array($countries) ? $countries : preg_split('/[\s,]+/', (string) $countries);
        $countries = array_values(array_unique(array_filter(array_map(fn ($c): string => strtoupper(trim((string) $c)), $countries), fn (string $c): bool => $c !== '')));
        foreach ($countries as $code) {
            if (!Countries::exists($code)) {
                throw new InformationException('Unknown country code: :code', [':code' => $code]);
            }
        }
        $countriesOn = !empty($data['countries_on']);
        if ($countriesOn && $countries === []) {
            throw new InformationException('Choose at least one country staff may sign in from.');
        }
        if ($countriesOn && !self::ipListed($di, $ip)) {
            $own = self::countryOf($di, $ip);
            if ($own === null) {
                throw new InformationException('The country of your address (:ip) is not known, so this would lock you out. Add your address to the Allowed IPs list in Staff settings first.', [':ip' => $ip]);
            }
            if (!in_array($own, $countries, true)) {
                throw new InformationException('You are signing in from :country (:ip), which is not on the list: this would lock you out. Add :country or put your address on the Allowed IPs list.', [':country' => Countries::getName($own), ':ip' => $ip]);
            }
        }

        $staffWas = in_array($old['pin'], ['staff', 'both'], true);
        $staffNow = in_array($pin, ['staff', 'both'], true);
        if ($staffNow && !$staffWas) {
            $confirm = $di['session']->get(self::CONFIRM);
            $code = preg_replace('/\D/', '', \ClientFence\Tools::latinDigits((string) ($data['confirm_pin'] ?? '')));
            if ($code === '' || !is_array($confirm) || (int) ($confirm['expires'] ?? 0) < time() || (int) ($confirm['admin_id'] ?? 0) !== (int) $admin['id']) {
                $pinCode = self::newPin();
                $di['session']->set(self::CONFIRM, ['admin_id' => (int) $admin['id'], 'hash' => self::hashPin($pinCode), 'expires' => time() + self::PIN_SECONDS, 'tries' => 0]);
                self::sendPin($di, 'staff', (string) $admin['email'], (string) $admin['name'], $pinCode, $ip, (string) ($admin['timezone'] ?? ''));

                return ['saved' => false, 'confirm' => true, 'email' => self::maskEmail((string) $admin['email'])];
            }
            if (!hash_equals((string) $confirm['hash'], self::hashPin($code))) {
                $confirm['tries'] = (int) $confirm['tries'] + 1;
                if ($confirm['tries'] >= self::MAX_TRIES) {
                    $di['session']->delete(self::CONFIRM);

                    throw new InformationException('Too many wrong PINs. Save again for a new one.');
                }
                $di['session']->set(self::CONFIRM, $confirm);

                throw new InformationException('The PIN is not correct.');
            }
            $di['session']->delete(self::CONFIRM);
        }

        self::saveState($di, ['pin' => $pin, 'countries_on' => $countriesOn, 'countries' => $countries]);
        $di['logger']->info('Sign-in security saved by staff #%s: email PIN %s, staff countries %s', $admin['id'], $pin, $countriesOn ? implode(',', $countries) : 'off');

        return ['saved' => true];
    }

    /** The console command for a locked-out site: turns the email PIN and/or the country rule off. */
    public static function turnOff(\Pimple\Container $di, bool $pin, bool $countries): array
    {
        $settings = self::settings($di);
        if ($pin) {
            $settings['pin'] = 'off';
        }
        if ($countries) {
            $settings['countries_on'] = false;
        }
        self::saveState($di, $settings);

        return self::settings($di);
    }

    // ----- countries -----------------------------------------------------------------------------

    /**
     * The ISO country code of an address from the GeoIP country database, or null. A private or
     * reserved address has no country (the bundled database gives some of them one).
     */
    public static function countryOf(\Pimple\Container $di, string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        try {
            $code = $di['geoip']->get($ip)['country']['iso_code'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($code) && Countries::exists($code) ? $code : null;
    }

    /** Whether an address is on the Staff "Allowed IPs" list (it then skips the country rule). */
    public static function ipListed(\Pimple\Container $di, string $ip): bool
    {
        try {
            $list = (string) ($di['mod']('staff')->getConfig()['allowed_ips'] ?? '');
        } catch (\Throwable) {
            return false;
        }

        return $ip !== '' && in_array($ip, array_map(trim(...), preg_split('/\R/', $list) ?: []), true);
    }

    /** The staff login (core patch): refuses an address outside the chosen countries. */
    public static function checkStaffCountry(\Pimple\Container $di, string $ip): void
    {
        $settings = self::settings($di);
        if (!$settings['countries_on'] || self::ipListed($di, $ip)) {
            return;
        }
        $country = self::countryOf($di, $ip);
        if ($country !== null && in_array($country, $settings['countries'], true)) {
            return;
        }
        $di['logger']->warning('Staff sign-in refused from %s (%s): country not allowed', $ip, $country ?? 'unknown country');

        throw new InformationException('Staff sign-in is not allowed from your location.', null, 403);
    }

    // ----- email PIN ---------------------------------------------------------------------------

    /**
     * After the right password: when the area asks for a PIN and this browser is not trusted for
     * the login, keeps the sign-in waiting (PENDING), sends the PIN and returns true; false when
     * the sign-in may go on. $login: {kind: staff|client|user, id, email, name, timezone};
     * $payload: what the finishing step needs (kept in the session).
     */
    public static function challenge(\Pimple\Container $di, string $area, array $login, array $payload, string $ip): bool
    {
        if (!self::pinOn($di, $area) || self::trusted($di, $area, (string) $login['kind'], (int) $login['id'])) {
            return false;
        }
        if ($area === 'client' && $login['kind'] === 'client' && self::$newClientId !== null && self::$newClientId === (int) $login['id']) {
            return false;
        }
        $limitKey = 'clientfence-pin-' . $login['kind'] . '-' . $login['id'];
        $di['rate_limiter']->consumeOrThrow('client_email_resend_ip', $ip);
        $di['rate_limiter']->consumeOrThrow('client_email_resend_account', $limitKey);
        $pin = self::newPin();
        // One waiting sign-in per session: not also an authenticator code step of an earlier one.
        $di['session']->delete(\ClientFence\Mod\Client\TwoFactor::PENDING);
        $di['session']->set(self::PENDING, [
            'area' => $area,
            'kind' => (string) $login['kind'],
            'id' => (int) $login['id'],
            'email' => (string) $login['email'],
            'name' => (string) ($login['name'] ?? ''),
            'timezone' => (string) ($login['timezone'] ?? ''),
            'payload' => $payload,
            'password_fingerprint' => hash('sha256', (string) self::passwordHash($di, (string) $login['kind'], (int) $login['id'])),
            'hash' => self::hashPin($pin),
            'expires' => time() + self::PIN_SECONDS,
            'sent_at' => time(),
            'sends' => 1,
            'tries' => 0,
        ]);
        self::sendPin($di, $area, (string) $login['email'], (string) ($login['name'] ?? ''), $pin, $ip, (string) ($login['timezone'] ?? ''));

        return true;
    }

    /**
     * The login of a client-area sign-in for challenge(): the account owner, or the authorized
     * user ($user from Users::authorize()), each with its own email.
     */
    public static function clientLogin(\Pimple\Container $di, \ClientFence\Mod\Client\Entity\Client $client, ?array $user): array
    {
        if ($user !== null) {
            $row = $di['em']->getConnection()->fetchAssociative('SELECT email, first_name, last_name FROM client_user WHERE id = ?', [(int) $user['user_id']]) ?: [];

            return ['kind' => 'user', 'id' => (int) $user['user_id'], 'email' => (string) ($row['email'] ?? ''), 'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')), 'timezone' => (string) $client->getTimezone()];
        }

        return ['kind' => 'client', 'id' => (int) $client->getId(), 'email' => (string) $client->getEmail(), 'name' => trim($client->getFirstName() . ' ' . $client->getLastName()), 'timezone' => (string) $client->getTimezone()];
    }

    /** The sign-in waiting for a PIN in this session for an area, or null. */
    public static function pending(\Pimple\Container $di, string $area): ?array
    {
        $pending = $di['session']->get(self::PENDING);

        return is_array($pending) && ($pending['area'] ?? '') === $area && (int) ($pending['expires'] ?? 0) >= time() ? $pending : null;
    }

    /** The PIN page: where the PIN went (masked) and when a new one may be sent. */
    public static function pageInfo(\Pimple\Container $di, string $area): ?array
    {
        $pending = self::pending($di, $area);

        return $pending === null ? null : [
            'email' => self::maskEmail((string) $pending['email']),
            'resend_in' => max(0, (int) $pending['sent_at'] + self::RESEND_SECONDS - time()),
            'minutes' => intdiv(self::PIN_SECONDS, 60),
            'trust_days' => self::TRUST_DAYS,
        ];
    }

    /** Sends a new PIN for the waiting sign-in (the old one stops working). */
    public static function resend(\Pimple\Container $di, string $area, string $ip): array
    {
        $pending = self::pending($di, $area) ?? throw new InformationException('Your sign-in has expired. Please sign in again.', null, 401);
        if ((int) $pending['sent_at'] + self::RESEND_SECONDS > time()) {
            throw new InformationException('Please wait a minute before asking for a new PIN.', null, 429);
        }
        if ((int) $pending['sends'] >= self::MAX_SENDS) {
            throw new InformationException('Too many PINs for this sign-in. Please sign in again later.', null, 429);
        }
        $di['rate_limiter']->consumeOrThrow('client_email_resend_ip', $ip);
        $di['rate_limiter']->consumeOrThrow('client_email_resend_account', 'clientfence-pin-' . $pending['kind'] . '-' . $pending['id']);
        $pin = self::newPin();
        $pending['hash'] = self::hashPin($pin);
        $pending['expires'] = time() + self::PIN_SECONDS;
        $pending['sent_at'] = time();
        $pending['sends'] = (int) $pending['sends'] + 1;
        $pending['tries'] = 0;
        $di['session']->set(self::PENDING, $pending);
        self::sendPin($di, $area, (string) $pending['email'], (string) $pending['name'], $pin, $ip, (string) $pending['timezone']);

        return self::pageInfo($di, $area) ?? [];
    }

    /**
     * Checks the PIN of the waiting sign-in; returns the waiting sign-in (its payload finishes it)
     * and, with $trust, trusts this browser for the login.
     */
    public static function verify(\Pimple\Container $di, string $area, mixed $pin, bool $trust, string $ip): array
    {
        $session = $di['session'];
        $pending = self::pending($di, $area);
        if ($pending === null || !hash_equals((string) ($pending['password_fingerprint'] ?? ''), hash('sha256', (string) self::passwordHash($di, (string) $pending['kind'], (int) $pending['id'])))) {
            $session->delete(self::PENDING);

            throw new InformationException('Your sign-in has expired. Please sign in again.', null, 401);
        }
        $di['rate_limiter']->consumeOrThrow('client_password_reset_confirm_post_ip', $ip);
        $limitKey = 'clientfence-pin-' . $pending['kind'] . '-' . $pending['id'];
        if ($di['rate_limiter']->getStatus('profile_password_change_account', $limitKey)->isLimited()) {
            throw new InformationException('Too many wrong PINs. Please try again later.', null, 429);
        }
        $code = is_scalar($pin) ? preg_replace('/\D/', '', \ClientFence\Tools::latinDigits((string) $pin)) : '';
        if (strlen((string) $code) !== 6 || !hash_equals((string) $pending['hash'], self::hashPin((string) $code))) {
            $di['rate_limiter']->consume('profile_password_change_account', $limitKey);
            $pending['tries'] = (int) $pending['tries'] + 1;
            if ($pending['tries'] >= self::MAX_TRIES) {
                $session->delete(self::PENDING);
                $di['logger']->warning('Email PIN sign-in of %s #%s stopped after %s wrong PINs from %s', $pending['kind'], $pending['id'], self::MAX_TRIES, $ip);

                throw new InformationException('Too many wrong PINs. Please sign in again.', null, 401);
            }
            $session->set(self::PENDING, $pending);

            throw new InformationException('The PIN is not correct.');
        }
        $session->delete(self::PENDING);
        if ($trust) {
            self::trust($di, $area, (string) $pending['kind'], (int) $pending['id']);
        }

        return $pending;
    }

    // ----- trusted browsers --------------------------------------------------------------------

    /** Whether this browser was trusted for the login in the last TRUST_DAYS (and since its password). */
    public static function trusted(\Pimple\Container $di, string $area, string $kind, int $id): bool
    {
        $cookie = $di['request']->cookies->get(self::COOKIES[$area] ?? '');
        if (!is_string($cookie) || !str_contains($cookie, '.')) {
            return false;
        }
        [$body, $mac] = explode('.', $cookie, 2);
        $data = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);
        if (!is_array($data) || ($data['k'] ?? null) !== $kind || (int) ($data['i'] ?? 0) !== $id || (int) ($data['e'] ?? 0) < time()) {
            return false;
        }
        $password = self::passwordHash($di, $kind, $id);

        return $password !== null && hash_equals(self::sign($body, $password), $mac);
    }

    private static function trust(\Pimple\Container $di, string $area, string $kind, int $id): void
    {
        $password = self::passwordHash($di, $kind, $id);
        if ($password === null) {
            return;
        }
        $expires = time() + self::TRUST_DAYS * 86400;
        $body = rtrim(strtr(base64_encode((string) json_encode(['k' => $kind, 'i' => $id, 'e' => $expires])), '+/', '-_'), '=');
        $di['cookie_queue']->queue(self::COOKIES[$area], $body . '.' . self::sign($body, $password), $expires, '/', null, $di['request']->isSecure(), true, 'Lax');
    }

    private static function sign(string $body, string $passwordHash): string
    {
        return hash_hmac('sha256', $body . '|' . $passwordHash, 'clientfence-trusted-browser|' . (string) Config::getProperty('info.salt', ''));
    }

    public static function passwordHash(\Pimple\Container $di, string $kind, int $id): ?string
    {
        $table = match ($kind) {
            'staff' => 'admin',
            'client' => 'client',
            'user' => 'client_user',
            default => null,
        };
        if ($table === null) {
            return null;
        }
        $pass = $di['em']->getConnection()->fetchOne("SELECT pass FROM {$table} WHERE id = ?", [$id]);

        return is_string($pass) && $pass !== '' ? $pass : null;
    }

    // ----- helpers -----------------------------------------------------------------------------

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }

    private static function newPin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function hashPin(string $pin): string
    {
        return hash_hmac('sha256', $pin, 'clientfence-signin-pin|' . (string) Config::getProperty('info.salt', ''));
    }

    /**
     * Sends a PIN at once through the Email settings' mailer (not the queue, not the email log).
     * The text is the email template of the area (Email templates; translated).
     */
    private static function sendPin(\Pimple\Container $di, string $area, string $email, string $name, string $pin, string $ip, string $timezone): void
    {
        $country = self::countryOf($di, $ip);
        $vars = ['pin' => $pin, 'minutes' => intdiv(self::PIN_SECONDS, 60), 'ip' => $ip, 'country' => $country !== null ? Countries::getName($country) : '', 'name' => $name];
        $preview = ['pin' => '123456', 'minutes' => intdiv(self::PIN_SECONDS, 60), 'ip' => '203.0.113.7', 'country' => 'Germany', 'name' => 'Example Name'];
        [$subject, $content] = $di['mod_service']('email')->renderTemplate(self::TEMPLATES[$area], $vars, $preview, $timezone !== '' ? $timezone : null);
        $settings = $di['mod']('email')->getConfig();
        $system = $di['mod_service']('system');
        $from = (string) ($settings['from_email'] ?? '') ?: (string) $system->getParamValue('company_email');
        $company = (string) ($system->getParamValue('company_name') ?? '') ?: 'ClientFence';
        try {
            if ($from === '') {
                throw new \RuntimeException('no sender address in the Email settings');
            }
            $mail = new \ClientFence\Mail(['email' => $from, 'name' => (string) ($settings['from_name'] ?? '') ?: $company], ['email' => $email, 'name' => $name], $subject, $content, $settings['mailer'] ?? 'sendmail', $settings['custom_dsn'] ?? null);
            if (!\ClientFence\Environment::isProduction()) {
                $di['logger']->setChannel('email')->info('Skip email sending. Application ENV: ' . \ClientFence\Environment::getCurrentEnvironment());

                return;
            }
            $mail->send($settings);
        } catch (\Throwable $e) {
            $di['logger']->setChannel('email')->error('The sign-in PIN could not be sent: ' . $e->getMessage());

            throw new InformationException('The sign-in PIN could not be emailed. Please try again later or contact us.', null, 503);
        }
    }

    private static function state(\Pimple\Container $di): ?array
    {
        try {
            $row = $di['em']->getConnection()->fetchOne('SELECT value FROM mod_clientfence_state WHERE name = ?', [self::STATE]);
        } catch (\Throwable) {
            return null;
        }
        $value = is_string($row) ? json_decode($row, true) : null;

        return is_array($value) ? $value : null;
    }

    private static function saveState(\Pimple\Container $di, array $value): void
    {
        $db = $di['em']->getConnection();
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');
        if ($db->executeStatement('UPDATE mod_clientfence_state SET value = ?, updated_at = ? WHERE name = ?', [$json, $now, self::STATE]) === 0
            && $db->fetchOne('SELECT 1 FROM mod_clientfence_state WHERE name = ?', [self::STATE]) === false) {
            $db->insert('mod_clientfence_state', ['name' => self::STATE, 'value' => $json, 'updated_at' => $now]);
        }
    }
}
