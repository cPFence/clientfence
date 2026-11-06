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
 *Staff methods.
 */

namespace ClientFence\Mod\Staff\Api;

use ClientFence\Mod\Staff\Entity\Admin;
use ClientFence\Mod\Staff\Entity\AdminPasswordReset;
use ClientFence\Security\RandomizedTimeFloor;
use ClientFence\Validation\Api\RequiredParams;

class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * Login to admin area and save information to session.
     *
     * @return array
     *
     * @throws \ClientFence\Exception
     */
    #[RequiredParams(['email' => 'Email required', 'password' => 'Password required'])]
    public function login($data)
    {
        $startedAt = microtime(true);

        try {
            $data['email'] = $this->getDi()['tools']->validateAndSanitizeEmail($data['email'], true, false);

            $config = $this->getMod()->getConfig();

            // check ip
            if (!empty($config['allowed_ips']) && isset($config['check_ip']) && $config['check_ip']) {
                $allowed_ips = explode(PHP_EOL, (string) $config['allowed_ips']);
                $allowed_ips = array_map(trim(...), $allowed_ips);
                if (!in_array($this->getIp(), $allowed_ips)) {
                    throw new \ClientFence\InformationException('You are not allowed to login to admin area from this IP address.', null, 403);
                }
            }

            // ClientFence core patch: sign-in security, staff sign-in from chosen countries only
            // (modules/Clientfence/SignInSecurity.php).
            \ClientFence\Mod\Clientfence\SignInSecurity::checkStaffCountry($this->getDi(), (string) $this->getIp());

            // ClientFence core patch: CAPTCHA on the staff login (Antispam settings). The helper is the
            // password reset one: it checks only while the Antispam module is active.
            $this->checkPasswordResetCaptcha($data);

            $result = $this->getService()->login($data['email'], $data['password'], $this->getIp());
            // ClientFence core patch: a sign-in waiting for its email PIN or app code keeps the page to return to.
            if (empty($result['clientfence_pin']) && empty($result['clientfence_2fa'])) {
                $this->getDi()['session']->delete('redirect_uri');
            }

            return $result;
        } finally {
            RandomizedTimeFloor::apply($startedAt);
        }
    }

    /**
     * ClientFence: the email PIN of a staff sign-in waiting for it (Clientfence/SignInSecurity.php):
     * `pin`, and `trust` to trust this browser for 30 days. Finishes the sign-in.
     */
    public function login_pin($data): array
    {
        $startedAt = microtime(true);

        try {
            $di = $this->getDi();
            \ClientFence\Mod\Clientfence\SignInSecurity::checkStaffCountry($di, (string) $this->getIp());
            $pending = \ClientFence\Mod\Clientfence\SignInSecurity::verify($di, 'staff', $data['pin'] ?? null, !empty($data['trust']), (string) $this->getIp());
            $model = $di['db']->load('Admin', (int) $pending['id']);
            if (!$model instanceof \Model_Admin || $model->status !== \Model_Admin::STATUS_ACTIVE) {
                throw new \ClientFence\InformationException('Check your login details', null, 403);
            }
            $result = $this->getService()->completeLogin($model, (string) $this->getIp());
            $di['session']->delete('redirect_uri');

            return $result;
        } finally {
            RandomizedTimeFloor::apply($startedAt);
        }
    }

    /**
     * ClientFence: a new email PIN for the staff sign-in waiting for one.
     */
    public function login_pin_resend(): array
    {
        return \ClientFence\Mod\Clientfence\SignInSecurity::resend($this->getDi(), 'staff', (string) $this->getIp());
    }

    /**
     * ClientFence: the authenticator code (or the backup code) of a staff sign-in waiting for one
     * (Client/TwoFactor.php): `code`, and `trust` to trust this browser for 30 days. Finishes the sign-in.
     */
    public function login_code($data): array
    {
        $startedAt = microtime(true);

        try {
            $di = $this->getDi();
            \ClientFence\Mod\Clientfence\SignInSecurity::checkStaffCountry($di, (string) $this->getIp());
            $accepted = \ClientFence\Mod\Client\TwoFactor::verifyStaff($di, $data['code'] ?? null, (string) $this->getIp());
            $model = $di['db']->load('Admin', $accepted['id']);
            if (!$model instanceof \Model_Admin || $model->status !== \Model_Admin::STATUS_ACTIVE) {
                throw new \ClientFence\InformationException('Check your login details', null, 403);
            }
            // Switched off while it waited: the email PIN when staff need it, as at a sign-in without the app.
            $login = ['kind' => 'staff', 'id' => (int) $model->id, 'email' => (string) $model->email, 'name' => (string) $model->name, 'timezone' => (string) ($model->timezone ?? '')];
            if ($accepted['off'] && \ClientFence\Mod\Clientfence\SignInSecurity::challenge($di, 'staff', $login, [], (string) $this->getIp())) {
                return ['clientfence_pin' => true];
            }
            if (!empty($data['trust'])) {
                \ClientFence\Mod\Clientfence\SignInSecurity::trust($di, 'staff', 'staff', (int) $model->id);
            }
            $result = $this->getService()->completeLogin($model, (string) $this->getIp());
            $di['session']->delete('redirect_uri');

            return $result + ['backup_used' => $accepted['backup_used']];
        } finally {
            RandomizedTimeFloor::apply($startedAt);
        }
    }

    /**
     * ClientFence: the staff sign-in waiting for an app code takes the email PIN instead (either one signs in).
     */
    public function login_code_email(): bool
    {
        $di = $this->getDi();
        $pending = \ClientFence\Mod\Client\TwoFactor::staffPending($di);
        $model = $pending === null ? null : $di['db']->load('Admin', (int) $pending['id']);
        if (!$model instanceof \Model_Admin || !hash_equals((string) $pending['password_fingerprint'], hash('sha256', (string) \ClientFence\Mod\Clientfence\SignInSecurity::passwordHash($di, 'staff', (int) $model->id)))) {
            throw new \ClientFence\InformationException('Your sign-in has expired. Please sign in again.', null, 401);
        }
        $login = ['kind' => 'staff', 'id' => (int) $model->id, 'email' => (string) $model->email, 'name' => (string) $model->name, 'timezone' => (string) ($model->timezone ?? '')];

        return \ClientFence\Mod\Clientfence\SignInSecurity::challenge($di, 'staff', $login, [], (string) $this->getIp(), true);
    }

    /**
     * ClientFence: the staff sign-in waiting for an email PIN goes back to the authenticator code.
     */
    public function login_pin_app(): bool
    {
        $di = $this->getDi();
        $pending = \ClientFence\Mod\Clientfence\SignInSecurity::pending($di, 'staff');
        if ($pending === null || !hash_equals((string) $pending['password_fingerprint'], hash('sha256', (string) \ClientFence\Mod\Clientfence\SignInSecurity::passwordHash($di, 'staff', (int) $pending['id'])))) {
            throw new \ClientFence\InformationException('Your sign-in has expired. Please sign in again.', null, 401);
        }
        if (!\ClientFence\Mod\Client\TwoFactor::challengeStaff($di, (int) $pending['id'])) {
            throw new \ClientFence\InformationException('Two-factor sign-in is not on.');
        }

        return true;
    }

    public function update_password($data): void
    {
        $startedAt = microtime(true);

        try {
            $this->getDi()['rate_limiter']->consumeOrThrow('staff_password_reset_confirm_post_ip', (string) $this->getIp());

            $config = $this->getMod()->getConfig();
            if (isset($config['public']['reset_pw']) && $config['public']['reset_pw'] == '0') {
                throw new \ClientFence\InformationException('Password reset has been disabled');
            }
            $this->getDi()['events_manager']->fire(['event' => 'onBeforePasswordResetStaff']);
            $required = [
                'code' => 'Code required',
                'password' => 'Password required',
                'password_confirm' => 'Password confirmation required',
            ];

            $validator = $this->getDi()['validator'];
            $validator->checkRequiredParamsForArray($required, $data);
            $validator->passwordsMatch($data);
            $validator->isPasswordStrong($data['password']);

            $reset = $this->getDi()['db']->findOne('AdminPasswordReset', 'hash = ?', [$data['code']]);
            if (!$reset instanceof \Model_AdminPasswordReset) {
                $this->getDi()['logger']->setChannel('security')->info('Staff password reset confirmation failed from IP %s: reset token not found', $this->getIp());

                throw new \ClientFence\InformationException('The link has expired or you have already confirmed the password reset.');
            }

            if (strtotime($reset->created_at) - time() + 900 < 0) {
                $this->getDi()['logger']->setChannel('security')->info('Staff password reset confirmation failed for admin #%s from IP %s: reset token expired', $reset->admin_id, $this->getIp());

                throw new \ClientFence\InformationException('The link has expired or you have already confirmed the password reset.');
            }

            $admin = $this->getDi()['db']->getExistingModelById('Admin', $reset->admin_id, 'Admin not found');

            if ($admin->status !== \Model_Admin::STATUS_ACTIVE || $admin->isCron()) {
                $this->getDi()['logger']->setChannel('security')->info('Staff password reset confirmation failed for admin #%s from IP %s: account status %s, system name %s', $admin->id, $this->getIp(), $admin->status, $admin->system_name);

                throw new \ClientFence\InformationException('The link has expired or you have already confirmed the password reset.');
            }

            $em = $this->getDi()['em'];
            $adminEntity = $em->getRepository(Admin::class)->find((int) $admin->id);
            // ClientFence core patch (0.8.9 port): password changes and token revocation share the identity lock.
            if (!$adminEntity instanceof Admin) {
                throw new \ClientFence\InformationException('Admin not found');
            }
            $em->getRepository(AdminPasswordReset::class)
                ->changePassword($adminEntity, $this->getDi()['password']->hashIt($data['password']), $data['code']);

            $profileService = $this->getDi()['mod_service']('profile');
            $profileService->invalidateSessions('admin', (int) $admin->id);

            $this->getDi()['logger']->setChannel('security')->info('Staff password reset completed for admin #%s from IP %s', $admin->id, $this->getIp());

            $this->getDi()['events_manager']->fire(['event' => 'onAfterPasswordResetStaff', 'params' => ['id' => $admin->id]]);

            // send email
            $email = [];
            $email['to_admin'] = $admin->id;
            $email['code'] = 'mod_staff_password_reset_approve';
            $emailService = $this->getDi()['mod_service']('email');
            $emailService->sendTemplate($email);
        } finally {
            RandomizedTimeFloor::apply($startedAt, 300, 450);
        }
    }

    public function passwordreset(array $data): bool
    {
        $config = $this->getMod()->getConfig();
        if (isset($config['public']['reset_pw']) && $config['public']['reset_pw'] == '0') {
            throw new \ClientFence\InformationException('Password reset has been disabled');
        }

        $startedAt = microtime(true);

        try {
            $this->getDi()['events_manager']->fire(['event' => 'onBeforePasswordResetStaff']);
            $required = [
                'email' => 'Email required',
            ];
            $validator = $this->getDi()['validator'];
            $validator->checkRequiredParamsForArray($required, $data);
            $data['email'] = $this->getDi()['tools']->validateAndSanitizeEmail($data['email']);

            $ipLimit = $this->getDi()['rate_limiter']->consume('staff_password_reset_ip', (string) $this->getIp());
            if ($ipLimit->isLimited()) {
                $this->getDi()['logger']->setChannel('security')->info('Staff password reset rate limited from IP %s: email %s', $this->getIp(), $data['email']);

                return true;
            }

            $this->checkPasswordResetCaptcha($data);

            $emailLimit = $this->getDi()['rate_limiter']->consume('staff_password_reset_email', (string) $data['email']);
            if ($emailLimit->isLimited()) {
                $this->getDi()['logger']->setChannel('security')->info('Staff password reset rate limited for email %s from IP %s', $data['email'], $this->getIp());

                return true;
            }

            $c = $this->getDi()['db']->findOne('Admin', 'email = ?', [$data['email']]);

            if (!$c instanceof \Model_Admin) {
                $this->getDi()['logger']->setChannel('security')->info('Staff password reset requested for unknown email %s from IP %s', $data['email'], $this->getIp());

                return true;
            }

            if ($c->status !== \Model_Admin::STATUS_ACTIVE || $c->isCron()) {
                $this->getDi()['logger']->setChannel('security')->info('Staff password reset requested for ineligible admin #%s from IP %s: email %s, account status %s, system name %s', $c->id, $this->getIp(), $data['email'], $c->status, $c->system_name);

                return true;
            }

            $hash = hash('sha256', random_bytes(32));

            $em = $this->getDi()['em'];
            $adminEntity = $em->getRepository(Admin::class)->find((int) $c->id);
            if (!$adminEntity instanceof Admin || !$em->getRepository(AdminPasswordReset::class)->replaceReset($adminEntity, $hash, $this->ip)) {
                return true;
            }

            // send email
            $email = [];
            $email['to_admin'] = $c->id;
            $email['code'] = 'mod_staff_password_reset_request';
            $email['hash'] = $hash;
            $emailService = $this->getDi()['mod_service']('email');
            // ClientFence core patch: reset email delivery must not reveal staff accounts through response time.
            register_shutdown_function(static function () use ($emailService, $email): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                // shortcut: without either finish function (Apache mod_php or CLI), email is sent before the response ends.
                $emailService->sendTemplate($email);
            });

            $this->getDi()['logger']->setChannel('security')->info('Staff password reset email sent for admin #%s from IP %s: email %s', $c->id, $this->getIp(), $data['email']);

            return true;
        } finally {
            RandomizedTimeFloor::apply($startedAt);
        }
    }

    private function checkPasswordResetCaptcha(array $data): void
    {
        $extensionService = $this->getDi()['mod_service']('extension');
        if (!$extensionService->isExtensionActive('mod', 'antispam')) {
            return;
        }

        $this->getDi()['mod_service']('Antispam')->checkCaptcha($data);
    }
}
