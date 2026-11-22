<?php

declare(strict_types=1);

/**
 * Copyright 2026 NovaPanel
 * SPDX-License-Identifier: Apache-2.0
 * Adapted for ClientFence.
 *
 * Licensed under the Apache License, Version 2.0. A copy of the license is
 * available at https://www.apache.org/licenses/LICENSE-2.0.
 */

use ClientFence\Tools;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Server_Manager_Novapanel extends Server_Manager
{
    public static function getForm(): array
    {
        return [
            'label' => __trans('NovaPanel'),
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'accesshash',
                            'type' => 'password',
                            'label' => __trans('API Key'),
                            'placeholder' => __trans('Create a key in NovaPanel under Settings → API Keys.'),
                            'required' => true,
                            'secret' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function init(): void
    {
        if (empty($this->_config['host']) || trim((string) $this->_config['accesshash']) === '') {
            throw new Server_Exception(__trans('NovaPanel requires a hostname and API key.'));
        }
    }

    public function getLoginUrl(?Server_Account $account = null): string
    {
        $login = $this->panelUrl($this->port() - 4);
        if ($account === null) {
            return $login;
        }

        try {
            $result = $this->request('POST', $this->userPath($account) . '/login-link');

            return $result['url'] ?? $login;
        } catch (Server_Exception) {
            return $login;
        }
    }

    public function getResellerLoginUrl(?Server_Account $account = null): string
    {
        return $this->panelUrl($this->port());
    }

    public function testConnection(): bool
    {
        $this->request('GET', '/packages');

        return true;
    }

    public function createAccount(Server_Account $account): bool
    {
        $payload = [
            'domain' => $account->getDomain(),
            'username' => $account->getUsername(),
            'password' => $account->getPassword(),
        ];

        $email = $account->getClient()->getEmail();
        if ($email) {
            $payload['email'] = $email;
        }

        $name = $account->getPackage()->getName();
        $packageId = $this->findPackageId($name);
        if ($packageId !== null) {
            $payload['package_id'] = $packageId;
        } else {
            $this->getLog()?->info(sprintf('NovaPanel has no package named "%s": the account uses the server defaults.', $name));
        }

        $this->request('POST', '/accounts', $payload);

        return true;
    }

    public function suspendAccount(Server_Account $account): bool
    {
        $this->request('POST', $this->userPath($account) . '/suspend');

        return true;
    }

    public function unsuspendAccount(Server_Account $account): bool
    {
        $this->request('POST', $this->userPath($account) . '/unsuspend');

        return true;
    }

    public function cancelAccount(Server_Account $account): bool
    {
        $found = $this->lookup($account);
        if (!empty($found['id'])) {
            $this->request('DELETE', '/users/' . rawurlencode((string) $found['id']));
        }

        return true;
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        $path = $this->userPath($account);
        $name = $package->getName();
        $packageId = $this->findPackageId($name);
        if ($packageId === null) {
            throw new Server_Exception(__trans('NovaPanel has no package named ":name".', [':name' => $name]));
        }

        $this->request('PUT', $path, ['package_id' => $packageId]);

        return true;
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword): bool
    {
        $path = $this->userPath($account) . '/password';
        try {
            $this->request('POST', $path, ['password' => $newPassword]);
        } catch (Server_Exception $e) {
            if ($e->getCode() === 404) {
                throw new Server_Exception(__trans('Changing passwords from billing requires NovaPanel 1.8.3 or newer.'));
            }

            throw $e;
        }

        return true;
    }

    public function synchronizeAccount(Server_Account $account): Server_Account
    {
        $found = $this->lookup($account);
        if (empty($found['id'])) {
            throw new Server_Exception(__trans('NovaPanel could not find the account ":username".', [':username' => $account->getUsername()]));
        }

        $updated = clone $account;
        if (isset($found['username'])) {
            $updated->setUsername($found['username']);
        }

        return $updated;
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'NovaPanel', ':action:' => __trans('username changes')]));
    }

    public function changeAccountDomain(Server_Account $account, string $newDomain): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'NovaPanel', ':action:' => __trans('changing the account domain')]));
    }

    public function changeAccountIp(Server_Account $account, string $newIp): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'NovaPanel', ':action:' => __trans('changing the account IP')]));
    }

    private function userPath(Server_Account $account): string
    {
        $found = $this->lookup($account);
        if (empty($found['id'])) {
            throw new Server_Exception(__trans('NovaPanel could not find the account ":username".', [':username' => $account->getUsername()]));
        }

        return '/users/' . rawurlencode((string) $found['id']);
    }

    private function lookup(Server_Account $account): array
    {
        try {
            return $this->request('GET', '/users/lookup', ['username' => $account->getUsername()]);
        } catch (Server_Exception $e) {
            if ($e->getCode() === 404) {
                return [];
            }

            throw $e;
        }
    }

    private function findPackageId(?string $name): ?string
    {
        foreach ($this->request('GET', '/packages') as $plan) {
            if (isset($plan['name'], $plan['id']) && strcasecmp($plan['name'], (string) $name) === 0) {
                return (string) $plan['id'];
            }
        }

        return null;
    }

    private function port(): int
    {
        return Tools::normalizePort($this->_config['port'], 2087);
    }

    private function panelUrl(int $port): string
    {
        $scheme = $this->_config['secure'] ? 'https' : 'http';

        return $scheme . '://' . $this->_config['host'] . ':' . $port . '/';
    }

    private function request(string $method, string $path, array $params = []): array
    {
        $verifyTls = Tools::normalizeBoolean($this->_config['config']['tls_verify'] ?? true, true);
        $options = [
            'headers' => ['X-API-Key' => trim($this->_config['accesshash']), 'Accept' => 'application/json'],
            'verify_peer' => $verifyTls,
            'verify_host' => $verifyTls,
            'timeout' => 120,
        ];
        if ($params !== []) {
            $options[$method === 'GET' ? 'query' : 'json'] = $params;
        }

        try {
            $response = $this->getHttpClient()->request($method, $this->getResellerLoginUrl() . 'api/v1' . $path, $options);
            $status = $response->getStatusCode();
            $result = json_decode($response->getContent(false), true);
        } catch (TransportExceptionInterface) {
            throw new Server_Exception(__trans('Could not connect to NovaPanel.'));
        }

        if ($status >= 400) {
            $message = $result['error']['message'] ?? __trans('HTTP status :status', [':status' => $status]);
            if ($status === 403 && (($result['error']['code'] ?? null) === 'TIER_REQUIRED' || str_contains($message, 'plan'))) {
                throw new Server_Exception(__trans('NovaPanel requires a Pro or Developer licence for API access.'));
            }

            throw new Server_Exception(__trans('NovaPanel refused the request: :message', [':message' => $message]), null, $status);
        }

        if (!is_array($result) || !array_key_exists('data', $result)) {
            throw new Server_Exception(__trans('NovaPanel returned an invalid response.'));
        }

        return is_array($result['data']) ? $result['data'] : [];
    }
}
