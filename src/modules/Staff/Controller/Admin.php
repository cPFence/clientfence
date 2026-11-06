<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Staff\Controller;

use ClientFence\InjectionAwareInterface;
use ClientFence\Security\RandomizedTimeFloor;
use Symfony\Component\HttpFoundation\Response;

class Admin implements InjectionAwareInterface
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

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'activity',
                    'index' => 400,
                    'label' => __trans('Staff Login History'),
                    'uri' => $this->di['url']->adminLink('staff/logins'),
                    'permission' => 'manage_settings',
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\ClientFence\App &$app): void
    {
        $app->get('/staff/login', 'get_login', [], static::class);
        // ClientFence core patch: the email PIN step of a staff sign-in (Clientfence/SignInSecurity.php).
        $app->get('/staff/login/pin', 'get_login_pin', [], static::class);
        // ClientFence core patch: the authenticator code step of a staff sign-in (Client/TwoFactor.php).
        $app->get('/staff/login/code', 'get_login_code', [], static::class);
        $app->get('/staff/manage/:id', 'get_manage', ['id' => '[0-9]+'], static::class);
        $app->get('/staff/group/:id', 'get_group', ['id' => '[0-9]+'], static::class);
        $app->get('/staff/profile', 'get_profile', [], static::class);
        $app->get('/staff/logins', 'get_history', [], static::class);
        // staff password reset
        $app->get('/staff/passwordreset', 'get_passwordreset', [], static::class);
        $app->get('/staff/email/:hash', 'get_updatepassword', ['hash' => '[a-zA-Z0-9]+'], static::class);
    }

    /**
     * ClientFence: the email PIN step of a staff sign-in; the sign-in page when none is waiting.
     */
    public function get_login_pin(\ClientFence\App $app): string|Response
    {
        if ($this->di['auth']->isAdminLoggedIn()) {
            return $app->redirect('');
        }
        $pin = \ClientFence\Mod\Clientfence\SignInSecurity::pageInfo($this->di, 'staff');
        if ($pin === null) {
            return $app->redirect('staff/login');
        }

        $pending = \ClientFence\Mod\Clientfence\SignInSecurity::pending($this->di, 'staff');

        return $app->render('mod_staff_login_pin', ['pin' => $pin, 'app' => \ClientFence\Mod\Client\TwoFactor::isOn($this->di, 'staff', (int) $pending['id'])]);
    }

    /**
     * ClientFence: the authenticator code step of a staff sign-in; the sign-in page when none is waiting.
     */
    public function get_login_code(\ClientFence\App $app): string|Response
    {
        if ($this->di['auth']->isAdminLoggedIn()) {
            return $app->redirect('');
        }
        if (\ClientFence\Mod\Client\TwoFactor::staffPending($this->di) === null) {
            return $app->redirect('staff/login');
        }

        return $app->render('mod_staff_login_code', ['trust_days' => \ClientFence\Mod\Clientfence\SignInSecurity::TRUST_DAYS]);
    }

    public function get_login(\ClientFence\App $app): string|Response
    {
        if ($this->di['auth']->isAdminLoggedIn()) {
            return $app->redirect('');
        }

        return $app->render('mod_staff_login');
    }

    public function get_profile(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_staff_profile');
    }

    public function get_manage(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $staff = $api->staff_get(['id' => $id]);

        return $app->render('mod_staff_manage', ['staff' => $staff]);
    }

    public function get_group(\ClientFence\App $app, $id): string
    {
        $api = $this->di['api_admin'];
        $group = $api->staff_group_get(['id' => $id]);
        $members = $api->staff_group_member_get_list(['group_id' => $id]);
        $staffService = $this->di['mod_service']('staff');
        $parentPairs = $staffService->getAdminGroupRepository()->getParentPairs((int) $id);

        $extensionService = $this->di['mod_service']('Extension');
        $mods = $extensionService->getCoreAndActiveModulesAndPermissions();

        return $app->render('mod_staff_group', [
            'group' => $group,
            'members' => $members,
            'mods' => $mods,
            'parent_pairs' => $parentPairs,
            'is_super_admin' => $staffService->isSuperAdministrator(),
        ]);
    }

    public function get_history(\ClientFence\App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_staff_login_history');
    }

    public function get_passwordreset(\ClientFence\App $app): string
    {
        return $app->render('mod_staff_password_reset');
    }

    public function get_updatepassword(\ClientFence\App $app, $hash): string|Response
    {
        if ($error = $this->checkPageRateLimit($app, 'staff_password_reset_confirm_ip')) {
            return $error;
        }

        $data = [];
        $this->di['events_manager']->fire(['event' => 'onBeforePasswordResetStaff']);

        $mod = $this->di['mod']('staff');
        $config = $mod->getConfig();
        if (isset($config['public']['reset_pw']) && $config['public']['reset_pw'] == '0') {
            return $app->render('mod_staff_password_reset');
        }
        $isValidReset = false;
        $startedAt = microtime(true);

        try {
            $reset = $this->di['db']->findOne('AdminPasswordReset', 'hash = ?', [$hash]);
            if ($reset instanceof \Model_AdminPasswordReset) {
                $expiresAt = strtotime($reset->created_at) + 900;

                if ($expiresAt >= time()) {
                    $admin = $this->di['db']->getExistingModelById('Admin', $reset->admin_id, 'User not found');
                    $data['hash'] = $reset->hash;
                    $data['email'] = $admin->email;
                    $isValidReset = true;
                }
            }
        } finally {
            RandomizedTimeFloor::apply($startedAt);
        }

        if (!$isValidReset) {
            return $app->render('mod_staff_password_reset');
        }

        return $app->render('mod_staff_password_update', ['data' => $data]);
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
}
