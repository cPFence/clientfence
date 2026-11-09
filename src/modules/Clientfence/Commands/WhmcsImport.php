<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Commands;

use ClientFence\Mod\Clientfence\Whmcs\Import;
use ClientFence\Mod\Clientfence\Whmcs\Run;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The WHMCS import from the console (Whmcs/Import.php), for installs too large for the admin
 * page: the same engine with the database details saved on System > Settings > WHMCS import.
 * Steps: plan (read WHMCS and show what an import brings), apply (import, going on from where
 * a stopped run left off) and verify (WHMCS against what was imported).
 */
#[AsCommand(
    name: 'clientfence:whmcs-import',
    description: 'Imports a WHMCS install: plan, apply, verify',
    hidden: false
)]
class WhmcsImport extends Command implements \ClientFence\InjectionAwareInterface
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
        $this->addArgument('step', InputArgument::REQUIRED, 'upmind, blesta or clientexec (read that system first, with the details saved on the admin tab), plan, apply or verify');
        $this->addOption('restart', null, InputOption::VALUE_NONE, 'upmind, blesta, clientexec: read it again from the start');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON');
        $this->addOption('attachments', null, InputOption::VALUE_REQUIRED, 'WHMCS\'s ticket attachments folder on this server (saved for the import, as on the admin tab)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $step = (string) $input->getArgument('step');
        if (!in_array($step, ['upmind', 'blesta', 'clientexec', 'plan', 'apply', 'verify'], true)) {
            $output->writeln('<error>Unknown step "' . $step . '". Steps: upmind, blesta, clientexec, plan, apply, verify</error>');

            return Command::INVALID;
        }
        if ($input->getOption('attachments') !== null) {
            try {
                $folder = Import::saveAttachments($this->di, (string) $input->getOption('attachments'));
            } catch (\ClientFence\InformationException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }
            $output->writeln($folder === '' ? 'Attachments folder: none' : 'Attachments folder: ' . $folder);
        }
        if (in_array($step, ['upmind', 'blesta', 'clientexec'], true)) {
            return $this->readSnapshot($step, (bool) $input->getOption('restart'), $output);
        }
        if ($step !== 'plan') {
            return $this->runStep($step, (bool) $input->getOption('json'), $output);
        }
        try {
            $plan = Import::plan($this->di);
        } catch (\ClientFence\InformationException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $target = Import::target($this->di);
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(['plan' => $plan, 'target' => $target], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $system = Import::system(Import::settings($this->di));
        $output->writeln($system === 'WHMCS' ? sprintf('WHMCS %s', $plan['version'] !== '' ? $plan['version'] : '(version unknown)') : $system);
        foreach (\ClientFence\Mod\Clientfence\Whmcs\Inventory::GROUPS as $group => $label) {
            $output->writeln('');
            $output->writeln('<info>' . $label . '</info>');
            foreach ($plan['areas'] as $area) {
                if ($area['group'] !== $group) {
                    continue;
                }
                $detail = [];
                foreach ($area['by_status'] as $status => $count) {
                    $detail[] = $status . ' ' . $count;
                }
                $output->writeln(sprintf('  %-32s %7d%s', $area['label'], $area['rows'], $detail !== [] ? '  (' . implode(', ', $detail) . ')' : ''));
            }
        }
        if ($plan['money'] !== []) {
            $output->writeln('');
            $output->writeln('<info>Money</info>');
            foreach ($plan['money'] as $money) {
                $output->writeln(sprintf('  %s: unpaid invoices %.2f, client credit %.2f, paid invoices %.2f', $money['currency'], $money['unpaid_invoices'], $money['client_credit'], $money['paid_invoices']));
            }
        }
        $output->writeln('');
        $output->writeln('<info>Add-on modules</info>');
        foreach ($plan['modules'] as $module) {
            $output->writeln(sprintf('  %-40s %-9s %7d rows%s', $module['label'], ['import' => 'imported', 'archive' => 'archived', 'settings' => 'settings'][$module['handling']] ?? $module['handling'], $module['rows'], $module['active'] ? '' : ' (not switched on)'));
        }
        $output->writeln('');
        $output->writeln('<info>This server (checked before the import starts)</info>');
        foreach ($plan['preflight'] ?? [] as $check) {
            $output->writeln(sprintf('  %-4s %-40s %s', $check['ok'] ? 'ok' : 'FIX', $check['check'], $check['detail']));
            if (!$check['ok']) {
                $output->writeln('<comment>       ' . $check['fix'] . '</comment>');
            }
        }
        foreach ($plan['warnings'] as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }
        if (!$target['fresh']) {
            $output->writeln('<comment>This install already has data (' . implode(', ', array_map(static fn (string $k, int $v): string => $v . ' ' . $k, array_keys($target['counts']), $target['counts'])) . '): the import needs a fresh install.</comment>');
        }

        return Command::SUCCESS;
    }

    /** Reads Upmind, Blesta or ClientExec into the import's snapshot (Snapshot.php), a line per page. */
    private function readSnapshot(string $step, bool $restart, OutputInterface $output): int
    {
        try {
            do {
                $state = match ($step) {
                    'blesta' => Import::fetchBlesta($this->di, 20.0, $restart),
                    'clientexec' => Import::fetchClientexec($this->di, 20.0, $restart),
                    default => Import::fetchUpmind($this->di, 20.0, $restart),
                };
                $restart = false;
                $current = $state['steps'][$state['step'] ?? ''] ?? null;
                if ($current !== null) {
                    $output->writeln(sprintf('  %-36s %7d', $current['label'], $current['done']));
                }
            } while (empty($state['finished_at']));
        } catch (\ClientFence\InformationException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('<info>' . Import::system(Import::settings($this->di)) . ' read. Next: php console.php clientfence:whmcs-import plan</info>');
        foreach ($this->di['em']->getConnection()->fetchFirstColumn('SELECT message FROM ' . Run::WARNINGS . ' WHERE step = ? ORDER BY id', [$step]) as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }

        return Command::SUCCESS;
    }

    /** apply (until done, a line per batch) or verify. */
    private function runStep(string $step, bool $json, OutputInterface $output): int
    {
        try {
            if ($step === 'verify') {
                $result = Run::verify($this->di);
            } else {
                do {
                    $result = Run::apply($this->di, 20.0);
                    if (!$json) {
                        $current = $result['steps'][$result['step'] ?? ''] ?? null;
                        if ($current !== null) {
                            $output->writeln(sprintf('  %-32s %7d of %d', $current['label'], $current['done'], $current['total']));
                        }
                    }
                } while (empty($result['finished_at']));
            }
        } catch (\ClientFence\InformationException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        if ($json) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }
        if ($step === 'verify') {
            $system = Import::system(Import::settings($this->di));
            foreach ($result['rows'] as $row) {
                $output->writeln(sprintf('  %-32s %s %7d  imported %7d%s', $row['label'], $system, $row['whmcs'], $row['imported'], $row['whmcs'] !== $row['imported'] ? '  (see the warnings)' : ''));
            }
            $output->writeln(sprintf('Missing imported clients: %d. Warnings: %d.', $result['missing_clients'], $result['warnings']));

            return $result['missing_clients'] === 0 ? Command::SUCCESS : Command::FAILURE;
        }
        $output->writeln('<info>Import finished.</info>');
        foreach (Run::warnings($this->di['em']->getConnection()) as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }

        return Command::SUCCESS;
    }
}
