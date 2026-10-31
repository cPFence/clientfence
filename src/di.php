<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use ClientFence\Cache\CacheFactory;
use ClientFence\Config;
use ClientFence\Doctrine\DriverManagerFactory;
use ClientFence\Doctrine\EntityManagerFactory;
use ClientFence\Environment;
use ClientFence\Http\RequestFactory;
use ClientFence\Security\AuthenticationRequiredException;
use ClientFence\Security\EmailValidationRequiredException;
use ClientFence\Version;
use Psr\Cache\CacheItemPoolInterface;
use RedBeanPHP\Facade;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

$di = new Pimple\Container();

global $request;

if (!$request instanceof Request) {
    throw new LogicException('The request must be initialized before loading the DI container.');
}

/*
 * Create a new logger instance and configures it based on the settings in the configuration file.
 *
 * @param void
 *
 * @return \ClientFence\Log A new logger instance
 */
$di['logger'] = function () use ($di) {
    $log = new \ClientFence\Log();
    $log->setDi($di);

    $activity_service = $di['mod_service']('activity');
    $dbWriter = new \ClientFence\LogDb($activity_service);
    $log->addWriter($dbWriter);

    if ($di['auth']->isAdminLoggedIn()) {
        $admin = $di['loggedin_admin'];
        $log->setEventItem('admin_id', $admin->id);
    } elseif ($di['auth']->isClientLoggedIn()) {
        $client = $di['loggedin_client'];
        $log->setEventItem('client_id', $client->id);
    }

    $monolog = new ClientFence\Monolog();
    $log->addWriter($monolog);

    return $log;
};

/*
 *
 * @param void
 *
 * @return \ClientFence\Crypt
 */
$di['crypt'] = function () use ($di) {
    $crypt = new \ClientFence\Crypt();
    $crypt->setDi($di);

    return $crypt;
};

/*
 * Creates a new PDO object for database connections
 *
 * @param void
 *
 * @return PDO The PDO object used for database connections
 */
$di['pdo'] = function () {
    $debugConfig = Config::getProperty('debug_and_monitoring', []);
    $dbConfig = DriverManagerFactory::getDatabaseConfig();
    $connection = DriverManagerFactory::getSharedConnection();
    /** @var PDO $pdo */
    $pdo = $connection->getNativeConnection();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if (isset($debugConfig['debug']) && $debugConfig['debug']) {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['ClientFence\DbLoggedPDOStatement']);
    }

    if ($dbConfig['driver'] === 'pdo_mysql') {
        // Set server default charset for newly created tables. Connection charset is handled by DBAL via DSN.
        $pdo->exec('SET character_set_server = utf8mb4');
        $pdo->exec('SET SESSION interactive_timeout = 28800');
        $pdo->exec('SET SESSION wait_timeout = 28800');

        // Get the timezone offset in the PDO format
        $datetime = new DateTime('now');
        $offset = $datetime->format('P');
        $pdo->exec("SET time_zone = '{$offset}'");
    }

    // ClientFence core patch: only web requests in debug mode trace their queries (for the debug
    // bar). A console run (the WHMCS import, cron) would keep every query it ever ran in memory
    // until it dies, and tracing every query of every page costs time nobody looks at.
    if (PHP_SAPI === 'cli' || empty($debugConfig['debug'])) {
        return $pdo;
    }

    return new DebugBar\DataCollector\PDO\TraceablePDO($pdo);
};

/*
 *
 * @param void
 *
 * @return \ClientFence\Database The new \ClientFence\Database object that was just created.
 */
$di['db'] = function () use ($di) {
    $pdo = $di->offsetGet('pdo');
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('PDO service must resolve to a PDO instance');
    }

    RedBeanPHP\R::setup($pdo);
    RedBeanPHP\Util\DispenseHelper::setEnforceNamingPolicy(false);

    // SECURITY: bind string literals as PARAM_STR, not PARAM_INT. Without
    // this, ?hash=107 in /api/guest/invoice/get resolves to the invoice
    // whose hash starts with '107' because MySQL coerces VARCHAR against
    // int to a leading-digits match. Do not remove; see RedBeanBindingTest.
    /* @phpstan-ignore-next-line Adapter::getDatabase() returns the abstract Driver; the concrete RPDO implements setUseStringOnlyBinding. */
    Facade::getDatabaseAdapter()->getDatabase()->setUseStringOnlyBinding(true);

    $helper = new \ClientFence\BeanHelper();
    $helper->setDi($di);

    $mapper = new Facade();
    $mapper->getRedBean()->setBeanHelper($helper);
    $freeze = Config::getProperty('db.freeze', true);
    $mapper->freeze($freeze);

    // Doctrine writes do not invalidate RedBean's cache. Keep legacy reads
    // fresh so activation sees the service_id saved during service creation.
    $mapper->useWriterCache(false);

    $db = new \ClientFence\Database();
    $db->setDi($di);
    $db->setDataMapper($mapper);

    return $db;
};

/*
 * Creates and returns a Doctrine DBAL connection instance.
 *
 * @return Connection The Doctrine DBAL connection instance.
 */
$di['dbal'] = (fn (): Connection => DriverManagerFactory::getSharedConnection());

/*
 * Creates and returns a Doctrine ORM EntityManager instance.
 *
 * @param void
 *
 * @return EntityManager The Doctrine ORM EntityManager instance.
 */
$di['em'] = (fn (): EntityManager => EntityManagerFactory::create());

/*
 *
 * @param void
 *
 * @return ClientFence\Pagination
 */
$di['pager'] = function () use ($di) {
    $service = new ClientFence\Pagination();
    $service->setDi($di);

    return $service;
};

/*
 *
 * @param void
 *
 * @return \ClientFence\Url
 */
$di['url'] = function () use ($di) {
    $url = new \ClientFence\Url();
    $url->setDi($di);
    $url->setBaseUri(SYSTEM_URL);

    return $url;
};

/*
 * Returns a new Module object, created with the provided module name.
 *
 * @param string $name The name of the module to create the object with.
 *
 * @return \Module The new Module object that was just created.
 */
$di['mod'] = $di->protect(function ($name) use ($di) {
    $mod = new ClientFence\Module($name);
    $mod->setDi($di);

    return $mod;
});

/*
 *
 * @param string $mod the name of the module to get
 *
 * @return mixed the service of the associated module
 */
$di['mod_service'] = $di->protect(fn ($mod, $sub = '') => $di['mod']($mod)->getService($sub));

/*
 *
 * @param string $name the name of the module to get the configuration of
 *
 * @return mixed the configuration of the associated module
 */
$di['mod_config'] = $di->protect(fn ($name) => $di['mod']($name)->getConfig());

$di['cookie_queue'] = fn (): ClientFence\Http\CookieQueue => new ClientFence\Http\CookieQueue();

// ClientFence core patch: values worked out once per request (ClientFence\RequestCache).
$di['request_cache'] = static fn (): ArrayObject => new ArrayObject();

/*
 *
 * @param void
 *
 * @return \ClientFence\EventManager
 */
$di['events_manager'] = function () use ($di) {
    $service = new \ClientFence\EventManager();
    $service->setDi($di);

    return $service;
};

/*
 * Creates a new session, applying specified security rules depending on the config.php settings.
 *
 * @param void
 *
 * @return \ClientFence\Session
 *
 * @var \ClientFence\Session $di['session']
 */
$di['session'] = function () use ($di) {
    $pdo = $di->offsetGet('pdo');
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('PDO service must resolve to a PDO instance');
    }

    $handler = new PdoSessionHandler($pdo);
    $session = new ClientFence\Session($handler);
    $session->setDi($di);
    $session->setupSession();

    return $session;
};

/*
 * Creates a new request object based on the current request.
 *
 * @param void
 *
 * @link https://symfony.com/doc/current/components/http_foundation.html
 *
 * @return Symfony\Component\HttpFoundation\Request
 */
$di['request'] = $request;

/*
 * The general-purpose application cache. Backed by the filesystem by default; can be configured
 * to use Redis or Memcached instead via the `cache` block in the FOSSBilling configuration file.
 *
 * @param void
 *
 * @link https://symfony.com/doc/current/components/cache.html
 *
 * @return CacheItemPoolInterface
 */
$di['cache'] = fn (): CacheItemPoolInterface => CacheFactory::create(CacheFactory::NAMESPACE_APP, 24 * 60 * 60);

$di['rate_limit_cache'] = fn (): CacheItemPoolInterface => CacheFactory::create(CacheFactory::NAMESPACE_RATE_LIMIT, 24 * 60 * 60);

$di['http_client'] = fn (): HttpClientInterface => HttpClient::create([
    'bindto' => BIND_TO,
    'headers' => [
        'User-Agent' => 'ClientFence/' . Version::CLIENTFENCE,
    ],
]);

$di['filesystem'] = fn (): Filesystem => new Filesystem();

$di['rate_limiter'] = function () use ($di) {
    $rateLimiter = new ClientFence\Security\RateLimiter();
    $rateLimiter->setDi($di);

    return $rateLimiter;
};

/*
 *
 * @param void
 *
 * @return \ClientFence\Authorization
 *
 * @var \ClientFence\Authorization $di['auth']
 */
$di['auth'] = fn (): \ClientFence\Authorization => new \ClientFence\Authorization($di);

/*
 * Checks whether a client is logged in and throws an exception or redirects to the login page if not.
 *
 * @param void
 *
 * @return bool True if a client is logged in.
 *
 * @throws \Exception If a client is not logged in and the request is an API request.
 *
 * @throws \HttpException If a client is not logged in and the request is a browser request.
 */
$di['is_client_logged'] = function () use ($di) {
    /** @var \ClientFence\Authorization $auth */
    $auth = $di['auth'];
    if (!$auth->isClientLoggedIn()) {
        throw new AuthenticationRequiredException('client');
    }

    return true;
};

/*
 * @param mixed $model The client DB model to check
 * @return bool Returns true if the client's email address is valid or if email confirmation is disabled.
 */
$di['is_client_email_validated'] = $di->protect(function ($model) use ($di) {
    $config = $di['mod_config']('client');
    if (isset($config['require_email_confirmation']) && (bool) $config['require_email_confirmation']) {
        return (bool) $model->email_approved;
    }

    return true;
});

/*
 * Checks whether an admin is logged in and throws an exception or redirects to the login page if not.
 *
 * @param void
 *
 * @return bool True if an admin is logged in.
 *
 * @throws \Exception If an admin is not logged in and the request is an API request.
 *
 */
$di['is_admin_logged'] = function () use ($di) {
    /** @var \ClientFence\Authorization $auth */
    $auth = $di['auth'];
    if (!$auth->isAdminLoggedIn()) {
        throw new AuthenticationRequiredException('admin');
    }

    return true;
};

/*
 * Returns an existing logged-in client model object.
 *
 * @param void
 *
 * @return \Model_Client The existing logged-in client model object.
 */
$di['loggedin_client'] = function () use ($di) {
    $di['is_client_logged'];
    /** @var ClientFence\Session $session */
    $session = $di['session'];
    $client_id = $session->get('client_id');

    try {
        $client = $di['db']->getExistingModelById('Client', $client_id);
        if ($client->status !== Model_Client::ACTIVE) {
            throw new Exception('Client account is not active');
        }
        // ClientFence core patch: authorized users. A user who was removed, switched off or whose
        // password was reset is signed out (modules/Client/Users.php context()).
        if (class_exists(\ClientFence\Mod\Client\Users::class)) {
            \ClientFence\Mod\Client\Users::context($di);
        }

        return $client;
    } catch (Exception) {
        // Either the account was deleted or the session is invalid. Either way, remove the ID from the session so the system doesn't consider someone logged in
        $session->delete('client_id');

        throw new AuthenticationRequiredException('client');
    }
};

/*
 * Signals whether the current request is executing cron tasks (CLI or HTTP-triggered).
 * Set to true by Cron\Service::runCrons(). Used to scope cron-admin identity fallbacks
 * so they cannot fire for unauthenticated requests outside of a genuine cron run.
 */
$di['is_cron'] = false;

/*
 * Returns an existing logged-in admin model object.
 *
 * @param void
 *
 * @return \Model_Admin|null The existing logged-in admin model object, or null if no admin is logged in.
 *
 * @throws \ClientFence\Exception If the script is running in CLI or CGI mode and there is no cron admin available.
 */
$di['loggedin_admin'] = function () use ($di) {
    // ClientFence core patch: web cron listeners need the cron staff identity for notifications.
    if (Environment::isCLI() || ($di->offsetExists('is_cron') && $di->offsetGet('is_cron') === true)) {
        return $di['mod_service']('staff')->getCronAdmin();
    }

    $di['is_admin_logged'];
    /** @var ClientFence\Session $session */
    $session = $di['session'];
    $admin = $session->get('admin');

    try {
        $model = $di['db']->getExistingModelById('Admin', $admin['id']);
        if ($model->status !== Model_Admin::STATUS_ACTIVE) {
            throw new Exception('Admin account is not active');
        }

        return $model;
    } catch (Exception) {
        // Either the account was deleted or the session is invalid. Either way, remove the ID from the session so the system doesn't consider someone logged in
        $session->delete('admin');

        throw new AuthenticationRequiredException('admin');
    }
};

$di['set_return_uri'] = function () use ($di): void {
    $request = $di['request'];
    $url = RequestFactory::getRoutePath($request);
    $query = $request->query->all();
    unset($query['_url']);

    if (str_starts_with($url, ADMIN_PREFIX)) {
        $url = substr($url, strlen(ADMIN_PREFIX));
    }
    // ClientFence core patch: never back to a sign-out page (the next sign-in signed the person out again).
    if (preg_match('#/logout/?$#', $url) === 1) {
        return;
    }

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    /** @var ClientFence\Session $session */
    $session = $di['session'];
    $session->set('redirect_uri', $url);
};

/*
 * Creates a new API object based on the specified role and returns it.
 *
 * @param string $role The role to create the API object for. Can be 'guest', 'client', or 'admin'.
 *
 * @return \ClientFence\Api\Identity The new API identity wrapper that was just created.
 *
 * @throws \Exception If the specified role is not recognized or if a client is trying to use the API while their email is not valid.
 */
$di['api_identity'] = $di->protect(function ($role) use ($di) {
    $identity = match ($role) {
        'guest' => new ClientFence\Identity\Guest(),
        'client' => $di['loggedin_client'],
        'admin' => $di['loggedin_admin'],
        default => throw new Exception('Unrecognized Handler type: ' . $role),
    };

    // Checks to enforce email validation for clients
    if ($role === 'client' && !$di['is_client_email_validated']($identity)) {
        $routePath = RequestFactory::getRoutePath($di['request']);
        $isApiRequest = str_starts_with($routePath, '/api/');
        $isAllowedClientApi = str_starts_with($routePath, '/api/client/client/')
            || str_starts_with($routePath, '/api/client/profile/');
        $isAllowedClientPage = str_starts_with($routePath, '/client/profile')
            || str_starts_with($routePath, '/client/logout');

        if (($isApiRequest && !$isAllowedClientApi) || (!$isApiRequest && !$isAllowedClientPage)) {
            throw new EmailValidationRequiredException();
        }
    }

    return new ClientFence\Api\Identity($identity);
});

$di['api_dispatcher'] = function () use ($di): ClientFence\Api\Dispatcher {
    $dispatcher = new ClientFence\Api\Dispatcher();
    $dispatcher->setDi($di);

    return $dispatcher;
};

$di['api_proxy'] = $di->protect(function (string $role) use ($di): ClientFence\Api\Proxy {
    $identity = $di['api_identity']($role);
    $api = new ClientFence\Api\Proxy($identity->getIdentity());
    $api->setDi($di);

    return $api;
});

/*
 *
 * @param void
 *
 * @return \ClientFence\Api\Proxy
 */
$di['api_guest'] = fn () => $di['api_proxy']('guest');

/*
 *
 * @param void
 *
 * @return \ClientFence\Api\Proxy
 */
$di['api_client'] = fn () => $di['api_proxy']('client');

/*
 *
 * @param void
 *
 * @return \ClientFence\Api\Proxy
 */
$di['api_admin'] = fn () => $di['api_proxy']('admin');

/*
 *
 * @param void
 *
 * @return \ClientFence\Api\Proxy Internal-only system API proxy used for cron/background processing.
 */
$di['api_system'] = function () use ($di) {
    $identity = $di['mod_service']('staff')->getCronAdmin();
    $api = new ClientFence\Api\Proxy($identity);
    $api->setDi($di);

    return $api;
};

$di['tools'] = function () use ($di) {
    $service = new ClientFence\Tools();
    $service->setDi($di);

    return $service;
};

/*
 *
 * @param void
 *
 * @return \ClientFence\Validate
 */
$di['validator'] = function () use ($di) {
    $validator = new ClientFence\Validate();
    $validator->setDi($di);

    return $validator;
};

/*
 *
 * @param void
 *
 * @return \ClientFence\CentralAlerts
 */
$di['central_alerts'] = function () use ($di) {
    $centralalerts = new ClientFence\CentralAlerts();
    $centralalerts->setDi($di);

    return $centralalerts;
};

/*
 *
 * @param void
 *
 * @return \ClientFence\ExtensionManager
 */
$di['extension_manager'] = function () use ($di) {
    $extension = new ClientFence\ExtensionManager();
    $extension->setDi($di);

    return $extension;
};

/*
 *
 * @param void
 *
 * @return \ClientFence\Update
 */
$di['updater'] = function () use ($di) {
    $updater = new ClientFence\Update();
    $updater->setDi($di);

    return $updater;
};

$di['update_finalization'] = function () use ($di) {
    $finalization = new ClientFence\UpdateFinalization();
    $finalization->setDi($di);

    return $finalization;
};

$di['update_readiness'] = new ClientFence\UpdateReadinessCheck(
    \PATH_ROOT,
    \PATH_DATA,
    \PATH_CONFIG,
);

/*
 * Creates a new server manager object and returns it.
 *
 * @param string $manager The name of the server manager to create.
 * @param array $config The configuration options for the server manager.
 *
 * @return \Server_Manager The new server manager object that was just created.
 */
$di['server_manager'] = $di->protect(function ($manager, $config) use ($di) {
    $managerName = ucfirst((string) $manager);
    $class = sprintf('Server_Manager_%s', $managerName);

    if (!class_exists($class)) {
        $file = Path::join(PATH_LIBRARY, 'Server', 'Manager', $managerName . '.php');
        if ($di['filesystem']->exists($file)) {
            require_once $file;
        }
    }

    $s = new $class($config);
    $s->setLog($di['logger']);

    return $s;
});

/*
 * Creates a new \ClientFence\Period object using the provided period code and returns it.
 *
 * @param string $code The two character period code to create the period object with.
 *
 * @return \ClientFence\Period The new period object that was just created.
 */
$di['period'] = $di->protect(fn ($code): \ClientFence\Period => new \ClientFence\Period($code));

/*
 * Gets the current client area theme.
 *
 * @param void
 *
 * @return \ClientFence\Theme The current client area theme.
 */
$di['theme'] = function () use ($di) {
    $service = $di['mod_service']('theme');

    return $service->getCurrentClientAreaTheme();
};

/*
 * Loads an existing cart session or creates a new one if there is no session.
 *
 * @param void
 *
 * @return mixed The either existing or new cart.
 */
$di['cart'] = function () use ($di) {
    $service = $di['mod_service']('cart');

    return $service->getSessionCart();
};

/*
 * Creates a new table object and returns it.
 *
 * @param string $name The name of the table to create.
 *
 * @return \ClientFence\Table The new table object that was just created.
 */
$di['table'] = $di->protect(function ($name) use ($di) {
    $tools = new ClientFence\Tools();
    $tools->setDi($di);
    $table = $tools->getTable($name);
    $table->setDi($di);

    return $table;
});

/*
 * @param void
 *
 * @return \ClientFence\Mod\Servicelicense\Server
 */
$di['license_server'] = function () use ($di) {
    $server = new ClientFence\Mod\Servicelicense\Server();
    $server->setDi($di);

    return $server;
};

/*
 * @param void
 *
 * @return \ClientFence\GeoIP\Reader
 */
$di['geoip'] = function () use ($di) {
    $reader = new ClientFence\GeoIP\Reader();
    $reader->setDi($di);

    return $reader;
};

/*
 * @param void
 *
 * @return \ClientFence\PasswordManager
 */
$di['password'] = fn (): ClientFence\PasswordManager => new ClientFence\PasswordManager();

/*
 * Creates a new \ClientFence\Translate object and sets the specified text domain, locale, and other options.
 *
 * @param string $textDomain The text domain to create the translation object with.
 *
 * @return \ClientFence\Translate The new translation object that was just created.
 */
$di['translate'] = $di->protect(function ($textDomain = '') use ($di) {
    $tr = new \ClientFence\Translate();

    if (!empty($textDomain)) {
        $tr->setDomain($textDomain);
    }

    $locale = ClientFence\i18n::getActiveLocale($di['request'], true, $di['cookie_queue']);

    $tr->setLocale($locale);
    $tr->setup();

    return $tr;
});

$di['csv_response_factory'] = function () use ($di): ClientFence\Http\CsvResponseFactory {
    $database = $di->offsetGet('db');
    if (!$database instanceof \ClientFence\Database) {
        throw new RuntimeException('Database service must resolve to a \ClientFence\Database instance');
    }

    return new ClientFence\Http\CsvResponseFactory($database);
};

$di['twig_factory'] = fn (): ClientFence\Twig\TwigFactory => new ClientFence\Twig\TwigFactory($di);

return $di;
