<?php

declare(strict_types=1);

use ClientFence\Mod\System\Commands\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

test('system:version prints the ClientFence version, not the FOSSBilling base', function (): void {
    $tester = new CommandTester(new Version());
    expect($tester->execute([]))->toBe(Command::SUCCESS)
        ->and(trim($tester->getDisplay()))->toBe(ClientFence\Version::CLIENTFENCE);
});

test('system:releasenotes says when there are no newer release notes', function (array $info, int $code, string $shown): void {
    $updater = Mockery::mock(ClientFence\Update::class);
    $updater->shouldReceive('getLatestVersionInfo')->andReturn($info + ['version' => ClientFence\Version::CLIENTFENCE, 'release_notes' => '', 'last_check' => '2026-11-18 00:00:00', 'check_error' => null]);
    $di = new Pimple\Container();
    $di['updater'] = $updater;
    $command = new ClientFence\Mod\System\Commands\ReleaseNotes();
    $command->setDi($di);
    $tester = new CommandTester($command);

    expect($tester->execute([]))->toBe($code)
        ->and(trim($tester->getDisplay()))->toBe($shown);
})->with([
    'newer release' => [['version' => '99.0.0', 'release_notes' => '### Fixed'], Command::SUCCESS, '### Fixed'],
    'newer release without notes' => [['version' => '99.0.0'], Command::SUCCESS, 'ClientFence 99.0.0 has no release notes.'],
    'up to date' => [[], Command::SUCCESS, 'ClientFence ' . ClientFence\Version::CLIENTFENCE . ' is the latest release: there are no newer release notes.'],
    'check failed' => [['check_error' => 'timeout'], Command::FAILURE, 'The update check failed: timeout'],
    'never checked' => [['last_check' => null], Command::FAILURE, 'No update check has run yet: turn on the update check or run system:update --check.'],
]);
