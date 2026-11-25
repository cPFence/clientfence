<?php

declare(strict_types=1);

use ClientFence\Site;
use Symfony\Component\HttpFoundation\Request;

use function Tests\Helpers\container;

/** A test module's service that answers site() with `$answer($host)` and remembers the hosts it was asked. */
function siteProvider(callable $answer): object
{
    return new class($answer) {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private readonly mixed $answer)
        {
        }

        public function site(string $host): mixed
        {
            $this->asked[] = $host;

            return ($this->answer)($host);
        }
    };
}

/**
 * A container whose switched-on modules are `$providers` (they declare "sites": true) and `$others` (they do not),
 * name => service.
 */
function siteDi(array $providers, array $others = []): Pimple\Container
{
    $di = container();
    $modules = $providers + $others;
    ksort($modules);
    $extension = Mockery::mock();
    $extension->shouldReceive('getCoreAndActiveModules')->andReturn(array_keys($modules));
    $di['mod_service'] = $di->protect(fn (string $name): object => $name === 'extension' ? $extension : $modules[$name]);
    $di['mod'] = $di->protect(function (string $name) use ($providers): object {
        $mod = Mockery::mock(ClientFence\Module::class);
        $mod->shouldReceive('hasManifest')->andReturn(true);
        $mod->shouldReceive('getManifest')->andReturn(['id' => $name] + (isset($providers[$name]) ? ['sites' => true] : []));
        $mod->shouldReceive('cannotRun')->andReturn(null);

        return $mod;
    });

    return $di;
}

function shopSite(string $key = 'shop'): Site
{
    return new Site('https://shop.example.test/', 'shop.example.test', $key);
}

/** shopSite() as the core keeps it: its key names the module that answered. */
function servedShop(string $module = 'shop', string $key = 'shop'): Site
{
    return shopSite($module . '/' . $key);
}

test('the primary site is the portal URL: in the console, on the portal host and on a host no module serves', function (): void {
    $shop = siteProvider(fn (string $host): ?Site => $host === 'shop.example.test' ? shopSite() : null);
    $di = siteDi(['shop' => $shop]);
    $malformed = Request::create(SYSTEM_URL);
    $malformed->headers->set('HOST', 'bad host!');
    $primary = Site::primary();

    expect([$primary->url, $primary->host, $primary->key])->toBe([SYSTEM_URL, parse_url(SYSTEM_URL, PHP_URL_HOST), null])
        ->and(Site::current(null))->toEqual($primary)
        ->and(Site::current(container()))->toEqual($primary)
        ->and(Site::ofRequest(null))->toEqual($primary)
        ->and(Site::ofRequest(container()))->toEqual($primary)
        ->and(Site::resolve($di, null))->toEqual($primary)
        ->and(Site::resolve($di, Request::create(SYSTEM_URL . 'order')))->toEqual($primary)
        ->and(Site::resolve($di, $malformed))->toEqual($primary)
        ->and($shop->asked)->toBe([])
        ->and(Site::resolve($di, Request::create('https://unknown.example.test/')))->toEqual($primary)
        ->and($shop->asked)->toBe(['unknown.example.test'])
        ->and($di['logger']->calls)->toBe([]);
});

test('a switched-on module that declares "sites" serves its host; a module that does not declare it is not asked', function (): void {
    $other = siteProvider(fn (): never => throw new LogicException('asked'));
    $di = siteDi(['shop' => siteProvider(fn (string $host): ?Site => $host === 'shop.example.test' ? shopSite() : null)], ['client' => $other]);

    expect(Site::resolve($di, Request::create('https://SHOP.example.test/cart')))->toEqual(servedShop())
        ->and(Site::resolve($di, Request::create('http://shop.example.test/')))->toEqual(servedShop())
        ->and($other->asked)->toBe([])
        ->and($di['logger']->calls)->toBe([]);
});

test('a switched-on module with a broken manifest is left out; the other modules still answer', function (): void {
    $di = siteDi(['shop' => siteProvider(fn (string $host): ?Site => $host === 'shop.example.test' ? shopSite() : null)], ['broken' => siteProvider(fn (): never => throw new LogicException('asked'))]);
    $mod = $di['mod'];
    $di['mod'] = $di->protect(function (string $name) use ($mod): object {
        if ($name !== 'broken') {
            return $mod($name);
        }
        $broken = Mockery::mock(ClientFence\Module::class);
        $broken->shouldReceive('hasManifest')->andReturn(true);
        $broken->shouldReceive('cannotRun')->andReturn(null);
        $broken->shouldReceive('getManifest')->andThrow(new ClientFence\InformationException('Invalid manifest file for the :mod module', [':mod' => $name]));

        return $broken;
    });

    expect(Site::resolve($di, Request::create('https://www.example.test/')))->toEqual(Site::primary())
        ->and(Site::resolve($di, Request::create('https://shop.example.test/')))->toEqual(servedShop());
});

test('a module that fails to load is skipped, even with the unavailable exception: only site() can say a host is unavailable', function (): void {
    $di = siteDi(['alpha' => siteProvider(fn (): never => throw new LogicException('asked')), 'beta' => siteProvider(fn (): Site => shopSite('beta'))]);
    $load = $di['mod_service'];
    $di['mod_service'] = $di->protect(fn (string $name): object => $name === 'alpha' ? throw new ClientFence\SiteUnavailableException('cannot load') : $load($name));

    expect(Site::resolve($di, Request::create('https://shop.example.test/'))->key)->toBe('beta/beta');
});

test('the first module by name that serves the host wins', function (): void {
    $gamma = siteProvider(fn (): Site => shopSite('gamma'));
    $di = siteDi(['gamma' => $gamma, 'beta' => siteProvider(fn (): Site => shopSite('beta')), 'alpha' => siteProvider(fn (): ?Site => null)]);

    expect(Site::resolve($di, Request::create('https://shop.example.test/'))->key)->toBe('beta/beta')
        ->and($gamma->asked)->toBe([]);
});

test('a module that fails or answers with a site that is not on the host is skipped, with a warning', function (Closure $answer, string $why): void {
    $di = siteDi(['alpha' => siteProvider($answer), 'beta' => siteProvider(fn (): Site => shopSite('beta'))]);

    expect(Site::resolve($di, Request::create('https://shop.example.test/'))->key)->toBe('beta/beta')
        ->and($di['logger']->calls)->toHaveCount(1)
        ->and($di['logger']->calls[0]['method'])->toBe('warning')
        ->and($di['logger']->calls[0]['params'][0])->toBe('The alpha module could not answer for the host shop.example.test: ' . $why);
})->with([
    'it throws' => [fn (): never => throw new RuntimeException('the database is down'), 'the database is down'],
    'another host' => [fn (): Site => new Site('https://other.example.test/', 'other.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a URL on another host' => [fn (): Site => new Site('https://other.example.test/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a URL on another port' => [fn (): Site => new Site('https://shop.example.test:8443/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'not http(s)' => [fn (): Site => new Site('ftp://shop.example.test/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'no trailing slash' => [fn (): Site => new Site('https://shop.example.test', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'no key' => [fn (): Site => new Site('https://shop.example.test/', 'shop.example.test'), 'it answered with a site that is not on that host'],
    'not a site' => [fn (): string => 'https://shop.example.test/', 'it answered with a site that is not on that host'],
    'a fragment in the URL' => [fn (): Site => new Site('https://shop.example.test/#preview/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a query in the URL' => [fn (): Site => new Site('https://shop.example.test/?a=/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a backslash before the host' => [fn (): Site => new Site('https://other.example.test\\@shop.example.test/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'user info before the host' => [fn (): Site => new Site('https://other.example.test@shop.example.test/', 'shop.example.test', 'x'), 'it answered with a site that is not on that host'],
    'a key over 50 characters' => [fn (): Site => shopSite(str_repeat('k', 51)), 'it answered with a site that is not on that host'],
    'a key with an upper-case letter' => [fn (): Site => shopSite('Shop'), 'it answered with a site that is not on that host'],
    'a key with a slash' => [fn (): Site => shopSite('a/b'), 'it answered with a site that is not on that host'],
    'a key with a space' => [fn (): Site => shopSite('my shop'), 'it answered with a site that is not on that host'],
    'a key ending in a line break' => [fn (): Site => shopSite("shop\n"), 'it answered with a site that is not on that host'],
]);

test('the origin is the lower-case scheme and the host, without the default port, for any host name', function (): void {
    $di = siteDi(['shop' => siteProvider(fn (): Site => new Site('HTTPS://SHOP.example.test/', 'shop.example.test', 'shop'))]);

    expect(Site::resolve($di, Request::create('https://shop.example.test/'))->origin())->toBe('https://shop.example.test')
        ->and((new Site('https://bücher.example/', 'bücher.example', 'x'))->origin())->toBe('https://bücher.example')
        ->and((new Site('http://shop.example.test:8080/', 'shop.example.test:8080', 'x'))->origin())->toBe('http://shop.example.test:8080');
});

test('without the module list (the database is down) a request keeps the primary site', function (): void {
    $di = siteDi([]);
    $extension = Mockery::mock();
    $extension->shouldReceive('getCoreAndActiveModules')->andThrow(new RuntimeException('the database is down'));
    $di['mod_service'] = $di->protect(fn (): object => $extension);

    expect(Site::resolve($di, Request::create('https://www.example.test/invoice/payment-return')))->toEqual(Site::primary());
});

test('$di[\'site\'] is worked out once, on the web only, and links are built on its base', function (): void {
    $source = (string) file_get_contents(PATH_ROOT . '/di.php');
    $block = static function (string $start, string $end) use ($source): string {
        $at = strpos($source, $start);

        return substr($source, $at, strpos($source, $end, $at) + strlen($end) - $at);
    };
    $siteService = str_replace('Environment::', '\ClientFence\Environment::', $block("\$di['site'] =", ";\n"));
    $urlService = $block("\$di['url'] =", "\n};");
    $shop = siteProvider(fn (): Site => shopSite());

    // The web: the suite itself runs in the console.
    $di = siteDi(['shop' => $shop]);
    $di['request'] = Request::create('https://shop.example.test/invoice');
    eval(str_replace('\ClientFence\Environment::isCLI()', 'false', $siteService));
    eval($urlService);
    $tools = new ClientFence\Tools();
    $tools->setDi($di);

    expect($di['site'])->toEqual(servedShop())
        ->and($di['site'])->toBe($di['site'])
        ->and($shop->asked)->toBe(['shop.example.test'])
        ->and($di['url']->link('invoice/abc', ['status' => 'ok']))->toBe('https://shop.example.test/invoice/abc?status=ok')
        ->and($tools->url('/order'))->toBe('https://shop.example.test/order');

    // The console (cron, the command line): the primary site.
    $di = siteDi(['shop' => $shop]);
    $di['request'] = Request::create('https://shop.example.test/invoice');
    eval($siteService);
    eval($urlService);

    expect($di['site'])->toEqual(Site::primary())
        ->and($di['url']->link('order'))->toBe(SYSTEM_URL . 'order');
});

test('the site the portal works for is, for now, the site the browser is on', function (): void {
    $di = container();
    $di['site'] = servedShop();

    expect(Site::ofRequest($di))->toBe($di['site'])
        ->and(Site::current($di))->toBe($di['site']);
});

test('a module that cannot serve its host now gets that request a 503, never the primary site; the next module is not asked', function (): void {
    $why = new ClientFence\SiteUnavailableException('the site is switched off');
    $later = siteProvider(fn (): ?Site => null);
    $di = siteDi(['alpha' => siteProvider(fn (string $host): ?Site => $host === 'shop.example.test' ? throw $why : null), 'beta' => $later]);
    $answer = static function (string $url) use ($di): ?Symfony\Component\HttpFoundation\Response {
        $request = Request::create($url);
        unset($di['site']);
        $di['site'] = static fn (): Site => Site::resolve($di, $request);

        return Site::unavailable($di, $request);
    };

    expect(fn () => Site::resolve($di, Request::create('https://shop.example.test/')))->toThrow(ClientFence\SiteUnavailableException::class, 'the site is switched off')
        ->and($later->asked)->toBe([])
        ->and($di['logger']->calls)->toHaveCount(1)
        ->and($di['logger']->calls[0]['method'])->toBe('warning')
        ->and($di['logger']->calls[0]['params'][0])->toBe('The alpha module cannot serve the host shop.example.test now: the site is switched off');

    $page = $answer('https://shop.example.test/order');
    $api = $answer('https://shop.example.test/api/client/profile/get');
    $viaIndex = $answer('https://shop.example.test/index.php?_url=/api/guest/system/company');

    expect($page?->getStatusCode())->toBe(503)
        ->and($page?->headers->get('Content-Type'))->toBe('text/html; charset=utf-8')
        ->and($page?->getContent())->toContain('This site is not available right now.')
        ->and($page?->getContent())->not->toContain(Site::primary()->host)
        ->and([$api?->getStatusCode(), json_decode((string) $api?->getContent(), true)])->toBe([503, ['result' => null, 'error' => ['message' => 'This site is not available right now. Please try again later.', 'code' => 503]]])
        ->and($viaIndex?->getStatusCode())->toBe(503)
        ->and(json_decode((string) $viaIndex?->getContent(), true)['error']['code'] ?? null)->toBe(503)
        // The other hosts keep their site: the primary one, and one no module serves.
        ->and($answer(SYSTEM_URL . 'order'))->toBeNull()
        ->and($answer('https://www.example.test/'))->toBeNull()
        ->and($di['site'])->toEqual(Site::primary());
});

/** A site module's service with these sites, by its own key: site() answers for their hosts, siteByKey() for their keys. */
function keyedSites(Site ...$sites): object
{
    return new class($sites) {
        /** @param list<Site> $sites */
        public function __construct(private readonly array $sites)
        {
        }

        public function site(string $host): ?Site
        {
            foreach ($this->sites as $site) {
                if ($site->host === $host) {
                    return $site;
                }
            }

            return null;
        }

        public function siteByKey(string $key): ?Site
        {
            foreach ($this->sites as $site) {
                if ($site->key === $key) {
                    return $site;
                }
            }

            return null;
        }
    };
}

/** Sites B and C of the module "shop" (keys "shop/b", "shop/c"), on a database with a site column on every record table. */
function recordSiteDi(): Pimple\Container
{
    $di = siteDi(['shop' => keyedSites(new Site('https://b.example.test/', 'b.example.test', 'b'), new Site('https://c.example.test/', 'c.example.test', 'c'))]);
    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach (['client', 'client_order', 'invoice', 'invoice_quote', 'support_ticket', 'email_queue'] as $table) {
        $db->executeStatement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, client_id INTEGER, site TEXT)");
    }
    $di['dbal'] = $db;
    $di['request_cache'] = new ArrayObject();
    $url = new ClientFence\Url();
    $url->setDi($di);
    $di['url'] = $url;

    return $di;
}

function siteB(): Site
{
    return new Site('https://b.example.test/', 'b.example.test', 'shop/b');
}

function siteC(): Site
{
    return new Site('https://c.example.test/', 'c.example.test', 'shop/c');
}

/** A Doctrine entity manager on $di's database with the site listener, as EntityManagerFactory::create($di) has it. */
function siteEntityManager(Pimple\Container $di): Doctrine\ORM\EntityManager
{
    $config = Doctrine\ORM\ORMSetup::createAttributeMetadataConfig(array_map(static fn (string $m): string => PATH_MODS . '/' . $m . '/Entity', ['Client', 'Order', 'Support', 'Email']), true);
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('ClientFence\\Tests\\SiteProxies');
    $em = new Doctrine\ORM\EntityManager($di['dbal'], $config);
    $em->getEventManager()->addEventListener([Doctrine\ORM\Events::postPersist, Doctrine\ORM\Events::postUpdate, Doctrine\ORM\Events::postRemove, Doctrine\ORM\Events::postFlush], new ClientFence\Doctrine\SiteStamp($di));
    $classes = [ClientFence\Mod\Client\Entity\Client::class, ClientFence\Mod\Order\Entity\Order::class, ClientFence\Mod\Support\Entity\SupportTicket::class, ClientFence\Mod\Support\Entity\Helpdesk::class, ClientFence\Mod\Email\Entity\QueuedEmail::class];
    $metadata = array_map($em->getClassMetadata(...), $classes);
    $metadata[0]->fieldMappings['gender']['columnDefinition'] = null;
    $metadata[0]->fieldMappings['gender']['type'] = 'string';
    foreach (['client', 'client_order', 'support_ticket', 'email_queue'] as $table) {
        $di['dbal']->executeStatement("DROP TABLE {$table}");
    }
    (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema($metadata);
    foreach (['client', 'client_order', 'support_ticket', 'email_queue'] as $table) {
        $di['dbal']->executeStatement("ALTER TABLE {$table} ADD COLUMN site TEXT DEFAULT NULL");
    }

    return $em;
}

/** ClientFence\Database on $di's database: a new bean gets the next id, an old one is updated. */
function siteDatabase(Pimple\Container $di): ClientFence\Database
{
    $orm = new class($di['dbal']) {
        public function __construct(private readonly Doctrine\DBAL\Connection $db)
        {
        }

        public function store(RedBeanPHP\OODBBean $bean): int
        {
            if ($bean->id) {
                $this->db->update($bean->getMeta('type'), ['client_id' => $bean->client_id], ['id' => $bean->id]);

                return (int) $bean->id;
            }
            $this->db->insert($bean->getMeta('type'), ['client_id' => $bean->client_id]);

            return $bean->id = (int) $this->db->lastInsertId();
        }
    };
    $database = new ClientFence\Database();
    $database->setDi($di);
    $database->setDataMapper($orm);

    return $database;
}

function siteBean(string $type, int $clientId): RedBeanPHP\OODBBean
{
    $bean = new RedBeanPHP\OODBBean();
    $bean->initializeForDispense($type);
    $bean->client_id = $clientId;

    return $bean;
}

test('without a site module no record gets a site: nothing is read or written, and links stay on the primary site', function (): void {
    $di = siteDi([]);
    $db = Mockery::mock(Doctrine\DBAL\Connection::class);
    $db->shouldNotReceive('fetchOne', 'update', 'executeStatement', 'insert');
    $di['dbal'] = $db;
    $url = new ClientFence\Url();
    $url->setDi($di);
    $di['url'] = $url;
    $orm = Mockery::mock();
    $orm->allows('store')->andReturn(5);
    $database = new ClientFence\Database();
    $database->setDi($di);
    $database->setDataMapper($orm);

    foreach (Site::RECORDS + ['queue' => 'email_queue'] as $table) {
        Site::stamp($di, $table, 5);
    }
    $link = Site::within($di, Site::forRecord($di, 'invoice', 5), fn (): string => $di['url']->link('invoice/abc'));
    $di['site'] = $request = siteB();
    $on = Site::on($di, 'order', 6, fn (): array => [Site::current($di), $database->store(siteBean('invoice', 7))]);
    unset($di['site']);

    expect($database->store(siteBean('invoice', 7)))->toBe(5)
        ->and($on)->toBe([$request, 5])
        ->and([Site::active($di), Site::storedKey($di, 'order', 6)])->toBe([false, null])
        ->and(Site::forRecord($di, 'client', 7))->toEqual(Site::primary())
        ->and(Site::byKey($di, null))->toEqual(Site::primary())
        ->and($link)->toBe(SYSTEM_URL . 'invoice/abc')
        ->and($di['url']->link('invoice/abc'))->toBe(SYSTEM_URL . 'invoice/abc')
        ->and($di['logger']->calls)->toBe([]);
});

test('without a site module the switched-on modules are read once, not per request, and again after an extension changes or a failed read', function (): void {
    $cache = new Symfony\Component\Cache\Adapter\ArrayAdapter();
    $reads = 0;
    $fail = false;
    $request = function () use ($cache, &$reads, &$fail): Pimple\Container {
        $di = siteDi([]);
        $extension = Mockery::mock();
        $extension->allows('getCoreAndActiveModules')->andReturnUsing(function () use (&$reads, &$fail): array {
            ++$reads;

            return $fail ? throw new RuntimeException('database down') : ['client', 'invoice'];
        });
        $di['mod_service'] = $di->protect(fn (): object => $extension);
        $di['cache'] = $cache;
        $di['request_cache'] = new ArrayObject();
        // Nothing is read or written for the records.
        $di['dbal'] = Mockery::mock(Doctrine\DBAL\Connection::class);

        return $di;
    };
    $work = static function (Pimple\Container $di): void {
        Site::stamp($di, 'invoice', 5);
        Site::stamp($di, 'client', 7);
        Site::forRecord($di, 'order', 6);
        expect($di['logger']->calls)->toBe([]);
    };
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $changed = static function (Pimple\Container $di, string $event) use ($em): void {
        $listener = new ClientFence\Doctrine\SiteStamp($di);
        $listener->{$event}(new Doctrine\ORM\Event\PostUpdateEventArgs(new ClientFence\Mod\Extension\Entity\Extension(), $em));
        $listener->postFlush(new Doctrine\ORM\Event\PostFlushEventArgs($em));
    };

    $work($request());
    $work($di = $request());
    $once = $reads;
    // A module is upgraded (or activated): the next request reads the list again.
    $changed($di, 'postUpdate');
    $work($request());
    $afterUpdate = $reads;
    // Deactivated (its row removed), with the database down for the next read: that answer is not kept.
    $changed($di, 'postRemove');
    $fail = true;
    $work($request());
    $fail = false;
    $work($request());
    $work($request());

    expect([$once, $afterUpdate, $reads])->toBe([1, 2, 4]);
});

test('a client who signs up on site B, and what is made for them later by cron or staff, are on B; a request on another site wins', function (): void {
    $di = recordSiteDi();
    $db = $di['dbal'];
    $em = siteEntityManager($di);
    $database = siteDatabase($di);
    $site = static fn (string $table, int $id): mixed => $db->fetchOne("SELECT site FROM {$table} WHERE id = ?", [$id]);

    // The sign-up, in a request on B.
    $di['site'] = siteB();
    $client = (new ClientFence\Mod\Client\Entity\Client())->setEmail('b@example.test');
    $em->persist($client);
    $em->flush();
    $clientId = (int) $client->getId();

    // Cron and the console (and staff on the primary site): the primary site, so each record takes its client's.
    unset($di['site']);
    $order = new ClientFence\Mod\Order\Entity\Order();
    $order->setClientId($clientId);
    $ticket = (new ClientFence\Mod\Support\Entity\SupportTicket())->setClientId($clientId)->setSubject('From email piping');
    $em->persist($order);
    $em->persist($ticket);
    $em->flush();
    $renewal = (int) $database->store(siteBean('invoice', $clientId));
    $creditNote = (int) $database->store(siteBean('invoice', $clientId));
    $db->insert('invoice_quote', ['client_id' => $clientId]);
    Site::stamp($di, 'invoice_quote', $quote = (int) $db->lastInsertId());
    // A queued email keeps the site it was built on: none outside within().
    $queued = static fn (): ClientFence\Mod\Email\Entity\QueuedEmail => (new ClientFence\Mod\Email\Entity\QueuedEmail())->setRecipient('b@example.test')->setSender('billing@example.test')->setSubject('Invoice')->setContent('Body');
    $em->persist($plain = $queued());
    $em->flush();
    Site::within($di, siteB(), static function () use ($em, $queued, &$built): void {
        $em->persist($built = $queued());
        $em->flush();
    });

    expect([$site('client', $clientId), $site('client_order', (int) $order->getId()), $site('support_ticket', (int) $ticket->getId()), $site('invoice', $renewal), $site('invoice', $creditNote), $site('invoice_quote', $quote)])
        ->toBe(array_fill(0, 6, 'shop/b'))
        ->and([$site('email_queue', (int) $built->getId()), $site('email_queue', (int) $plain->getId())])->toBe(['shop/b', null]);

    // An order the client places in a request on C is on C; storing an invoice again never changes its site.
    $di['site'] = siteC();
    $other = new ClientFence\Mod\Order\Entity\Order();
    $other->setClientId($clientId);
    $em->persist($other);
    $em->flush();
    $again = siteBean('invoice', $clientId);
    $again->id = $renewal;
    $database->store($again);
    // A client with no site, on the primary site: nothing is written.
    unset($di['site']);
    $db->insert('client', ['id' => 50]);
    $primaryOrder = new ClientFence\Mod\Order\Entity\Order();
    $primaryOrder->setClientId(50);
    $em->persist($primaryOrder);
    $em->flush();

    expect([$site('client_order', (int) $other->getId()), $site('invoice', $renewal), $site('client_order', (int) $primaryOrder->getId())])->toBe(['shop/c', 'shop/b', null])
        ->and($di['logger']->calls)->toBe([]);
});

test('a record\'s site is its own key, else its client\'s; a key the module does not serve now gives the primary site, logged once per request', function (): void {
    $di = recordSiteDi();
    $db = $di['dbal'];
    $db->insert('client', ['id' => 7, 'site' => 'shop/b']);
    $db->insert('client', ['id' => 8, 'site' => 'gone/b']);
    $db->insert('client', ['id' => 9, 'site' => 'shop/zz']);
    $db->insert('invoice', ['id' => 5, 'client_id' => 7]);
    $db->insert('invoice', ['id' => 6, 'client_id' => 7, 'site' => 'shop/c']);
    $db->insert('invoice_quote', ['id' => 3]);
    $db->insert('support_ticket', ['id' => 4, 'client_id' => 8]);

    expect(Site::forRecord($di, 'invoice', 5))->toEqual(siteB())
        ->and(Site::forRecord($di, 'invoice', 6))->toEqual(siteC())
        ->and(Site::forRecord($di, 'client', 7))->toEqual(siteB())
        ->and(Site::forRecord($di, 'quote', 3))->toEqual(Site::primary())
        ->and(Site::forRecord($di, 'invoice', 404))->toEqual(Site::primary())
        ->and(Site::forRecord($di, 'payment', 5))->toEqual(Site::primary())
        // A switched-off or unknown module, and a key the module does not answer.
        ->and(Site::forRecord($di, 'ticket', 4))->toEqual(Site::primary())
        ->and(Site::forRecord($di, 'client', 8))->toEqual(Site::primary())
        ->and(Site::forRecord($di, 'client', 9))->toEqual(Site::primary())
        ->and(array_column(array_column($di['logger']->calls, 'params'), 0))->toBe([
            'The site gone/b is not available, the primary site is used: its module is not switched on or serves no sites',
            'The site shop/zz is not available, the primary site is used: its module answered with no valid site',
        ]);
});

test('a stored key gives the primary site when its module cannot run or answers with a site it does not serve', function (Closure $answer, ?string $reason): void {
    $di = siteDi(['shop' => new class($answer) {
        public function __construct(private readonly Closure $answer)
        {
        }

        public function siteByKey(string $key): mixed
        {
            return ($this->answer)($key);
        }
    }]);
    $mod = $di['mod'];
    $di['mod'] = $di->protect(function (string $name) use ($mod, $reason): object {
        $module = $mod($name);
        if ($reason !== null) {
            $module = Mockery::mock(ClientFence\Module::class);
            $module->allows('hasManifest')->andReturn(true);
            $module->allows('getManifest')->andReturn(['sites' => true]);
            $module->allows('cannotRun')->andReturn([$reason, []]);
        }

        return $module;
    });

    expect(Site::byKey($di, 'shop/b'))->toEqual(Site::primary())
        ->and($di['logger']->calls)->toHaveCount(1);
})->with([
    'it cannot run here' => [fn (): Site => new Site('https://b.example.test/', 'b.example.test', 'b'), 'needs another PHP'],
    'another key' => [fn (): Site => new Site('https://b.example.test/', 'b.example.test', 'c'), null],
    'a host not in its URL' => [fn (): Site => new Site('https://b.example.test/', 'other.example.test', 'b'), null],
    'not http(s)' => [fn (): Site => new Site('ftp://b.example.test/', 'b.example.test', 'b'), null],
    'it throws' => [fn (): never => throw new RuntimeException('down'), null],
    'no hook' => [fn (): string => 'https://b.example.test/', null],
]);

test('within() names the site links are built for, only for the call: restored after it, also when it throws, and nested; the request\'s site never changes', function (): void {
    $di = recordSiteDi();
    $di['site'] = siteB();
    $seen = [];

    $result = Site::within($di, siteC(), function () use ($di, &$seen): string {
        $seen[] = [Site::current($di)->key, Site::ofRequest($di)->key, $di['url']->link('a')];
        $seen[] = Site::within($di, Site::primary(), fn (): array => [Site::current($di)->key, Site::ofRequest($di)->key, $di['url']->link('a')]);
        $seen[] = [Site::current($di)->key, Site::ofRequest($di)->key, $di['url']->link('a')];

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($seen)->toBe([['shop/c', 'shop/b', 'https://c.example.test/a'], [null, 'shop/b', SYSTEM_URL . 'a'], ['shop/c', 'shop/b', 'https://c.example.test/a']])
        ->and(fn () => Site::within($di, siteC(), fn (): never => throw new RuntimeException('failed')))->toThrow(RuntimeException::class, 'failed')
        ->and(Site::current($di))->toEqual(siteB())
        ->and($di['url']->link('a'))->toBe('https://b.example.test/a')
        ->and(isset($di['site_within']))->toBeFalse();
});

test('on() works on the site of a record, also from cron, and what is made inside takes that record\'s stored key, also while its module is switched off; restored after it and nested', function (): void {
    $di = recordSiteDi();
    $db = $di['dbal'];
    $database = siteDatabase($di);
    $site = static fn (int $id): mixed => $db->fetchOne('SELECT site FROM invoice WHERE id = ?', [$id]);
    // Client #7 is on B; order #6 on C, #8 has no site of its own, #9 on a site whose module is switched off.
    $db->insert('client', ['id' => 7, 'site' => 'shop/b']);
    $db->insert('client_order', ['id' => 6, 'client_id' => 7, 'site' => 'shop/c']);
    $db->insert('client_order', ['id' => 8, 'client_id' => 7]);
    $db->insert('client_order', ['id' => 9, 'client_id' => 7, 'site' => 'gone/c']);
    $make = static fn (): array => [Site::current($di)->key, Site::ofRequest($di)->key, $di['url']->link('a'), (int) $database->store(siteBean('invoice', 7))];

    // Cron: the primary site.
    $cron = Site::on($di, 'order', 6, $make);
    // A request on B, with on() and within() nested in on().
    $di['site'] = siteB();
    $nested = Site::on($di, 'order', 9, fn (): array => [$make(), Site::on($di, 'order', 8, $make), Site::within($di, siteC(), $make), $make()]);
    $failed = fn () => Site::on($di, 'order', 6, fn (): never => throw new RuntimeException('failed'));

    expect($cron)->toBe(['shop/c', null, 'https://c.example.test/a', 1])
        ->and($site(1))->toBe('shop/c')
        // The switched-off module's site is the primary one, but its key is kept.
        ->and($nested[0])->toBe([null, 'shop/b', SYSTEM_URL . 'a', 2])
        ->and($site(2))->toBe('gone/c')
        // An order with no site of its own: its client's.
        ->and($nested[1])->toBe(['shop/b', 'shop/b', 'https://b.example.test/a', 3])
        ->and($site(3))->toBe('shop/b')
        // within() names another site: the current-site rule.
        ->and($nested[2])->toBe(['shop/c', 'shop/b', 'https://c.example.test/a', 4])
        ->and($site(4))->toBe('shop/c')
        ->and($nested[3][3])->toBe(5)
        ->and($site(5))->toBe('gone/c')
        ->and($failed)->toThrow(RuntimeException::class, 'failed')
        ->and(Site::current($di))->toEqual(siteB())
        ->and(isset($di['site_within']) || isset($di['site_on']))->toBeFalse()
        ->and($make()[3])->toBe(6)
        ->and($site(6))->toBe('shop/b');
});

/**
 * The email service on $di (recordSiteDi()) with any template enabled; each email sent is added to the returned list as
 * [address, body (a link the template builds), the site it is queued with].
 */
function siteEmailService(Pimple\Container $di): array
{
    $templates = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templates->allows('findOneByActionCode')->andReturnUsing(fn (string $code): ClientFence\Mod\Email\Entity\EmailTemplate => (new ClientFence\Mod\Email\Entity\EmailTemplate($code))->setEnabled(true)->setIsCustom(true)->setSubject('Invoice')->setContent('Body'));
    $admins = Mockery::mock(ClientFence\Mod\Staff\Repository\AdminRepository::class);
    $admins->allows('find')->andReturn(Tests\Helpers\createEntity(ClientFence\Mod\Staff\Entity\Admin::class, ['id' => 1, 'email' => 'staff@example.test', 'name' => 'Staff', 'signature' => '', 'timezone' => 'UTC']));
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $em->allows('getConnection')->andReturn($di['dbal']);
    $em->allows('getRepository')->andReturnUsing(fn (string $class): object => match ($class) {
        ClientFence\Mod\Email\Entity\EmailTemplate::class => $templates,
        ClientFence\Mod\Staff\Entity\Admin::class => $admins,
        default => Mockery::mock(str_replace('\\Entity\\', '\\Repository\\', $class) . 'Repository')->shouldIgnoreMissing(),
    });
    $di['em'] = $em;
    $di['crypt'] = Mockery::mock(ClientFence\Crypt::class)->shouldIgnoreMissing();
    // The body as the template renders it: with a link.
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->allows('renderEmailTplString')->andReturnUsing(fn (): string => $di['url']->link('invoice/abc'));
    $system->allows('renderEmailSubjectString')->andReturn('Invoice');
    $system->allows('getParamValue')->andReturn('billing@example.test');
    $sites = $di['mod_service'];
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = ''): object => strtolower($name) === 'system' ? $system : $sites($name));
    $modules = $di['mod'];
    $emailModule = Mockery::mock(ClientFence\Module::class);
    $emailModule->allows('getConfig')->andReturn([]);
    $di['mod'] = $di->protect(fn (string $name): object => $name === 'email' ? $emailModule : $modules($name));
    $sent = new ArrayObject();
    $service = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $service->allows('sendMail')->andReturnUsing(function ($to, $from, $subject, $content) use ($di, $sent): bool {
        // The queue row takes this site (Site::stamp()).
        $sent[] = [$to, $content, Site::current($di)->key];

        return true;
    });
    $service->setDi($di);

    return [$service, $sent];
}

test('a client\'s email is built on the site of its record, also from cron, and queued there; a staff email on the primary site', function (): void {
    $di = recordSiteDi();
    $di['dbal']->insert('client', ['id' => 7, 'site' => 'shop/b']);
    $di['dbal']->insert('invoice', ['id' => 5, 'client_id' => 7]);
    [$service, $sent] = siteEmailService($di);
    $email = ['code' => 'mod_invoice_created', 'to' => 'b@example.test', 'invoice' => ['id' => 5]];

    // In a request on the primary site (staff send it), from cron (the console), and for staff in a request on B.
    $di['site'] = Site::primary();
    $service->sendTemplate($email);
    unset($di['site']);
    $service->sendTemplate($email);
    $di['site'] = siteB();
    $service->sendTemplate(['code' => 'mod_invoice_created', 'to_admin' => 1, 'invoice' => ['id' => 5]]);

    expect($sent->getArrayCopy())->toBe([
        ['b@example.test', 'https://b.example.test/invoice/abc', 'shop/b'],
        ['b@example.test', 'https://b.example.test/invoice/abc', 'shop/b'],
        ['staff@example.test', SYSTEM_URL . 'invoice/abc', null],
    ])->and(Site::current($di))->toEqual(siteB());
});

test('the email\'s record is found in every shape its callers give it; a staff template to a plain address is on the primary site', function (): void {
    $di = recordSiteDi();
    $di['dbal']->insert('client', ['id' => 7, 'site' => 'shop/b']);
    $di['dbal']->insert('client_order', ['id' => 6, 'client_id' => 7, 'site' => 'shop/c']);
    // A guest's ticket, opened on B.
    $di['dbal']->insert('support_ticket', ['id' => 9, 'site' => 'shop/b']);
    [$service, $sent] = siteEmailService($di);
    $di['site'] = Site::primary();

    foreach ([
        // A flat id, as Order\Upgrades::cancelSubscriptions() gives it (there with to_client: the order's site wins).
        ['code' => 'mod_order_subscription_stopped', 'to' => 'a@example.test', 'order_id' => 6],
        // Order\Holds: a list of orders.
        ['code' => 'mod_order_held', 'to' => 'a@example.test', 'orders' => [['id' => 6, 'title' => 'Hosting']]],
        // A guest's own ticket, and the "not imported" reply to its sender (Support\MailImport).
        ['code' => 'mod_support_ticket_open', 'to' => 'guest@example.test', 'ticket' => ['id' => 9]],
        ['code' => 'mod_support_mail_rejected', 'to' => 'guest@example.test', 'ticket_id' => 9],
        // Staff templates to a plain address: a help desk's email, the test email.
        ['code' => 'mod_support_helpdesk_ticket_open', 'to' => 'desk@example.test', 'ticket' => ['id' => 9]],
        ['code' => 'mod_email_test', 'to' => 'staff@example.test', 'order_id' => 6],
    ] as $email) {
        $service->sendTemplate($email);
    }

    // A sign-in PIN is rendered without sendTemplate() (Clientfence\SignInSecurity): staff and a client signing in on B.
    $di['site'] = siteB();
    $pins = [$service->renderTemplate('mod_staff_clientfence_signin_pin', [])[1], $service->renderTemplate('mod_client_clientfence_signin_pin', [])[1]];

    expect(array_column($sent->getArrayCopy(), 2))->toBe(['shop/c', 'shop/c', 'shop/b', 'shop/b', null, null])
        ->and($pins)->toBe([SYSTEM_URL . 'invoice/abc', 'https://b.example.test/invoice/abc']);
});

test('the invoice PDF is built on the invoice\'s site', function (): void {
    $di = recordSiteDi();
    $di['dbal']->insert('client', ['id' => 7, 'site' => 'shop/b']);
    $di['dbal']->insert('invoice', ['id' => 5, 'client_id' => 7]);
    // The company's addresses, read while the PDF is built.
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->allows('getCompany')->andReturnUsing(fn (): never => throw new RuntimeException('built on ' . $di['url']->link('')));
    $sites = $di['mod_service'];
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = ''): object => strtolower($name) === 'system' ? $system : $sites($name));
    $service = new ClientFence\Mod\Invoice\Service();
    $service->setDi($di);
    $invoice = new Model_Invoice();
    $invoice->loadBean(new Tests\Helpers\DummyBean());
    $invoice->id = 5;

    expect(fn () => (new ReflectionMethod(ClientFence\Mod\Invoice\Service::class, 'renderInvoicePdfContent'))->invoke($service, $invoice, ['client' => ['lang' => '']], 'en_US'))
        ->toThrow(RuntimeException::class, 'built on https://b.example.test/');
});

test('a cron run started through another site\'s host builds its links on the primary site', function (): void {
    $di = recordSiteDi();
    $di['site'] = siteB();
    $seen = null;
    $finalization = Mockery::mock();
    $finalization->allows('isRequired')->andReturnUsing(function () use ($di, &$seen): bool {
        $seen = $di['url']->link('invoice/abc');

        return true;
    });
    $di['update_finalization'] = $finalization;
    $service = new ClientFence\Mod\Cron\Service();
    $service->setDi($di);

    expect(fn () => $service->runCrons())->toThrow(ClientFence\InformationException::class)
        ->and($seen)->toBe(SYSTEM_URL . 'invoice/abc')
        ->and($di['url']->link('invoice/abc'))->toBe('https://b.example.test/invoice/abc');
});

test('links made for an email before it is built: a client\'s on the client\'s site, a staff member\'s on the primary site', function (): void {
    $di = recordSiteDi();
    $db = $di['dbal'];
    $db->executeStatement('ALTER TABLE client ADD COLUMN email TEXT');
    $db->executeStatement('ALTER TABLE client ADD COLUMN email_approved INTEGER');
    $db->executeStatement('CREATE TABLE extension_meta (id INTEGER PRIMARY KEY, extension TEXT, client_id INTEGER, rel_type TEXT, meta_key TEXT, meta_value TEXT, created_at TEXT, updated_at TEXT)');
    foreach (['subject TEXT', 'status TEXT', 'priority INTEGER', 'support_helpdesk_id INTEGER'] as $column) {
        $db->executeStatement('ALTER TABLE support_ticket ADD COLUMN ' . $column);
    }
    $db->executeStatement('CREATE TABLE support_helpdesk (id INTEGER PRIMARY KEY, name TEXT)');
    $db->insert('client', ['id' => 7, 'site' => 'shop/b', 'email' => 'b@example.test']);
    $db->insert('support_ticket', ['id' => 4, 'client_id' => 7, 'subject' => 'Help', 'status' => 'open', 'priority' => 50, 'support_helpdesk_id' => 1]);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class)->shouldIgnoreMissing();
    $em->allows('getConnection')->andReturn($db);
    $em->allows('getRepository')->andReturnUsing(fn (string $class): object => Mockery::mock(str_replace('\\Entity\\', '\\Repository\\', $class) . 'Repository')->shouldIgnoreMissing());
    $di['em'] = $em;
    $tools = new ClientFence\Tools();
    $tools->setDi($di);
    unset($di['tools']);
    $di['tools'] = $tools;
    $mail = new class {
        public array $sent = [];

        public function sendTemplate(array $email): bool
        {
            $this->sent[] = $email;

            return true;
        }
    };
    // The staff email layout, as rendered.
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->allows('renderEmailTplString')->andReturnUsing(fn (): string => $di['url']->link(''));
    $sites = $di['mod_service'];
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = ''): object => match (strtolower($name)) {
        'email' => $mail,
        'system' => $system,
        default => $sites($name),
    });
    $modules = $di['mod'];
    $emailModule = Mockery::mock(ClientFence\Module::class);
    $emailModule->allows('getConfig')->andReturn([]);
    $di['mod'] = $di->protect(fn (string $name): object => $name === 'email' ? $emailModule : $modules($name));
    $emailService = new ClientFence\Mod\Email\Service();
    $emailService->setDi($di);

    // Staff on the primary site send the client of B the confirmation link again.
    $di['site'] = Site::primary();
    $client = new ClientFence\Mod\Client\Service();
    $client->setDi($di);
    $confirm = $client->generateEmailConfirmationLink(7);
    // The ticket of B's client is assigned in a request on B; a staff email's layout is built there too.
    $di['site'] = siteB();
    (new ReflectionMethod(ClientFence\Mod\Support\TicketAssign::class, 'notify'))->invoke(null, $di, 4, 1, null);

    expect($confirm)->toStartWith('https://b.example.test/client/confirm-email/')
        ->and($mail->sent[0]['ticket']['url'])->toStartWith(SYSTEM_URL)->toEndWith('/support/ticket/4')
        ->and($emailService->renderHtmlInLayout('<p>Backup</p>', 'staff'))->toBe(SYSTEM_URL)
        ->and($emailService->renderHtmlInLayout('<p>Hello</p>'))->toBe('https://b.example.test/');
});
