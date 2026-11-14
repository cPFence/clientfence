<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Commands;

use ClientFence\Mod\Clientfence\Updates;
use ClientFence\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Path;

/**
 * System → Update from the server (CF-111): the same checks and the same update (system_update_core, run as the
 * cron's system identity), latest release only. The update's last step runs in a new PHP process, which loads the
 * new release's code.
 */
#[AsCommand(
    name: 'system:update',
    description: 'Updates ClientFence to the latest release, as System > Update does',
    hidden: false
)]
class SystemUpdate extends Command implements \ClientFence\InjectionAwareInterface
{
    protected $di;
    private bool $installed = false;
    /** --beta on a site whose saved channel is stable. */
    private bool $betaRun = false;
    /** @var array<int, mixed> the signal handlers holdSignals() replaced */
    private array $signals = [];

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
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Only show the installed and the latest version');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Update without asking first (scripts)');
        $this->addOption('beta', null, InputOption::VALUE_NONE, 'Use the beta channel for this run, without changing the saved setting');
        $this->setHelp(<<<'HELP'
            Checks the release feed and installs the latest release, with the checks, the backup, the checksum and the
            automatic rollback of System > Update. Run it as the site's user, never as root.

            Exit codes: 0 updated or already up to date; 1 not updated (refused, cancelled or failed).
            With --check: 0 up to date, 1 an update is available, 2 the update check failed.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $check = (bool) $input->getOption('check');
        // Files written by root (the cache too) break every later update; console.php refuses the full name earlier.
        if ($this->isRoot()) {
            $output->writeln('<error>Do not run this as root: the files the update writes would belong to root, and later updates from System > Update would fail. Run it as the site\'s user (for example with sudo -u).</error>');

            return Command::FAILURE;
        }

        $this->betaRun = $input->getOption('beta') && Updates::channel($this->di) !== 'beta';

        try {
            return $this->update($input, $output, $check);
        } finally {
            if (!$this->installed) {
                $this->beta(false);
            }
            Updates::useChannel(null);
        }
    }

    /**
     * Switches this run to the beta channel, or back to the saved stable one: the check saved while on the beta
     * channel is forgotten, so the banner, System > Update and the cron's release email never offer that beta.
     */
    private function beta(bool $on): void
    {
        if (!$this->betaRun) {
            return;
        }
        Updates::useChannel($on ? 'beta' : null);
        if (!$on) {
            Updates::setChannel($this->di, 'stable');
        }
    }

    private function update(InputInterface $input, OutputInterface $output, bool $check): int
    {
        $api = $this->di['api_system'];
        // The beta check is saved only for these few calls; held signals, so Ctrl-C cannot leave it saved.
        $this->beta(true);
        $held = $this->betaRun && $this->holdSignals(true);
        try {
            $api->system_recheck_update();
            $info = $api->system_update_info();
            $available = $api->system_update_available();
        } finally {
            $this->beta(false);
            if ($held) {
                $this->holdSignals(false);
            }
        }
        $version = (string) $info['version'];

        $output->writeln('Installed: ClientFence ' . Version::CLIENTFENCE);
        if (!empty($info['check_error'])) {
            $output->writeln('<error>The update check failed: ' . OutputFormatter::escape((string) $info['check_error']) . '</error>');

            return $check ? 2 : Command::FAILURE;
        }
        $output->writeln(sprintf('Latest on the %s channel: ClientFence %s', $info['channel'], $version));
        if ($check) {
            return $available ? Command::FAILURE : Command::SUCCESS;
        }
        if (!$available) {
            $output->writeln('Nothing to update.');

            return Command::SUCCESS;
        }

        // The checks System > Update makes before it offers the button.
        $minimumPhp = (string) $info['minimum_php_version'];
        if (!$info['php_version_supported']) {
            $output->writeln(sprintf('<error>ClientFence %s needs PHP %s or newer; this command runs PHP %s. Nothing was changed.</error>', $version, $minimumPhp, PHP_VERSION));

            return Command::FAILURE;
        }
        if (!($info['readiness']['can_update'] ?? true)) {
            $output->writeln('<error>The update cannot start: fix the owner or permissions of these files and folders. Nothing was changed.</error>');
            foreach ($info['readiness']['issues'] ?? [] as $issue) {
                $output->writeln(sprintf('  %s: %s', $issue['path'], $issue['message']));
            }

            return Command::FAILURE;
        }
        $changes = $api->clientfence_update_changes();
        if ($changes['files'] !== []) {
            $output->writeln(sprintf('<comment>%d files of ClientFence %s were changed on this site. The update overwrites them; the backup before the update keeps a copy:</comment>', count($changes['files']), Version::CLIENTFENCE));
            foreach ($changes['files'] as $file) {
                $output->writeln('  ' . $file);
            }
        } elseif (!$changes['checked']) {
            $output->writeln('This version has no list of its files, so files changed on this site cannot be found before this update.');
        }

        $output->writeln(['', sprintf('What is new in ClientFence %s:', $version), '', OutputFormatter::escape(trim((string) $info['release_notes'])), '']);
        if ((int) $info['update_type'] === Version::MAJOR) {
            $output->writeln('<comment>This is a major update: check the release notes for breaking changes.</comment>');
        }
        // The web server's PHP is not recorded anywhere this command can read.
        if ($minimumPhp !== 'unknown') {
            $output->writeln(sprintf('<comment>The web server\'s PHP must also be %s or newer: this command runs PHP %s, and the web server may run another version.</comment>', $minimumPhp, PHP_VERSION));
        }

        if (!$input->getOption('yes')) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>Nothing was changed: add --yes to update without the question.</error>');

                return Command::FAILURE;
            }
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
            $helper = $this->getHelper('question');
            try {
                $yes = $helper->ask($input, $output, new ConfirmationQuestion(sprintf('Update to ClientFence %s? [y/N] ', $version), false));
            } catch (\Symfony\Component\Console\Exception\RuntimeException) {
                $yes = false;
            }
            if (!$yes) {
                $output->writeln('Cancelled. Nothing was changed.');

                return Command::FAILURE;
            }
        }

        // Killed half way, the update leaves the site locked and half unpacked, with no rollback.
        $held = $this->holdSignals(true);
        if (!$held) {
            $output->writeln('<comment>Do not interrupt the update or close this session (run it in screen or tmux): this PHP cannot hold off Ctrl-C or a dropped connection.</comment>');
        }
        try {
            // The update installs the release the feed names now (and needs it saved): only the one shown above.
            $this->beta(true);
            $api->system_recheck_update();
            $now = $api->system_update_info();
            if (!empty($now['check_error']) || (string) $now['version'] !== $version) {
                $output->writeln(sprintf('<error>The latest release is no longer ClientFence %s (%s). Nothing was changed: run the command again.</error>', $version, OutputFormatter::escape((string) ($now['check_error'] ?: $now['version']))));

                return Command::FAILURE;
            }

            $output->writeln('Updating: download, checksum, backup, unpacking and database update. This can take several minutes...');
            try {
                $api->system_update_core();
            } catch (\Throwable $e) {
                $output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');

                return Command::FAILURE;
            }
            $this->installed = true;

            $output->writeln('Finishing the update with the new release\'s code (system:run-patcher)...');
            if ($this->finishUpdate() !== 0) {
                $output->writeln('<error>The files are installed, but finishing the update failed: run php console.php system:run-patcher, or sign in to the admin area and finalize the update.</error>');

                return Command::FAILURE;
            }
        } finally {
            if ($held) {
                $this->holdSignals(false);
            }
        }
        $output->writeln(sprintf('<info>ClientFence %s is installed.</info>', $version));
        // opcache_reset() here would clear only this command's own cache, not the web server's.
        $output->writeln('<comment>If the site still runs the old version, restart the web server\'s PHP: its code cache (OPcache) can keep the old files.</comment>');

        return Command::SUCCESS;
    }

    /**
     * Ignores (true) Ctrl-C, a dropped connection (SIGHUP) and SIGTERM, or puts the handlers back (false); the
     * run-patcher process inherits the ignored signals. False when PHP has no pcntl.
     */
    protected function holdSignals(bool $hold): bool
    {
        if (!function_exists('pcntl_signal')) {
            return false;
        }
        foreach ([SIGINT, SIGHUP, SIGTERM] as $signal) {
            if ($hold) {
                $this->signals[$signal] = pcntl_signal_get_handler($signal);
            }
            pcntl_signal($signal, $hold ? SIG_IGN : ($this->signals[$signal] ?? SIG_DFL));
        }

        return true;
    }

    protected function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /** Runs the update's last step (Update finalization) in a new PHP process: this one still has the old code loaded. */
    protected function finishUpdate(): int
    {
        if (!function_exists('passthru')) {
            return 1;
        }
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(Path::join(PATH_ROOT, 'console.php')) . ' system:run-patcher', $code);

        return (int) $code;
    }
}
