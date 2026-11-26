<?php

declare(strict_types=1);

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Health;
use ClientFence\Mod\Extension\Entity\Extension;
use ClientFence\Mod\Extension\Repository\ExtensionRepository;
use ClientFence\Mod\Extension\Service as ExtensionService;
use ClientFence\Module;
use ClientFence\ServiceTypes;

use function Tests\Helpers\container;

// Modules written to disk for a test: a Service.php that records when PHP loads it, with hooks, staff permissions and a
// registrar adapter (also recording when it is loaded).
function requirementsModule(string $id, array $manifest, string $methods = ''): void
{
    $dir = PATH_MODS . '/' . ucfirst($id);
    mkdir($dir . '/Registrar/Adapter', 0o755, true);
    mkdir($dir . '/Payment/Adapter', 0o755, true);
    file_put_contents($dir . '/manifest.json', json_encode(['version' => '1.0.0', ...$manifest]));
    $class = ucfirst($id);
    file_put_contents($dir . "/Registrar/Adapter/{$class}Reg.php", "<?php\n\$GLOBALS['requirements_loaded'][] = '{$id}-registrar';\n");
    file_put_contents($dir . "/Payment/Adapter/{$class}Pay.php", "<?php\n\$GLOBALS['requirements_loaded'][] = '{$id}-gateway';\n");
    file_put_contents($dir . '/Service.php', <<<PHP
        <?php
        namespace ClientFence\\Mod\\{$class};
        \$GLOBALS['requirements_loaded'][] = '{$id}';
        class Service
        {
            public static function onBeforeAdminCronRun(\\ClientFence\\Event \$event): void
            {
                \$GLOBALS['requirements_ran'][] = '{$id}';
            }

            public static function onBeforeClientSignUp(\\ClientFence\\Event \$event): void
            {
                \$GLOBALS['requirements_ran'][] = '{$id}';
            }

            public static function onBeforeAdminUninstallExtension(\\ClientFence\\Event \$event): void
            {
                if (!empty(\$GLOBALS['requirements_veto'])) {
                    throw new \\ClientFence\\InformationException('{$id} refuses');
                }
            }

            public function getModulePermissions(): array
            {
                return ['view' => []];
            }
            {$methods}
        }
        PHP);
}

/** A container with the real module loader and an extension table holding `$installed` switched on. */
function requirementsContainer(array $installed): Pimple\Container
{
    $di = container();
    $di['request_cache'] = new ArrayObject();
    $di['mod'] = $di->protect(function (string $name) use ($di): Module {
        $mod = new Module($name);
        $mod->setDi($di);

        return $mod;
    });
    $repository = Mockery::mock(ExtensionRepository::class);
    $repository->shouldReceive('findInstalledNamesByType')->with(Extension::TYPE_MOD)->andReturn($installed);
    $repository->shouldReceive('existsActiveByTypeAndName')->andReturnUsing(fn (string $type, string $name): bool => in_array($name, $installed, true));
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->with(Extension::class)->andReturn($repository);
    $em->shouldReceive('flush');
    $em->shouldReceive('getRepository')->with(ClientFence\Mod\Extension\Entity\ExtensionMeta::class)->andReturn(Mockery::mock(ClientFence\Mod\Extension\Repository\ExtensionMetaRepository::class));
    $di['em'] = $em;
    $extension = new ExtensionService();
    $extension->setDi($di);
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class)->shouldIgnoreMissing();
    $di['mod_service'] = $di->protect(fn (string $name): object => match (strtolower($name)) {
        'extension' => $extension,
        'staff' => $staff,
        default => $di['mod']($name)->getService(),
    });

    return $di;
}

beforeEach(function (): void {
    $GLOBALS['requirements_loaded'] = [];
    $GLOBALS['requirements_ran'] = [];
    requirementsModule('zzreqok', ['module_api' => 1, 'requires' => ['extensions' => ['json'], 'php' => '8.0']]);
    requirementsModule('zzreqext', ['requires' => ['extensions' => ['zz_no_such_extension']]]);
    requirementsModule('zzreqapi', ['module_api' => 2]);
    requirementsModule('zzreqphp', ['requires' => ['php' => '99.0']]);
});

afterEach(function (): void {
    (new Symfony\Component\Filesystem\Filesystem())->remove([...glob(PATH_MODS . '/Zzreq*') ?: [], ...glob(PATH_MODS . '/Servicezzreq*') ?: []]);
});

test('a switched-on module this PHP cannot run stays active, but its code is never loaded and what needs it fails', function (): void {
    $di = requirementsContainer(['zzreqapi', 'zzreqext', 'zzreqok', 'zzreqphp']);
    $extension = $di['mod_service']('extension');
    // Each one listens to a cron event and to a sign-up veto event.
    $rows = [];
    foreach (['zzreqapi', 'zzreqext', 'zzreqok', 'zzreqphp'] as $i => $id) {
        $rows[] = ['id' => $i * 2 + 1, 'rel_id' => $id, 'meta_value' => 'onBeforeAdminCronRun'];
        $rows[] = ['id' => $i * 2 + 2, 'rel_id' => $id, 'meta_value' => 'onBeforeClientSignUp'];
    }
    $di['db'] = Mockery::mock(ClientFence\Database::class);
    $di['db']->shouldReceive('getAll')->andReturn($rows);
    $di['logger'] = $logger = new Tests\Helpers\TestLogger();
    $events = new ClientFence\EventManager();
    $events->setDi($di);
    $permissions = array_map($extension->getSpecificModulePermissions(...), ['zzreqapi', 'zzreqext', 'zzreqok', 'zzreqphp']);

    $events->fire(['event' => 'onBeforeAdminCronRun', 'isolated' => true]);
    $warnings = array_filter($logger->calls, fn (array $call): bool => $call['method'] === 'warning');

    expect($extension->getCoreAndActiveModules())->toContain('zzreqok', 'zzreqext', 'zzreqapi', 'zzreqphp', 'invoice')
        ->and($extension->getInstalledMods())->toBe(['zzreqapi', 'zzreqext', 'zzreqok', 'zzreqphp'])
        ->and($extension->isExtensionActive('mod', 'zzreqext'))->toBeTrue()
        // Its permissions are not read from its code: it declares none, so its settings stay refused.
        ->and($permissions)->toBe([[], [], ['view' => []], []])
        // The cron event (isolated) runs the module that can run and logs the three that cannot.
        ->and($GLOBALS['requirements_ran'])->toBe(['zzreqok'])
        ->and($warnings)->toHaveCount(3)
        // A veto event of a module that cannot run refuses, with the reason, instead of being skipped.
        ->and(fn () => $events->fire(['event' => 'onBeforeClientSignUp']))->toThrow(InformationException::class, 'is written for module API level 2')
        // Its gateway, registrar and server-manager files are not included.
        ->and(array_keys($extension->getModuleAdapterFiles('Registrar/Adapter')))->toBe(['ZzreqokReg'])
        ->and(fn () => $di['mod']('zzreqext')->getService())->toThrow(InformationException::class, 'needs the PHP extension zz_no_such_extension, which this PHP (' . PHP_SAPI . ') does not load')
        ->and(fn () => $di['mod']('zzreqapi')->getService())->toThrow(InformationException::class, 'is written for module API level 2, which this ClientFence does not run (levels 1 to 1)')
        ->and(fn () => $di['mod']('zzreqphp')->getService())->toThrow(InformationException::class, 'needs PHP 99.0 or later')
        ->and(array_unique($GLOBALS['requirements_loaded']))->toBe(['zzreqok']);
});

test('a hook call on a paid invoice whose listener cannot run is not taken as done', function (): void {
    $di = requirementsContainer(['zzreqext']);
    $stored = [];
    $di['db'] = Mockery::mock(ClientFence\Database::class);
    $di['db']->shouldReceive('getAll')->andReturn([['id' => 1, 'rel_id' => 'zzreqext', 'meta_value' => 'onBeforeClientSignUp']]);
    $di['db']->shouldReceive('store')->andReturnUsing(function (Model_InvoiceItem $item) use (&$stored): int {
        $stored[] = [$item->status, $item->attempts];

        return 1;
    });
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['events_manager'] = new ClientFence\EventManager();
    $di['events_manager']->setDi($di);
    $item = new Model_InvoiceItem();
    $item->loadBean(new Tests\Helpers\DummyBean());
    $item->type = Model_InvoiceItem::TYPE_HOOK_CALL;
    $item->task = 'onBeforeClientSignUp';
    $item->rel_id = '{}';
    $item->status = Model_InvoiceItem::STATUS_PENDING_PAYMENT;
    $service = new ClientFence\Mod\Invoice\ServiceInvoiceItem();
    $service->setDi($di);

    $service->executeTask($item);

    // One failed attempt recorded; still pending, so the next run tries again.
    expect($stored)->toBe([[Model_InvoiceItem::STATUS_PENDING_PAYMENT, 1]])
        ->and($GLOBALS['requirements_loaded'])->toBe([]);
});

test('a registrar whose module cannot run is listed as unavailable, not loaded', function (): void {
    $di = requirementsContainer(['zzreqext']);
    $registrar = new Model_TldRegistrar();
    $registrar->loadBean(new Tests\Helpers\DummyBean());
    $registrar->id = 7;
    $registrar->name = 'QA registrar';
    $registrar->registrar = 'ZzreqextReg';
    $registrar->test_mode = 0;
    $di['db'] = Mockery::mock(ClientFence\Database::class);
    $di['db']->shouldReceive('find')->andReturn([$registrar]);
    $di['pager'] = Mockery::mock(ClientFence\Pagination::class);
    $di['pager']->shouldReceive('getPaginatedResultSet')->andReturn(['list' => []]);
    $domains = new ClientFence\Mod\Servicedomain\Service();
    $domains->setDi($di);
    $api = new ClientFence\Mod\Servicedomain\Api\Admin();
    $api->setDi($di);
    $api->setService($domains);

    $list = $api->registrar_get_list([])['list'];

    expect($list)->toBe([['id' => 7, 'title' => 'QA registrar', 'test_mode' => 0, 'unavailable' => 'Domain registrar ZzreqextReg was not found']])
        ->and($GLOBALS['requirements_loaded'])->toBe([]);
});

test('while a site module cannot run, another host gets the 503 page, never the primary site', function (): void {
    requirementsModule('zzreqsite', ['sites' => true, 'requires' => ['extensions' => ['zz_no_such_extension']]]);
    $di = requirementsContainer(['zzreqsite']);
    $di['logger'] = $logger = new Tests\Helpers\TestLogger();
    $request = Symfony\Component\HttpFoundation\Request::create('http://brand-b.example/');

    expect(fn () => ClientFence\Site::resolve($di, $request))->toThrow(ClientFence\SiteUnavailableException::class)
        ->and(ClientFence\Site::resolve($di, Symfony\Component\HttpFoundation\Request::create(SYSTEM_URL))->key)->toBeNull()
        ->and(ClientFence\Site::resolve(requirementsContainer(['zzreqok']), $request)->key)->toBeNull()
        ->and(array_values(array_filter($logger->calls, fn (array $call): bool => $call['method'] === 'warning'))[0]['params'][0] ?? '')
        ->toContain('The zzreqsite module cannot answer for the host brand-b.example')
        ->and($GLOBALS['requirements_loaded'])->toBe([]);
});

test('a present but malformed module_api or requires means the module cannot run', function (array $manifest, string $key): void {
    requirementsModule('zzreqbadshape', $manifest);
    $mod = new Module('zzreqbadshape');
    $mod->setDi(container());

    $reason = $mod->cannotRun();

    expect($reason === null ? null : strtr($reason[0], $reason[1]))->toBe("The manifest.json of the zzreqbadshape module is not valid: {$key} has the wrong type.");
})->with([
    'requires as a list' => [['requires' => [['extensions' => ['json']]]], 'requires'],
    'module_api null' => [['module_api' => null], 'module_api'],
    'module_api as text' => [['module_api' => '1'], 'module_api'],
    'extensions as text' => [['requires' => ['extensions' => 'json']], 'requires.extensions'],
    'php as a range' => [['requires' => ['php' => '>=8.4']], 'requires.php'],
]);

test('a module whose files need a newer ClientFence or FOSSBilling base does not run until the core is updated', function (string $key, string $product, string $have): void {
    requirementsModule('zzreqnewer', [$key => '99.0.0']);
    $di = requirementsContainer(['zzreqnewer']);

    expect(fn () => $di['mod']('zzreqnewer')->getService())->toThrow(InformationException::class, "The zzreqnewer module needs {$product} 99.0.0 or later; this site has {$have}.")
        ->and($GLOBALS['requirements_loaded'])->toBe([]);
})->with([
    'ClientFence' => ['minimum_clientfence_version', 'ClientFence', ClientFence\Version::CLIENTFENCE],
    'FOSSBilling base' => ['minimum_fossbilling_version', 'FOSSBilling', ClientFence\Version::FOSSBILLING],
]);

test('a module whose folder was deleted takes the old path: no reason, its listeners logged and skipped, no Health row', function (): void {
    $di = requirementsContainer(['zzreqgone']);
    $di['db'] = Mockery::mock(ClientFence\Database::class);
    $di['db']->shouldReceive('getAll')->andReturn([['id' => 1, 'rel_id' => 'zzreqgone', 'meta_value' => 'onBeforeClientSignUp']]);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $events = new ClientFence\EventManager();
    $events->setDi($di);

    $events->fire(['event' => 'onBeforeClientSignUp']);

    expect($di['mod']('zzreqgone')->cannotRun())->toBeNull()
        ->and(fn () => $di['mod']('zzreqgone')->getService())->toThrow(ClientFence\Exception::class, 'does not have a service class')
        ->and(iterator_to_array((new ReflectionMethod(Health::class, 'modules'))->invoke(null, $di), false))->toBe([]);
});

test('a module that cannot run blocks neither every action nor its own deactivation and uninstall, but other modules\' vetoes stand', function (): void {
    $di = requirementsContainer(['zzreqext', 'zzreqok']);
    $events = ['onEveryEvent', 'onBeforeAdminDeactivateExtension', 'onAfterAdminDeactivateExtension', 'onBeforeAdminUninstallExtension', 'onAfterAdminUninstallExtension'];
    $rows = array_map(fn (string $event): array => ['id' => 1, 'rel_id' => 'zzreqext', 'meta_value' => $event], $events);
    $rows[] = ['id' => 2, 'rel_id' => 'zzreqok', 'meta_value' => 'onBeforeAdminUninstallExtension'];
    $di['db'] = Mockery::mock(ClientFence\Database::class);
    $di['db']->shouldReceive('getAll')->andReturn($rows);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['events_manager'] = new ClientFence\EventManager();
    $di['events_manager']->setDi($di);
    $ext = new Extension();
    $ext->setName('zzreqext');
    $ext->setType(Extension::TYPE_MOD);
    (new ReflectionProperty($ext, 'id'))->setValue($ext, 5);
    $repository = Mockery::mock(ExtensionRepository::class);
    $repository->shouldReceive('findOneByTypeAndName')->andReturn($ext);
    // Before deactivation the event names the extension row.
    $repository->shouldReceive('find')->with(5)->andReturn($ext);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getRepository')->andReturn($repository);
    $di['em'] = $em;
    $service = Mockery::mock(ExtensionService::class);
    $service->shouldReceive('getExtensionRepository')->andReturn($repository);
    $service->shouldReceive('deactivate')->once()->andReturn(true);
    $service->shouldReceive('uninstall')->once()->andReturn(true);
    $api = new ClientFence\Mod\Extension\Api\Admin();
    $api->setDi($di);
    $api->setService($service);

    // Its listener of every event is skipped (logged), as before: any other action still works.
    $di['events_manager']->fire(['event' => 'onAfterClientLogin']);

    expect($api->deactivate(['type' => 'mod', 'id' => 'zzreqext']))->toBeTrue()
        ->and($api->uninstall(['type' => 'mod', 'id' => 'zzreqext']))->toBeTrue()
        // Removing another module: its listener still refuses, with why it cannot run.
        ->and(fn () => $di['events_manager']->fire(['event' => 'onBeforeAdminUninstallExtension', 'params' => ['type' => 'mod', 'id' => 'news']]))
        ->toThrow(InformationException::class, 'zz_no_such_extension')
        ->and($GLOBALS['requirements_loaded'])->not->toContain('zzreqext');

    // A healthy module's veto on removing the broken one still refuses.
    $GLOBALS['requirements_veto'] = true;
    expect(fn () => $api->uninstall(['type' => 'mod', 'id' => 'zzreqext']))->toThrow(InformationException::class, 'zzreqok refuses');
    unset($GLOBALS['requirements_veto']);
});

test('a module folder without a readable manifest does not run its code', function (): void {
    unlink(PATH_MODS . '/Zzreqok/manifest.json');
    $di = requirementsContainer(['zzreqok']);

    expect($di['mod']('zzreqok')->cannotRun()[0] ?? null)->toBe('The manifest.json of the :mod module cannot be read.')
        ->and(fn () => $di['mod']('zzreqok')->getService())->toThrow(InformationException::class, 'The manifest.json of the zzreqok module cannot be read.')
        ->and($GLOBALS['requirements_loaded'])->toBe([]);
});

test('staff can open and turn off a gateway whose module cannot run, without loading its adapter', function (): void {
    $di = requirementsContainer(['zzreqext']);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $gateway = new Model_PayGateway();
    $gateway->loadBean(new Tests\Helpers\DummyBean());
    $gateway->id = 3;
    $gateway->gateway = 'ZzreqextPay';
    $gateway->name = 'QA pay';
    $gateway->enabled = 1;
    $gateway->allow_single = 1;
    $gateway->allow_recurrent = 0;
    $gateway->test_mode = 0;
    $gateway->accepted_currencies = '["USD"]';
    $di['db'] = Mockery::mock(ClientFence\Database::class);
    $di['db']->shouldReceive('getExistingModelById')->andReturn($gateway);
    $di['db']->shouldReceive('store')->once();
    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchOne')->andReturn(0);
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->shouldReceive('getConnection')->andReturn($connection);
    $di['em'] = $em;
    $gateways = new ClientFence\Mod\Invoice\ServicePayGateway();
    $gateways->setDi($di);
    $base = $di['mod_service'];
    $di['mod_service'] = $di->protect(fn (string $name, string $sub = ''): object => strtolower($name . $sub) === 'invoicepaygateway' ? $gateways : $base($name));
    $api = new ClientFence\Mod\Invoice\Api\Admin();
    $api->setDi($di);
    $api->setIdentity(new Model_Admin());

    $shown = $api->gateway_get(['id' => 3]);
    $api->gateway_update(['id' => 3, 'title' => 'QA pay', 'enabled' => 0, 'allow_single' => 1, 'allow_recurrent' => 0, 'test_mode' => 0]);

    expect($shown['unavailable'])->toBe('Payment gateway ZzreqextPay was not found')
        ->and([$shown['enabled'], $shown['allow_single'], $shown['form'], $shown['config']])->toBe([1, 1, [], []])
        ->and((bool) $gateway->enabled)->toBeFalse()
        ->and($GLOBALS['requirements_loaded'])->toBe([]);
});

test('an upgrade connects the new version\'s listeners on the PHP that ran it', function (): void {
    $di = requirementsContainer(['zzreqok']);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $hook = Mockery::mock(ClientFence\Mod\Hook\Service::class);
    $hook->shouldReceive('batchConnect')->once()->with('zzreqok')->andReturn(true);
    $extension = $di['mod_service']('extension');
    $di['mod_service'] = $di->protect(fn (string $name): object => match (strtolower($name)) {
        'extension' => $extension,
        'hook' => $hook,
        default => $di['mod']($name)->getService(),
    });
    $installed = new Extension();
    $installed->setName('zzreqok');
    $installed->setType(Extension::TYPE_MOD);
    $installed->setVersion('0.9.0');

    expect($extension->upgradeModule($installed))->toBeTrue()
        ->and($installed->getVersion())->toBe('1.0.0');
});

test('a product type whose module cannot run offers no plan change', function (): void {
    requirementsModule('servicezzreqa', ['service' => ['changeable' => true]]);
    requirementsModule('servicezzreqb', ['service' => ['changeable' => true], 'requires' => ['extensions' => ['zz_no_such_extension']]]);
    $di = requirementsContainer(['servicezzreqa', 'servicezzreqb']);

    expect(ServiceTypes::changeable($di))->toContain('zzreqa')->not->toContain('zzreqb')
        ->and(ServiceTypes::declared($di, 'zzreqb'))->not->toHaveKey('changeable');
});

test('activation and upgrade refuse a module of another API level or with a requirement this PHP does not meet', function (string $id, string $refused): void {
    $di = requirementsContainer([]);
    $di['logger'] = new Tests\Helpers\TestLogger();
    $extension = $di['mod_service']('extension');
    $installed = new Extension();
    $installed->setName($id);
    $installed->setType(Extension::TYPE_MOD);
    $installed->setVersion('0.9.0');

    $deactivated = new Extension();
    $deactivated->setName($id);
    $deactivated->setType(Extension::TYPE_MOD);
    $deactivated->setStatus(Extension::STATUS_DEACTIVATED);

    expect(fn () => $extension->activate($deactivated))->toThrow(InformationException::class, $refused)
        ->and($deactivated->getStatus())->toBe(Extension::STATUS_DEACTIVATED)
        ->and(fn () => $extension->upgradeModule($installed))->toThrow(InformationException::class, $refused)
        ->and($installed->getVersion())->toBe('0.9.0')
        ->and($GLOBALS['requirements_loaded'])->toBe([]);
})->with([
    'API level 2' => ['zzreqapi', 'module API level 2'],
    'missing extension' => ['zzreqext', 'the PHP extension zz_no_such_extension'],
    'newer PHP' => ['zzreqphp', 'needs PHP 99.0 or later'],
]);

test('a switched-off module this PHP cannot run can still be uninstalled: its files go without loading its code', function (): void {
    $di = requirementsContainer([]);
    $di['logger'] = new Tests\Helpers\TestLogger();

    expect($di['mod_service']('extension')->uninstall('mod', 'zzreqapi'))->toBeTrue()
        ->and(is_dir(PATH_MODS . '/Zzreqapi'))->toBeFalse()
        ->and($GLOBALS['requirements_loaded'])->toBe([])
        ->and($di['logger']->calls[0]['params'][0] ?? null)->toBe('Uninstalled the %s module without its own cleanup: it cannot run on this PHP.');
});

test('System Health shows each switched-on module this PHP cannot run, and why', function (): void {
    $di = requirementsContainer(['zzreqext', 'zzreqok']);

    $rows = iterator_to_array((new ReflectionMethod(Health::class, 'modules'))->invoke(null, $di), false);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('mod_runs_zzreqext')
        ->and($rows[0]['state'])->toBe('bad')
        ->and(__trans($rows[0]['title'], $rows[0]['params']))->toBe('The zzreqext module cannot run')
        ->and(__trans($rows[0]['detail'], $rows[0]['params']))->toContain('needs the PHP extension zz_no_such_extension');
});

test('System Health adds the rows of each switched-on module under its own id; a throw is one bad row; a module that cannot run is not asked', function (): void {
    requirementsModule('zzreqrows', [], <<<'PHP'
        public function health(): array
        {
            return [
                ['id' => 'queue', 'state' => 'warn', 'title' => 'Queue of :name', 'detail' => 'Slow.', 'params' => [':name' => 'node 1']],
                ['id' => 'fw', 'state' => 'unknown', 'title' => 'Firewall', 'detail' => str_repeat('Up. ', 20000), 'url' => 'zzreqrows'],
            ];
        }
        PHP);
    requirementsModule('zzreqboom', [], 'public function health(): array { throw new \RuntimeException("API down token=abc (Authorization: Bearer ey.J9-x)"); }');
    requirementsModule('zzreqbroke', ['requires' => ['extensions' => ['zz_no_such_extension']]], 'public function health(): array { $GLOBALS["requirements_ran"][] = "health"; return []; }');
    $di = requirementsContainer(['zzreqboom', 'zzreqbroke', 'zzreqok', 'zzreqrows']);

    $rows = iterator_to_array((new ReflectionMethod(Health::class, 'modules'))->invoke(null, $di), false);

    expect(array_column($rows, 'state', 'id'))->toBe(['mod_zzreqboom:' => 'bad', 'mod_runs_zzreqbroke' => 'bad', 'mod_zzreqrows:queue' => 'warn', 'mod_zzreqrows:fw' => 'warn'])
        ->and(__trans($rows[0]['title'], $rows[0]['params']))->toBe('Health checks of the zzreqboom module')
        ->and(__trans($rows[0]['detail'], $rows[0]['params']))->toBe('The check could not run: API down token=*** (Authorization: ***')
        // The report is saved as one value: a module's long text is cut.
        ->and(mb_strlen($rows[3]['detail']))->toBe(1000)
        ->and(__trans($rows[2]['title'], $rows[2]['params']))->toBe('Queue of node 1')
        ->and([$rows[2]['group'], $rows[3]['url']])->toBe(['services', 'zzreqrows'])
        ->and($GLOBALS['requirements_ran'])->toBe([])
        ->and($GLOBALS['requirements_loaded'])->not->toContain('zzreqbroke');
});

test('the manifest check refuses a known key of the wrong type and names differing only in case, not an unknown key', function (array $manifest, ?string $problem): void {
    $found = ServiceTypes::invalid('example', $manifest);

    expect($found === null ? null : strtr($found[0], $found[1]))->toBe($problem);
})->with([
    'unknown key' => [['future_hook' => ['anything' => 1], 'service' => ['future' => 'x'], 'requires' => ['future' => 1]], null],
    'every known key well typed' => [['module_api' => 1, 'requires' => ['extensions' => ['sodium'], 'php' => '8.3'], 'sites' => true, 'migrations' => 'X', 'usage' => false,
        'client_calls' => ['a' => 'products', 'b' => ['products', 'invoices']], 'client_pages' => ['p' => null, 'q' => 'products'], 'store_calls' => ['guest' => ['m' => 'list']],
        'rate_limits' => ['example_ip' => ['policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 minute']],
        'service' => ['lifecycle' => 'built-in', 'entity' => 'E', 'single_unit' => true, 'order_config' => false, 'changeable' => true, 'import' => true, 'icon' => 'i',
            'import_fields' => [['key' => 'account_id', 'label' => 'Account ID', 'hint' => 'As shown in the panel.'], ['key' => 'region', 'label' => 'Region']]]], null],
    'module_api as text' => [['module_api' => '1'], 'The manifest.json of the example module is not valid: module_api has the wrong type.'],
    'php as a range' => [['requires' => ['php' => '>=8.4']], 'The manifest.json of the example module is not valid: requires.php has the wrong type.'],
    'requires as a list' => [['requires' => ['sodium']], 'The manifest.json of the example module is not valid: requires has the wrong type.'],
    'extension as text' => [['requires' => ['extensions' => 'sodium']], 'The manifest.json of the example module is not valid: requires.extensions has the wrong type.'],
    'client call permission as a number' => [['client_calls' => ['a' => 1]], 'The manifest.json of the example module is not valid: client_calls has the wrong type.'],
    'client calls as a list' => [['client_calls' => ['a']], 'The manifest.json of the example module is not valid: client_calls has the wrong type.'],
    'store call answer as a number' => [['store_calls' => ['guest' => ['m' => 1]]], 'The manifest.json of the example module is not valid: store_calls has the wrong type.'],
    'bad rate limit' => [['rate_limits' => ['example_ip' => ['policy' => 'fixed_window', 'limit' => 0, 'interval' => '1 minute']]], 'The manifest.json of the example module is not valid: rate_limits has the wrong type.'],
    'usage as text' => [['usage' => 'yes'], 'The manifest.json of the example module is not valid: usage has the wrong type.'],
    'sites as a number' => [['sites' => 1], 'The manifest.json of the example module is not valid: sites has the wrong type.'],
    'changeable as text' => [['service' => ['changeable' => 'true']], 'The manifest.json of the example module is not valid: service.changeable has the wrong type.'],
    'import as a number' => [['service' => ['import' => 1]], 'The manifest.json of the example module is not valid: service.import has the wrong type.'],
    'import fields as a map' => [['service' => ['import' => true, 'import_fields' => ['id' => ['key' => 'id', 'label' => 'ID']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import field without a key' => [['service' => ['import' => true, 'import_fields' => [['label' => 'ID']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import field key not a string' => [['service' => ['import' => true, 'import_fields' => [['key' => 7, 'label' => 'ID']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import field without a label' => [['service' => ['import' => true, 'import_fields' => [['key' => 'id']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import field key in capitals' => [['service' => ['import' => true, 'import_fields' => [['key' => 'Id', 'label' => 'ID']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import field key with a line break' => [['service' => ['import' => true, 'import_fields' => [['key' => "id\n", 'label' => 'ID']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import field hint as a number' => [['service' => ['import' => true, 'import_fields' => [['key' => 'id', 'label' => 'ID', 'hint' => 1]]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import field key of digits' => [['service' => ['import' => true, 'import_fields' => [['key' => '1', 'label' => 'ID']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import field key starting with _' => [['service' => ['import' => true, 'import_fields' => [['key' => '__proto__', 'label' => 'ID']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import field key twice' => [['service' => ['import' => true, 'import_fields' => [['key' => 'id', 'label' => 'ID'], ['key' => 'id', 'label' => 'Other']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'import fields without import' => [['service' => ['import_fields' => [['key' => 'id', 'label' => 'ID']]]], 'The manifest.json of the example module is not valid: service.import_fields has the wrong type.'],
    'client calls in two cases' => [['client_calls' => ['report' => 'products', 'Report' => 'invoices']], 'The manifest.json of the example module is not valid: client_calls has names that differ only in letter case.'],
    'pages in two cases' => [['client_pages' => ['meters' => null, 'Meters' => null]], 'The manifest.json of the example module is not valid: client_pages has names that differ only in letter case.'],
    'store roles in two cases' => [['store_calls' => ['guest' => ['m' => 'list'], 'Guest' => ['n' => 'list']]], 'The manifest.json of the example module is not valid: store_calls has names that differ only in letter case.'],
    'store calls in two cases' => [['store_calls' => ['guest' => ['offers' => 'list', 'Offers' => 'closed']]], 'The manifest.json of the example module is not valid: store_calls.guest has names that differ only in letter case.'],
]);

test('every shipped module manifest passes the manifest check', function (): void {
    $checked = 0;
    foreach (glob(PATH_MODS . '/*/manifest.json') ?: [] as $file) {
        if (str_starts_with(basename(dirname($file)), 'Zzreq')) {
            continue;
        }
        expect(ServiceTypes::invalid(basename(dirname($file)), json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)))->toBeNull();
        ++$checked;
    }

    expect($checked)->toBeGreaterThan(30);
});

test('activation refuses a manifest the check refuses', function (): void {
    $di = requirementsContainer([]);
    requirementsModule('zzreqbad', ['client_calls' => ['report' => 'products', 'REPORT' => 'products']]);

    $ext = new Extension();
    $ext->setName('zzreqbad');
    $ext->setType(Extension::TYPE_MOD);
    $ext->setStatus(Extension::STATUS_DEACTIVATED);

    expect(fn () => $di['mod_service']('extension')->activate($ext))->toThrow(InformationException::class, 'client_calls has names that differ only in letter case')
        ->and($GLOBALS['requirements_loaded'])->toBe([]);
});
