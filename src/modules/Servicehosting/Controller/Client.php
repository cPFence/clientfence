<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicehosting\Controller;

use ClientFence\Mod\Servicehosting\Panel\Panels;

/**
 * Client area pages of hosting accounts, while a hosting panel is on (Panel/Panels.php).
 *
 * /hosting lists the client's hosting accounts, /hosting/:id is one account's page, which loads
 * its usage from /hosting/:id/usage, its domains' DNS status from /hosting/:id/domains and its
 * backups from /hosting/:id/backups.
 * FOSSBilling routes a path to the module its first segment names, so a core change
 * (\ClientFence\AppClient's page map) gives /hosting to this module. /enhance, the retired Enhance
 * extension's page, goes there too (.htaccess block CLIENTFENCE OLD HOSTING PAGE).
 */
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
        $app->get('/hosting', 'get_accounts', [], static::class);
        $app->get('/hosting/:id', 'get_account', ['id' => '[0-9]+'], static::class);
        $app->get('/hosting/:id/usage', 'get_account_usage', ['id' => '[0-9]+'], static::class);
        $app->get('/hosting/:id/domains', 'get_account_domains', ['id' => '[0-9]+'], static::class);
        $app->get('/hosting/:id/backups', 'get_account_backups', ['id' => '[0-9]+'], static::class);
    }

    public function get_accounts(\ClientFence\App $app): string
    {
        $this->panelOn();

        return $app->render('mod_servicehosting_accounts', ['accounts' => $this->di['api_client']->servicehosting_accounts()]);
    }

    /**
     * One of the client's hosting accounts; "not found" for any other order.
     */
    public function get_account(\ClientFence\App $app, $id): string
    {
        $this->panelOn();
        foreach ($this->di['api_client']->servicehosting_accounts() as $account) {
            if ($account['order_id'] === (int) $id) {
                return $app->render('mod_servicehosting_account', ['account' => $account]);
            }
        }

        throw new \ClientFence\InformationException('Page :url not found', [':url' => '/hosting/' . (int) $id], 404);
    }

    /**
     * The usage panel of one hosting order (an HTML fragment). The usage API checks that the order
     * belongs to the signed-in client and renders "unavailable" otherwise.
     */
    public function get_account_usage(\ClientFence\App $app, $id): string
    {
        $this->panelOn();

        return $app->render('mod_servicehosting_account_usage', ['order_id' => (int) $id]);
    }

    /**
     * The DNS status panel of one hosting order (an HTML fragment for the Domains tab); `refresh=1`
     * looks the domains up again. The API checks that the order is the signed-in client's.
     */
    public function get_account_domains(\ClientFence\App $app, $id): string
    {
        $this->panelOn();

        return $app->render('mod_servicehosting_account_domains', ['order_id' => (int) $id, 'refresh' => $this->di['request']->query->getBoolean('refresh')]);
    }

    /**
     * The backups panel of one hosting order (an HTML fragment for the Backups tab). The API checks
     * that the order is the signed-in client's.
     */
    public function get_account_backups(\ClientFence\App $app, $id): string
    {
        $this->panelOn();

        return $app->render('mod_servicehosting_account_backups', ['order_id' => (int) $id]);
    }

    private function panelOn(): void
    {
        $this->di['is_client_logged'];
        if (!Panels::anyOn($this->di)) {
            throw new \ClientFence\InformationException('Page :url not found', [':url' => '/hosting'], 404);
        }
    }
}
