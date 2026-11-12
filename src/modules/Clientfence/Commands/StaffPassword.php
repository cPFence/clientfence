<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Commands;

use ClientFence\Mod\Staff\Entity\Admin;
use ClientFence\Mod\Staff\Entity\AdminPasswordReset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * For an owner locked out of the admin (CF-79): sets a staff member's password from the server, as the staff
 * Change password does (recovery links revoked, their sessions signed out). Without an email it lists the staff.
 */
#[AsCommand(
    name: 'clientfence:staff-password',
    description: 'Sets a staff member\'s password, for an owner locked out of the admin',
    hidden: false
)]
class StaffPassword extends Command implements \ClientFence\InjectionAwareInterface
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
        $this->addArgument('email', InputArgument::OPTIONAL, 'The staff member\'s email address (none: list the staff)');
        $this->addOption('activate', null, InputOption::VALUE_NONE, 'Also set an inactive staff member active');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $staff = $this->di['em']->getRepository(Admin::class);
        $email = trim((string) $input->getArgument('email'));
        $admin = $email === '' ? null : $staff->findOneBy(['email' => $email, 'systemName' => null]);
        if (!$admin instanceof Admin) {
            if ($email !== '') {
                $output->writeln(sprintf('<error>No staff member has the email address %s.</error>', $email));
            }
            // The cron's own account never signs in.
            foreach ($staff->findBy(['systemName' => null], ['id' => 'ASC']) as $member) {
                $output->writeln(sprintf('%s  %s (%s)', $member->getEmail(), $member->getName(), $member->getStatus()));
            }

            return $email === '' ? Command::SUCCESS : Command::FAILURE;
        }

        // Typed twice without showing it; with nothing to read (no terminal, --no-interaction) a strong one is made.
        $password = null;
        if ($input->isInteractive()) {
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
            $helper = $this->getHelper('question');
            // Not trimmed: the sign-in compares the password as typed.
            $ask = fn (string $label): string => rtrim((string) $helper->ask($input, $output, (new Question($label))->setHidden(true)->setHiddenFallback(false)->setTrimmable(false)), "\r\n");
            try {
                $password = $ask('New password: ');
            } catch (\Symfony\Component\Console\Exception\RuntimeException $e) {
                // Ctrl-D at a terminal cancels; no terminal at all (or no way to hide the input) gets a made one.
                if ($e instanceof \Symfony\Component\Console\Exception\MissingInputException && stream_isatty(STDIN)) {
                    $output->writeln('<error>Cancelled. Nothing was changed.</error>');

                    return Command::FAILURE;
                }
                $output->writeln('');
            }
            if ($password !== null && $password !== $ask('The same password again: ')) {
                $output->writeln('<error>The passwords do not match. Nothing was changed.</error>');

                return Command::FAILURE;
            }
        }
        $made = $password === null;
        $password ??= $this->di['tools']->generatePassword(16);
        $this->di['validator']->isPasswordStrong($password);

        $this->di['events_manager']->fire(['event' => 'onBeforeAdminStaffPasswordChange', 'params' => ['id' => $admin->getId()]]);
        $this->di['em']->getRepository(AdminPasswordReset::class)->changePassword($admin, $this->di['password']->hashIt($password));
        $this->di['mod_service']('profile')->invalidateSessions('admin', (int) $admin->getId());
        $this->di['events_manager']->fire(['event' => 'onAfterAdminStaffPasswordChange', 'params' => ['id' => $admin->getId()]]);
        if ($input->getOption('activate') && $admin->getStatus() !== Admin::STATUS_ACTIVE) {
            $admin->setStatus(Admin::STATUS_ACTIVE);
            $this->di['em']->flush();
            $output->writeln(sprintf('%s is active again.', $admin->getName()));
        }
        $this->di['logger']->warning('Password of staff member #%s "%s" set from the console', $admin->getId(), $admin->getName());

        $output->writeln(sprintf('Password set for %s (%s). Their sessions were signed out.', $admin->getName(), $admin->getEmail()));
        if ($made) {
            $output->writeln('The new password (shown once): ' . $password);
        }
        if ($admin->getStatus() !== Admin::STATUS_ACTIVE) {
            $output->writeln('<comment>This staff member is inactive and cannot sign in: run it again with --activate, or another staff member sets them active.</comment>');
        }

        return Command::SUCCESS;
    }
}
