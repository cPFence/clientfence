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
 * PIN, the staff country and IP rules, the clients' required two-factor sign-in, passkeys and CAPTCHA Protection off
 * (or only the selected --pin / --countries / --ips / --twofactor / --passkeys / --captcha): a broken setup page must not lock every client out.
 */
#[AsCommand(
    name: 'clientfence:signin-security-off',
    description: 'Turns sign-in security off: the email PIN, the staff country and IP address rules, the required client two-factor sign-in, passkeys and CAPTCHA Protection',
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
        $this->addOption('captcha', null, InputOption::VALUE_NONE, 'Only turn CAPTCHA Protection off');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pin = (bool) $input->getOption('pin');
        $countries = (bool) $input->getOption('countries');
        $ips = (bool) $input->getOption('ips');
        $twofactor = (bool) $input->getOption('twofactor');
        $passkeys = (bool) $input->getOption('passkeys');
        $captcha = (bool) $input->getOption('captcha');
        if (!$pin && !$countries && !$ips && !$twofactor && !$passkeys && !$captcha) {
            $pin = $countries = $ips = $twofactor = $passkeys = $captcha = true;
        }
        $settings = SignInSecurity::turnOff($this->di, $pin, $countries, $ips, $twofactor, $passkeys, $captcha);
        $captchaOn = !empty($this->di['mod_service']('extension')->getConfig('mod_antispam')['captcha_enabled']);
        $this->di['logger']->warning('Sign-in security turned off from the console (email PIN: %s, country rule: %s, IP rule: %s, clients two-factor: %s, passkeys: %s, CAPTCHA: %s)', $pin ? 'off' : 'kept', $countries ? 'off' : 'kept', $ips ? 'off' : 'kept', $twofactor ? 'off' : 'kept', $passkeys ? 'off' : 'kept', $captcha ? 'off' : 'kept');
        $output->writeln(sprintf('Email PIN: %s. Staff countries: %s. Staff IPs: %s. Clients two-factor: %s. Passkeys: %s. CAPTCHA: %s.', $settings['pin'], $settings['countries_on'] ? implode(', ', $settings['countries']) : 'off', $settings['ips_on'] ? implode(', ', $settings['ips']) : 'off', $settings['twofactor'], $settings['passkeys'], $captchaOn ? 'on' : 'off'));

        return Command::SUCCESS;
    }
}
