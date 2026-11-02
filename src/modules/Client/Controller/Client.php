<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Client\Controller;

use ClientFence\Security\RandomizedTimeFloor;
use Symfony\Component\HttpFoundation\Response;
use ClientFence\Mod\Client\Google;
use ClientFence\Mod\Client\TwoFactor;
use ClientFence\Mod\Client\Users;

class Client implements \ClientFence\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/client/reset-password-confirm/:hash', 'get_reset_password_confirm', ['hash' => '[a-z0-9]+'], static::class);
        $app->get('/client', 'get_client_index', [], static::class);
        $app->get('/client/logout', 'get_client_logout', [], static::class);
        $app->get('/client/:page', 'get_client_page', ['page' => '[a-z0-9-]+'], static::class);
        $app->get('/client/confirm-email/:hash', 'get_client_confirmation', ['page' => '[a-z0-9-]+'], static::class);
        // ClientFence: authorized users, two-factor sign-in and Google sign-in (see below).
        $app->get('/password-reset/:token', 'get_password_reset', ['token' => '[a-f0-9]{64}'], static::class);
        $app->get('/invite/:token', 'get_invite', ['token' => '[a-f0-9]{64}'], static::class);
        $app->get('/users', 'get_users', [], static::class);
        $app->get('/contacts', 'get_contacts', [], static::class);
        $app->get('/profile', 'get_user_profile', [], static::class);
        $app->get('/two-factor', 'get_twofactor', [], static::class);
        $app->get('/two-factor/code', 'get_twofactor_code', [], static::class);
        $app->get('/google-sign-in', 'get_google', [], static::class);
        $app->get('/google-sign-in/start', 'get_google_start', [], static::class);
        $app->get('/google-sign-in/callback', 'get_google_callback', [], static::class);
        $app->get('/google-sign-in/profile', 'get_google_profile', [], static::class);
        // ClientFence core patch: privacy (Privacy.php): the client's data as a file, and the one-click
        // unsubscribe link of marketing email (GET shows a button; POST unsubscribes, RFC 8058).
        $app->get('/my-data', 'get_my_data', [], static::class);
        $app->get('/unsubscribe/:id/:token', 'get_unsubscribe', ['id' => '[0-9]+', 'token' => '[a-f0-9]{32}'], static::class);
        $app->post('/unsubscribe/:id/:token', 'post_unsubscribe', ['id' => '[0-9]+', 'token' => '[a-f0-9]{32}'], static::class);
    }

    public function get_client_index(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_client_index');
    }

    public function get_client_confirmation(\ClientFence\App $app, $hash): string|Response
    {
        if ($error = $this->checkPageRateLimit($app, 'client_email_confirm_ip')) {
            return $error;
        }

        $startedAt = microtime(true);
        $service = $this->di['mod_service']('client');

        $systemService = $this->di['mod_service']('System');

        try {
            $service->approveClientEmailByHash($hash);
            $systemService->setPendingMessage(__trans('Email address was confirmed'));
        } catch (\ClientFence\InformationException $e) {
            // ClientFence core patch: a used, replaced or expired link shows its reason on the home page (it showed
            // the developer error page).
            $systemService->setPendingMessage($e->getMessage());
        } finally {
            RandomizedTimeFloor::apply($startedAt);
        }

        return $app->redirect('/');
    }

    public function get_client_logout(\ClientFence\App $app): Response
    {
        $api = $this->di['api_client'];
        $api->profile_logout();

        return $app->redirect('/');
    }

    public function get_client_page(\ClientFence\App $app, $page): string
    {
        $this->di['is_client_logged'];
        $template = 'mod_client_' . $page;

        return $app->render($template);
    }

    public function get_reset_password_confirm(\ClientFence\App $app, $hash): string|Response
    {
        if ($error = $this->checkPageRateLimit($app, 'client_password_reset_confirm_ip')) {
            return $error;
        }

        $service = $this->di['mod_service']('client');
        $this->di['events_manager']->fire(['event' => 'onBeforePasswordResetClient']);
        $data = [
            'hash' => $hash,
        ];

        $startedAt = microtime(true);

        // ClientFence core patch: a used, replaced or expired link opens the reset page with a note
        // (it showed the developer error page, or silently went to the home page).
        try {
            $result = $service->password_reset_valid($data);
        } catch (\ClientFence\InformationException $e) {
            return $app->render('mod_page_password-reset', ['link_error' => $e->getMessage()]);
        } finally {
            RandomizedTimeFloor::apply($startedAt);
        }

        if ($result !== false) {
            return $app->render('mod_client_set_new_password', ['hash' => $hash]);
        }

        return $app->render('mod_page_password-reset', ['link_error' => __trans('The link has expired or you have already reset your password.')]);
    }

    private function checkPageRateLimit(\ClientFence\App $app, string $policy): ?Response
    {
        $result = $this->di['rate_limiter']->consume($policy, (string) $this->di['request']->getClientIp());
        if (!$result->isLimited()) {
            return null;
        }

        $headers = [];
        if ($result->hasRetryAfter()) {
            $headers['Retry-After'] = (string) $result->getRetryAfterSeconds();
        }

        return $app->errorResponse(new \ClientFence\Security\RateLimitException($result), 429, $headers);
    }

    /**
     * The new password form of an authorized user's reset link; "expired" when the link is not
     * valid (any more).
     */
    public function get_password_reset(\ClientFence\App $app, $token): string
    {
        $valid = Users::resetValid($this->di, (string) $token);

        return $app->render('mod_client_user_reset', ['token' => $valid ? (string) $token : null]);
    }

    /**
     * The invitation page: accept with a new name and password, or the existing user's password.
     */
    public function get_invite(\ClientFence\App $app, $token): string
    {
        $invite = Users::inviteView($this->di, (string) $token);

        return $app->render('mod_client_user_invite', ['token' => $invite !== null ? (string) $token : null, 'invite' => $invite]);
    }

    /**
     * The account's users (owner and owner links; the page gate refuses other users).
     */
    public function get_users(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];
        return $app->render('mod_client_users_page', ['users_page' => $this->di['api_client']->client_users_get_list()]);
    }

    /**
     * The account's contacts (Contacts.php; the page gate needs the `contacts` permission).
     */
    public function get_contacts(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_client_contacts_page', ['contacts_page' => $this->di['api_client']->client_contacts_get_list()]);
    }

    /**
     * An authorized user's own profile; the owner has FOSSBilling's profile page.
     */
    public function get_user_profile(\ClientFence\App $app)
    {
        $this->di['is_client_logged'];
        $session = $this->di['api_client']->client_user_session();
        if ($session === null) {
            return $app->redirect('client/profile');
        }

        return $app->render('mod_client_user_profile', ['clientfence_user' => $session]);
    }

    /**
     * Two-factor sign-in settings of the signed-in login.
     */
    public function get_twofactor(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_client_twofactor_settings', ['twofactor' => $this->di['api_client']->client_twofactor_status()]);
    }

    /**
     * The code step of a sign-in with two-factor sign-in on. `back`: the page to return to after
     * the code (checkout: the order page); a path on this site only, never another site.
     */
    public function get_twofactor_code(\ClientFence\App $app)
    {
        // ClientFence: a sign-in waiting for its email PIN (Clientfence/SignInSecurity.php) gets the PIN form.
        $pin = TwoFactor::pending($this->di) ? null : \ClientFence\Mod\Clientfence\SignInSecurity::pageInfo($this->di, 'client');
        if (!TwoFactor::pending($this->di) && $pin === null) {
            return $app->redirect('login');
        }
        $back = (string) $this->di['request']->query->get('back', '');
        if ($pin !== null) {
            return $app->render('mod_client_signin_pin', ['pin' => $pin, 'back' => preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $back) === 1 ? $back : null]);
        }

        return $app->render('mod_client_twofactor_code', ['back' => preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $back) === 1 ? $back : null]);
    }

    /**
     * Google sign-in of the signed-in login: link (with the password) or unlink.
     */
    public function get_google(\ClientFence\App $app): string
    {
        $this->di['is_client_logged'];

        return $app->render('mod_client_google_link', ['google' => $this->di['api_client']->client_google_status()]);
    }

    /**
     * The profile fields an account made by Google sign-up still has to fill (the client area
     * waits for them); home when there are none.
     */
    public function get_google_profile(\ClientFence\App $app)
    {
        $this->di['is_client_logged'];
        $fields = Users::context($this->di) === null ? Google::missingFields($this->di, (int) $this->di['session']->get('client_id')) : [];
        if ($fields === []) {
            return $app->redirect('/');
        }

        return $app->render('mod_client_google_profile', ['fields' => $fields, 'next' => Google::profileNext($this->di)]);
    }

    /**
     * "Sign in with Google": on to Google, or back to the sign-in page when it is off.
     */
    public function get_google_start(\ClientFence\App $app): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $query = $this->di['request']->query->all();
        try {
            return $app->redirectUrl(Google::startSignIn($this->di, $query));
        } catch (\ClientFence\InformationException) {
            return $app->redirect(Google::startBack($query));
        }
    }

    /**
     * Google sends the browser back here: once more with the session that started it when the
     * browser left its SameSite=Strict session cookie out (Google::restoreUrl()), then signed in
     * (or on to the code page), linked, or back to the page that shows why not. A page that moves
     * on, not a redirect: a redirect would still count as coming from Google, and the next page
     * would again get no session cookie.
     */
    public function get_google_callback(\ClientFence\App $app): Response
    {
        $request = $this->di['request'];
        $query = $request->query->all();
        $next = Google::restoreUrl($this->di, $query, (string) $request->cookies->get(Google::COOKIE, ''))
            ?? $this->di['url']->link(Google::callback($this->di, $query, (string) $request->getClientIp()));
        $html = htmlspecialchars($next, ENT_QUOTES, 'UTF-8');
        $js = json_encode($next, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $continue = htmlspecialchars(__trans('Continue'), ENT_QUOTES, 'UTF-8');

        return new Response(
            "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"referrer\" content=\"no-referrer\"><meta http-equiv=\"refresh\" content=\"0;url={$html}\"><title>{$continue}</title></head>"
            . "<body><p><a href=\"{$html}\">{$continue}</a></p><script nonce=\"" . \ClientFence\Csp::nonce() . "\">location.replace({$js});</script></body></html>",
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex']
        );
    }

    /**
     * ClientFence: the signed-in client's data as a JSON file (Privacy::export), while the privacy
     * tools are on. The account owner only: an authorized user gets the profile page.
     */
    public function get_my_data(\ClientFence\App $app): Response
    {
        $this->di['is_client_logged'];
        if (!\ClientFence\Mod\Client\Privacy::toolsOn($this->di) || Users::context($this->di) !== null) {
            return $app->redirect('client/profile');
        }
        $clientId = (int) $this->di['loggedin_client']->id;
        $json = json_encode(\ClientFence\Mod\Client\Privacy::export($this->di, $clientId), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new Response((string) $json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="my-data-' . date('Y-m-d') . '.json"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** ClientFence: the unsubscribe link's page: one button, so link checkers never unsubscribe. */
    public function get_unsubscribe(\ClientFence\App $app, $id, $token): string
    {
        return $app->render('mod_client_unsubscribe', ['state' => $this->unsubscribeState((int) $id, (string) $token), 'id' => (int) $id, 'token' => (string) $token]);
    }

    /** ClientFence: unsubscribes (the page's button, or a mail provider's one-click POST). */
    public function post_unsubscribe(\ClientFence\App $app, $id, $token): string
    {
        $state = $this->unsubscribeState((int) $id, (string) $token);
        if ($state === 'ask' || $state === 'done') {
            \ClientFence\Mod\Client\Privacy::setOptOut($this->di, (int) $id, true, 'link');
            $state = 'done';
        }

        return $app->render('mod_client_unsubscribe', ['state' => $state, 'id' => (int) $id, 'token' => (string) $token]);
    }

    /** off (no opt-out on this site), invalid (a wrong link), done (opted out) or ask. */
    private function unsubscribeState(int $clientId, string $token): string
    {
        if (!\ClientFence\Mod\Client\Privacy::optOutOn($this->di)) {
            return 'off';
        }
        if (!\ClientFence\Mod\Client\Privacy::tokenValid($this->di, $clientId, $token)) {
            return 'invalid';
        }

        return \ClientFence\Mod\Client\Privacy::optedOut($this->di['em']->getConnection(), $clientId) ? 'done' : 'ask';
    }
}
