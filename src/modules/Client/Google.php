<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\Mod\Clientfence\Service as Clientfence;
use ClientFence\InformationException;

/**
 * Google sign-in (like WHMCS's "Sign in with Google").
 *
 * OpenID Connect authorization code flow with PKCE (S256), a state and a nonce bound to the
 * browser session. The ID token comes straight from Google's token endpoint over TLS, so its
 * claims are checked (issuer, audience, expiry, nonce) without fetching Google's keys (OpenID
 * Connect Core 3.1.3.7). Google's account id (`sub`) is what links: table client_google
 * holds one row per linked login (kind 'client' for an account owner, 'user' for an authorized
 * user), a Google account links to one login and a login to one Google account. WHMCS's
 * Google links (tblauthn_account_links) carry the same ids, so an import keeps them.
 *
 * Sign-in (/google-sign-in/start, then Google, then /google-sign-in/callback): a linked Google
 * account signs its login in; an unlinked one whose email Google confirms is linked to the one
 * login here with that email (as WHMCS does) and signs it in. Two-factor sign-in still asks for
 * its code, and a switched-off login is refused. Otherwise the Google account is linked while
 * signed in (/google-sign-in, with the password).
 *
 * Google sign-up: only while sign-up is open (FOSSBilling's
 * `disable_signup` off, or the store preview link) and the Terms and Privacy Policy addresses
 * are set, a confirmed email no login here has makes the client account at once, through
 * FOSSBilling's own sign-up (its events, anti-spam checks and welcome email; a random password,
 * the email confirmed). The sign-in page says that continuing with Google accepts the Terms and
 * the Privacy Policy; table client_google_signup records that acceptance (addresses, date,
 * IP). The profile fields the portal requires at sign-up and Google does not give are asked on
 * /google-sign-in/profile before the client area opens (core patch "Google sign-up: the
 * required profile fields first"). With sign-up off, no account is made.
 *
 * Modes (Client settings page): "Login & sign-up" (the default) offers Google
 * on the sign-in page and in the checkout's sign-up (the registration page: the theme has no
 * separate sign-up page), "Login only" on the sign-in page and never makes an account,
 * "Sign-up only" in the checkout's sign-up and makes accounts: there an already linked Google account signs in (no
 * second account), an unlinked one whose email has a login links nothing and signs nothing in,
 * and an account it makes chooses a password on the profile page (the sign-in page has no
 * Google then).
 *
 * Lock-out protection: an account made by Google sign-up has a random password nobody knows
 * (table client_google_password keeps a hash of it); until the client chooses one
 * (/google-sign-in, or the profile page), the client cannot unlink Google. Staff can, with a
 * warning ("Forgot password" then sets one).
 *
 * FOSSBilling's session cookie is SameSite=Strict (security mode "strict"), so the browser
 * does not send it when Google sends it back: the callback then arrives in a new, empty
 * session. start() therefore keeps the session id in the cache under the state's hash, with
 * the hash of a SameSite=Lax cookie (COOKIE, HttpOnly, ten minutes, on /google-sign-in only)
 * that the browser does send then; restoreUrl() checks both and sends the browser once more to
 * the callback with an opaque one-use restore token, which
 * puts the real session back. The cookie keeps someone else's state from pulling a browser into
 * that person's session. The callback then gives the session a new id, so the token in the
 * address works no more. The callback answers with a page that moves on (not a redirect), so
 * the next request is same-site and carries the session cookie.
 *
 * Settings (Client settings page, Google sign-in): on/off, the OAuth client id and secret (FOSSBilling
 * keeps module settings encrypted; the secret is never shown again). Off while ClientFence is
 * off. Errors of the Google round trip are kept in the session and shown once on the page the
 * browser comes back to.
 */
final class Google
{
    public const SESSION = 'clientfence_google';
    public const ERROR = 'clientfence_google_error';
    public const COOKIE = 'google_sign_in';
    private const CACHE_PREFIX = 'clientfence_google_flow_';
    public const STATE_SECONDS = 600;
    public const CLIENT_ID_PATTERN = '/^[0-9A-Za-z._-]{1,200}\.apps\.googleusercontent\.com$/';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /** The profile fields FOSSBilling can require at sign-up (Clients settings), in form order. */
    public const PROFILE_FIELDS = ['last_name', 'company', 'phone', 'birthday', 'gender', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
    public const PROFILE_PAGE = 'google-sign-in/profile';
    /**
     * The settings (Client settings page, kept in mod_client) and their defaults: off until
     * switched on with an OAuth client id and secret; the Terms and Privacy Policy addresses a
     * Google sign-up accepts (no account is made without them); the mode.
     */
    public const DEFAULTS = [
        'google_signin' => '0',
        'google_client_id' => '',
        'google_client_secret' => '',
        'google_terms_url' => '',
        'google_privacy_url' => '',
        'google_mode' => 'both',
    ];

    /** Login & sign-up (the default), Login only, Sign-up only. */
    public const MODES = ['both', 'signin', 'signup'];
    /** Where the browser goes once the profile page is filled. */
    private const NEXT = 'clientfence_google_next';
    /** While Google sign-up makes an account (its welcome email is sent then; welcome()). */
    private static bool $creating = false;

    /**
     * The saved settings (mod_client), DEFAULTS for the unset ones. Extension\Service::setConfig()
     * keeps them when the Client settings page's main form saves the rest.
     */
    public static function config(\Pimple\Container $di): array
    {
        $saved = array_intersect_key($di['mod_config']('client'), self::DEFAULTS);

        return array_merge(self::DEFAULTS, array_filter($saved, static fn ($v): bool => $v !== null && $v !== ''));
    }

    /**
     * Whether Google sign-in is offered: switched on, with a client id and secret.
     */
    public static function on(\Pimple\Container $di): bool
    {
        $config = self::config($di);

        return ($config['google_signin'] ?? '0') === '1' && (string) ($config['google_client_id'] ?? '') !== '' && (string) ($config['google_client_secret'] ?? '') !== '';
    }

    /**
     * The mode staff chose: 'both', 'signin' or 'signup'.
     */
    public static function mode(\Pimple\Container $di): string
    {
        $mode = (string) (self::config($di)['google_mode'] ?? 'both');

        return in_array($mode, self::MODES, true) ? $mode : 'both';
    }

    /**
     * Whether existing logins sign in with Google (the sign-in page's button, the link page):
     * on, and not "Sign-up only".
     */
    public static function signinOn(\Pimple\Container $di): bool
    {
        return self::on($di) && self::mode($di) !== 'signup';
    }

    /**
     * The address Google sends the browser back to (registered with the OAuth client).
     */
    public static function redirectUri(\Pimple\Container $di): string
    {
        return $di['url']->link('google-sign-in/callback');
    }

    /**
     * The settings page: whether it is on, the redirect address and whether a secret is saved.
     */
    public static function settings(\Pimple\Container $di): array
    {
        $config = self::config($di);

        return [
            'on' => ($config['google_signin'] ?? '0') === '1',
            'client_id' => (string) ($config['google_client_id'] ?? ''),
            'secret_saved' => (string) ($config['google_client_secret'] ?? '') !== '',
            'redirect_uri' => self::redirectUri($di),
            'terms_url' => (string) ($config['google_terms_url'] ?? ''),
            'privacy_url' => (string) ($config['google_privacy_url'] ?? ''),
            'signup_open' => Clientfence::registrationOpen($di),
            'mode' => self::mode($di),
            // Why Google makes no new accounts now (empty: it does), in the order to fix them.
            'signup_blocked' => self::signupBlocked($di),
            // With client sign-up off, a browser with the store preview link can still sign up.
            'signup_preview' => !empty($di['mod_config']('client')['disable_signup']) && (string) (Clientfence::config($di)['store_preview_token'] ?? '') !== '',
        ];
    }

    /**
     * For the settings page: what stops Google from making new accounts now (empty: nothing).
     *
     * @return list<string> one of off, mode, signup, pages
     */
    private static function signupBlocked(\Pimple\Container $di): array
    {
        $config = self::config($di);
        $blocked = [];
        if (!self::on($di)) {
            $blocked[] = 'off';
        }
        if (self::mode($di) === 'signin') {
            $blocked[] = 'mode';
        }
        if (!empty($di['mod_config']('client')['disable_signup'])) {
            $blocked[] = 'signup';
        }
        if ((string) ($config['google_terms_url'] ?? '') === '' || (string) ($config['google_privacy_url'] ?? '') === '') {
            $blocked[] = 'pages';
        }

        return $blocked;
    }

    /**
     * Whether a first Google sign-in makes the account: Google sign-in on, not "Login only",
     * sign-up open, and the Terms and Privacy Policy addresses set.
     */
    public static function signupOn(\Pimple\Container $di): bool
    {
        $config = self::config($di);

        return self::on($di) && self::mode($di) !== 'signin' && Clientfence::registrationOpen($di)
            && (string) ($config['google_terms_url'] ?? '') !== '' && (string) ($config['google_privacy_url'] ?? '') !== '';
    }

    /**
     * Staff save the settings (Client settings page). An empty secret keeps the saved one.
     */
    public static function saveSettings(\Pimple\Container $di, array $data): void
    {
        $config = self::config($di);
        $clientId = trim((string) ($data['google_client_id'] ?? ''));
        $secret = trim((string) ($data['google_client_secret'] ?? ''));
        $on = (string) ($data['google_signin'] ?? '0') === '1';
        if ($clientId !== '' && preg_match(self::CLIENT_ID_PATTERN, $clientId) !== 1) {
            throw new InformationException('The client ID is a Google OAuth client ID (it ends in .apps.googleusercontent.com).');
        }
        if ($secret !== '' && preg_match('/^[\x21-\x7E]{8,200}$/', $secret) !== 1) {
            throw new InformationException('The client secret is not valid.');
        }
        $secret = $secret !== '' ? $secret : (string) ($config['google_client_secret'] ?? '');
        if ($on && ($clientId === '' || $secret === '')) {
            throw new InformationException('Google sign-in needs the client ID and the client secret.');
        }
        $mode = (string) ($data['google_mode'] ?? self::mode($di));
        if (!in_array($mode, self::MODES, true)) {
            throw new InformationException('Choose how Google sign-in is offered.');
        }
        $urls = [];
        foreach (['google_terms_url', 'google_privacy_url'] as $name) {
            $url = trim((string) ($data[$name] ?? ''));
            if ($url !== '' && (strlen($url) > 300 || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true))) {
                throw new InformationException('The Terms and Privacy Policy addresses are web addresses (https://…).');
            }
            $urls[$name] = $url;
        }
        $extension = $di['mod_service']('extension');
        $extension->setConfig(array_merge($extension->getConfig('mod_client'), [
            'google_signin' => $on ? '1' : '0',
            'google_client_id' => $clientId,
            'google_client_secret' => $secret,
            'google_mode' => $mode,
        ], $urls, ['ext' => 'mod_client']));
        $di['logger']->info('ClientFence: Google sign-in %s', $on ? 'switched on' : 'switched off');
    }

    /**
     * The buttons: on, the sign-in page's (not "Sign-up only"); signup, the checkout sign-up's
     * (Google sign-up makes accounts, with the Terms and Privacy Policy addresses it accepts;
     * the sign-in page then says "Continue with Google"); and the error of the last try (once).
     */
    public static function button(\Pimple\Container $di): array
    {
        $config = self::config($di);
        $signup = self::signupOn($di);

        return [
            'on' => self::signinOn($di),
            'error' => self::takeError($di),
            'signup' => $signup,
            'terms_url' => $signup ? (string) $config['google_terms_url'] : null,
            'privacy_url' => $signup ? (string) $config['google_privacy_url'] : null,
        ];
    }

    /**
     * /google-sign-in/start: the Google address that signs in, from the sign-in page, or with
     * `from=signup` from a registration page (`return`: the page to come back to, on this site).
     */
    public static function startSignIn(\Pimple\Container $di, array $query = []): string
    {
        $from = ($query['from'] ?? '') === 'signup' ? 'signup' : 'signin';
        if (!($from === 'signup' ? self::signupOn($di) : self::signinOn($di))) {
            throw new InformationException('Google sign-in is not available.');
        }
        $return = is_string($query['return'] ?? null) && $query['return'] !== '' ? self::localPath($query['return']) : '';

        return self::start($di, ['mode' => 'signin', 'from' => $from, 'return' => $return]);
    }

    /**
     * Where a failed start goes back to: the registration page it came from, or the sign-in page.
     */
    public static function startBack(array $query): string
    {
        return ($query['from'] ?? '') === 'signup' && is_string($query['return'] ?? null) && $query['return'] !== '' ? self::localPath($query['return']) : 'login';
    }

    /**
     * The signed-in login links a Google account: the password first, then Google.
     */
    public static function startLink(\Pimple\Container $di, int $clientId, array $data, string $ip): string
    {
        if (!self::signinOn($di)) {
            throw new InformationException('Google sign-in is not available.');
        }
        [$kind, $id] = TwoFactor::subject($di, $clientId);
        TwoFactor::checkPassword($di, $kind, $id, $data['password'] ?? null, $ip);

        return self::start($di, ['mode' => 'link', 'kind' => $kind, 'id' => $id, 'client_id' => $clientId]);
    }

    private static function start(\Pimple\Container $di, array $flow): string
    {
        self::requireOn($di);
        $config = self::config($di);
        $verifier = self::base64url(random_bytes(32));
        $bind = bin2hex(random_bytes(32));
        $flow += [
            'state' => bin2hex(random_bytes(32)),
            'nonce' => bin2hex(random_bytes(32)),
            'verifier' => $verifier,
            'expires' => time() + self::STATE_SECONDS,
        ];
        $di['session']->set(self::SESSION, $flow);
        // For the way back without the session cookie (see the class comment).
        $item = $di['cache']->getItem(self::cacheKey((string) $flow['state']));
        $item->set(['session' => (string) $di['session']->getId(), 'bind' => hash('sha256', $bind)]);
        $item->expiresAfter(self::STATE_SECONDS);
        $di['cache']->save($item);
        $di['cookie_queue']->queue(self::COOKIE, $bind, time() + self::STATE_SECONDS, self::cookiePath($di), null, self::secure($di), true, 'Lax');

        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => (string) $config['google_client_id'],
            'redirect_uri' => self::redirectUri($di),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'code_challenge' => self::base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * /google-sign-in/callback without the session that started it (the browser did not send
     * the SameSite=Strict session cookie): the callback address again with a restore_token for
     * that session, once, when the state and the Lax cookie ($cookie) match what start() kept.
     * Null when there is nothing to restore (the callback then finishes, or refuses, as is).
     */
    public static function restoreUrl(\Pimple\Container $di, array $query, string $cookie): ?string
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $flow = $di['session']->get(self::SESSION);
        if (isset($query['restore_token']) || $state === '' || $cookie === '' || (is_array($flow) && hash_equals((string) ($flow['state'] ?? ''), $state))) {
            return null;
        }
        $key = self::cacheKey($state);
        $item = $di['cache']->getItem($key);
        $kept = $item->isHit() ? $item->get() : null;
        if (!is_array($kept) || !hash_equals((string) ($kept['bind'] ?? ''), hash('sha256', $cookie)) || (string) ($kept['session'] ?? '') === '') {
            return null;
        }
        $restore = bin2hex(random_bytes(32));
        $kept['restore'] = hash('sha256', $restore);
        $item->set($kept);
        $di['cache']->save($item);
        $back = ['state' => $state, 'restore_token' => $restore];
        foreach (['code', 'error'] as $name) {
            if (is_string($query[$name] ?? null)) {
                $back[$name] = $query[$name];
            }
        }

        return $di['url']->link('google-sign-in/callback', $back);
    }

    // ClientFence core patch (0.8.9 port): only this callback's state and browser cookie can redeem its opaque restore token.
    public static function consumeRestoreToken(\Pimple\Container $di, array $query, string $cookie): ?string
    {
        $state = $query['state'] ?? null;
        $token = $query['restore_token'] ?? null;
        if (!is_string($state) || !is_string($token) || $cookie === '') {
            return null;
        }
        return \ClientFence\Tools::withLock($di, 'google-restore:' . substr(hash('sha256', $state), 0, 40), function () use ($di, $state, $token, $cookie): ?string {
            $key = self::cacheKey($state);
            $item = $di['cache']->getItem($key);
            $kept = $item->isHit() ? $item->get() : null;
            if (!is_array($kept) || !isset($kept['restore'])
                || !hash_equals($kept['restore'], hash('sha256', $token))
                || !hash_equals($kept['bind'], hash('sha256', $cookie))) {
                return null;
            }
            $di['cache']->deleteItem($key);

            return $kept['session'];
        });
    }

    /**
     * /google-sign-in/callback: finishes the sign-in or the link. Returns the client path to go
     * to; an error is kept for that page.
     */
    public static function callback(\Pimple\Container $di, array $query, string $ip): string
    {
        $session = $di['session'];
        $flow = $session->get(self::SESSION);
        $session->delete(self::SESSION);
        // Only this round trip's error is shown.
        $session->delete(self::ERROR);
        if (is_array($flow)) {
            $di['cache']->deleteItem(self::cacheKey((string) ($flow['state'] ?? '')));
        }
        $di['cookie_queue']->queue(self::COOKIE, '', time() - 3600, self::cookiePath($di), null, self::secure($di), true, 'Lax');
        try {
            return self::finish($di, $flow, $query, $ip);
        } finally {
            // A restore_token in the address works no more.
            $session->regenerateId();
        }
    }

    private static function finish(\Pimple\Container $di, mixed $flow, array $query, string $ip): string
    {
        $session = $di['session'];
        $mode = is_array($flow) && ($flow['mode'] ?? '') === 'link' ? 'link' : 'signin';
        $fromSignup = is_array($flow) && ($flow['from'] ?? '') === 'signup';
        $back = $mode === 'link' ? 'google-sign-in' : ($fromSignup && (string) ($flow['return'] ?? '') !== '' ? (string) $flow['return'] : 'login');
        try {
            $di['rate_limiter']->consumeOrThrow('client_password_reset_confirm_ip', $ip);
            if (!self::on($di)) {
                throw new InformationException('Google sign-in is not available.');
            }
            $state = is_string($query['state'] ?? null) ? $query['state'] : '';
            if (!is_array($flow) || (int) ($flow['expires'] ?? 0) < time() || $state === '' || !hash_equals((string) $flow['state'], $state)) {
                throw new InformationException('The Google sign-in has expired or was not started here. Please try again.');
            }
            if (isset($query['error'])) {
                throw new InformationException('Google sign-in was cancelled.');
            }
            $claims = self::exchange($di, is_string($query['code'] ?? null) ? $query['code'] : '', $flow);

            return $mode === 'link' ? self::finishLink($di, $flow, $claims) : self::finishSignIn($di, $claims, $ip, $flow);
        } catch (InformationException $e) {
            $session->set(self::ERROR, $e->getMessage());

            return $back;
        }
    }

    /**
     * Trades the code for the ID token (server to server, with the secret and the PKCE verifier)
     * and checks its claims. Returns {sub, email, email_verified}.
     */
    private static function exchange(\Pimple\Container $di, string $code, array $flow): array
    {
        $config = self::config($di);
        $clientId = (string) $config['google_client_id'];
        if ($code === '' || strlen($code) > 2048) {
            throw new InformationException('Google did not confirm the sign-in. Please try again.');
        }
        try {
            $response = $di['http_client']->request('POST', self::TOKEN_URL, [
                'body' => [
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => (string) $config['google_client_secret'],
                    'redirect_uri' => self::redirectUri($di),
                    'grant_type' => 'authorization_code',
                    'code_verifier' => (string) $flow['verifier'],
                ],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $di['logger']->setChannel('security')->warning('Google sign-in: the token request failed (%s)', $e::class);

            throw new InformationException('Google did not confirm the sign-in. Please try again.');
        }
        $claims = $status === 200 && is_string($data['id_token'] ?? null) ? self::claims((string) $data['id_token'], $clientId, (string) $flow['nonce']) : null;
        if ($claims === null) {
            $di['logger']->setChannel('security')->warning('Google sign-in: Google answered %s without a valid ID token (%s)', $status, is_string($data['error'] ?? null) ? substr($data['error'], 0, 40) : '-');

            throw new InformationException('Google did not confirm the sign-in. Please try again.');
        }

        return $claims;
    }

    /**
     * The claims of an ID token received from Google's token endpoint, or null when they are
     * not for this client and this sign-in (public for tests).
     */
    public static function claims(string $idToken, string $clientId, string $nonce): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($payload)) {
            return null;
        }
        $aud = $payload['aud'] ?? null;
        $sub = $payload['sub'] ?? null;
        $now = time();
        $valid = in_array($payload['iss'] ?? null, self::ISSUERS, true)
            && ($aud === $clientId || (is_array($aud) && in_array($clientId, $aud, true)))
            && (!isset($payload['azp']) || $payload['azp'] === $clientId)
            && is_int($payload['exp'] ?? null) && $payload['exp'] >= $now - 60
            && is_int($payload['iat'] ?? null) && $payload['iat'] <= $now + 300
            && is_string($payload['nonce'] ?? null) && $nonce !== '' && hash_equals($nonce, $payload['nonce'])
            && is_string($sub) && preg_match('/^[\x21-\x7E]{1,255}$/', $sub) === 1;
        if (!$valid) {
            return null;
        }
        $email = is_string($payload['email'] ?? null) ? strtolower(trim($payload['email'])) : '';

        $name = static fn (string $key): string => is_string($payload[$key] ?? null) ? mb_substr(trim((string) preg_replace('/[\p{C}<>]+/u', '', $payload[$key])), 0, 100) : '';

        return [
            'sub' => $sub,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'email_verified' => ($payload['email_verified'] ?? false) === true || ($payload['email_verified'] ?? '') === 'true',
            'given_name' => $name('given_name'),
            'family_name' => $name('family_name'),
            'name' => $name('name'),
        ];
    }

    private static function finishSignIn(\Pimple\Container $di, array $claims, string $ip, array $flow): string
    {
        $db = $di['em']->getConnection();
        // "Sign-up only": Google makes accounts and links no existing login (a linked Google
        // account still signs in, so a second click makes no second account).
        $signupOnly = self::mode($di) === 'signup';
        $have = 'There is already an account with this email. Please sign in with your email and password (or use “Forgot password”).';
        $link = $db->fetchAssociative('SELECT kind, subject_id FROM client_google WHERE google_sub = ?', [$claims['sub']]);
        if ($link !== false) {
            [$kind, $id] = [(string) $link['kind'], (int) $link['subject_id']];
        } else {
            $match = self::matchEmail($di, $claims);
            if ($match === null) {
                [$kind, $id] = ['client', self::createAccount($di, $claims, $ip)];
            } elseif ($signupOnly) {
                throw new InformationException($have);
            } else {
                [$kind, $id] = $match;
                self::checkUsable($di, $kind, $id);
                if (self::linkOf($di, $kind, $id) !== null) {
                    throw new InformationException('Your sign-in here is linked to another Google account. Use that Google account, or your password.');
                }
                self::store($di, $kind, $id, $claims);
                self::log($di, $kind, $id, 'Google sign-in linked to ' . self::shown($claims) . ' (the email Google confirmed)');
            }
        }
        self::checkUsable($di, $kind, $id);
        $db->update('client_google', ['last_used_at' => date('Y-m-d H:i:s')], ['google_sub' => $claims['sub']]);
        $redirect = self::localPath((string) ($flow['return'] ?? '') !== '' ? (string) $flow['return'] : (string) $di['session']->get('redirect_uri'));
        $user = $kind === 'user' ? Users::loginFor($di, $id) : null;
        $clientId = $kind === 'user' ? (int) $user['client_id'] : $id;
        $di['logger']->setChannel('security')->info('Google sign-in of %s #%s', $kind, $id);
        if (TwoFactor::challenge($di, $clientId, $user)) {
            return 'two-factor/code';
        }
        TwoFactor::signIn($di, $clientId, $user, $ip);

        if ($kind === 'client' && self::missingFields($di, $clientId) !== []) {
            $di['session']->set(self::NEXT, $redirect);

            return self::PROFILE_PAGE;
        }

        return $redirect;
    }

    /**
     * The one login whose email is the Google account's confirmed email; null when no login has
     * it and Google sign-up may make the account.
     *
     * @return ?array{0: string, 1: int}
     */
    private static function matchEmail(\Pimple\Container $di, array $claims): ?array
    {
        $none = 'There is no account here for this Google account. If you have one, sign in with your email and password, then link Google on the Google sign-in page of your account.';
        if (!$claims['email_verified'] || $claims['email'] === '') {
            throw new InformationException($none);
        }
        $db = $di['em']->getConnection();
        $logins = [];
        foreach ($db->fetchAllAssociative('SELECT id, email_approved FROM client WHERE email = ?', [$claims['email']]) as $row) {
            // A client account whose email was never confirmed may have been made by someone else with
            // this address (a password they still know): it is not linked by email.
            if ((int) ($row['email_approved'] ?? 0) !== 1) {
                throw new InformationException('Your account email is not confirmed yet. Sign in with your email and password, then link Google on the Google sign-in page of your account.');
            }
            $logins[] = ['client', (int) $row['id']];
        }
        foreach ($db->fetchFirstColumn('SELECT id FROM client_user WHERE email = ?', [$claims['email']]) as $id) {
            $logins[] = ['user', (int) $id];
        }
        if ($logins === [] && self::signupOn($di)) {
            return null;
        }
        if (count($logins) !== 1) {
            throw new InformationException($logins === [] ? $none : 'More than one sign-in here uses this email. Sign in with your password, then link Google on the Google sign-in page.');
        }

        return $logins[0];
    }

    /**
     * Google sign-up: the client account, made through FOSSBilling's sign-up (its events and
     * anti-spam checks, the welcome email), with the email confirmed, the Google account linked
     * and the acceptance of the Terms and the Privacy Policy recorded. Returns the client id.
     */
    private static function createAccount(\Pimple\Container $di, array $claims, string $ip): int
    {
        // FOSSBilling's sign-up limits, by address and by email (as its own sign-up form).
        $di['rate_limiter']->consumeOrThrow('client_signup', $ip);
        $di['rate_limiter']->consumeOrThrow('client_signup_email', $claims['email']);
        $config = self::config($di);
        $first = $claims['given_name'] !== '' ? $claims['given_name'] : ($claims['name'] !== '' ? $claims['name'] : (string) strstr($claims['email'], '@', true));
        // No password: FOSSBilling makes a random one nobody knows ("Forgot password" sets one).
        self::$creating = true;
        try {
            $client = $di['mod_service']('client')->guestCreateClient([
                'email' => $claims['email'],
                'first_name' => $first,
                'last_name' => $claims['family_name'],
            ]);
        } catch (InformationException $e) {
            // The anti-spam checks' own message.
            throw $e;
        } catch (\Throwable $e) {
            $di['logger']->setChannel('security')->error('Google sign-up could not make the account: %s', $e->getMessage());

            throw new InformationException('Your account could not be made right now. Please try again later or contact support.');
        } finally {
            self::$creating = false;
        }
        $clientId = (int) $client->getId();
        $db = $di['em']->getConnection();
        $db->update('client', ['email_approved' => 1], ['id' => $clientId]);
        $now = date('Y-m-d H:i:s');
        $terms = (string) $config['google_terms_url'];
        $privacy = (string) $config['google_privacy_url'];
        $db->insert('client_google_signup', [
            'client_id' => $clientId,
            'terms_url' => $terms,
            'privacy_url' => $privacy,
            'ip' => substr($ip, 0, 45),
            'accepted_at' => $now,
        ]);
        // Lock-out protection: the random password, until the client chooses one.
        $db->insert('client_google_password', [
            'client_id' => $clientId,
            'pass_check' => hash('sha256', (string) $db->fetchOne('SELECT pass FROM client WHERE id = ?', [$clientId])),
            'created_at' => $now,
        ]);
        self::store($di, 'client', $clientId, $claims);
        Users::activity($di, [$clientId], sprintf('Account made with Google sign-in (%s): accepted the Terms and Conditions (%s) and the Privacy Policy (%s) by continuing with Google, from %s', $claims['email'], $terms, $privacy, $ip));
        $di['logger']->setChannel('security')->info('Google sign-up made client #%s', $clientId);

        return $clientId;
    }

    /**
     * The profile fields a client made by Google sign-up still has to fill: the ones the portal
     * requires at sign-up (Clients settings) and its required custom fields, when empty. Empty
     * for any other client.
     *
     * @return list<array{name: string, label: string, custom: bool}>
     */
    public static function missingFields(\Pimple\Container $di, int $clientId): array
    {
        $db = $di['em']->getConnection();
        try {
            if ($db->fetchOne('SELECT client_id FROM client_google_signup WHERE client_id = ?', [$clientId]) === false) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }
        $client = $db->fetchAssociative('SELECT * FROM client WHERE id = ?', [$clientId]);
        if ($client === false) {
            return [];
        }
        $config = $di['mod_config']('client');
        $required = is_array($config['required'] ?? null) ? $config['required'] : [];
        $missing = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (in_array($field, $required, true) && trim((string) ($client[$field] ?? '')) === '') {
                $missing[] = ['name' => $field, 'label' => ucwords(str_replace('_', ' ', $field)), 'custom' => false];
            }
        }
        foreach (is_array($config['custom_fields'] ?? null) ? $config['custom_fields'] : [] as $name => $field) {
            if (preg_match('/^custom_([1-9]|1[0-9]|20)$/', (string) $name) === 1 && !empty($field['active']) && !empty($field['required']) && trim((string) ($client[$name] ?? '')) === '') {
                $missing[] = ['name' => (string) $name, 'label' => (string) ($field['title'] ?? '') !== '' ? (string) $field['title'] : ucwords(str_replace('_', ' ', (string) $name)), 'custom' => true];
            }
        }
        // "Sign-up only": the sign-in page has no Google, so the account chooses a password.
        if (self::mode($di) === 'signup' && !self::ownPassword($di, $clientId)) {
            $missing[] = ['name' => 'password', 'label' => 'Password', 'custom' => false];
        }

        return $missing;
    }

    /**
     * The welcome email (core patches: FOSSBilling's onAfterClientSignUp passes it to the
     * mod_client_signup template as `clientfence_google`): whether it is for an account Google
     * sign-up is making now, which has no password of its own; in "Sign-up only" it chooses one
     * right after (password_step).
     */
    public static function welcome(\Pimple\Container $di): array
    {
        return ['google' => self::$creating, 'password_step' => self::$creating && self::mode($di) === 'signup'];
    }

    /**
     * Whether the client has a password of their own: false for an account made by Google
     * sign-up until a password is chosen or set (the random one it got is unchanged).
     */
    public static function ownPassword(\Pimple\Container $di, int $clientId): bool
    {
        $db = $di['em']->getConnection();
        try {
            $check = $db->fetchOne('SELECT pass_check FROM client_google_password WHERE client_id = ?', [$clientId]);
        } catch (\Throwable) {
            return true;
        }
        if ($check === false) {
            return true;
        }

        return !hash_equals((string) $check, hash('sha256', (string) $db->fetchOne('SELECT pass FROM client WHERE id = ?', [$clientId])));
    }

    /**
     * An account made by Google sign-up chooses its password (`password`, `password_confirm`;
     * FOSSBilling's strength rules), without the random one it never knew.
     */
    public static function choosePassword(\Pimple\Container $di, int $clientId, array $data): void
    {
        if (self::ownPassword($di, $clientId)) {
            throw new InformationException('Your account already has a password. You can change it on your profile page.');
        }
        $di['validator']->isPasswordStrong($data['password'] ?? null);
        $di['validator']->passwordsMatch($data, 'password', 'password_confirm');
        $di['rate_limiter']->consumeOrThrow('profile_password_change_account', 'client:' . $clientId);
        $client = $di['em']->getRepository(\ClientFence\Mod\Client\Entity\Client::class)->find($clientId);
        if (!$client instanceof \ClientFence\Mod\Client\Entity\Client) {
            throw new InformationException('Client not found.', null, 404);
        }
        $di['mod_service']('profile')->changeClientPassword($client, (string) $data['password']);
        $di['em']->getConnection()->delete('client_google_password', ['client_id' => $clientId]);
        self::log($di, 'client', $clientId, 'Password chosen (account made with Google sign-in)', $clientId);
    }

    /**
     * Where the profile page goes once filled: the page the sign-in was for.
     */
    public static function profileNext(\Pimple\Container $di): string
    {
        return self::localPath((string) $di['session']->get(self::NEXT));
    }

    /**
     * The client area gate (core patch): the profile page while the signed-in owner of an
     * account made by Google sign-up has required fields to fill; null otherwise. Staff
     * "Login as client" and authorized users are not stopped.
     */
    public static function profileGate(\Pimple\Container $di, string $url): ?string
    {
        $path = strtolower(trim($url, '/'));
        if ($path === self::PROFILE_PAGE || $path === 'client/logout') {
            return null;
        }
        $clientId = (int) $di['session']->get('client_id');
        if ($clientId <= 0 || Users::context($di) !== null || $di['auth']->isAdminLoggedIn()) {
            return null;
        }

        return self::missingFields($di, $clientId) !== [] ? self::PROFILE_PAGE : null;
    }

    /**
     * The profile page saves the missing fields through FOSSBilling's profile update (its
     * checks of each field); every one must then be filled. The email goes along only when the
     * portal forbids changing it (the update refuses any other email then, and checks it as on
     * the client's own profile page); otherwise it is left out and kept.
     */
    public static function saveProfile(\Pimple\Container $di, int $clientId, array $data): void
    {
        $missing = self::missingFields($di, $clientId);
        if ($missing === []) {
            return;
        }
        $client = $di['em']->getRepository(\ClientFence\Mod\Client\Entity\Client::class)->find($clientId);
        if (!$client instanceof \ClientFence\Mod\Client\Entity\Client) {
            throw new InformationException('Client not found.', null, 404);
        }
        $update = empty($di['mod_config']('client')['disable_change_email']) ? [] : ['email' => $client->getEmail()];
        $password = false;
        foreach ($missing as $field) {
            if ($field['name'] === 'password') {
                $password = true;
                $di['validator']->isPasswordStrong($data['password'] ?? null);
                $di['validator']->passwordsMatch($data, 'password', 'password_confirm');

                continue;
            }
            $value = $data[$field['name']] ?? '';
            if (!is_string($value) || trim($value) === '') {
                throw new InformationException('Please fill in every field.');
            }
            $update[$field['name']] = trim($value);
        }
        if (isset($update['phone']) && is_string($data['phone_cc'] ?? null)) {
            $update['phone_cc'] = $data['phone_cc'];
        }
        if (array_diff(array_keys($update), ['email']) !== []) {
            $di['mod_service']('profile')->updateClient($client, $update);
        }
        if ($password) {
            self::choosePassword($di, $clientId, $data);
        }
        if (self::missingFields($di, $clientId) !== []) {
            throw new InformationException('Please fill in every field.');
        }
    }

    /**
     * A login that may sign in: an active client, or an active user with an active account.
     */
    private static function checkUsable(\Pimple\Container $di, string $kind, int $id): void
    {
        $usable = $kind === 'user'
            ? Users::loginFor($di, $id) !== null
            : $di['em']->getConnection()->fetchOne('SELECT status FROM client WHERE id = ?', [$id]) === \Model_Client::ACTIVE;
        if (!$usable) {
            throw new InformationException('This sign-in cannot be used. Please contact support.');
        }
    }

    private static function finishLink(\Pimple\Container $di, array $flow, array $claims): string
    {
        $session = $di['session'];
        $kind = (string) ($flow['kind'] ?? '');
        $id = (int) ($flow['id'] ?? 0);
        $clientId = (int) ($flow['client_id'] ?? 0);
        $signedIn = (int) $session->get('client_id') === $clientId && $clientId > 0
            && TwoFactor::subject($di, $clientId) === [$kind, $id];
        if (!$signedIn) {
            throw new InformationException('You were signed out. Please sign in and link Google again.');
        }
        $db = $di['em']->getConnection();
        $other = $db->fetchAssociative('SELECT kind, subject_id FROM client_google WHERE google_sub = ?', [$claims['sub']]);
        if ($other !== false && ((string) $other['kind'] !== $kind || (int) $other['subject_id'] !== $id)) {
            throw new InformationException('This Google account is linked to another sign-in here.');
        }
        $db->delete('client_google', ['kind' => $kind, 'subject_id' => $id]);
        self::store($di, $kind, $id, $claims);
        self::log($di, $kind, $id, 'Google sign-in linked to ' . self::shown($claims), $clientId);

        return 'google-sign-in';
    }

    private static function store(\Pimple\Container $di, string $kind, int $id, array $claims): void
    {
        $now = date('Y-m-d H:i:s');
        $di['em']->getConnection()->insert('client_google', [
            'kind' => $kind,
            'subject_id' => $id,
            'google_sub' => $claims['sub'],
            'email' => substr((string) $claims['email'], 0, 191),
            'created_at' => $now,
            'last_used_at' => null,
        ]);
    }

    /**
     * The Google account of a login, or null: {email, created_at, last_used_at}.
     */
    public static function linkOf(\Pimple\Container $di, string $kind, int $id): ?array
    {
        try {
            $row = $di['em']->getConnection()->fetchAssociative('SELECT email, created_at, last_used_at FROM client_google WHERE kind = ? AND subject_id = ?', [$kind, $id]);
        } catch (\Throwable) {
            // No table yet: nothing is linked.
            return null;
        }

        return $row === false ? null : $row;
    }

    /**
     * The Google sign-in page of the signed-in login.
     */
    public static function status(\Pimple\Container $di, int $clientId): array
    {
        [$kind, $id] = TwoFactor::subject($di, $clientId);

        return [
            'on' => self::signinOn($di),
            'link' => self::linkOf($di, $kind, $id),
            // Lock-out protection: an account made by Google sign-up chooses a password first.
            'needs_password' => $kind === 'client' && !self::ownPassword($di, $id),
            'error' => self::takeError($di),
        ];
    }

    /**
     * The signed-in login removes its Google link (its password still signs in).
     */
    public static function unlink(\Pimple\Container $di, int $clientId): void
    {
        [$kind, $id] = TwoFactor::subject($di, $clientId);
        if ($kind === 'client' && self::linkOf($di, $kind, $id) !== null && !self::ownPassword($di, $id)) {
            throw new InformationException('You sign in with Google only, so Google cannot be unlinked yet. Choose a password first, then unlink Google.');
        }
        if ($di['em']->getConnection()->delete('client_google', ['kind' => $kind, 'subject_id' => $id]) === 0) {
            throw new InformationException('No Google account is linked.');
        }
        self::log($di, $kind, $id, 'Google sign-in unlinked', $clientId);
    }

    /**
     * Staff remove a login's Google link (Users tab of the client page): the account owner, or a
     * user of that account ($userId).
     */
    public static function staffUnlink(\Pimple\Container $di, int $clientId, ?int $userId, int $adminId): void
    {
        $db = $di['em']->getConnection();
        if ($db->fetchOne('SELECT id FROM client WHERE id = ?', [$clientId]) === false) {
            throw new InformationException('Client not found.', null, 404);
        }
        if ($userId !== null && $db->fetchOne('SELECT id FROM client_user_account WHERE user_id = ? AND client_id = ?', [$userId, $clientId]) === false) {
            throw new InformationException('User not found.', null, 404);
        }
        [$kind, $id] = $userId !== null ? ['user', $userId] : ['client', $clientId];
        if ($db->delete('client_google', ['kind' => $kind, 'subject_id' => $id]) === 0) {
            throw new InformationException('No Google account is linked.');
        }
        self::log($di, $kind, $id, $kind === 'client' && !self::ownPassword($di, $id) ? 'Google sign-in unlinked by staff (the client has no password of their own: "Forgot password" sets one)' : 'Google sign-in unlinked by staff', $clientId, $adminId);
    }

    /**
     * A deleted client or user leaves no Google link (Users::clientDeleted, dropOrphan).
     */
    public static function forgetLogin(\Doctrine\DBAL\Connection $db, string $kind, int $id): void
    {
        try {
            $db->delete('client_google', ['kind' => $kind, 'subject_id' => $id]);
            if ($kind === 'client') {
                $db->delete('client_google_signup', ['client_id' => $id]);
                $db->delete('client_google_password', ['client_id' => $id]);
            }
        } catch (\Throwable) {
            // No table yet.
        }
    }

    private static function log(\Pimple\Container $di, string $kind, int $id, string $what, ?int $clientId = null, ?int $adminId = null): void
    {
        $clientId ??= $kind === 'client' ? $id : (int) (Users::linkedClients($di['em']->getConnection(), $id)[0] ?? 0);
        TwoFactor::log($di, $kind, $id, $clientId, $what, $adminId);
    }

    private static function shown(array $claims): string
    {
        return $claims['email'] !== '' ? $claims['email'] : 'a Google account';
    }

    private static function takeError(\Pimple\Container $di): ?string
    {
        $error = $di['session']->get(self::ERROR);
        $di['session']->delete(self::ERROR);

        return is_string($error) ? $error : null;
    }

    /**
     * The page the sign-in was for (FOSSBilling's redirect_uri): a path on this site, else home.
     */
    private static function localPath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $path) === 1 && !str_contains($path, '://') ? $path : '/';
    }

    private static function requireOn(\Pimple\Container $di): void
    {
        if (!self::on($di)) {
            throw new InformationException('Google sign-in is not available.');
        }
    }

    private static function cacheKey(string $state): string
    {
        return self::CACHE_PREFIX . hash('sha256', $state);
    }

    private static function cookiePath(\Pimple\Container $di): string
    {
        return '/' . trim((string) parse_url($di['url']->link('google-sign-in'), PHP_URL_PATH), '/');
    }

    private static function secure(\Pimple\Container $di): bool
    {
        return (bool) \ClientFence\Config::getProperty('security.force_https', true) || $di['request']->isSecure();
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
