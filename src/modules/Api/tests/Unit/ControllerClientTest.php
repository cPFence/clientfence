<?php

declare(strict_types=1);

use ClientFence\Mod\Api\Controller\Client;
use ClientFence\InformationException;
use Symfony\Component\HttpFoundation\Request;

function invokeControllerPrivate(object $instance, string $method, array $args = []): mixed
{
    $reflection = new ReflectionClass($instance);
    $reflectionMethod = $reflection->getMethod($method);

    return $reflectionMethod->invokeArgs($instance, $args);
}

function createControllerDiWithRequest(): Pimple\Container
{
    $di = new Pimple\Container();
    $di['request'] = Request::create('/', 'GET', [], [], [], $_SERVER);

    return $di;
}

function buildAdminModel(int $id, string $apiToken, ?string $systemName = null): Model_Admin
{
    $admin = new Model_Admin();
    $bean = new stdClass();
    $bean->id = $id;
    $bean->api_token = $apiToken;
    $bean->system_name = $systemName;
    $bean->status = Model_Admin::STATUS_ACTIVE;

    $property = new ReflectionProperty(RedBeanPHP\SimpleModel::class, 'bean');
    $property->setValue($admin, $bean);

    return $admin;
}

readonly class CronStaffServiceDouble
{
    public function __construct(private Model_Admin $cronAdmin)
    {
    }

    public function getCronAdmin(): Model_Admin
    {
        return $this->cronAdmin;
    }
}

uses()->beforeEach(function (): void {
    $this->serverBackup = $_SERVER;
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
})->afterEach(function (): void {
    $_SERVER = $this->serverBackup;
});

test('register allowed route roles', function (): void {
    $controller = new Client();

    $roles = invokeControllerPrivate($controller, 'registerAllowedRouteRoles');
    expect($roles)->toBeArray();
    expect($roles)->toHaveKey('role');
    expect($roles['role'])->toBe('guest|client|admin');
});

test('is role allowed rejects system role', function (): void {
    $controller = new Client();

    expect(fn (): mixed => invokeControllerPrivate($controller, 'isRoleAllowed', ['system']))
        ->toThrow(ClientFence\Exception::class);
});

test('should use token login ignores non-API basic auth usernames', function (): void {
    $controller = new Client();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('upstream:token');
    $controller->setDi(createControllerDiWithRequest());

    $result = invokeControllerPrivate($controller, 'shouldUseTokenLogin', ['admin']);
    expect($result)->toBeFalse();
});

test('should use token login rejects route role mismatch', function (): void {
    $controller = new Client();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('client:token');
    $controller->setDi(createControllerDiWithRequest());

    expect(fn (): mixed => invokeControllerPrivate($controller, 'shouldUseTokenLogin', ['admin']))
        ->toThrow(InformationException::class);
});

test('should prefer session auth for API key management methods', function (): void {
    $controller = new Client();

    expect(invokeControllerPrivate($controller, 'shouldPreferSessionAuth', ['profile_api_key_get']))->toBeTrue();
    expect(invokeControllerPrivate($controller, 'shouldPreferSessionAuth', ['profile_api_key_reset']))->toBeTrue();
    expect(invokeControllerPrivate($controller, 'shouldPreferSessionAuth', ['profile_generate_api_key']))->toBeTrue();
    expect(invokeControllerPrivate($controller, 'shouldPreferSessionAuth', ['profile_get']))->toBeFalse();
});

test('has authenticated session returns false when session lookup throws', function (): void {
    $controller = new Client();
    $di = new Pimple\Container();
    $di['is_client_logged'] = function (): void {
        throw new Exception('Session lookup failed');
    };
    $controller->setDi($di);

    expect(invokeControllerPrivate($controller, 'hasAuthenticatedSession', ['client']))->toBeFalse();
});

test('require session auth normalizes generic auth exceptions', function (): void {
    $controller = new Client();
    $di = new Pimple\Container();
    $di['is_admin_logged'] = function (): void {
        throw new Exception('Not logged in');
    };
    $controller->setDi($di);

    expect(fn (): mixed => invokeControllerPrivate($controller, 'requireSessionAuth', ['admin']))
        ->toThrow(InformationException::class);
});

test('get auth rejects invalid basic payload', function (): void {
    $controller = new Client();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic invalid###';
    $controller->setDi(createControllerDiWithRequest());

    expect(fn (): mixed => invokeControllerPrivate($controller, 'getAuth'))
        ->toThrow(InformationException::class);
});

test('try token login rejects cron admin token', function (): void {
    $controller = new Client();
    $cronToken = 'cron-api-token';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('admin:' . $cronToken);

    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('findOne')->with('Admin', 'api_token = ? AND status = ? AND (system_name IS NULL OR system_name != ?)', [$cronToken, Model_Admin::STATUS_ACTIVE, Model_Admin::SYSTEM_CRON])->once()->andReturn(null);

    $di = new Pimple\Container();
    $di['db'] = $dbMock;
    $di['request'] = Request::create('/', 'GET', [], [], [], $_SERVER);
    $controller->setDi($di);

    expect(fn (): mixed => invokeControllerPrivate($controller, '_tryTokenLogin', ['admin']))
        ->toThrow(InformationException::class);
});

test('try token login rejects cron admin by id fallback', function (): void {
    $controller = new Client();
    $cronToken = 'legacy-cron-api-token';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('admin:' . $cronToken);

    $dbAdmin = buildAdminModel(1, $cronToken);
    $cronAdmin = buildAdminModel(1, $cronToken, Model_Admin::SYSTEM_CRON);

    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('findOne')->with('Admin', 'api_token = ? AND status = ? AND (system_name IS NULL OR system_name != ?)', [$cronToken, Model_Admin::STATUS_ACTIVE, Model_Admin::SYSTEM_CRON])->once()->andReturn($dbAdmin);

    $di = new Pimple\Container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn ($name): ?object => $name === 'staff' ? new CronStaffServiceDouble($cronAdmin) : null);
    $di['request'] = Request::create('/', 'GET', [], [], [], $_SERVER);
    $controller->setDi($di);

    expect(fn (): mixed => invokeControllerPrivate($controller, '_tryTokenLogin', ['admin']))
        ->toThrow(InformationException::class);
});

test('try token login never signs a client in with an API key', function (): void {
    // ClientFence: client API keys are disabled, so a key is refused without looking it up,
    // even one that belongs to an active client.
    $controller = new Client();
    $apiToken = 'active-client-token';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('client:' . $apiToken);

    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldNotReceive('findOne');

    $di = new Pimple\Container();
    $di['db'] = $dbMock;
    $di['request'] = Request::create('/', 'GET', [], [], [], $_SERVER);
    $controller->setDi($di);

    expect(fn (): mixed => invokeControllerPrivate($controller, '_tryTokenLogin', ['client']))
        ->toThrow(InformationException::class);
});

test('try token login requires active non-cron admin token', function (): void {
    $controller = new Client();
    $apiToken = 'inactive-admin-token';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('admin:' . $apiToken);

    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('findOne')->with('Admin', 'api_token = ? AND status = ? AND (system_name IS NULL OR system_name != ?)', [$apiToken, Model_Admin::STATUS_ACTIVE, Model_Admin::SYSTEM_CRON])->once()->andReturn(null);

    $di = new Pimple\Container();
    $di['db'] = $dbMock;
    $di['request'] = Request::create('/', 'GET', [], [], [], $_SERVER);
    $controller->setDi($di);

    expect(fn (): mixed => invokeControllerPrivate($controller, '_tryTokenLogin', ['admin']))
        ->toThrow(InformationException::class);
});

test('guest identity and cart mutations require POST and a same-origin session nonce', function (string $method, string $verb, ?string $token, string $origin, ?int $error): void {
    $di = createControllerDiWithRequest();
    $di['request'] = Request::create('/', $verb, [], [], [], ['HTTP_ORIGIN' => $origin]);
    $di['session'] = Mockery::mock();
    $di['session']->shouldReceive('get')->with('csrf_token')->andReturn('session-token');
    $controller = new Client();
    $controller->setDi($di);
    $call = fn () => invokeControllerPrivate($controller, 'checkGuestAuthentication', ['guest', $method, ['CSRFToken' => $token]]);
    if ($error === null) {
        expect($call())->toBeNull();
    } else {
        try {
            $call();
            test()->fail('Request was accepted');
        } catch (InformationException $exception) {
            expect($exception->getCode())->toBe($error);
        }
    }
})->with([
    ['CLIENT_LOGIN', 'POST', 'session-token', rtrim(SYSTEM_URL, '/'), null],
    ['client_create', 'POST', null, rtrim(SYSTEM_URL, '/'), 403],
    ['staff_login', 'GET', 'session-token', rtrim(SYSTEM_URL, '/'), 405],
    ['client_user_invite_accept', 'POST', 'session-token', 'https://foreign.example', 403],
    ['client_twofactor_verify', 'POST', null, rtrim(SYSTEM_URL, '/'), 403],
    ['client_signin_pin', 'POST', null, rtrim(SYSTEM_URL, '/'), 403],
    ['staff_login_pin', 'POST', null, rtrim(SYSTEM_URL, '/'), 403],
    ['cart_add_item', 'POST', 'session-token', rtrim(SYSTEM_URL, '/'), null],
    ['cart_remove_item', 'GET', 'session-token', rtrim(SYSTEM_URL, '/'), 405],
    ['cart_apply_promo', 'POST', null, rtrim(SYSTEM_URL, '/'), 403],
    ['cart_remove_promo', 'POST', 'session-token', 'https://foreign.example', 403],
    ['cart_reset', 'POST', null, rtrim(SYSTEM_URL, '/'), 403],
    ['cart_set_currency', 'GET', 'session-token', rtrim(SYSTEM_URL, '/'), 405],
]);

test('the API IP list applies to API key sign-ins and takes ranges', function (): void {
    $controller = new Client();
    (new ReflectionProperty(Client::class, 'apiConfig'))->setValue($controller, ['allowed_ips' => ['203.0.113.0/24']]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('admin:some-api-token');
    $dbMock = Mockery::mock(\ClientFence\Database::class);
    $dbMock->shouldReceive('findOne')->once()->andReturn(null);
    $di = new Pimple\Container();
    $di['db'] = $dbMock;
    $controller->setDi($di);

    $di['request'] = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.1'] + $_SERVER);
    expect(fn (): mixed => invokeControllerPrivate($controller, '_tryTokenLogin', ['admin']))->toThrow(InformationException::class, 'Unauthorized IP');

    $di['request'] = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9'] + $_SERVER);
    expect(fn (): mixed => invokeControllerPrivate($controller, '_tryTokenLogin', ['admin']))->toThrow(InformationException::class, 'Authentication Failed');
});
