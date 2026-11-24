<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\SignInSecurity;

/**
 * Passkeys (WebAuthn, CF-127): a passkey with user verification (fingerprint, face, device PIN) signs in
 * instead of the password and the second step, for account owners ('client'), authorized users ('user') and
 * staff ('staff'). Security > Settings > Sign-in turns them on for clients, staff or both (SignInSecurity,
 * `passkeys`); off by default, and on only with an https:// portal URL (or localhost, as browsers allow).
 *
 * Own small code, attestation "none", ES256 and RS256 only: the browser gives the new key as SPKI DER
 * (getPublicKey()), so no CBOR is read. Every answer is checked for its type, the session's challenge (single
 * use, CHALLENGE_SECONDS), the portal's origin, the hash of its host name (rpId), and the UP and UV flags; a
 * sign-in also for the signature and a sign count that only goes up (synced passkeys always send 0).
 * Discoverable credentials: the sign-in page asks for no email, the passkey's user handle (random per login)
 * names the login.
 *
 * Table client_passkey: one row per passkey (kind, subject_id, credential id and user handle as base64url,
 * the key as PEM, the site it was made on: each site sees only its own, siteWhere()). Rows go with the login (TwoFactor::forgetLogin()). Adding one needs the password, except
 * within OFFER_SECONDS of a sign-in that offered it ("Sign in faster next time"); removing one needs the
 * password; staff remove a login's passkeys where they switch off its app. A passkey counts as the second
 * factor CF-118 requires (TwoFactor::hasSecondFactor()). Adding one to a client login emails that login.
 */
final class Passkeys
{
    public const MODES = ['off', 'clients', 'staff', 'both'];
    public const CHALLENGE = 'clientfence_passkey_challenge';
    public const OFFER = 'clientfence_passkey_offer';
    /** "kind:id" of the login this session signed in (or enrolled) with a passkey: only then it meets "required". */
    public const SIGNED_IN = 'clientfence_passkey_signed_in';
    /** "Not now" on the offer card: a cookie for a year in that browser (set by the page script), per area. */
    public const NOT_NOW = ['client' => 'clientfence_passkey_later', 'staff' => 'clientfence_passkey_later_staff'];
    public const CHALLENGE_SECONDS = 300;
    public const OFFER_SECONDS = 600;
    private const MAX_ID_BYTES = 255;

    /** Whether passkeys are on for an area ('client' or 'staff'): only the saved settings row is read. */
    public static function on(\Pimple\Container $di, string $area): bool
    {
        $mode = SignInSecurity::state($di)['passkeys'] ?? 'off';

        return $mode === 'both' || $mode === ($area === 'staff' ? 'staff' : 'clients');
    }

    /** Passkeys need HTTPS; browsers also allow them on localhost (and its subdomains). */
    public static function secureUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' || $host === 'localhost' || str_ends_with($host, '.localhost');
    }

    /** The origin the browser reports for the portal: scheme, host and port of the request's site. */
    public static function origin(\Pimple\Container $di): string
    {
        return \ClientFence\Site::current($di)->origin();
    }

    /** The relying party id: the host name of the request's site (a passkey works only there). */
    public static function rpId(\Pimple\Container $di): string
    {
        return \Symfony\Component\HttpFoundation\Request::create(\ClientFence\Site::current($di)->url)->getHost();
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(mixed $text): string
    {
        $bytes = is_string($text) && $text !== '' ? base64_decode(strtr($text, '-_', '+/'), true) : false;

        return is_string($bytes) ? $bytes : '';
    }

    private static function area(string $kind): string
    {
        return $kind === 'staff' ? 'staff' : 'client';
    }

    // ----- sign-in -----------------------------------------------------------------------------

    /** The sign-in page: the options for the browser's passkey prompt (a new challenge). */
    public static function signInOptions(\Pimple\Container $di, string $area): array
    {
        if (!self::on($di, $area)) {
            throw new InformationException('Passkey sign-in is not on.');
        }

        return ['challenge' => self::newChallenge($di, ['type' => 'get', 'area' => $area]), 'rpId' => self::rpId($di), 'userVerification' => 'required', 'timeout' => self::CHALLENGE_SECONDS * 1000];
    }

    /**
     * Checks the browser's answer to signInOptions() (`id`, `clientDataJSON`, `authenticatorData`, `signature`,
     * `userHandle`, base64url) and saves its sign count. Returns the login: [kind, id]. The caller signs it in.
     *
     * @return array{0: string, 1: int}
     */
    public static function verifySignIn(\Pimple\Container $di, string $area, array $data): array
    {
        $state = self::takeChallenge($di, 'get', $area);
        if (!self::on($di, $area)) {
            throw new InformationException('Passkey sign-in is not on.');
        }
        $credentialId = self::b64(self::decode($data['id'] ?? null));
        [$site, $siteParams] = self::siteWhere($di);
        try {
            $row = $credentialId === '' ? false : $di['em']->getConnection()->fetchAssociative("SELECT * FROM client_passkey WHERE credential_id = ? AND {$site}", [$credentialId, ...$siteParams]);
        } catch (\Doctrine\DBAL\Exception $e) {
            // New files before Finalize update (the site column is missing): the password still signs in.
            self::refuse($di, $e->getMessage());
        }
        if ($row === false || !in_array($row['kind'], $area === 'staff' ? ['staff'] : ['client', 'user'], true)) {
            self::refuse($di, 'unknown passkey', 'This passkey is not set up for this sign-in. Please sign in with your password.');
        }
        $handle = self::decode($data['userHandle'] ?? null);
        if ($handle !== '' && !hash_equals((string) $row['user_handle'], self::b64($handle))) {
            self::refuse($di, 'user handle of another login');
        }
        $clientData = self::decode($data['clientDataJSON'] ?? null);
        $authData = self::decode($data['authenticatorData'] ?? null);
        self::checkClientData($di, $clientData, 'webauthn.get', (string) $state['challenge']);
        $count = self::checkAuthData($di, $authData)['count'];
        if (openssl_verify($authData . hash('sha256', $clientData, true), self::decode($data['signature'] ?? null), (string) $row['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            self::refuse($di, 'wrong signature');
        }
        // A count that does not go up means a copied authenticator; synced passkeys always send 0.
        if ($count !== 0 && $count <= (int) $row['sign_count']) {
            self::refuse($di, 'sign count ' . $count . ' not above ' . $row['sign_count']);
        }
        $di['em']->getConnection()->update('client_passkey', ['sign_count' => max($count, (int) $row['sign_count']), 'last_used_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
        $di['logger']->setChannel('security')->info('Passkey sign-in of %s #%s', $row['kind'], $row['subject_id']);

        return [(string) $row['kind'], (int) $row['subject_id']];
    }

    /**
     * The client area's passkey sign-in: the owner or the authorized user signs in as after the password and
     * the second step (TwoFactor::signIn(): history, events, the CF-118 check, the cart).
     */
    public static function signInClient(\Pimple\Container $di, array $data, string $ip): void
    {
        [$kind, $id] = self::verifySignIn($di, 'client', $data);
        $user = $kind === 'user' ? Users::loginFor($di, $id) : null;
        if ($kind === 'user' && $user === null) {
            throw new InformationException('Please check your login details.', [], 401);
        }
        self::endWaiting($di);
        $di['session']->set(self::SIGNED_IN, $kind . ':' . $id);
        TwoFactor::signIn($di, $user !== null ? (int) $user['client_id'] : $id, $user, $ip);
    }

    /** A passkey sign-in ends any password sign-in still waiting in this session for its code or PIN. */
    public static function endWaiting(\Pimple\Container $di): void
    {
        $di['session']->delete(TwoFactor::PENDING);
        $di['session']->delete(TwoFactor::STAFF_PENDING);
        $di['session']->delete(SignInSecurity::PENDING);
    }

    // ----- the offer after a sign-in -------------------------------------------------------------

    /**
     * After a sign-in that did not use a passkey: the next page offers one ("Sign in faster next time") when
     * passkeys are on for the area, the login has none and this browser did not choose "Not now".
     */
    public static function offer(\Pimple\Container $di, string $kind, int $id): void
    {
        $area = self::area($kind);
        if (self::on($di, $area) && !$di['request']->cookies->has(self::NOT_NOW[$area]) && self::count($di, $kind, $id) === 0) {
            // The session id ties it to this sign-in: sign-out and staff "Login as client" get a new one.
            $di['session']->set(self::OFFER, ['kind' => $kind, 'id' => $id, 'session' => $di['session']->getId(), 'at' => time(), 'shown' => false]);
        }
    }

    /** The layout: true once, on the first page after the sign-in that offered a passkey. */
    public static function takeOffer(\Pimple\Container $di, string $kind, int $id): bool
    {
        if (!self::recentSignIn($di, $kind, $id) || $di['session']->get(self::OFFER)['shown']) {
            return false;
        }
        $di['session']->set(self::OFFER, ['shown' => true] + $di['session']->get(self::OFFER));

        return true;
    }

    private static function recentSignIn(\Pimple\Container $di, string $kind, int $id): bool
    {
        $offer = $di['session']->get(self::OFFER);

        return is_array($offer) && ($offer['kind'] ?? null) === $kind && (int) ($offer['id'] ?? 0) === $id
            && ($offer['session'] ?? null) === $di['session']->getId() && (int) ($offer['at'] ?? 0) + self::OFFER_SECONDS >= time();
    }

    // ----- adding, renaming, removing ------------------------------------------------------------

    /**
     * The options for the browser's "create a passkey" prompt (a new challenge); `password` unless the login
     * signed in within OFFER_SECONDS and was offered a passkey then.
     */
    public static function createOptions(\Pimple\Container $di, string $kind, int $id, array $data, string $ip): array
    {
        if (!self::on($di, self::area($kind))) {
            throw new InformationException('Passkeys are not on.');
        }
        if (!self::recentSignIn($di, $kind, $id)) {
            TwoFactor::checkPassword($di, $kind, $id, $data['password'] ?? null, $ip);
        }
        $rows = self::rows($di, $kind, $id);
        // One user handle per login, so a device keeps one passkey per login.
        $handle = (string) ($rows[0]['user_handle'] ?? self::b64(random_bytes(16)));
        $challenge = self::newChallenge($di, ['type' => 'create', 'area' => self::area($kind), 'kind' => $kind, 'id' => $id, 'handle' => $handle]);
        $email = TwoFactor::loginEmail($di, $kind, $id);

        return [
            'challenge' => $challenge,
            'rp' => ['id' => self::rpId($di), 'name' => trim((string) ($di['mod_service']('system')->getParamValue('company_name') ?: 'Client area'))],
            'user' => ['id' => $handle, 'name' => $email, 'displayName' => $email],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none',
            'excludeCredentials' => array_map(static fn (array $row): array => ['type' => 'public-key', 'id' => (string) $row['credential_id']], $rows),
            'timeout' => self::CHALLENGE_SECONDS * 1000,
        ];
    }

    /**
     * Saves the passkey the browser made for createOptions() (`id`, `clientDataJSON`, `authenticatorData`,
     * `publicKey` (SPKI DER), base64url; `publicKeyAlgorithm`). Returns {id, name}.
     */
    public static function add(\Pimple\Container $di, string $kind, int $id, int $clientId, array $data, string $ip): array
    {
        $state = self::takeChallenge($di, 'create', self::area($kind));
        if ($state['kind'] !== $kind || (int) $state['id'] !== $id) {
            throw new InformationException('The passkey request has expired. Please try again.');
        }
        $spki = self::decode($data['publicKey'] ?? null);
        if ($spki === '') {
            throw new InformationException('This browser cannot create a passkey. Please update it, or use another browser.');
        }
        $clientData = self::decode($data['clientDataJSON'] ?? null);
        $authData = self::decode($data['authenticatorData'] ?? null);
        self::checkClientData($di, $clientData, 'webauthn.create', (string) $state['challenge']);
        $checked = self::checkAuthData($di, $authData);
        // Attested credential data after the count: AAGUID (16 bytes), the id's length (2) and the id the browser named.
        $length = strlen($authData) >= 55 ? (int) unpack('n', $authData, 53)[1] : 0;
        $credentialId = self::decode($data['id'] ?? null);
        if (($checked['flags'] & 0x40) === 0 || $length === 0 || $length > self::MAX_ID_BYTES || !hash_equals(substr($authData, 55, $length), $credentialId)) {
            self::refuse($di, 'no credential in the authenticator data');
        }
        $alg = (int) ($data['publicKeyAlgorithm'] ?? 0);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        $details = $key === false ? [] : (openssl_pkey_get_details($key) ?: []);
        $fits = match ($alg) {
            -7 => ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') === 'prime256v1',
            -257 => ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA,
            default => false,
        };
        if (!$fits) {
            throw new InformationException('This passkey uses a kind of key that cannot be used here. Please try another one.');
        }
        $db = $di['em']->getConnection();
        if ($db->fetchOne('SELECT id FROM client_passkey WHERE credential_id = ?', [self::b64($credentialId)]) !== false) {
            throw new InformationException('This passkey is already saved.');
        }
        $name = mb_substr((string) (\ClientFence\Mod\Clientfence\SignInHistory::browser((string) $di['request']->headers->get('User-Agent', '')) ?? __trans('Passkey')), 0, 100);
        $now = date('Y-m-d H:i:s');
        $db->insert('client_passkey', [
            'kind' => $kind,
            'subject_id' => $id,
            'credential_id' => self::b64($credentialId),
            'public_key' => $pem,
            'alg' => $alg,
            'sign_count' => $checked['count'],
            'name' => $name,
            'user_handle' => (string) $state['handle'],
            'created_at' => $now,
            'site' => \ClientFence\Site::current($di)->key,
        ]);
        $passkeyId = (int) $db->lastInsertId();
        TwoFactor::log($di, $kind, $id, $clientId, sprintf('added a passkey (%s)', $name));
        if ($kind !== 'staff') {
            // A passkey meets the two-factor sign-in the portal requires (CF-118).
            $di['session']->delete(TwoFactor::REQUIRED);
            $di['session']->set(self::SIGNED_IN, $kind . ':' . $id);
            // Someone with only the password could add one: the login hears of it.
            TwoFactor::emailLogin($di, $kind, $id, 'mod_client_passkey_added', $ip, ['added_at' => $now, 'passkey' => $name]);
        }

        return ['id' => $passkeyId, 'name' => $name];
    }

    /** The profile pages: {on, passkeys: [{id, name, created_at, last_used_at}]}. */
    public static function listFor(\Pimple\Container $di, string $kind, int $id): array
    {
        // Off reads no table and shows no card.
        if (!self::on($di, self::area($kind))) {
            return ['on' => false, 'passkeys' => []];
        }
        $rows = array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'created_at' => $row['created_at'], 'last_used_at' => $row['last_used_at']], self::rows($di, $kind, $id));

        return ['on' => true, 'passkeys' => $rows];
    }

    public static function rename(\Pimple\Container $di, string $kind, int $id, int $passkeyId, mixed $name): void
    {
        $name = is_string($name) ? mb_substr(trim($name), 0, 100) : '';
        if ($name === '') {
            throw new InformationException('Enter a name for the passkey.');
        }
        $db = $di['em']->getConnection();
        [$site, $siteParams] = self::siteWhere($di);
        // An unknown id (or another login's or another site's passkey) is an error, as in remove(), not a silent success.
        if ($db->fetchOne("SELECT id FROM client_passkey WHERE id = ? AND kind = ? AND subject_id = ? AND {$site}", [$passkeyId, $kind, $id, ...$siteParams]) === false) {
            throw new InformationException('Passkey not found.', null, 404);
        }
        $db->update('client_passkey', ['name' => $name], ['id' => $passkeyId, 'kind' => $kind, 'subject_id' => $id]);
    }

    /** Removes one of the login's passkeys (`password`); a client login may then have to set up two-factor sign-in again. */
    public static function remove(\Pimple\Container $di, string $kind, int $id, int $clientId, int $passkeyId, array $data, string $ip): void
    {
        TwoFactor::checkPassword($di, $kind, $id, $data['password'] ?? null, $ip);
        [$site, $siteParams] = self::siteWhere($di);
        $name = $di['em']->getConnection()->fetchOne("SELECT name FROM client_passkey WHERE id = ? AND kind = ? AND subject_id = ? AND {$site}", [$passkeyId, $kind, $id, ...$siteParams]);
        if ($name === false) {
            throw new InformationException('Passkey not found.', null, 404);
        }
        $di['em']->getConnection()->delete('client_passkey', ['id' => $passkeyId]);
        TwoFactor::log($di, $kind, $id, $clientId, sprintf('removed a passkey (%s)', $name));
        if ($kind !== 'staff') {
            TwoFactor::checkRequired($di, $clientId, Users::context($di));
        }
    }

    /** Staff remove every passkey of the account owner, or of a user of the account, on every site (lost device; Users tab). */
    public static function staffRemove(\Pimple\Container $di, int $clientId, ?int $userId, int $adminId): void
    {
        $db = $di['em']->getConnection();
        if ($db->fetchOne('SELECT id FROM client WHERE id = ?', [$clientId]) === false) {
            throw new InformationException('Client not found.', null, 404);
        }
        if ($userId !== null && $db->fetchOne('SELECT id FROM client_user_account WHERE user_id = ? AND client_id = ?', [$userId, $clientId]) === false) {
            throw new InformationException('User not found.', null, 404);
        }
        [$kind, $id] = $userId !== null ? ['user', $userId] : ['client', $clientId];
        if ($db->delete('client_passkey', ['kind' => $kind, 'subject_id' => $id]) === 0) {
            throw new InformationException('This login has no passkeys.');
        }
        TwoFactor::log($di, $kind, $id, $clientId, 'passkeys removed by staff', $adminId);
    }

    /** A Super Administrator removes a staff member's passkeys on every site (staff page). */
    public static function adminRemove(\Pimple\Container $di, int $adminId, int $byAdminId): void
    {
        if ($di['em']->getConnection()->delete('client_passkey', ['kind' => 'staff', 'subject_id' => $adminId]) === 0) {
            throw new InformationException('This login has no passkeys.');
        }
        TwoFactor::log($di, 'staff', $adminId, 0, 'passkeys removed by staff #' . $byAdminId);
    }

    /** The login's passkeys on this site; staff pages count those of every site (`$allSites`), as staffRemove() removes them all. */
    public static function count(\Pimple\Container $di, string $kind, int $id, bool $allSites = false): int
    {
        // Off reads no table: the admin client and staff pages count each login's passkeys.
        return self::on($di, self::area($kind)) ? count(self::rows($di, $kind, $id, $allSites)) : 0;
    }

    /** A deleted login leaves no passkeys (TwoFactor::forgetLogin()). */
    public static function forgetLogin(\Doctrine\DBAL\Connection $db, string $kind, int $id): void
    {
        try {
            $db->delete('client_passkey', ['kind' => $kind, 'subject_id' => $id]);
        } catch (\Throwable) {
            // No table yet.
        }
    }

    // ----- checks ------------------------------------------------------------------------------

    private static function rows(\Pimple\Container $di, string $kind, int $id, bool $allSites = false): array
    {
        [$site, $siteParams] = $allSites ? ['1 = 1', []] : self::siteWhere($di);
        try {
            return $di['em']->getConnection()->fetchAllAssociative("SELECT * FROM client_passkey WHERE kind = ? AND subject_id = ? AND {$site} ORDER BY id", [$kind, $id, ...$siteParams]);
        } catch (\Throwable) {
            // No table yet (before migration 14) or no site column (before 24): nobody has a passkey.
            return [];
        }
    }

    /**
     * The condition for the passkeys of the request's site: a passkey works only on the site (host name) it was made
     * on, so each site sees only its own (`site` is the ClientFence\Site key, NULL for the primary site).
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function siteWhere(\Pimple\Container $di): array
    {
        $key = \ClientFence\Site::current($di)->key;

        return $key === null ? ['site IS NULL', []] : ['site = ?', [$key]];
    }

    private static function newChallenge(\Pimple\Container $di, array $state): string
    {
        $challenge = self::b64(random_bytes(32));
        $di['session']->set(self::CHALLENGE, $state + ['challenge' => $challenge, 'expires' => time() + self::CHALLENGE_SECONDS]);

        return $challenge;
    }

    /** The session's challenge of a type ('get' or 'create') and area, which works once. */
    private static function takeChallenge(\Pimple\Container $di, string $type, string $area): array
    {
        $state = $di['session']->get(self::CHALLENGE);
        $di['session']->delete(self::CHALLENGE);
        if (!is_array($state) || ($state['type'] ?? null) !== $type || ($state['area'] ?? null) !== $area || (int) ($state['expires'] ?? 0) < time()) {
            throw new InformationException('The passkey request has expired. Please try again.');
        }

        return $state;
    }

    private static function checkClientData(\Pimple\Container $di, string $json, string $type, string $challenge): void
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['type'] ?? null) !== $type) {
            self::refuse($di, 'wrong type');
        }
        if (!is_string($data['challenge'] ?? null) || !hash_equals($challenge, $data['challenge'])) {
            self::refuse($di, 'wrong challenge');
        }
        if (($data['origin'] ?? null) !== self::origin($di) || ($data['crossOrigin'] ?? false) === true) {
            self::refuse($di, 'wrong origin ' . (is_string($data['origin'] ?? null) ? $data['origin'] : ''));
        }
    }

    /**
     * The authenticator data: the hash of the portal's host name, user presence and verification.
     *
     * @return array{flags: int, count: int}
     */
    private static function checkAuthData(\Pimple\Container $di, string $authData): array
    {
        if (strlen($authData) < 37 || !hash_equals(hash('sha256', self::rpId($di), true), substr($authData, 0, 32))) {
            self::refuse($di, 'wrong relying party');
        }
        $flags = ord($authData[32]);
        if (($flags & 0x05) !== 0x05) {
            self::refuse($di, 'no user verification');
        }

        return ['flags' => $flags, 'count' => (int) unpack('N', $authData, 33)[1]];
    }

    private static function refuse(\Pimple\Container $di, string $reason, string $message = 'The passkey could not be checked. Please try again, or sign in with your password.'): never
    {
        $di['logger']->setChannel('security')->info('Passkey refused: %s', $reason);

        throw new InformationException($message);
    }
}
