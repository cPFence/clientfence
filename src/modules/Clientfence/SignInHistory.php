<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\Config;
use ClientFence\GeoIP\Reader;
use ClientFence\InformationException;
use Symfony\Component\Intl\Countries;

/**
 * Sign-in details on FOSSBilling's login history (activity_client_history, activity_admin_history; migration 12):
 * country, network (ASN number and name), browser and the session, kept while the "Sign-in history" box or the
 * email PIN "Only from a new country or network" is on (SignInSecurity); and always, for clients, the authorized user.
 * Kept as long as the rest of the history (Activity "max age").
 *
 * - Sign-in history: the Sign-ins tab of the client and staff profiles, the last LIST_SIZE sign-ins of the
 *   login itself (an account owner does not see the authorized users' ones), "This session" and "Active"
 *   marks, and "Sign out" of another session. The browser never sees a session id: rows go by their own id.
 * - New-location PIN: knownPlace(), the same country and network as an earlier sign-in in KNOWN_DAYS.
 *
 * Rows are written by Activity's login listeners only after the whole sign-in (password, then the app code,
 * email PIN or a trusted browser), so a place becomes known only once it passed them.
 */
final class SignInHistory
{
    public const LIST_SIZE = 20;
    public const KNOWN_DAYS = 90;
    public const LEARN_ROWS = 50;
    private const CLIENT = 'activity_client_history';

    /** The row of this request's sign-in: [table, id, details kept] until started() names its session and user. */
    private static ?array $row = null;

    private static ?Reader $asn = null;

    /** Whether the details are kept (the saved settings alone: no Staff settings read at sign-in). */
    public static function on(\Pimple\Container $di): bool
    {
        $saved = SignInSecurity::state($di) ?? [];

        return !empty($saved['history']) || ($saved['pin_when'] ?? null) === 'new';
    }

    /** Activity's login listeners (core patch): writes the history row, with the details while they are kept. */
    public static function record(\Pimple\Container $di, string $table, array $row): void
    {
        $on = self::on($di);
        if ($on) {
            $row += self::locate($di, $row['ip']) + ['browser' => self::browser((string) $di['request']->headers->get('User-Agent', ''))];
        }
        $db = $di['em']->getConnection();
        $db->insert($table, $row);
        self::$row = [$table, (int) $db->lastInsertId(), $on];
    }

    /**
     * After a sign-in set its session (the session id changes at sign-in, after the login event): the row of
     * record() gets the session (while kept) and the authorized user ($userId, null for the account owner and staff).
     */
    public static function started(\Pimple\Container $di, ?int $userId): void
    {
        if (self::$row === null) {
            return;
        }
        [$table, $id, $on] = self::$row;
        self::$row = null;
        // The authorized user also while off: else its row would count as the owner's place for the PIN later.
        $values = ($on ? ['session_id' => $di['session']->getId()] : []) + ($table === self::CLIENT && $userId !== null ? ['user_id' => $userId] : []);
        if ($values !== []) {
            $di['em']->getConnection()->update($table, $values, ['id' => $id]);
        }
    }

    /**
     * Session::regenerateId() (core patch): the rows of the logins still signed in in $session (staff, the client
     * and its authorized user) follow the browser's new session id; a signed-out login's row stays on the old one.
     */
    public static function moved(\Pimple\Container $di, string $old, string $new, array $session): void
    {
        if (!self::on($di)) {
            return;
        }
        $db = $di['em']->getConnection();
        if (isset($session['admin']['id'])) {
            $db->update('activity_admin_history', ['session_id' => $new], ['session_id' => $old, 'admin_id' => (int) $session['admin']['id']]);
        }
        $user = $session[\ClientFence\Mod\Client\Users::SESSION]['user_id'] ?? null;
        if ($user !== null) {
            // Not by account: the row keeps the account signed in to, the session may be on another (Users::switchTo()).
            $db->update(self::CLIENT, ['session_id' => $new], ['session_id' => $old, 'user_id' => (int) $user]);
        } elseif (is_int($session['client_id'] ?? null)) {
            $db->executeStatement('UPDATE ' . self::CLIENT . ' SET session_id = ? WHERE session_id = ? AND client_id = ? AND user_id IS NULL', [$new, $old, $session['client_id']]);
        }
    }

    /**
     * The new-location PIN: whether the login ($kind client|user|staff, $id) signed in from the country and network
     * of $ip in the last KNOWN_DAYS. An unknown country or network is never known. When not, the login's sign-ins
     * of that time logged without details (before it was on) get theirs, LEARN_ROWS at a time, and count too (staff
     * only: older client rows do not say which login signed in).
     */
    public static function knownPlace(\Pimple\Container $di, string $kind, int $id, string $ip): bool
    {
        $place = self::locate($di, $ip);
        if ($place['country'] === '' || $place['asn'] === null) {
            return false;
        }
        [$table, $where, $params] = self::owner($kind, $id);
        $since = date('Y-m-d H:i:s', time() - self::KNOWN_DAYS * 86400);
        $db = $di['em']->getConnection();
        if ($db->fetchOne("SELECT 1 FROM {$table} WHERE {$where} AND country = ? AND asn = ? AND created_at > ? LIMIT 1", [...$params, $place['country'], $place['asn'], $since]) !== false) {
            return true;
        }
        // Client rows logged before migration 12 name no authorized user (also of removed ones): an owner cannot tell
        // its own from theirs, so only staff learn them; a client's place is known after one sign-in with details.
        if ($kind !== 'staff') {
            return false;
        }
        $known = false;
        foreach ($db->fetchAllAssociative("SELECT id, ip FROM {$table} WHERE {$where} AND country IS NULL AND ip IS NOT NULL AND created_at > ? LIMIT " . self::LEARN_ROWS, [...$params, $since]) as $old) {
            $found = self::locate($di, (string) $old['ip']);
            $db->update($table, $found, ['id' => $old['id']]);
            $known = $known || ($found['country'] === $place['country'] && $found['asn'] === $place['asn']);
        }

        return $known;
    }

    /**
     * The Sign-ins tab: null while the sign-in history is off, else the login's last LIST_SIZE sign-ins kept with
     * details: {id, created_at, ip, country (name), network, browser, current, active}.
     */
    public static function recent(\Pimple\Container $di, string $kind, int $id): ?array
    {
        if (empty(SignInSecurity::state($di)['history'])) {
            return null;
        }
        [$table, $where, $params] = self::owner($kind, $id);
        // An authorized user's session may be on another of its accounts (Users::switchTo()).
        $login = match ($kind) { 'staff' => 'admin_id', 'user' => 'user_id', default => 'client_id' };
        $rows = $di['em']->getConnection()->fetchAllAssociative(
            "SELECT h.*, s.content AS live FROM (SELECT id, {$login} AS login_id, created_at, ip, country, network, browser, session_id FROM {$table}
                WHERE {$where} AND session_id IS NOT NULL ORDER BY id DESC LIMIT " . self::LIST_SIZE . ') h
             LEFT JOIN session s ON s.id = h.session_id AND (s.created_at IS NULL OR s.created_at > ?) ORDER BY h.id DESC',
            [...$params, time() - (int) Config::getProperty('security.session_lifespan', 7200)]
        );
        $current = $di['session']->getId();
        $profile = $di['mod_service']('profile');

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'created_at' => $row['created_at'],
            'ip' => $row['ip'],
            'country' => $row['country'] ? Countries::getName($row['country']) : null,
            'network' => $row['network'],
            'browser' => $row['browser'],
            'current' => $row['session_id'] === $current,
            // Still signed in as this login: a signed-out browser keeps its session id as a guest.
            'active' => $row['session_id'] === $current || (is_string($row['live']) && $row['live'] !== '' && $profile->sessionLogin($row['live'])[$login] === (int) $row['login_id']),
        ], $rows);
    }

    /** "Sign out" on the Sign-ins tab: ends the session of one of the login's own sign-ins ($rowId), not this one. */
    public static function end(\Pimple\Container $di, string $kind, int $id, int $rowId): void
    {
        [$table, $where, $params] = self::owner($kind, $id);
        $db = $di['em']->getConnection();
        $session = $db->fetchOne("SELECT session_id FROM {$table} WHERE id = ? AND {$where}", [$rowId, ...$params]);
        if (!is_string($session) || $session === '') {
            throw new InformationException('Sign-in not found.', null, 404);
        }
        if ($session === $di['session']->getId()) {
            throw new InformationException('This is the session you are using.');
        }
        $db->delete('session', ['id' => $session]);
        $di['logger']->info('Signed out the session of sign-in #%s (%s #%s)', $rowId, $kind, $id);
    }

    /**
     * Country, network (ASN) and its name from the local GeoIP databases; country '' when not known (looked up,
     * so the learning in knownPlace() does not look it up again). No lookup for a private or reserved address.
     *
     * @return array{country: string, asn: ?int, network: ?string}
     */
    public static function locate(\Pimple\Container $di, ?string $ip): array
    {
        $place = ['country' => '', 'asn' => null, 'network' => null];
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $place;
        }
        $place['country'] = SignInSecurity::countryOf($di, $ip) ?? '';
        try {
            self::$asn ??= new Reader(Reader::getAsnDatabase());
            $record = self::$asn->get($ip);
        } catch (\Throwable) {
            return $place;
        }
        if (is_int($record['autonomous_system_number'] ?? null)) {
            $place['asn'] = $record['autonomous_system_number'];
            $place['network'] = mb_substr((string) ($record['autonomous_system_organization'] ?? ''), 0, 128) ?: null;
        }

        return $place;
    }

    /** "Chrome on Windows" from a user agent, or only the browser or the system when one is not known; null for neither. */
    public static function browser(string $agent): ?string
    {
        $browser = match (true) {
            (bool) preg_match('/\bEdg(e|A|iOS)?\//', $agent) => 'Edge',
            (bool) preg_match('/\b(OPR|OPiOS|Opera)\//', $agent) => 'Opera',
            str_contains($agent, 'SamsungBrowser/') => 'Samsung Internet',
            (bool) preg_match('/\b(Chrome|CriOS)\//', $agent) => 'Chrome',
            (bool) preg_match('/\b(Firefox|FxiOS)\//', $agent) => 'Firefox',
            str_contains($agent, 'Safari/') && str_contains($agent, 'Version/') => 'Safari',
            default => null,
        };
        // iOS and Android before macOS and Linux: their agents name those too.
        $system = match (true) {
            (bool) preg_match('/\b(iPhone|iPad|iPod)\b/', $agent) => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Macintosh') || str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $browser !== null && $system !== null ? $browser . ' on ' . $system : ($browser ?? $system);
    }

    /** The table and condition of a login's own rows: an account owner's are those without an authorized user. */
    private static function owner(string $kind, int $id): array
    {
        return match ($kind) {
            'staff' => ['activity_admin_history', 'admin_id = ?', [$id]],
            'user' => [self::CLIENT, 'user_id = ?', [$id]],
            default => [self::CLIENT, 'client_id = ? AND user_id IS NULL', [$id]],
        };
    }
}
