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
