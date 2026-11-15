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
 * PIN, the staff country and IP rules, the clients' required two-factor sign-in and passkeys off (or only the
 * selected --pin / --countries / --ips / --twofactor / --passkeys): a broken setup page must not lock every client out.
 */
#[AsCommand(
    name: 'clientfence:signin-security-off',
    description: 'Turns sign-in security off: the email PIN, the staff country and IP address rules, the required client two-factor sign-in and passkeys',
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
        $this->addOption('ips', null, InputOption::VALUE_NONE, 'Only turn the staff IP address rule off');
        $this->addOption('twofactor', null, InputOption::VALUE_NONE, 'Only turn the required two-factor sign-in for clients off');
        $this->addOption('passkeys', null, InputOption::VALUE_NONE, 'Only turn passkey sign-in off');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pin = (bool) $input->getOption('pin');
        $countries = (bool) $input->getOption('countries');
        $ips = (bool) $input->getOption('ips');
        $twofactor = (bool) $input->getOption('twofactor');
        $passkeys = (bool) $input->getOption('passkeys');
        if (!$pin && !$countries && !$ips && !$twofactor && !$passkeys) {
            $pin = $countries = $ips = $twofactor = $passkeys = true;
        }
        $settings = SignInSecurity::turnOff($this->di, $pin, $countries, $ips, $twofactor, $passkeys);
        $this->di['logger']->warning('Sign-in security turned off from the console (email PIN: %s, country rule: %s, IP rule: %s, clients two-factor: %s, passkeys: %s)', $pin ? 'off' : 'kept', $countries ? 'off' : 'kept', $ips ? 'off' : 'kept', $twofactor ? 'off' : 'kept', $passkeys ? 'off' : 'kept');
        $output->writeln(sprintf('Email PIN: %s. Staff countries: %s. Staff IPs: %s. Clients two-factor: %s. Passkeys: %s.', $settings['pin'], $settings['countries_on'] ? implode(', ', $settings['countries']) : 'off', $settings['ips_on'] ? implode(', ', $settings['ips']) : 'off', $settings['twofactor'], $settings['passkeys']));

        return Command::SUCCESS;
    }
}
