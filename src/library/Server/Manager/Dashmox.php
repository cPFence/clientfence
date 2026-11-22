<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Dashmox Software
 * SPDX-License-Identifier: MIT
 * Adapted for ClientFence.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

use ClientFence\Tools;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Server_Manager_Dashmox extends Server_Manager
{
    public static function getForm(): array
    {
        return [
            'label' => __trans('Dashmox'),
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'accesshash',
                            'type' => 'password',
                            'label' => __trans('Server Token'),
                            'placeholder' => __trans('Create a token in Dashmox under Server → Integrations.'),
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
            throw new Server_Exception(__trans('Dashmox requires a hostname and server token.'));
        }
    }

    public function testConnection(): bool
    {
        $this->call('GET', '/server/health');

        return true;
    }

    public function createAccount(Server_Account $account): bool
    {
        // Restoring a cancelled service reuses the customer and its suspended website.
        if ($account->getPanelOrgId() !== null) {
            return $this->unsuspendAccount($account);
        }

        $contact = $account->getClient();
        $customer = $this->call('POST', '/customers', array_filter([
            'name' => trim($contact->getFirstName() . ' ' . $contact->getLastName()),
            'email' => $contact->getEmail(),
            'package_id' => $this->packageId($account->getPackage()),
        ], static fn (?string $value): bool => $value !== '' && $value !== null));

        if (empty($customer['id'])) {
            throw new Server_Exception(__trans('Dashmox did not return the new customer ID.'));
        }

        $domain = $account->getDomain();
        $this->call('POST', '/sites', [
            'name' => $domain ?: $account->getUsername(),
            'domain' => $domain,
            'runtime' => 'php',
            // Dashmox can issue a certificate once the domain points to the website.
            'ssl' => false,
            'customer_id' => $customer['id'],
        ]);
        $account->setPanelOrgId((string) $customer['id']);

        return true;
    }

    public function suspendAccount(Server_Account $account): bool
    {
        return $this->setSuspended($account, true);
    }

    public function unsuspendAccount(Server_Account $account): bool
    {
        return $this->setSuspended($account, false);
    }

    public function cancelAccount(Server_Account $account): bool
    {
        return $this->suspendAccount($account);
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        $this->call('PUT', $this->customerPath($account) . '/package', ['package_id' => $this->packageId($package)]);

        return true;
    }

    public function synchronizeAccount(Server_Account $account): Server_Account
    {
        // Dashmox reports only the suspended state, which Sync does not save.
        return clone $account;
    }

    public function getLoginUrl(?Server_Account $account = null): string
    {
        if ($account === null) {
            return $this->baseUrl() . '/';
        }

        $domain = $account->getDomain();
        if (!$domain) {
            throw new Server_Exception(__trans('Dashmox requires a website domain for customer login.'));
        }
        $login = 'https://' . $domain . '/dashmox';

        try {
            $site = $this->findSite($account);
            if ($site !== null) {
                $result = $this->call('POST', '/sites/' . rawurlencode((string) $site['id']) . '/sign-on', ['requested_by' => 'ClientFence']);

                return !empty($result['url']) ? $result['url'] : $login;
            }
        } catch (Server_Exception) {
            return $login;
        }

        return $login;
    }

    public function getResellerLoginUrl(?Server_Account $account = null): string
    {
        if ($account === null) {
            return $this->getLoginUrl();
        }

        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'Dashmox', ':action:' => __trans('reseller accounts')]));
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'Dashmox', ':action:' => __trans('password changes')]));
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'Dashmox', ':action:' => __trans('username changes')]));
    }

    public function changeAccountDomain(Server_Account $account, string $newDomain): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'Dashmox', ':action:' => __trans('changing the account domain')]));
    }

    public function changeAccountIp(Server_Account $account, string $newIp): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'Dashmox', ':action:' => __trans('changing the account IP')]));
    }

    private function setSuspended(Server_Account $account, bool $suspended): bool
    {
        $this->call('PATCH', $this->customerPath($account), ['suspended' => $suspended]);

        return true;
    }

    private function customerPath(Server_Account $account): string
    {
        return '/customers/' . rawurlencode($this->customerId($account));
    }

    private function customerId(Server_Account $account): string
    {
        $id = $account->getPanelOrgId() ?? $this->findSite($account)['customer_id'] ?? null;
        if ($id === null || $id === '') {
            throw new Server_Exception(__trans('The Dashmox customer could not be found.'));
        }
        // An imported account learns its customer here, so a restore after cancel finds it.
        $account->setPanelOrgId((string) $id);

        return (string) $id;
    }

    private function findSite(Server_Account $account): ?array
    {
        $domain = trim((string) $account->getDomain());
        $customerId = $account->getPanelOrgId();
        $cursor = '';
        $scanned = 0;

        do {
            [$sites, $headers] = $this->request('GET', '/sites', $cursor === '' ? [] : ['query' => ['cursor' => $cursor]]);
            foreach ($sites as $site) {
                if (strcasecmp($site['domain'], $domain) === 0 && ($customerId === null || (string) $site['customer_id'] === $customerId)) {
                    return $site;
                }
            }
            $scanned += count($sites);
            $cursor = $headers['x-next-cursor'][0] ?? '';
        } while ($cursor !== '' && $sites !== [] && $scanned < 5000);

        return null;
    }

    private function packageId(Server_Package $package): string
    {
        $name = trim((string) $package->getName());
        if ($name === '') {
            return '';
        }

        foreach ($this->call('GET', '/packages') as $plan) {
            if (strcasecmp(trim($plan['name']), $name) === 0) {
                return (string) $plan['id'];
            }
        }

        throw new Server_Exception(__trans('Dashmox has no plan named ":name".', [':name' => $name]));
    }

    private function baseUrl(): string
    {
        $scheme = $this->_config['secure'] ? 'https' : 'http';
        $port = Tools::normalizePort($this->_config['port'], 8443);

        return $scheme . '://' . $this->_config['host'] . ':' . $port;
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        return $this->request($method, $path, $body === null ? [] : ['json' => $body])[0];
    }

    private function request(string $method, string $path, array $options): array
    {
        $verifyTls = Tools::normalizeBoolean($this->_config['config']['tls_verify'] ?? true, true);

        try {
            $response = $this->getHttpClient()->request($method, $this->baseUrl() . '/api/v1' . $path, $options + [
                'auth_bearer' => trim($this->_config['accesshash']),
                'headers' => ['Accept' => 'application/json'],
                'verify_peer' => $verifyTls,
                'verify_host' => $verifyTls,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface) {
            throw new Server_Exception(__trans('Could not connect to Dashmox.'));
        }

        $data = $body === '' ? [] : json_decode($body, true);
        if ($status >= 400) {
            $message = is_string($data['error'] ?? null) ? $data['error'] : __trans('HTTP status :status', [':status' => $status]);
            if ($status === 403 && str_contains($message, 'Pro or Business licence')) {
                throw new Server_Exception(__trans('Dashmox requires a Pro or Business licence for API access.'));
            }
            if ($status === 409 && (str_contains($message, 'unfinished operation') || str_contains($message, 'removal is already in progress'))) {
                throw new Server_Exception(__trans('Dashmox is still working on this website. Try again shortly.'));
            }

            throw new Server_Exception(__trans('Dashmox refused the request: :message', [':message' => $message]));
        }
        if (!is_array($data)) {
            throw new Server_Exception(__trans('Dashmox returned an invalid response.'));
        }

        return [$data, $headers];
    }
}
