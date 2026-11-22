<?php

declare(strict_types=1);

use ClientFence\Tools;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Server_Manager_OpenPanel extends Server_Manager
{
    public static function getForm(): array
    {
        return [
            'label' => __trans('OpenPanel'),
            'form' => [
                'credentials' => [
                    'fields' => [
                        [
                            'name' => 'username',
                            'type' => 'text',
                            'label' => __trans('OpenAdmin Username'),
                            'placeholder' => __trans('The API requires OpenPanel Enterprise.'),
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
            throw new Server_Exception(__trans('OpenPanel requires a hostname and OpenAdmin credentials.'));
        }
    }

    public function getLoginUrl(?Server_Account $account = null): string
    {
        return $this->panelUrl(2083);
    }

    public function getResellerLoginUrl(?Server_Account $account = null): string
    {
        return $this->panelUrl(Tools::normalizePort($this->_config['port'], 2087));
    }

    public function testConnection(): bool
    {
        [$status, $body] = $this->send('GET', '', ['auth_bearer' => $this->authenticate()]);
        if ($status >= 400 || (json_decode($body, true)['message'] ?? null) !== 'API is working!') {
            throw new Server_Exception(__trans('Could not connect to the OpenAdmin API. Enable API access on OpenPanel Enterprise and check the server credentials.'));
        }

        return true;
    }

    public function generateUsername(string $domain): string
    {
        $name = strtolower(substr(preg_replace('/[^a-z0-9]/i', '', $domain), 0, 7)) . random_int(0, 9);

        // OpenPanel reserves usernames that begin with test.
        return str_starts_with($name, 'test') ? 'a' . bin2hex(random_bytes(2)) . substr($name, 5) : $name;
    }

    public function createAccount(Server_Account $account): bool
    {
        return $this->userCommand('POST', 'users', [
            'username' => $account->getUsername(),
            'password' => $account->getPassword(),
            'plan_name' => $account->getPackage()->getName(),
            'email' => $account->getClient()->getEmail(),
        ]);
    }

    public function suspendAccount(Server_Account $account): bool
    {
        return $this->userCommand('PATCH', $this->userPath($account), ['action' => 'suspend']);
    }

    public function unsuspendAccount(Server_Account $account): bool
    {
        return $this->userCommand('PATCH', $this->userPath($account), ['action' => 'unsuspend']);
    }

    public function cancelAccount(Server_Account $account): bool
    {
        return $this->userCommand('DELETE', $this->userPath($account));
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package): bool
    {
        return $this->userCommand('PUT', $this->userPath($account), ['plan_name' => $package->getName()]);
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword): bool
    {
        return $this->userCommand('PATCH', $this->userPath($account), ['password' => $newPassword]);
    }

    public function synchronizeAccount(Server_Account $account): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'OpenPanel', ':action:' => __trans('account synchronization')]));
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'OpenPanel', ':action:' => __trans('username changes')]));
    }

    public function changeAccountDomain(Server_Account $account, string $newDomain): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'OpenPanel', ':action:' => __trans('changing the account domain')]));
    }

    public function changeAccountIp(Server_Account $account, string $newIp): never
    {
        throw new Server_Exception(__trans(':type: does not support :action:', [':type:' => 'OpenPanel', ':action:' => __trans('changing the account IP')]));
    }

    private function panelUrl(int $port): string
    {
        $scheme = $this->_config['secure'] ? 'https' : 'http';

        return $scheme . '://' . $this->_config['host'] . ':' . $port . '/';
    }

    private function userPath(Server_Account $account): string
    {
        return 'users/' . rawurlencode($account->getUsername());
    }

    private function authenticate(): string
    {
        [$status, $body] = $this->send('POST', '', ['json' => [
            'username' => $this->_config['username'],
            'password' => $this->_config['password'],
        ]]);
        $token = json_decode($body, true)['access_token'] ?? null;
        if ($status >= 400 || !is_string($token) || $token === '') {
            throw new Server_Exception(__trans('Could not connect to the OpenAdmin API. Enable API access on OpenPanel Enterprise and check the server credentials.'));
        }

        return $token;
    }

    private function userCommand(string $method, string $path, array $payload = []): bool
    {
        $options = ['auth_bearer' => $this->authenticate()];
        if ($payload !== []) {
            $options['json'] = $payload;
        }
        [$status, $body] = $this->send($method, $path, $options);
        $result = json_decode($body, true);

        // Older OpenAdmin releases report successful account creation as text.
        if ($status < 400 && (!empty($result['success']) || ($method === 'POST' && str_contains($body, 'Successfully added user')))) {
            return true;
        }

        throw new Server_Exception(__trans('OpenPanel refused the request: :message', [
            ':message' => $result['error'] ?? __trans('The panel did not confirm the operation.'),
        ]));
    }

    private function send(string $method, string $path, array $options): array
    {
        $verifyTls = Tools::normalizeBoolean($this->_config['config']['tls_verify'] ?? true, true);

        try {
            $response = $this->getHttpClient()->request($method, $this->getResellerLoginUrl() . 'api/' . $path, $options + [
                'headers' => ['Accept' => 'application/json'],
                'verify_peer' => $verifyTls,
                'verify_host' => $verifyTls,
                'timeout' => 60,
            ]);

            return [$response->getStatusCode(), $response->getContent(false)];
        } catch (TransportExceptionInterface) {
            throw new Server_Exception(__trans('Could not reach the OpenAdmin API. Check the server address and enable API access on OpenPanel Enterprise.'));
        }
    }
}
