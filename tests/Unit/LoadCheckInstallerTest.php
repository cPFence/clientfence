<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Stubs the global $filesystem used by checkInstaller() so the test never touches real
 * disk.
 */
function runCheckInstallerWithStubbedFilesystem(bool $expectRemoval): void
{
    $configPath = PATH_CONFIG;
    $installDir = Path::normalize('install');

    $mock = Mockery::mock(Filesystem::class);
    $mock->shouldReceive('exists')->with($configPath)->andReturn(true);
    $mock->shouldReceive('exists')->with($installDir)->andReturn(true);

    if ($expectRemoval) {
        $mock->shouldReceive('remove')->once()->with('install');
    } else {
        $mock->shouldNotReceive('remove');
    }

    $previousFilesystem = $GLOBALS['filesystem'] ?? null;
    $GLOBALS['filesystem'] = $mock;

    try {
        checkInstaller();
    } finally {
        $GLOBALS['filesystem'] = $previousFilesystem;
    }
}

test('checkInstaller deletes the install directory when APP_ENV is unset', function (): void {
    withAppEnv(null, fn () => runCheckInstallerWithStubbedFilesystem(expectRemoval: true));
});

test('checkInstaller deletes the install directory when APP_ENV holds an unrecognized value', function (): void {
    withAppEnv('staging', fn () => runCheckInstallerWithStubbedFilesystem(expectRemoval: true));
});

test('checkInstaller does not delete the install directory when APP_ENV is dev or test', function (): void {
    withAppEnv('dev', fn () => runCheckInstallerWithStubbedFilesystem(expectRemoval: false));
    withAppEnv('test', fn () => runCheckInstallerWithStubbedFilesystem(expectRemoval: false));
});

test('checkInstaller deletes the install directory when APP_ENV is explicitly prod', function (): void {
    withAppEnv('prod', fn () => runCheckInstallerWithStubbedFilesystem(expectRemoval: true));
});

test('an existing configuration blocks installation even in debug mode', function (): void {
    $source = file_get_contents(PATH_ROOT . '/install/install.php');
    preg_match_all('/^use [^;]+;/m', $source, $imports);
    eval(implode("\n", $imports[0]) . "\n" . substr($source, strpos($source, 'final class ClientFence_Installer')));
    $reflection = new ReflectionClass(ClientFence_Installer::class);
    $installer = $reflection->newInstanceWithoutConstructor();
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->with(PATH_CONFIG)->andReturnTrue();
    $reflection->getProperty('filesystem')->setValue($installer, $filesystem);
    $reflection->getProperty('isDebug')->setValue($installer, true);
    expect($installer->isAlreadyInstalled())->toBeTrue();
});
