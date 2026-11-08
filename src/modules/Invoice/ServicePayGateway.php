<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;
use ClientFence\InjectionAwareInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;

class ServicePayGateway implements InjectionAwareInterface
{
    /**
     * Sent by the admin UI in place of a secret config field's value to mean
     * "keep the currently stored value". Blank input means the same thing;
     * this sentinel exists only for clients that can't send an empty string.
     */
    public const string CREDENTIAL_KEEP_SENTINEL = '__KEEP__';

    protected ?\Pimple\Container $di = null;

    public function __construct(private ?Filesystem $filesystem = null)
    {
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        if (isset($di['filesystem'])) {
            $this->filesystem = $di['filesystem'];
        }
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getSearchQuery(array $data): array
    {
        $sql = 'SELECT *
            FROM pay_gateway
            WHERE 1 ';

        $search = $data['search'] ?? null;
        $enabled = $data['enabled'] ?? null;
        $allowSingle = $data['allow_single'] ?? null;
        $allowRecurrent = $data['allow_recurrent'] ?? null;
        $testMode = $data['test_mode'] ?? null;
        $params = [];
        if ($search) {
            $sql .= 'AND (name LIKE :search OR gateway LIKE :search) ';
            $params[':search'] = "%$search%";
        }

        if ($enabled !== null && $enabled !== '') {
            $sql .= 'AND enabled = :enabled ';
            $params[':enabled'] = (int) $enabled;
        }

        if ($allowSingle !== null && $allowSingle !== '') {
            $sql .= 'AND allow_single = :allow_single ';
            $params[':allow_single'] = (int) $allowSingle;
        }

        if ($allowRecurrent !== null && $allowRecurrent !== '') {
            $sql .= 'AND allow_recurrent = :allow_recurrent ';
            $params[':allow_recurrent'] = (int) $allowRecurrent;
        }

        if ($testMode !== null && $testMode !== '') {
            $sql .= 'AND test_mode = :test_mode ';
            $params[':test_mode'] = (int) $testMode;
        }

        $sql .= ' ORDER by gateway ASC';

        return [$sql, $params];
    }

    /**
     * @return mixed[]
     */
    public function getPairs(): array
    {
        $sql = 'SELECT id, gateway, name
            FROM pay_gateway';

        $rows = $this->di['db']->getAll($sql);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = $row['name'];
        }

        return $result;
    }

    /**
     * @return mixed[]
     */
    public function getAvailable(): array
    {
        $sql = 'SELECT id, gateway, name
            FROM pay_gateway';

        $rows = $this->di['db']->getAll($sql);
        $exists = [];
        foreach ($rows as $row) {
            $exists[$row['gateway']] = $row['name'];
        }

        $finder = new Finder();
        $finder->files()
            ->in(Path::join(PATH_LIBRARY, 'Payment', 'Adapter'))
            ->name('*.php')
            ->depth('== 0');

        $adapters = [];
        foreach ($finder as $file) {
            $adapter = $file->getFilenameWithoutExtension();
            if (!array_key_exists($adapter, $exists)) {
                $adapters[] = $adapter;
            }
        }

        try {
            $subFinder = new Finder();
            $subFinder->files()
                ->in(Path::join(PATH_LIBRARY, 'Payment', 'Adapter', '*'))
                ->name('*.php')
                ->depth('== 0');
            foreach ($subFinder as $file) {
                $adapter = $file->getFilenameWithoutExtension();
                if (!array_key_exists($adapter, $exists)) {
                    $adapters[] = $adapter;
                }
            }
        } catch (DirectoryNotFoundException) {
            // If the subdirectory does not exist, ignore the exception.
        }

        // ClientFence core patch: gateways shipped by active modules (Extension\Service::getModuleAdapterFiles()).
        foreach (array_keys($this->di['mod_service']('extension')->getModuleAdapterFiles('Payment/Adapter')) as $adapter) {
            if (!array_key_exists($adapter, $exists) && !in_array($adapter, $adapters, true)) {
                $adapters[] = $adapter;
            }
        }

        return $adapters;
    }

    public function install($code): bool
    {
        $available = $this->getAvailable();
        if (!in_array($code, $available)) {
            throw new \ClientFence\Exception('Payment gateway is not available for installation.');
        }

        $new = $this->di['db']->dispense('PayGateway');
        $new->gateway = $code;
        // ClientFence core patch: the adapter's display name and payment kinds, not the code and "allow both".
        $config = $this->getAdapterConfig($new);
        $new->name = $config['title'] ?? $code;
        $new->allow_single = empty($config['supports_one_time_payments']) ? 0 : 1;
        $new->allow_recurrent = empty($config['supports_subscriptions']) ? 0 : 1;
        $new->enabled = 0;
        $new->accepted_currencies = null;
        $new->test_mode = 0;
        $new->config = null;
        $this->di['db']->store($new);

        $this->di['logger']->info('Installed new payment gateway %s', $code);

        return true;
    }

    public function toApiArray(\Model_PayGateway $model, $deep = false, $identity = null): array
    {
        [$single, $recurrent] = $this->_getAllowTuple($model);

        $result = [
            'id' => $model->id,
            'code' => $model->gateway,
            'title' => $model->name,
            'allow_single' => $model->allow_single,
            'allow_recurrent' => $model->allow_recurrent,
            'accepted_currencies' => $this->getAcceptedCurrencies($model),
        ];

        if ($identity instanceof \Model_Admin) {
            $config = json_decode($model->config ?? '', true) ?? [];
            $secretFields = $this->getSecretFields($model);
            foreach ($secretFields as $field) {
                $value = $config[$field] ?? null;
                $config[$field] = null;
                $config[$field . '_set'] = $value !== null && $value !== '';
            }

            $result['supports_one_time_payments'] = $single;
            $result['supports_subscriptions'] = $recurrent;
            $result['config'] = $config;
            $result['secret_fields'] = $secretFields;
            $result['form'] = $this->getFormElements($model);
            $result['description'] = $this->getDescription($model);
            $result['enabled'] = $model->enabled;
            $result['test_mode'] = $model->test_mode;
            $result['callback'] = $this->getCallbackUrl($model);
            // ClientFence: the edit form asks for a replacement when an enabled gateway with unpaid invoices is turned off.
            $result['unpaid_invoices'] = (int) $this->di['em']->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE gateway_id = ? AND status = ?', [$model->id, \Model_Invoice::STATUS_UNPAID]);
        }

        return $result;
    }

    /**
     * Config field names for this gateway's adapter whose stored values must
     * be hidden in the API and admin UI: the adapter's own declared secrets
     * plus any form field marked `'secret' => true`.
     *
     * @return string[]
     */
    public function getSecretFields(\Model_PayGateway $model): array
    {
        $secrets = [];

        try {
            $class = $this->getAdapterClassName($model);
            if (is_callable($class . '::getSecretFields')) {
                $declared = $class::getSecretFields();
                $secrets = array_merge($secrets, $declared);
            }
        } catch (\Throwable) {
            // Gateway adapter could not be resolved; fall back to the form's own 'secret' flags below.
        }

        $form = $this->getAdapterConfig($model)['form'] ?? [];
        if (is_array($form)) {
            foreach ($form as $name => $element) {
                $options = $element[1] ?? [];
                if (!empty($options['secret'])) {
                    $secrets[] = (string) $name;
                }
            }
        }

        return array_values(array_unique($secrets));
    }

    public function copy(\Model_PayGateway $model): int
    {
        $new = $this->di['db']->dispense('PayGateway');
        $new->name = $model->name . ' (Copy)';
        $new->gateway = $model->gateway;
        $new->enabled = 0;
        $new->accepted_currencies = $model->accepted_currencies;
        $new->test_mode = $model->test_mode;
        $new->config = $model->config;
        $newId = $this->di['db']->store($new);
        $this->di['logger']->info('Copied payment gateway #%s - %s', $newId, $model->gateway);

        return $newId;
    }

    public function update(\Model_PayGateway $model, array $data): bool
    {
        $model->name = $data['title'] ?? $model->name;

        $newEnabled = isset($data['enabled']) ? (bool) $data['enabled'] : (bool) $model->enabled;
        $newTestMode = isset($data['test_mode']) ? (bool) $data['test_mode'] : (bool) $model->test_mode;
        $existingConfig = json_decode($model->config ?? '', true) ?? [];
        $mergedConfig = $existingConfig;
        if (isset($data['config']) && is_array($data['config'])) {
            $secretFields = $this->getSecretFields($model);
            foreach ($data['config'] as $key => $value) {
                $mergedConfig[$key] = in_array($key, $secretFields, true)
                    ? $this->normalizeSecretValue($key, $value, $existingConfig[$key] ?? null, $model)
                    : $value;
            }
        }

        if ($newEnabled) {
            $this->validateGatewayConfig($model, $mergedConfig, $newTestMode);
        }

        if (isset($data['config']) && is_array($data['config'])) {
            $model->config = json_encode($mergedConfig);
        }

        if (isset($data['accepted_currencies']) && is_array($data['accepted_currencies'])) {
            $model->accepted_currencies = json_encode($data['accepted_currencies']);
        }

        // ClientFence core patch: turning a gateway off moves its unpaid invoices to a replacement, as deleting does.
        if ($model->enabled && !$newEnabled) {
            $this->moveUnpaidInvoices((int) $model->id, isset($data['replacement_id']) && $data['replacement_id'] !== '' ? (int) $data['replacement_id'] : null);
        }
        $model->enabled = $newEnabled;
        $model->allow_single = (bool) ($data['allow_single'] ?? $model->allow_single);
        $model->allow_recurrent = (bool) ($data['allow_recurrent'] ?? $model->allow_recurrent);
        $model->test_mode = $newTestMode;
        $this->di['db']->store($model);
        $this->di['logger']->info('Updated payment gateway %s', $model->gateway);

        return true;
    }

    /**
     * Verify that the gateway configuration would be accepted by the adapter
     * by attempting to instantiate it. This is used to enforce that the
     * required keys for the currently selected test mode are present before
     * persisting an "enabled" gateway update.
     */
    private function validateGatewayConfig(\Model_PayGateway $model, array $config, bool $testMode): void
    {
        $adapterConfig = $config;
        $adapterConfig['test_mode'] = $testMode;

        try {
            $class = $this->getAdapterClassName($model);
            if (!class_exists($class)) {
                return;
            }
            $adapter = new $class($adapterConfig);
            // ClientFence core patch: a gateway with API keys (PayPal Email's REST keys) checks them on saving.
            if (method_exists($adapter, 'testConnection')) {
                $adapter->setDi($this->di);
                $adapter->testConnection();
            }
        } catch (\ClientFence\InformationException $e) {
            throw $e;
        } catch (\Payment_Exception $e) {
            throw new \ClientFence\Exception($e->getMessage(), null, 819);
        } catch (\Throwable $e) {
            throw new \ClientFence\Exception('Payment gateway configuration error: ' . $e->getMessage(), null, 819);
        }
    }

    /**
     * Returns the value to store for a secret config field. Blank, whitespace-only
     * or {@see CREDENTIAL_KEEP_SENTINEL} inputs preserve the existing value;
     * everything else replaces it. A successful rotation is logged (the value
     * itself is never logged).
     */
    private function normalizeSecretValue(string $field, mixed $incoming, mixed $existing, \Model_PayGateway $model): mixed
    {
        if ($incoming === null || !is_scalar($incoming)) {
            return $existing;
        }

        $incoming = (string) $incoming;

        if (trim($incoming) === '' || $incoming === self::CREDENTIAL_KEEP_SENTINEL) {
            return $existing;
        }

        if ($incoming !== $existing) {
            $adminId = $this->di['loggedin_admin']->id ?? 'unknown';
            $this->di['logger']->info('Rotated {field} for payment gateway {gateway_id} by admin {admin_id}', ['field' => $field, 'gateway_id' => (string) $model->id, 'admin_id' => (string) $adminId]);
        }

        return $incoming;
    }

    public function delete(\Model_PayGateway $model, ?int $replacementId = null): bool
    {
        $id = (int) $model->id;
        $db = $this->di['em']->getConnection();

        // ClientFence core patch: as WHMCS, the unpaid invoices move to a replacement gateway; refused while active
        // subscriptions or saved payment methods would stop paying (disabling keeps them). Paid invoices and
        // transactions keep their history.
        if ((int) $db->fetchOne("SELECT COUNT(*) FROM subscription WHERE pay_gateway_id = ? AND status = 'active'", [$id]) > 0) {
            throw new InformationException('This gateway has active subscriptions. Disable it instead of deleting it.');
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM pay_gateway_customer WHERE pay_gateway_id = ? AND saved_cards = 1 AND default_payment_method IS NOT NULL', [$id]) > 0) {
            throw new InformationException('Clients have saved payment methods on this gateway. Disable it instead of deleting it.');
        }
        $this->moveUnpaidInvoices($id, $replacementId);

        // Customer IDs and product caches mean nothing once the gateway is gone (no foreign keys cascade them).
        $db->executeStatement('DELETE FROM pay_gateway_customer WHERE pay_gateway_id = :id', ['id' => $id]);
        $db->executeStatement('DELETE FROM pay_gateway_product WHERE pay_gateway_id = :id', ['id' => $id]);

        $this->di['db']->trash($model);
        $this->di['logger']->info('Removed payment gateway %s', $id);

        return true;
    }

    /** ClientFence: the unpaid invoices of a gateway being deleted or turned off move to an enabled replacement, as WHMCS. */
    private function moveUnpaidInvoices(int $id, ?int $replacementId): void
    {
        $db = $this->di['em']->getConnection();
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM invoice WHERE gateway_id = ? AND status = ?', [$id, \Model_Invoice::STATUS_UNPAID]) === 0) {
            return;
        }
        $replacement = $replacementId !== null && $replacementId !== $id ? $this->di['db']->load('PayGateway', $replacementId) : null;
        if (!$replacement instanceof \Model_PayGateway || !$replacement->enabled) {
            throw new InformationException('Choose an enabled gateway for the unpaid invoices of this gateway.');
        }
        $db->executeStatement('UPDATE invoice SET gateway_id = ? WHERE gateway_id = ? AND status = ?', [$replacementId, $id, \Model_Invoice::STATUS_UNPAID]);
    }

    /**
     * @return mixed[]
     */
    public function getActive(array $data): array
    {
        $format = $data['format'] ?? null;

        $gateways = $this->di['db']->find('PayGateway', 'enabled = 1 ORDER BY id desc');
        $result = [];
        foreach ($gateways as $gtw) {
            if ($format == 'pairs') {
                $result[$gtw->id] = $gtw->name;
            } else {
                $gateway = $this->toApiArray($gtw);
                $adapter = $this->getPaymentAdapter($gtw);
                $config = $adapter->getConfig();

                if (!empty($config['logo'])) {
                    $gateway['logo'] = $config['logo'];
                    $gateway['logo']['logo'] = $this->resolveGatewayLogo($config['logo']);
                } else {
                    // Templates read gtw.logo.logo/height/width unconditionally,
                    // so always provide the key even when the adapter ships no logo.
                    $gateway['logo'] = [
                        'logo' => $this->resolveGatewayLogo([]),
                        'height' => '50px',
                        'width' => '50px',
                    ];
                }

                $result[] = $gateway;
            }
        }

        return $result;
    }

    public function resolveGatewayLogo(array $logoConfig): string
    {
        $filename = $logoConfig['logo'] ?? 'default.png';

        $libraryPath = Path::join(PATH_LIBRARY, 'Payment', 'Adapter', $filename);
        $publicPath = Path::join(PATH_ROOT, 'public', 'gateways', $filename);

        if ($this->filesystem->exists($libraryPath)) {
            return $this->di['tools']->url("/library/Payment/Adapter/{$filename}");
        }

        if ($this->filesystem->exists($publicPath)) {
            return $this->di['tools']->url("/public/gateways/{$filename}");
        }

        return $this->di['tools']->url('/public/gateways/default.png');
    }

    public function canPerformRecurrentPayment(\Model_PayGateway $model): bool
    {
        return (bool) $model->allow_recurrent;
    }

    public function canPerformSinglePayment(\Model_PayGateway $model): bool
    {
        return (bool) $model->allow_single;
    }

    public function getPaymentAdapter(\Model_PayGateway $pg, ?\Model_Invoice $model = null, $optional = []): object
    {
        $config = json_decode($pg->config ?? '', true) ?? [];
        $defaults = [];
        $defaults['auto_redirect'] = false;
        $defaults['gateway_id'] = (int) $pg->id;
        $defaults['test_mode'] = $pg->test_mode;
        $defaults['return_url'] = $this->getReturnUrl($pg, $model);
        $defaults['cancel_url'] = $this->getCancelUrl($pg, $model);
        $defaults['notify_url'] = $this->getCallbackUrl($pg, $model);
        $defaults['redirect_url'] = $this->getCallbackRedirect($pg, $model);
        $defaults['continue_shopping_url'] = $this->di['tools']->url('/order');
        $defaults['single_page'] = true;
        if ($model instanceof \Model_Invoice) {
            $defaults['thankyou_url'] = $this->getPaymentReturnUrl($model, 'thankyou');
            $defaults['invoice_url'] = $this->di['tools']->url("/invoice/{$model->hash}");
        }

        if (isset($optional['auto_redirect'])) {
            $defaults['auto_redirect'] = $optional['auto_redirect'];
        }
        $defaults['logo'] = null;

        $config = array_merge($config, $defaults);

        $class = $this->getAdapterClassName($pg);

        if (!class_exists($class)) {
            throw new \ClientFence\Exception('Payment gateway :adapter was not found.', [':adapter' => $class]);
        }

        $adapter = new $class($config);

        if (method_exists($adapter, 'setDi')) {
            $adapter->setDi($this->di);
        }

        return $adapter;
    }

    private function _getAllowTuple(\Model_PayGateway $model): array
    {
        $adapter_config = $this->getAdapterConfig($model);
        $single = $adapter_config['supports_one_time_payments'] ?? false;
        $recurrent = $adapter_config['supports_subscriptions'] ?? false;

        return [
            $single,
            $recurrent,
        ];
    }

    public function getAdapterConfig(\Model_PayGateway $pg): array
    {
        $class = $this->getAdapterClassName($pg);

        if (!class_exists($class)) {
            throw new \ClientFence\Exception('Payment gateway :adapter was not found', [':adapter' => $pg->gateway]);
        }

        if (!method_exists($class, 'getConfig')) {
            error_log("Payment $class gateway does not have getConfig method");

            return [];
        }

        // @phpstan-ignore argument.type
        return call_user_func([$class, 'getConfig']);
    }

    public function getAdapterClassName(\Model_PayGateway $pg): string
    {
        $class = "Payment_Adapter_{$pg->gateway}";

        if (!class_exists($class)) {
            $nestedFile = Path::join(PATH_LIBRARY, 'Payment', 'Adapter', $pg->gateway, "{$pg->gateway}.php");
            $flatFile = Path::join(PATH_LIBRARY, 'Payment', 'Adapter', "{$pg->gateway}.php");

            if ($this->filesystem->exists($nestedFile)) {
                require_once $nestedFile;
            } elseif ($this->filesystem->exists($flatFile)) {
                require_once $flatFile;
            } elseif ($moduleFile = $this->di['mod_service']('extension')->getModuleAdapterFiles('Payment/Adapter')[$pg->gateway] ?? null) {
                // ClientFence core patch: a gateway shipped by an active module.
                require_once $moduleFile;
            }
        }

        return $class;
    }

    public function getAcceptedCurrencies(\Model_PayGateway $model): array
    {
        if ($model->accepted_currencies === null || empty($model->accepted_currencies)) {
            $currencyService = $this->di['mod_service']('currency');
            /** @var \ClientFence\Mod\Currency\Repository\CurrencyRepository $currencyRepository */
            $currencyRepository = $currencyService->getCurrencyRepository();

            return array_keys($currencyRepository->getPairs());
        }

        return json_decode($model->accepted_currencies ?? '', true);
    }

    public function getFormElements(\Model_PayGateway $model): array
    {
        $config = $this->getAdapterConfig($model);
        if (isset($config['form']) && is_array($config['form'])) {
            return $config['form'];
        }

        return [];
    }

    public function getDescription(\Model_PayGateway $model): ?string
    {
        $config = $this->getAdapterConfig($model);

        return $config['description'] ?? null;
    }

    /**
     * @param \Model_Invoice $model
     */
    public function getCallbackUrl(\Model_PayGateway $pg, $model = null): string
    {
        $p = [
            'gateway_id' => $pg->id,
        ];
        if ($model instanceof \Model_Invoice) {
            $p['invoice_id'] = $model->id;
        }

        return SYSTEM_URL . 'ipn.php?' . http_build_query($p);
    }

    /**
     * @param \Model_Invoice $model
     */
    private function getReturnUrl(\Model_PayGateway $pg, $model = null): string
    {
        return $this->getPaymentReturnUrl($model, 'ok');
    }

    /**
     * @param \Model_Invoice $model
     */
    private function getCancelUrl(\Model_PayGateway $pg, $model = null): string
    {
        return $this->getPaymentReturnUrl($model, 'cancel');
    }

    // ClientFence core patch (0.8.9 port): gateway URLs carry invoice destinations without session credentials.
    private function getPaymentReturnUrl($model, string $status): string
    {
        $params = ['status' => $status];
        if ($model instanceof \Model_Invoice) {
            $params['hash'] = $model->hash;
        }

        return $this->di['url']->link('/invoice/payment-return', $params);
    }

    /**
     * @param \Model_Invoice $model
     */
    private function getCallbackRedirect(\Model_PayGateway $pg, $model = null): string
    {
        $p = [
            'gateway_id' => $pg->id,
        ];

        if ($model instanceof \Model_Invoice) {
            $p['invoice_id'] = $model->id;
            $p['invoice_hash'] = $model->hash;
            $p['redirect'] = 1;
        }

        return SYSTEM_URL . 'ipn.php?' . http_build_query($p);
    }
}
