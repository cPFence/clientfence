<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\Http\CookieNames;
use ClientFence\InformationException;

/**
 * Two-factor sign-in (plan "Authorized users with 2FA", step 4): TOTP codes from an
 * authenticator app (RFC 6238: SHA-1, 6 digits, 30 seconds, one step of clock drift either way)
 * for account owners (FOSSBilling client logins) and authorized users (Users.php), each on the
 * login itself: a user's codes hold for every account the user has.
 *
 * Table client_twofactor: one row per login with two-factor on (kind 'client' or 'user',
 * subject_id), the secret (base32, encrypted with FOSSBilling's crypt, so a WHMCS import can
 * carry a secret over), the hash of the one backup code and the last time step used (a code
 * works once).
 *
 * Sign-in (core patch "two-factor sign-in" on the client login): after the password,
 * challenge() keeps the sign-in waiting in the session (PENDING, PENDING_SECONDS) instead of
 * signing in, and the login page goes to /two-factor/code, where verify() takes a code or the
 * backup code and finishes the sign-in as FOSSBilling's login does. Wrong codes: MAX_TRIES per
 * sign-in, then the password again; a login may get no more than five wrong codes an hour, and
 * an address 20 attempts a minute (FOSSBilling rate limits). The backup code works once and
 * then is gone until a new one is made. Staff "Login as client" is not asked for a code.
 * Enforced while the module is active; setting it up needs ClientFence on.
 *
 * Settings (/two-factor): setup() makes a secret (kept in the session until confirmed),
 * enable() needs a code from the app and the password and returns the backup code once;
 * disable() needs the password and a code (or the backup code); newBackup() the password.
 * Staff switch it off on the Users tab of the client page (staffDisable()). Every change is in
 * the activity log, under the account (a user's on every account of the user).
 */
final class TwoFactor
{
    public const PENDING = 'clientfence_2fa_pending';
    public const SETUP = 'clientfence_2fa_setup';
    public const PENDING_SECONDS = 300;
    public const SETUP_SECONDS = 900;
    public const MAX_TRIES = 5;
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const BACKUP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * The code of a secret (raw bytes) at a time step (public for tests).
     */
    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper((string) preg_replace('/[\s=-]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr((int) bindec($byte));
            }
        }

        return $bytes;
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $text = '';
        foreach (str_split($bits, 5) as $chunk) {
            $text .= self::BASE32[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $text;
    }

    /**
     * The time step a code belongs to (now, or one step before or after), if it is later than
     * the last one used; null otherwise.
     */
    private static function matchStep(string $secret, mixed $code, int $lastStep): ?int
    {
        // Arabic digits count (phones in Arabic type ١٢٣).
        $code = is_string($code) ? (string) preg_replace('/\s+/', '', \ClientFence\Tools::latinDigits($code)) : '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $now = intdiv(time(), self::PERIOD);
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if ($step > $lastStep && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private static function normalizeBackup(mixed $code): string
    {
        return is_string($code) ? strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)) : '';
    }

    private static function newBackupCode(): string
    {
        $code = '';
        for ($i = 0; $i < 16; ++$i) {
            $code .= self::BACKUP_ALPHABET[random_int(0, strlen(self::BACKUP_ALPHABET) - 1)];
        }

        return implode('-', str_split($code, 4));
    }

    private static function row(\Pimple\Container $di, string $kind, int $id): ?array
    {
        try {
            $row = $di['em']->getConnection()->fetchAssociative('SELECT * FROM client_twofactor WHERE kind = ? AND subject_id = ?', [$kind, $id]);
        } catch (\Throwable) {
            // No table yet: nobody has two-factor sign-in.
            return null;
        }

        return $row === false ? null : $row;
    }

    /**
     * Whether a login has two-factor sign-in on ('client' with a client id, 'user' with a user id).
     */
    public static function isOn(\Pimple\Container $di, string $kind, int $id): bool
    {
        return self::row($di, $kind, $id) !== null;
    }

    /**
     * The client login (core patch), after the password: true when the sign-in now waits for a
     * code (nothing is signed in). $user is Users::authorize()'s result for an authorized user.
     */
    public static function challenge(\Pimple\Container $di, int $clientId, ?array $user): bool
    {
        [$kind, $id] = $user !== null ? ['user', (int) $user['user_id']] : ['client', $clientId];
        if (!self::isOn($di, $kind, $id)) {
            return false;
        }
        // One waiting sign-in per session: not also an email PIN step of an earlier one.
        $di['session']->delete(\ClientFence\Mod\Clientfence\SignInSecurity::PENDING);
        $di['session']->set(self::PENDING, [
            'kind' => $kind,
            'id' => $id,
            'client_id' => $clientId,
            'user' => $user,
            'expires' => time() + self::PENDING_SECONDS,
            'tries' => 0,
        ]);

        return true;
    }

    /**
     * Whether a sign-in is waiting for a code in this session (the code page asks).
     */
    public static function pending(\Pimple\Container $di): bool
    {
        $pending = $di['session']->get(self::PENDING);

        return is_array($pending) && (int) ($pending['expires'] ?? 0) >= time();
    }

    /**
     * The code page: checks a code (or the backup code) and finishes the waiting sign-in.
     * Returns {backup_used}.
     */
    public static function verify(\Pimple\Container $di, mixed $code, string $ip): array
    {
        $session = $di['session'];
        $pending = $session->get(self::PENDING);
        if (!is_array($pending) || (int) ($pending['expires'] ?? 0) < time()) {
            $session->delete(self::PENDING);

            throw new InformationException('Your sign-in has expired. Please sign in again.', null, 401);
        }
        $di['rate_limiter']->consumeOrThrow('client_password_reset_confirm_post_ip', $ip);
        $limitKey = 'clientfence-2fa-' . $pending['kind'] . '-' . $pending['id'];
        if ($di['rate_limiter']->getStatus('profile_password_change_account', $limitKey)->isLimited()) {
            throw new InformationException('Too many wrong codes. Please try again later.', null, 429);
        }
        $db = $di['em']->getConnection();
        $row = self::row($di, (string) $pending['kind'], (int) $pending['id']);
        $backupUsed = false;
        if ($row !== null) {
            $secret = self::base32Decode((string) $di['crypt']->decrypt((string) $row['secret']));
            $step = $secret === '' ? null : self::matchStep($secret, $code, (int) $row['last_step']);
            $accepted = $step !== null
                && $db->executeStatement('UPDATE client_twofactor SET last_step = ? WHERE id = ? AND last_step < ?', [$step, (int) $row['id'], $step]) === 1;
            if (!$accepted && $step === null) {
                $backup = self::normalizeBackup($code);
                if ($backup !== '' && $row['backup_hash'] !== null && $di['password']->verify($backup, (string) $row['backup_hash'])) {
                    $accepted = $db->executeStatement('UPDATE client_twofactor SET backup_hash = NULL, updated_at = ? WHERE id = ? AND backup_hash IS NOT NULL', [date('Y-m-d H:i:s'), (int) $row['id']]) === 1;
                    $backupUsed = $accepted;
                }
            }
            if (!$accepted) {
                $di['rate_limiter']->consume('profile_password_change_account', $limitKey);
                $pending['tries'] = (int) $pending['tries'] + 1;
                if ($pending['tries'] >= self::MAX_TRIES) {
                    $session->delete(self::PENDING);
                    self::log($di, (string) $pending['kind'], (int) $pending['id'], (int) $pending['client_id'], 'two-factor sign-in stopped after ' . self::MAX_TRIES . ' wrong codes');

                    throw new InformationException('Too many wrong codes. Please sign in again.', null, 401);
                }
                $session->set(self::PENDING, $pending);

                throw new InformationException('The code is not correct.');
            }
            if ($backupUsed) {
                self::log($di, (string) $pending['kind'], (int) $pending['id'], (int) $pending['client_id'], 'signed in with the backup code (it no longer works; a new one can be made on the two-factor page)');
            }
        }
        // Switched off meanwhile (by staff): the password was right, so the sign-in completes.
        $session->delete(self::PENDING);
        self::signIn($di, (int) $pending['client_id'], is_array($pending['user'] ?? null) ? $pending['user'] : null, $ip);

        return ['backup_used' => $backupUsed];
    }

    /**
     * What FOSSBilling's client login does after the password (modules/Client/Api/Guest.php
     * login), for a sign-in that waited for its code and for Google sign-in (Google.php). $user
     * is Users::authorize()'s result for an authorized user, null for the owner.
     */
    public static function signIn(\Pimple\Container $di, int $clientId, ?array $user, string $ip): void
    {
        $client = $di['em']->getRepository(\ClientFence\Mod\Client\Entity\Client::class)->find($clientId);
        if (!$client instanceof \ClientFence\Mod\Client\Entity\Client || $client->getStatus() !== \ClientFence\Mod\Client\Entity\Client::ACTIVE) {
            throw new InformationException('Please check your login details.', [], 401);
        }
        $di['events_manager']->fire(['event' => 'onAfterClientLogin', 'params' => ['id' => $client->getId(), 'ip' => $ip]]);
        $session = $di['session'];
        $oldSession = $session->getId();
        $session->regenerateId();
        $session->set('client_id', $client->getId());
        Users::startSession($di, $user);
        $di['logger']->info('Client #%s logged in', $client->getId());
        $session->delete('redirect_uri');
        if (!empty($client->getLang())) {
            $di['cookie_queue']->queue(CookieNames::LOCALE, $client->getLang(), strtotime('+1 month'), '/');
        }
        $di['mod_service']('cart')->transferFromOtherSession($oldSession);
    }

    /**
     * The signed-in login of a client-area request: the owner (client) or an authorized user.
     *
     * @return array{0: string, 1: int}
     */
    public static function subject(\Pimple\Container $di, int $clientId): array
    {
        $context = Users::context($di);

        return $context !== null ? ['user', (int) $context['user_id']] : ['client', $clientId];
    }

    /**
     * The two-factor page: whether it is on and whether a backup code is left.
     */
    public static function status(\Pimple\Container $di, int $clientId): array
    {
        [$kind, $id] = self::subject($di, $clientId);
        $row = self::row($di, $kind, $id);

        return [
            'enabled' => $row !== null,
            'backup' => $row !== null && $row['backup_hash'] !== null,
            'enabled_at' => $row['enabled_at'] ?? null,
        ];
    }

    /**
     * Starts the setup: a new secret, kept in the session until enable() confirms it. Returns
     * {secret, uri} for the authenticator app (QR code and manual entry).
     */
    public static function setup(\Pimple\Container $di, int $clientId): array
    {
        [$kind, $id] = self::subject($di, $clientId);
        if (self::isOn($di, $kind, $id)) {
            throw new InformationException('Two-factor sign-in is already on.');
        }
        $secret = self::base32Encode(random_bytes(20));
        $di['session']->set(self::SETUP, ['kind' => $kind, 'id' => $id, 'secret' => $secret, 'expires' => time() + self::SETUP_SECONDS]);
        $issuer = trim((string) ($di['mod_service']('system')->getParamValue('company_name') ?: 'Client area'));
        $label = rawurlencode($issuer) . ':' . rawurlencode(self::loginEmail($di, $kind, $id));

        return [
            'secret' => implode(' ', str_split($secret, 4)),
            'uri' => 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD,
        ];
    }

    /**
     * Turns two-factor sign-in on: the password and a code from the app for the secret of
     * setup(). Returns {backup_code}, shown once.
     */
    public static function enable(\Pimple\Container $di, int $clientId, array $data, string $ip): array
    {
        [$kind, $id] = self::subject($di, $clientId);
        $setup = $di['session']->get(self::SETUP);
        if (!is_array($setup) || $setup['kind'] !== $kind || (int) $setup['id'] !== $id || (int) $setup['expires'] < time()) {
            throw new InformationException('The setup has expired. Please start again.');
        }
        self::checkPassword($di, $kind, $id, $data['password'] ?? null, $ip);
        $step = self::matchStep(self::base32Decode((string) $setup['secret']), $data['code'] ?? null, 0);
        if ($step === null) {
            throw new InformationException('The code is not correct. Check the time on your phone and try the newest code.');
        }
        $backup = self::newBackupCode();
        $now = date('Y-m-d H:i:s');
        $db = $di['em']->getConnection();
        $db->delete('client_twofactor', ['kind' => $kind, 'subject_id' => $id]);
        $db->insert('client_twofactor', [
            'kind' => $kind,
            'subject_id' => $id,
            'secret' => $di['crypt']->encrypt((string) $setup['secret']),
            'backup_hash' => $di['password']->hashIt(self::normalizeBackup($backup)),
            'last_step' => $step,
            'enabled_at' => $now,
            'updated_at' => $now,
        ]);
        $di['session']->delete(self::SETUP);
        self::log($di, $kind, $id, $clientId, 'switched on two-factor sign-in');

        return ['backup_code' => $backup];
    }

    /**
     * Turns two-factor sign-in off: the password and a code from the app (or the backup code).
     */
    public static function disable(\Pimple\Container $di, int $clientId, array $data, string $ip): void
    {
        [$kind, $id] = self::subject($di, $clientId);
        $row = self::row($di, $kind, $id);
        if ($row === null) {
            throw new InformationException('Two-factor sign-in is not on.');
        }
        self::checkPassword($di, $kind, $id, $data['password'] ?? null, $ip);
        $secret = self::base32Decode((string) $di['crypt']->decrypt((string) $row['secret']));
        $code = $data['code'] ?? null;
        $backup = self::normalizeBackup($code);
        $ok = ($secret !== '' && self::matchStep($secret, $code, (int) $row['last_step']) !== null)
            || ($backup !== '' && $row['backup_hash'] !== null && $di['password']->verify($backup, (string) $row['backup_hash']));
        if (!$ok) {
            throw new InformationException('The code is not correct.');
        }
        $di['em']->getConnection()->delete('client_twofactor', ['id' => (int) $row['id']]);
        self::log($di, $kind, $id, $clientId, 'switched off two-factor sign-in');
    }

    /**
     * A new backup code (the old one stops working): the password. Returns {backup_code}.
     */
    public static function newBackup(\Pimple\Container $di, int $clientId, array $data, string $ip): array
    {
        [$kind, $id] = self::subject($di, $clientId);
        $row = self::row($di, $kind, $id);
        if ($row === null) {
            throw new InformationException('Two-factor sign-in is not on.');
        }
        self::checkPassword($di, $kind, $id, $data['password'] ?? null, $ip);
        $backup = self::newBackupCode();
        $di['em']->getConnection()->update('client_twofactor', ['backup_hash' => $di['password']->hashIt(self::normalizeBackup($backup)), 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
        self::log($di, $kind, $id, $clientId, 'made a new two-factor backup code');

        return ['backup_code' => $backup];
    }

    /**
     * Staff switch off a login's two-factor sign-in (Users tab of the client page): the account
     * owner, or a user of that account ($userId).
     */
    public static function staffDisable(\Pimple\Container $di, int $clientId, ?int $userId, int $adminId): void
    {
        $db = $di['em']->getConnection();
        if ($db->fetchOne('SELECT id FROM client WHERE id = ?', [$clientId]) === false) {
            throw new InformationException('Client not found.', null, 404);
        }
        if ($userId !== null && $db->fetchOne('SELECT id FROM client_user_account WHERE user_id = ? AND client_id = ?', [$userId, $clientId]) === false) {
            throw new InformationException('User not found.', null, 404);
        }
        [$kind, $id] = $userId !== null ? ['user', $userId] : ['client', $clientId];
        if ($db->delete('client_twofactor', ['kind' => $kind, 'subject_id' => $id]) === 0) {
            throw new InformationException('Two-factor sign-in is not on.');
        }
        self::log($di, $kind, $id, $clientId, 'two-factor sign-in switched off by staff', $adminId);
    }

    /**
     * A deleted client or user leaves no two-factor row (Users::clientDeleted, dropOrphan).
     */
    public static function forgetLogin(\Doctrine\DBAL\Connection $db, string $kind, int $id): void
    {
        try {
            $db->delete('client_twofactor', ['kind' => $kind, 'subject_id' => $id]);
        } catch (\Throwable) {
            // No table yet.
        }
    }

    public static function checkPassword(\Pimple\Container $di, string $kind, int $id, mixed $password, string $ip): void
    {
        $di['rate_limiter']->consumeOrThrow('profile_password_change_ip', $ip);
        // Its own count: settings changes do not use up the wrong codes of a sign-in.
        $di['rate_limiter']->consumeOrThrow('profile_password_change_account', 'clientfence-2fa-password-' . $kind . '-' . $id);
        $db = $di['em']->getConnection();
        $hash = (string) $db->fetchOne($kind === 'user' ? 'SELECT pass FROM client_user WHERE id = ?' : 'SELECT pass FROM client WHERE id = ?', [$id]);
        if (!is_string($password) || $hash === '' || !$di['password']->verify($password, $hash)) {
            throw new InformationException('The password is not correct.');
        }
    }

    private static function loginEmail(\Pimple\Container $di, string $kind, int $id): string
    {
        $db = $di['em']->getConnection();

        return $kind === 'user' ? Users::userEmail($db, $id) : (string) $db->fetchOne('SELECT email FROM client WHERE id = ?', [$id]);
    }

    /**
     * An activity log entry: the owner's under the account, a user's under every account of the
     * user (the account in use when it has none left).
     */
    public static function log(\Pimple\Container $di, string $kind, int $id, int $clientId, string $what, ?int $adminId = null): void
    {
        if ($kind === 'user') {
            $db = $di['em']->getConnection();
            $clients = Users::linkedClients($db, $id) ?: [$clientId];
            Users::activity($di, $clients, sprintf('Authorized user %s: %s', Users::userEmail($db, $id), $what), $adminId);

            return;
        }
        Users::activity($di, [$clientId], 'Account owner: ' . $what, $adminId);
    }
}
