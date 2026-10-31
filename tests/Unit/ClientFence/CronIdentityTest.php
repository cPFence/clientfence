<?php

declare(strict_types=1);

test('web cron uses the cron admin without an admin session', function (): void {
    $admin = new Model_Admin();
    $staff = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staff->shouldReceive('getCronAdmin')->once()->andReturn($admin);
    $di = new Pimple\Container();
    $di['is_cron'] = true;
    $di['mod_service'] = $di->protect(fn (string $module): object => $staff);

    $source = file_get_contents(PATH_ROOT . '/di.php');
    $start = strpos($source, '$di[\'loggedin_admin\'] =');
    $end = strpos($source, "\n};", $start) + 3;
    // Force the web branch because the suite itself runs in the console.
    eval(str_replace('Environment::isCLI()', 'false', substr($source, $start, $end - $start)));

    expect($di['loggedin_admin'])->toBe($admin);
});
