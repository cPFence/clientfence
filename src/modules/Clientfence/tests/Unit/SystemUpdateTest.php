<?php

declare(strict_types=1);

/**
 * ClientFence: console.php system:update (CF-111), System > Update from the server. The system API is a fake that
 * counts the update calls; the root check and the new-process last step are stubbed in a subclass.
 */

use ClientFence\Mod\Clientfence\Commands\SystemUpdate;
use ClientFence\Mod\Clientfence\Updates;
use ClientFence\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

use function Tests\Helpers\container;

/** A stable-channel site with release 99.1.0 on the feed, unless $info or $available say otherwise. */
function systemUpdateWorld(array $info = [], bool $available = true, bool $root = false): object
{
    $world = new stdClass();
    $world->checks = 0;
    $world->updates = 0;
    $world->channelSeen = null;
    $world->params = [];
    $world->fail = null;
    // What the update steps did, in order: hold/release (signals), update, finish.
    $world->log = [];
    $world->infoCalls = 0;
    // The info from the second check on (the feed changed meanwhile).
    $world->later = [];

    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE mod_clientfence_state (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $world->db = $db;
    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    $em->allows('getConnection')->andReturn($db);
    $system = new class($world) {
        public function __construct(private readonly object $world)
        {
        }

        public function getParamValue(string $param, $default = null)
        {
            return $this->world->params[$param] ?? $default;
        }

        public function setParamValue(string $param, $value): void
        {
            $this->world->params[$param] = $value;
        }
    };

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(fn (): object => $system);
    $api = Mockery::mock();
    // Saves the release of the channel, as Updates::check() does.
    $api->allows('system_recheck_update')->andReturnUsing(function () use ($world, $di, $db): bool {
        ++$world->checks;
        $world->channelSeen = Updates::channel($di);
        $release = json_encode(['checked_at' => date('Y-m-d H:i:s'), 'release' => ['version' => $world->channelSeen === 'beta' ? '99.2.0-beta.1' : '99.1.0'], 'error' => null]);
        $db->executeStatement('INSERT OR REPLACE INTO mod_clientfence_state (name, value, updated_at) VALUES (?, ?, ?)', ['update_feed', $release, date('Y-m-d H:i:s')]);

        return true;
    });
    $api->allows('system_update_info')->andReturnUsing(fn (): array => (++$world->infoCalls > 1 ? $world->later : []) + $info + [
        'version' => '99.1.0',
        'channel' => 'stable',
        'check_error' => null,
        'release_notes' => '## Fixed',
        'update_type' => Version::PATCH,
        'minimum_php_version' => '8.3',
        'php_version_supported' => true,
        'readiness' => ['can_update' => true, 'issues' => []],
    ]);
    $api->allows('system_update_available')->andReturn($available);
    // Asked just before the question: what the site would offer while it waits.
    $api->allows('clientfence_update_changes')->andReturnUsing(function () use ($world, $di): array {
        $world->atQuestion = [Updates::channel($di), Updates::last($di)['release']['version'] ?? null];

        return ['checked' => true, 'files' => []];
    });
    $api->allows('system_update_core')->andReturnUsing(function () use ($world, $di): bool {
        ++$world->updates;
        $world->log[] = 'update';
        $world->channelAtUpdate = Updates::channel($di);
        if ($world->fail !== null) {
            throw new RuntimeException($world->fail);
        }

        return true;
    });
    $di['api_system'] = $api;

    $command = new class('system:update') extends SystemUpdate {
        public bool $root = false;
        public bool $pcntl = true;
        public int $finished = 0;
        public object $world;

        protected function isRoot(): bool
        {
            return $this->root;
        }

        protected function finishUpdate(): int
        {
            ++$this->finished;
            $this->world->log[] = 'finish';

            return 0;
        }

        protected function holdSignals(bool $hold): bool
        {
            $this->world->log[] = $hold ? 'hold' : 'release';

            return $this->pcntl;
        }
    };
    $command->world = $world;
    $command->root = $root;
    $command->setDi($di);
    $command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));
    $world->di = $di;
    $world->command = $command;
    $world->tester = new CommandTester($command);

    return $world;
}

test('--check shows the installed and the latest version and changes nothing', function (): void {
    $world = systemUpdateWorld(['version' => Version::CLIENTFENCE], false);
    expect($world->tester->execute(['--check' => true]))->toBe(Command::SUCCESS)
        ->and($world->tester->getDisplay())->toContain('Installed: ClientFence ' . Version::CLIENTFENCE)
        ->toContain('Latest on the stable channel: ClientFence ' . Version::CLIENTFENCE);

    $world = systemUpdateWorld();
    expect($world->tester->execute(['--check' => true]))->toBe(Command::FAILURE)
        ->and($world->tester->getDisplay())->toContain('Latest on the stable channel: ClientFence 99.1.0')->not->toContain('## Fixed')
        ->and($world->updates)->toBe(0);

    $world = systemUpdateWorld(['check_error' => 'The ClientFence release feed answered HTTP 500.']);
    expect($world->tester->execute(['--check' => true]))->toBe(2)
        ->and($world->tester->getDisplay())->toContain('The update check failed: The ClientFence release feed answered HTTP 500.');
});

test('as root it refuses before anything else, --check too', function (bool $check): void {
    $world = systemUpdateWorld(root: true);
    expect($world->tester->execute($check ? ['--check' => true] : ['--yes' => true]))->toBe(Command::FAILURE)
        ->and($world->tester->getDisplay())->toContain('Do not run this as root')
        ->and($world->checks)->toBe(0)
        ->and($world->updates)->toBe(0);
})->with([false, true]);

test('console.php refuses system:update as root before it loads the site', function (): void {
    $source = (string) file_get_contents(PATH_ROOT . '/console.php');
    expect(strpos($source, "in_array('system:update', \$_SERVER['argv'] ?? [], true) && function_exists('posix_geteuid') && posix_geteuid() === 0"))
        ->toBeInt()->toBeLessThan(strpos($source, "require_once __DIR__ . DIRECTORY_SEPARATOR . 'load.php';"));
});

test('--yes shows the release notes and runs the update of System > Update once, then its last step', function (): void {
    $world = systemUpdateWorld();
    expect($world->tester->execute(['--yes' => true], ['interactive' => false]))->toBe(Command::SUCCESS)
        ->and($world->updates)->toBe(1)
        ->and($world->command->finished)->toBe(1)
        ->and($world->tester->getDisplay())->toContain('What is new in ClientFence 99.1.0:')->toContain('## Fixed')
        ->toContain('The web server\'s PHP must also be 8.3 or newer')
        ->toContain('ClientFence 99.1.0 is installed.')
        // Ctrl-C, a dropped connection or SIGTERM cannot stop the update or its last step half way.
        ->and($world->log)->toBe(['hold', 'update', 'finish', 'release'])
        ->and($world->tester->getDisplay())->not->toContain('screen or tmux');

    // Without pcntl it says not to interrupt it.
    $world = systemUpdateWorld();
    $world->command->pcntl = false;
    expect($world->tester->execute(['--yes' => true]))->toBe(Command::SUCCESS)
        ->and($world->tester->getDisplay())->toContain('screen or tmux')
        ->and($world->log)->toBe(['hold', 'update', 'finish']);

    // A failed update (rolled back by the updater) says why and stops.
    $world = systemUpdateWorld();
    $world->fail = 'The database update failed, so the update was rolled back.';
    expect($world->tester->execute(['--yes' => true]))->toBe(Command::FAILURE)
        ->and($world->tester->getDisplay())->toContain('The database update failed, so the update was rolled back.')
        ->and($world->command->finished)->toBe(0);

    $world = systemUpdateWorld([], false);
    expect($world->tester->execute(['--yes' => true]))->toBe(Command::SUCCESS)
        ->and($world->tester->getDisplay())->toContain('Nothing to update.')
        ->and($world->updates)->toBe(0);
});

test('without a yes to the question, or without a terminal and --yes, nothing is updated', function (): void {
    $world = systemUpdateWorld();
    $world->tester->setInputs(['n']);
    expect($world->tester->execute([], ['interactive' => true]))->toBe(Command::FAILURE)
        ->and($world->tester->getDisplay())->toContain('Update to ClientFence 99.1.0? [y/N]')->toContain('Cancelled. Nothing was changed.')
        ->and($world->updates)->toBe(0);

    // Enter alone takes the default: no.
    $world = systemUpdateWorld();
    $world->tester->setInputs(['']);
    expect($world->tester->execute([], ['interactive' => true]))->toBe(Command::FAILURE)
        ->and($world->updates)->toBe(0);

    $world = systemUpdateWorld();
    expect($world->tester->execute([], ['interactive' => false]))->toBe(Command::FAILURE)
        ->and($world->tester->getDisplay())->toContain('add --yes')
        ->and($world->updates)->toBe(0);

    $world = systemUpdateWorld();
    $world->tester->setInputs(['y']);
    expect($world->tester->execute([], ['interactive' => true]))->toBe(Command::SUCCESS)
        ->and($world->updates)->toBe(1);
});

test('the checks of System > Update stop it before the question', function (array $info): void {
    $world = systemUpdateWorld($info);
    expect($world->tester->execute(['--yes' => true]))->toBe(Command::FAILURE)
        ->and($world->tester->getDisplay())->toContain('Nothing was changed')
        ->and($world->updates)->toBe(0);
})->with([
    'PHP too old' => [['minimum_php_version' => '99.0', 'php_version_supported' => false]],
    'files not writable' => [['readiness' => ['can_update' => false, 'issues' => [['path' => 'library', 'message' => 'Folder is not writable by the web server user (library).']]]]],
]);

test('--beta uses the beta channel for this run only and forgets the beta release it found', function (): void {
    $world = systemUpdateWorld([], false);
    $world->db->insert('mod_clientfence_state', ['name' => 'update_feed', 'value' => '{"checked_at":"2026-11-14 10:00:00","release":{"version":"99.2.0-beta.1"},"error":null}', 'updated_at' => '2026-11-14 10:00:00']);
    $world->tester->execute(['--check' => true, '--beta' => true]);

    expect($world->channelSeen)->toBe('beta')
        ->and(Updates::channel($world->di))->toBe('stable')
        ->and($world->params[Updates::CHANNEL_SETTING] ?? 'stable')->toBe('stable')
        ->and(Updates::last($world->di)['release'])->toBeNull();
});

test('a release other than the one shown is never installed', function (): void {
    $world = systemUpdateWorld();
    $world->later = ['version' => '99.3.0'];
    expect($world->tester->execute(['--yes' => true]))->toBe(Command::FAILURE)
        ->and($world->tester->getDisplay())->toContain('The latest release is no longer ClientFence 99.1.0 (99.3.0).')
        ->and($world->updates)->toBe(0)
        ->and($world->log)->toBe(['hold', 'release']);
});

test('--beta keeps the beta release saved only for the check and the update, never while it asks', function (): void {
    $world = systemUpdateWorld(['version' => '99.2.0-beta.1', 'channel' => 'beta']);
    $world->tester->setInputs(['n']);
    expect($world->tester->execute(['--beta' => true], ['interactive' => true]))->toBe(Command::FAILURE)
        ->and($world->channelSeen)->toBe('beta')
        ->and($world->atQuestion)->toBe(['stable', null])
        // The beta check held the signals, so Ctrl-C there cannot leave the beta saved either.
        ->and($world->log)->toBe(['hold', 'release'])
        ->and(Updates::last($world->di)['release'])->toBeNull();

    $world = systemUpdateWorld(['version' => '99.2.0-beta.1', 'channel' => 'beta']);
    expect($world->tester->execute(['--beta' => true, '--yes' => true]))->toBe(Command::SUCCESS)
        ->and($world->atQuestion)->toBe(['stable', null])
        ->and($world->channelAtUpdate)->toBe('beta')
        ->and($world->checks)->toBe(2)
        ->and(Updates::channel($world->di))->toBe('stable')
        ->and($world->params[Updates::CHANNEL_SETTING] ?? 'stable')->toBe('stable');
});
