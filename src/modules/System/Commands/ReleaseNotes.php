<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\System\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'system:releasenotes',
    // ClientFence core patch: the updater reads only the latest release's notes.
    description: 'Shows the release notes of the latest ClientFence release',
    hidden: false
)]
class ReleaseNotes extends Command implements \ClientFence\InjectionAwareInterface
{
    protected $di;

    public function setDi($di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ClientFence core patch: the feed gives notes only for a newer release; say so instead of printing an empty line.
        $info = $this->di['updater']->getLatestVersionInfo();
        if (!empty($info['check_error']) || $info['last_check'] === null) {
            $output->writeln('<error>' . \Symfony\Component\Console\Formatter\OutputFormatter::escape(!empty($info['check_error']) ? 'The update check failed: ' . $info['check_error'] : 'No update check has run yet: turn on the update check or run system:update --check.') . '</error>');

            return Command::FAILURE;
        }
        if ($info['version'] === \ClientFence\Version::CLIENTFENCE) {
            $output->writeln('ClientFence ' . \ClientFence\Version::CLIENTFENCE . ' is the latest release: there are no newer release notes.');
        } else {
            $output->writeln($info['release_notes'] !== '' ? $info['release_notes'] : 'ClientFence ' . $info['version'] . ' has no release notes.');
        }

        return Command::SUCCESS;
    }
}
