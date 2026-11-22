<?php

declare(strict_types=1);

use ClientFence\Tools;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Server_Manager_CyberPanel extends Server_Manager
{
    public static function getForm(): array
    {
        return [
            'label' => __trans('CyberPanel'),
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'username',
                            'type' => 'text',
                            'label' => __trans('CyberPanel Username'),
                            'placeholder' => __trans('Use a CyberPanel account with API access enabled.'),
                            'required' => true,
                        ],
                        [
                            'name' => 'password',
                            'type' => 'password',
                            'label' => __trans('Password'),
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
        if (empty($this->_config['host']) || empty($this->_config['username']) || empty($this->_config['password'])) {
            throw new Server_Exception(__trans('CyberPanel requires a hostname, username and password.'));
        }
    }

    public function getLoginUrl(?Server_Account $account = null): string
    {
        $port = Tools::normalizePort($this->_config['port'], 8090);

        return 'https://' . $this->_config['host'] . ':' . $port . '/';
    }

    public function getResellerLoginUrl(?Server_Account $account = null): string
    {
        return $this->getLoginUrl($account);
    }

    public function testConnection(): bool
    {
        return $this->post('verifyConn', 'verifyConn');
    }

    public function createAccount(Server_Account $account): bool
    {
        $plan = $account->getPackage();
        $contact = $account->getClient();
        $role = $plan->getCustomValue('ACL') ?? ($account->getReseller() ? 'reseller' : 'user');
        $names = explode(' ', (string) $contact->getFullName(), 2);
        $email = $contact->getEmail();
        $login = $account->getUsername();
        $password = $account->getPassword();

        $this->post('submitUserCreation', 'status', [
            'userName' => $login,
            'email' => $email,
            'password' => $password,
            'firstName' => $names[0],
            'lastName' => ($names[1] ?? '') ?: 'Unknown',
            'selectedACL' => $role,
            'websitesLimit' => (int) $plan->getMaxDomains(),
            'securityLevel' => 'HIGH',
        ]);

        return $this->post('createWebsite', 'status', [
            'websiteOwner' => $login,
            'ownerPassword' => $password,
            'ownerEmail' => $email,
            'domainName' => $account->getDomain(),
            'acl' => $role,
            'packageName' => $plan->getName(),
        ]);
    }

    public function suspendAccount(Server_Account $account): bool
    {
        return $this->websiteState($account, 'Suspend');
    }

    public function unsuspendAccount(Server_Account $account): bool
    {
        return $this->websiteState($account, 'Activate');
    }

    public function cancelAccount(Server_Account $account): bool
    {
        $this->post('deleteWebsite', 'websiteDeleteStatus', ['domainName' => $account->getDomain()]);

        // The website deletion needs time to release the user before it can be removed.
        sleep(2);

        return $this->post('submitUserDeletion', 'status', ['accountUsername' => $account->getUsername()]);
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        return $this->post('changePackageAPI', 'changePackage', [
            'packageName' => $package->getName(),
            'websiteName' => $account->getDomain(),
        ]);
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword): bool
    {
        return $this->post('changeUserPassAPI', 'changeStatus', [
            'ownerPassword' => $newPassword,
            'websiteOwner' => $account->getUsername(),
        ]);
    }

    public function synchronizeAccount(Server_Account $account): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'CyberPanel', ':action:' => __trans('account synchronization')]));
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'CyberPanel', ':action:' => __trans('username changes')]));
    }

    public function changeAccountDomain(Server_Account $account, string $newDomain): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'CyberPanel', ':action:' => __trans('changing the account domain')]));
    }

    public function changeAccountIp(Server_Account $account, string $newIp): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'CyberPanel', ':action:' => __trans('changing the account IP')]));
    }

    private function websiteState(Server_Account $account, string $state): bool
    {
        return $this->post('submitWebsiteStatus', 'websiteStatus', [
            'state' => $state,
            'websiteName' => $account->getDomain(),
        ]);
    }

    private function post(string $endpoint, string $success, array $fields = []): bool
    {
        $verifyTls = Tools::normalizeBoolean($this->_config['config']['tls_verify'] ?? true, true);
        $credentials = ['adminUser' => $this->_config['username'], 'adminPass' => $this->_config['password']];

        try {
            $response = $this->getHttpClient()->request('POST', $this->getLoginUrl() . 'api/' . $endpoint, [
                'json' => $credentials + $fields,
                'verify_peer' => $verifyTls,
                'verify_host' => $verifyTls,
                'timeout' => 60,
            ]);
            $status = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (TransportExceptionInterface) {
            throw new Server_Exception(__trans('Could not connect to CyberPanel.'));
        } catch (DecodingExceptionInterface) {
            throw new Server_Exception(__trans('CyberPanel returned an invalid response.'));
        }

        if ($status >= 400 || empty($result[$success])) {
            if ($success === 'verifyConn') {
                throw new Server_Exception(__trans('CyberPanel could not verify the credentials. Check the username, password and API access.'));
            }

            $message = $result['error_message'] ?? __trans('The panel did not confirm the operation.');

            throw new Server_Exception(__trans('CyberPanel refused the request: :message', [':message' => $message]));
        }

        return true;
    }
}
