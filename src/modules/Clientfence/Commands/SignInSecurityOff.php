<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Commands;

use ClientFence\Mod\Clientfence\SignInSecurity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * For a site whose staff are locked out by sign-in security (SignInSecurity.php): turns the email
 * PIN and the staff country rule off (or only one of them with --pin / --countries).
 */
#[AsCommand(
    name: 'clientfence:signin-security-off',
    description: 'Turns sign-in security off: the email PIN and the staff country rule',
    hidden: false
)]
class SignInSecurityOff extends Command implements \ClientFence\InjectionAwareInterface
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

    protected function configure(): void
    {
        $this->addOption('pin', null, InputOption::VALUE_NONE, 'Only turn the email PIN off');
        $this->addOption('countries', null, InputOption::VALUE_NONE, 'Only turn the staff country rule off');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pin = (bool) $input->getOption('pin');
        $countries = (bool) $input->getOption('countries');
        if (!$pin && !$countries) {
            $pin = $countries = true;
        }
        $settings = SignInSecurity::turnOff($this->di, $pin, $countries);
        $this->di['logger']->warning('Sign-in security turned off from the console (email PIN: %s, country rule: %s)', $pin ? 'off' : 'kept', $countries ? 'off' : 'kept');
        $output->writeln(sprintf('Email PIN: %s. Staff countries: %s.', $settings['pin'], $settings['countries_on'] ? implode(', ', $settings['countries']) : 'off'));

        return Command::SUCCESS;
    }
}
