<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Index\Controller;

use ClientFence\InjectionAwareInterface;
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

    public function register(\ClientFence\App &$app): void
    {
        $app->get('', 'get_index', [], static::class);
        $app->get('/', 'get_index', [], static::class);
        $app->get('/index', 'get_index', [], static::class);
        $app->get('/index/', 'get_index', [], static::class);
    }

    public function get_index(\ClientFence\App $app): string|Response
    {
        if ($this->di['auth']->isAdminLoggedIn()) {
            // ClientFence core patch: setup wizard. A new install opens the setup wizard
            // (Clientfence Setup.php) for a Super Administrator until it is finished or skipped.
            if (\ClientFence\Mod\Clientfence\Setup::pending($this->di) && \ClientFence\Mod\Clientfence\Setup::allowed($this->di)) {
                return $app->redirect('/clientfence/setup');
            }

            return $app->render('mod_index_dashboard');
        }

        // ClientFence core patch: a staff sign-in waiting for its email PIN goes to the PIN page
        // (Clientfence/SignInSecurity.php); the sign-in form returns here.
        if (\ClientFence\Mod\Clientfence\SignInSecurity::pending($this->di, 'staff') !== null) {
            return $app->redirect('/staff/login/pin');
        }
        // ClientFence core patch: one waiting for its authenticator code goes to the code page (Client/TwoFactor.php).
        if (\ClientFence\Mod\Client\TwoFactor::staffPending($this->di) !== null) {
            return $app->redirect('/staff/login/code');
        }

        return $app->redirect('/staff/login');
    }
}
