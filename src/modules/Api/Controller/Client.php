<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * This file connects FOSSBilling client area interface and API.
 */

namespace ClientFence\Mod\Api\Controller;

use ClientFence\Config;
use ClientFence\Environment;
use ClientFence\Http\ApiResponseFactory;
use ClientFence\InjectionAwareInterface;
use ClientFence\Security\AuthenticationRequiredException;
use ClientFence\Security\EmailValidationRequiredException;
use Symfony\Component\HttpFoundation\Response;

class Client implements InjectionAwareInterface
{
    private ?array $apiConfig = null;
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
        $allowedRouteRoles = $this->registerAllowedRouteRoles();
        $app->post('/api/:role/:class/:method', 'post_method', $allowedRouteRoles, static::class);
        $app->get('/api/:role/:class/:method', 'get_method', $allowedRouteRoles, static::class);

        // all other requests are error requests
        $app->get('/api/:page', 'show_error', ['page' => '(.?)+'], static::class);
        $app->post('/api/:page', 'show_error', ['page' => '(.?)+'], static::class);
    }

    public function show_error(\ClientFence\App $app, $page): Response
    {
        $exc = new \ClientFence\Exception('Unknown API call :call', [':call' => $page], 879);

        return $this->renderJson(null, $exc);
    }

    public function get_method(\ClientFence\App $app, $role, $class, $method): Response
    {
        $call = $class . '_' . $method;

        return $this->tryCall($role, $class, $call, $app->getRequest()->query->all());
    }

    public function post_method(\ClientFence\App $app, $role, $class, $method): Response
    {
        try {
            $p = $app->getRequest()->getPayload()->all();
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException $e) {
            $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();

            return $this->renderJson(null, new \ClientFence\Exception('Malformed JSON input: :error', [':error' => $message], 400));
        }

        $call = $class . '_' . $method;

        return $this->tryCall($role, $class, $call, $p);
    }

    /**
     * @param string $call
     */
    private function tryCall($role, $class, $call, $p): Response
    {
        try {
            return $this->_apiCall($role, $class, $call, $p);
        } catch (AuthenticationRequiredException) {
            return $this->renderJson(null, new \ClientFence\InformationException('Authentication Failed', null, 201));
        } catch (EmailValidationRequiredException $exc) {
            return $this->renderJson(null, new \ClientFence\InformationException($exc->getMessage(), null, 403));
        } catch (\Exception $exc) {
            return $this->renderJson(null, $this->sanitizeGuestError($role, $class, $call, $exc));
        }
    }

    /**
     * Guest and client callers get a generic message for unexpected internal failures so
     * implementation details (SQL, paths) never leak; the original is logged for operators.
     * ClientFence core patch: clients too, not only guests.
     */
    private function sanitizeGuestError($role, $class, $call, \Exception $exc): \Exception
    {
        if (!in_array($role, ['guest', 'client'], true) || $exc instanceof \ClientFence\Exception) {
            return $exc;
        }

        // ClientFence core patch: the logger fills %s (vsprintf), not {name}: the line had no details.
        $this->di['logger']->error('%s API internal error in %s: %s: %s', ucfirst((string) $role), (string) $call, $exc::class, $exc->getMessage());

        // ClientFence core patch: a text longer than its column names the field, instead of "try again later".
        if (preg_match("/Data too long for column '([a-z0-9_]+)'/", $exc->getMessage(), $column) === 1) {
            return new \ClientFence\InformationException('This text is too long: :field.', [':field' => str_replace('_', ' ', $column[1])]);
        }

        return new \ClientFence\InformationException('An unexpected error occurred. Please try again later.');
    }

    private function _loadConfig(): void
    {
        $this->apiConfig ??= Config::getProperty('api', []);
    }

    private function checkUpdateFinalization(string $role, string $class, string $method): void
    {
        if ($role === 'admin' && $this->di['update_finalization']->isRequired() && !$this->di['update_finalization']->isAdminApiCallAllowed($class, $method)) {
            throw new \ClientFence\InformationException('ClientFence update finalization is pending. Complete finalization before using the admin API.', [], 503);
        }
    }

    private function checkRateLimit(string $role, ?string $method = null, bool $useAuthenticatedSubject = true): bool
    {
        $subject = (string) $this->_getIp();

        // $role only reflects which API namespace was requested, not whether the caller has
        // actually authenticated yet - checkPreAuthRateLimit() runs before that. Exempting
        // client_login/staff_login from the anti-brute-force policy must require a real,
        // already-established admin session, or an unauthenticated caller could bypass it
        // entirely by just requesting the admin route.
        $isAuthenticatedAdmin = $role === 'admin' && $this->hasAuthenticatedSession('admin');

        // ClientFence core patch: call names are case-insensitive (the dispatcher lowercases the
        // module, PHP method names ignore case), so "Staff_LOGIN" must meet the same limit.
        $call = strtolower((string) $method);
        if (($call === 'staff_login' || $call === 'client_login') && !$isAuthenticatedAdmin) {
            $policy = 'api_login';
        } elseif ($role === 'guest') {
            $policy = 'api_guest';
        } else {
            $policy = 'api_authenticated_ip';

            if ($useAuthenticatedSubject && $role === 'client' && $this->di['session']->get('client_id')) {
                $subject = 'client:' . $this->di['session']->get('client_id');
                $policy = 'api_authenticated_account';
            } elseif ($useAuthenticatedSubject && $role === 'admin' && $this->di['session']->get('admin')) {
                $admin = $this->di['session']->get('admin');
                $subject = 'admin:' . ($admin['id'] ?? '');
                $policy = 'api_authenticated_account';
            }
        }

        $this->di['rate_limiter']->consumeOrThrow($policy, $subject);

        return true;
    }

    private function checkPreAuthRateLimit(string $role, ?string $method = null): bool
    {
        return $this->checkRateLimit($role, $method, false);
    }

    private function checkHttpReferer(): bool
    {
        // snake oil: check request is from the same domain as FOSSBilling is installed if present
        $check_referer_header = isset($this->apiConfig['require_referrer_header']) && (bool) $this->apiConfig['require_referrer_header'];
        if ($check_referer_header) {
            $url = strtolower(SYSTEM_URL);
            $referer = $this->di['request']->headers->get('Referer');
            $referer = is_string($referer) ? strtolower($referer) : null;
            if (!$referer || !str_starts_with($referer, $url)) {
                throw new \ClientFence\InformationException('Invalid request. Make sure request origin is :from', [':from' => SYSTEM_URL], 1004);
            }
        }

        return true;
    }

    // ClientFence core patch: the allowed IPs apply to API key sign-ins only (as WHMCS), so the admin and
    // client pages keep working from anywhere; addresses or ranges (Security > Settings > Access lists).
    private function checkAllowedIps(): bool
    {
        $ips = $this->apiConfig['allowed_ips'] ?? [];
        if (!empty($ips) && !\Symfony\Component\HttpFoundation\IpUtils::checkIp((string) $this->_getIp(), (array) $ips)) {
            throw new \ClientFence\InformationException('Unauthorized IP', null, 1002);
        }

        return true;
    }

    protected function isRoleLoggedIn($role): bool
    {
        if ($role == 'client') {
            return (bool) ($this->di['is_client_logged'] ?? false);
        }
        if ($role == 'admin') {
            return (bool) ($this->di['is_admin_logged'] ?? false);
        }

        return true;
    }

    private function _apiCall($role, $class, $method, $params): Response
    {
        $this->_loadConfig();

        $this->checkHttpReferer();
        $this->isRoleAllowed($role);

        if ($role !== 'guest') {
            $this->checkPreAuthRateLimit($role, $method);

            if ($this->shouldPreferSessionAuth($method) && $this->hasAuthenticatedSession($role)) {
                $this->requireSessionAuth($role);
            } elseif ($this->shouldUseTokenLogin($role)) {
                $this->_tryTokenLogin($role);
            } else {
                $this->requireSessionAuth($role);
            }
        }

        $this->checkUpdateFinalization($role, $class, $method);
        $this->checkRateLimit($role, $method);
        $this->checkGuestAuthentication($role, $method, $params);

        $api = $this->di['api_identity']($role);
        unset($params['CSRFToken']);
        $result = $this->di['api_dispatcher']->dispatch($api->getIdentity(), $method, $params);

        if ($result instanceof Response) {
            return $this->sendResponse($result);
        }

        return $this->renderJson($result);
    }

    // ClientFence core patch (0.8.9 port): guest identity and cart changes require the browser session's nonce and origin.
    private function checkGuestAuthentication(string $role, string $method, array $params): void
    {
        if ($role !== 'guest' || !in_array(strtolower($method), [
            'client_login', 'client_create', 'staff_login', 'client_user_invite_accept',
            'client_twofactor_verify', 'client_signin_pin', 'staff_login_pin', 'staff_login_code',
            'cart_add_item', 'cart_remove_item', 'cart_apply_promo',
            'cart_remove_promo', 'cart_reset', 'cart_set_currency',
        ], true)) {
            return;
        }
        $request = $this->di['request'];
        if (!$request->isMethod('POST')) {
            throw new \ClientFence\InformationException('This action requires POST', null, 405);
        }
        $token = $params['CSRFToken'] ?? $request->headers->get('X-CSRF-TOKEN');
        $sessionToken = $this->di['session']->get('csrf_token');
        if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            throw new \ClientFence\InformationException('CSRF token invalid', null, 403);
        }
        $origin = $request->headers->get('Origin');
        $expectedOrigin = \Symfony\Component\HttpFoundation\Request::create(SYSTEM_URL)->getSchemeAndHttpHost();
        if (($origin !== null && strcasecmp($origin, $expectedOrigin) !== 0)
            || $request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            throw new \ClientFence\InformationException('Invalid request origin', null, 403);
        }
    }

    private function getAuth(): array
    {
        $server = $this->di['request']->server;

        if (!$server->has('PHP_AUTH_USER') && $this->di['request']->headers->has('Authorization')) {
            $parsedAuth = $this->tryParseBasicAuthHeader();
            if ($parsedAuth === null) {
                throw new \ClientFence\InformationException('Authentication Failed', null, 201);
            }

            $server->set('PHP_AUTH_USER', $parsedAuth['username']);
            $server->set('PHP_AUTH_PW', $parsedAuth['password']);
        }

        if (!$server->has('PHP_AUTH_USER')) {
            throw new \ClientFence\InformationException('Authentication Failed', null, 201);
        }

        if (!$server->has('PHP_AUTH_PW')) {
            throw new \ClientFence\InformationException('Authentication Failed', null, 202);
        }

        if ($server->get('PHP_AUTH_PW') === '') {
            throw new \ClientFence\InformationException('Authentication Failed', null, 206);
        }

        return [(string) $server->get('PHP_AUTH_USER'), (string) $server->get('PHP_AUTH_PW')];
    }

    protected function _tryTokenLogin(string $routeRole): void
    {
        $this->checkAllowedIps();
        [$username, $password] = $this->getAuth();
        if ($username !== $routeRole) {
            throw new \ClientFence\InformationException('Authentication Failed', null, 203);
        }

        switch ($routeRole) {
            case 'client':
                // ClientFence core patch: client API keys are disabled, so a key never signs a client in.
                $model = null;
                if (!$model instanceof \Model_Client) {
                    throw new \ClientFence\InformationException('Authentication Failed', null, 204);
                }
                $this->di['session']->set('client_id', $model->id);

                break;

            case 'admin':
                $model = $this->di['db']->findOne('Admin', 'api_token = ? AND status = ? AND (system_name IS NULL OR system_name != ?)', [$password, \Model_Admin::STATUS_ACTIVE, \Model_Admin::SYSTEM_CRON]);
                if (!$model instanceof \Model_Admin) {
                    throw new \ClientFence\InformationException('Authentication Failed', null, 205);
                }

                $cronAdmin = $this->di['mod_service']('staff')->getCronAdmin();
                if ($cronAdmin instanceof \Model_Admin && (int) $model->id === (int) $cronAdmin->id) {
                    throw new \ClientFence\InformationException('Authentication Failed', null, 205);
                }

                $sessionAdminArray = [
                    'id' => $model->id,
                    'email' => $model->email,
                    'name' => $model->name,
                ];
                $this->di['session']->set('admin', $sessionAdminArray);

                break;

            default:
                throw new \ClientFence\InformationException('Authentication Failed', null, 203);
        }
    }

    private function registerAllowedRouteRoles(): array
    {
        return [
            'role' => 'guest|client|admin',
            'class' => '[a-zA-Z0-9_]+',
            'method' => '[a-zA-Z0-9_]+',
        ];
    }

    protected function shouldUseTokenLogin(string $routeRole): bool
    {
        if (!in_array($routeRole, ['client', 'admin'], true)) {
            return false;
        }

        $username = $this->getProvidedBasicAuthUsername();
        if ($username === null) {
            return false;
        }

        if ($username !== $routeRole) {
            throw new \ClientFence\InformationException('Authentication Failed', null, 203);
        }

        return true;
    }

    private function shouldPreferSessionAuth(string $method): bool
    {
        return in_array($method, [
            'profile_api_key_get',
            'profile_api_key_reset',
            'profile_generate_api_key',
        ], true);
    }

    private function hasAuthenticatedSession(string $role): bool
    {
        try {
            return $this->isRoleLoggedIn($role);
        } catch (\Exception) {
            return false;
        }
    }

    private function getProvidedBasicAuthUsername(): ?string
    {
        $server = $this->di['request']->server;
        $username = $server->get('PHP_AUTH_USER');
        $password = $server->get('PHP_AUTH_PW');
        if (is_string($username) && $password !== null && in_array($username, ['client', 'admin'], true)) {
            return $username;
        }

        $parsedAuth = $this->tryParseBasicAuthHeader();
        if ($parsedAuth === null) {
            return null;
        }

        $username = $parsedAuth['username'];
        if (!in_array($username, ['client', 'admin'], true)) {
            return null;
        }

        return $username;
    }

    /**
     * @return array{username: string, password: string}|null
     */
    private function tryParseBasicAuthHeader(): ?array
    {
        $authorization = $this->di['request']->headers->get('Authorization');
        if ($authorization === null) {
            return null;
        }

        $authorization = trim($authorization);
        if (stripos($authorization, 'Basic ') !== 0) {
            return null;
        }

        $decoded = base64_decode(substr($authorization, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    private function requireSessionAuth(string $role): void
    {
        try {
            $this->isRoleLoggedIn($role);
            if ($role === 'client' || $role === 'admin') {
                $this->_checkCSRFToken();
            }
        } catch (\ClientFence\InformationException $exception) {
            throw $exception;
        } catch (AuthenticationRequiredException|\Exception) {
            throw new \ClientFence\InformationException('Authentication Failed', null, 201);
        }
    }

    /**
     * @param string $role
     *
     * @throws \ClientFence\Exception
     */
    private function isRoleAllowed($role): bool
    {
        $allowed = ['guest', 'client', 'admin'];
        if (!in_array($role, $allowed, true)) {
            throw new \ClientFence\Exception('Unknown API call :call', [':call' => (string) $role], 701);
        }

        return true;
    }

    public function renderJson($data = null, ?\Exception $e = null): Response
    {
        $this->_loadConfig();

        if ($e instanceof \Exception) {
            error_log("{$e->getMessage()} {$e->getCode()}.");
        }

        return (new ApiResponseFactory())->create($data, $e);
    }

    protected function sendResponse(Response $response): Response
    {
        return $response;
    }

    private function _getIp()
    {
        return $this->di['request']->getClientIp();
    }

    /**
     * Checks if the CSRF token provided is valid.
     *
     * @throws \ClientFence\InformationException
     */
    public function _checkCSRFToken()
    {
        $this->_loadConfig();
        $csrfPrevention = $this->apiConfig['CSRFPrevention'] ?? true;
        if (!$csrfPrevention || Environment::isCLI()) {
            return true;
        }

        $input = $this->di['request']->getContent();
        $data = json_decode((string) $input);
        if (!is_object($data)) {
            $data = new \stdClass();
        }

        $cookieToken = $this->di['request']->cookies->get(\ClientFence\Http\CookieNames::CSRF)
            ?? $this->di['request']->cookies->get(\ClientFence\Http\CookieNames::LEGACY_CSRF);
        $headerToken = $this->di['request']->headers->get('X-CSRF-TOKEN');

        $token = $data->CSRFToken
            ?? $this->di['request']->request->get('CSRFToken')
            ?? $this->di['request']->query->get('CSRFToken')
            ?? $headerToken
            ?? null;

        $sessionToken = $this->di['session']->get('csrf_token');

        if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            throw new \ClientFence\InformationException('CSRF token invalid', null, 403);
        }

        if (is_string($cookieToken) && $cookieToken !== '' && !hash_equals($sessionToken, $cookieToken)) {
            throw new \ClientFence\InformationException('CSRF token invalid', null, 403);
        }
    }
}
