<?php

declare(strict_types=1);

/**
 * ClientFence setup wizard (Setup.php): the checks each step makes before it saves anything.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Setup;

/** A DI container whose extension service keeps module settings in memory. */
function setupTestDi(array $config = []): array
{
    $store = new ArrayObject($config);
    $extension = new class($store) {
        public function __construct(private ArrayObject $store)
        {
        }

        public function getConfig(string $ext): array
        {
            return $this->store[$ext] ?? [];
        }

        public function setConfig(array $data): bool
        {
            $this->store[$data['ext']] = $data;

            return true;
        }
    };
    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(fn (string $name) => $extension);

    return [$di, $store];
}

const SETUP_TEST_TAKEN = ['api', 'order', 'users', 'banklink', 'network-status', 'signup', 'login', 'public', 'install'];

test('the admin address is 4 to 40 lowercase letters, digits or dashes', function (): void {
    expect(Setup::validAdminPath('team-7k2', SETUP_TEST_TAKEN))->toBe('/team-7k2')
        ->and(Setup::validAdminPath('/Team-7K2/', SETUP_TEST_TAKEN))->toBe('/team-7k2')
        ->and(Setup::validAdminPath('/orders-desk', SETUP_TEST_TAKEN))->toBe('/orders-desk')
        ->and(fn () => Setup::validAdminPath('/abc', SETUP_TEST_TAKEN))->toThrow(InformationException::class)
        ->and(fn () => Setup::validAdminPath('/a_b-cd', SETUP_TEST_TAKEN))->toThrow(InformationException::class)
        ->and(fn () => Setup::validAdminPath('/-team', SETUP_TEST_TAKEN))->toThrow(InformationException::class)
        ->and(fn () => Setup::validAdminPath('/' . str_repeat('a', 41), SETUP_TEST_TAKEN))->toThrow(InformationException::class)
        ->and(fn () => Setup::validAdminPath('/team/7k2', SETUP_TEST_TAKEN))->toThrow(InformationException::class);
});

// The admin area takes every address that starts with its own (index.php), so an address that
// begins a client page would hide that page.
test('the admin address cannot be the usual one or begin a page of the site', function (string $path): void {
    expect(fn () => Setup::validAdminPath($path, SETUP_TEST_TAKEN))->toThrow(InformationException::class);
})->with(['/admin', '/order', '/user', '/users', '/bank', '/network', '/sign', '/logi', '/publ', '/install']);

test('the site\'s own pages, files and folders are taken', function (): void {
    $taken = Setup::takenPaths();

    expect($taken)->toContain('order', 'users', 'banklink', 'network-status', 'signup', 'login', 'api', 'public', 'themes', 'modules', 'cron', 'ipn');
});

test('email settings are checked before they are saved', function (array $data): void {
    [$di, $store] = setupTestDi();

    expect(fn () => Setup::saveEmail($di, $data))->toThrow(InformationException::class)
        ->and($store->getArrayCopy())->toBe([]);
})->with([
    'unknown mailer' => [['mailer' => 'mail', 'from_email' => 'billing@example.com']],
    'no sender' => [['mailer' => 'sendmail', 'from_email' => '']],
    'bad sender' => [['mailer' => 'sendmail', 'from_email' => 'billing']],
    'no SMTP server' => [['mailer' => 'smtp', 'from_email' => 'billing@example.com', 'smtp_port' => '587']],
    'bad SMTP server' => [['mailer' => 'smtp', 'from_email' => 'billing@example.com', 'smtp_host' => 'smtp.example.com/x', 'smtp_port' => '587']],
    'bad port' => [['mailer' => 'smtp', 'from_email' => 'billing@example.com', 'smtp_host' => 'smtp.example.com', 'smtp_port' => '70000']],
]);

test('SMTP settings are merged and an empty password keeps the saved one', function (): void {
    [$di, $store] = setupTestDi(['mod_email' => ['ext' => 'mod_email', 'mailer' => 'sendmail', 'smtp_password' => 'saved', 'log_enabled' => '1']]);

    Setup::saveEmail($di, ['mailer' => 'smtp', 'from_email' => 'billing@example.com', 'from_name' => 'Acme', 'smtp_host' => 'smtp.example.com', 'smtp_port' => '587', 'smtp_username' => 'acme', 'smtp_password' => '']);

    expect($store['mod_email'])->toMatchArray([
        'mailer' => 'smtp',
        'from_email' => 'billing@example.com',
        'from_name' => 'Acme',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => '587',
        'smtp_username' => 'acme',
        'smtp_password' => 'saved',
        'log_enabled' => '1',
    ]);
});

test('"keep" leaves a SendGrid or custom mailer as it is', function (): void {
    [$di, $store] = setupTestDi(['mod_email' => ['ext' => 'mod_email', 'mailer' => 'custom', 'custom_dsn' => 'smtp://mail:1025']]);

    Setup::saveEmail($di, ['mailer' => 'keep', 'from_email' => 'billing@example.com']);

    expect($store['mod_email'])->toMatchArray(['mailer' => 'custom', 'custom_dsn' => 'smtp://mail:1025', 'from_email' => 'billing@example.com']);
});

test('only a signed-in Super Administrator may run the wizard', function (): void {
    $di = new Pimple\Container();
    expect(Setup::allowed($di))->toBeFalse();

    $staff = new class {
        public function isSuperAdministrator(int $id): bool
        {
            return $id === 1;
        }
    };
    $di['mod_service'] = $di->protect(fn (string $name) => $staff);
    $di['loggedin_admin'] = (object) ['id' => 2];
    expect(Setup::allowed($di))->toBeFalse();

    $di['loggedin_admin'] = (object) ['id' => 1];
    expect(Setup::allowed($di))->toBeTrue();
});

test('the Store step keeps the store title, subtitle and opening cycle', function (): void {
    $di = new Pimple\Container();
    $di['mod'] = $di->protect(fn (string $name) => new class($name) {
        public function __construct(private string $name)
        {
        }

        public function getConfig(): array
        {
            return $this->name === 'order' ? ['store_title' => 'Plans', 'store_subtitle' => 'Pick one', 'store_cycle' => '1Y'] : [];
        }
    });
    $di['api_admin'] = new class {
        public array $saved = [];

        public function __call(string $method, array $args): bool
        {
            $this->saved = $args[0];

            return true;
        }
    };

    Setup::saveStore($di, ['store_mode' => 'public']);

    expect($di['api_admin']->saved)->toMatchArray(['store_mode' => 'public', 'store_title' => 'Plans', 'store_subtitle' => 'Pick one', 'store_cycle' => '1Y']);
});
