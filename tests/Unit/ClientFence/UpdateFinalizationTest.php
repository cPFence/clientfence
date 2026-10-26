<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use ClientFence\UpdateFinalization;
use ClientFence\UpdatePatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

beforeEach(function (): void {
    $filesystem = new Filesystem();
    $statePath = Path::join(PATH_DATA, UpdateFinalization::STATE_FILENAME);

    $this->updateFinalizationFilesystem = $filesystem;
    $this->updateFinalizationStatePath = $statePath;
    $this->updateFinalizationOriginalState = $filesystem->exists($statePath) ? $filesystem->readFile($statePath) : null;

    $filesystem->remove($statePath);
});

afterEach(function (): void {
    $this->updateFinalizationFilesystem->remove($this->updateFinalizationStatePath . '.lock');
    if ($this->updateFinalizationOriginalState === null) {
        $this->updateFinalizationFilesystem->remove($this->updateFinalizationStatePath);
    } else {
        $this->updateFinalizationFilesystem->dumpFile($this->updateFinalizationStatePath, $this->updateFinalizationOriginalState);
    }
});

function stubUpdatePatcherWithPendingPatches(int $pending = 1): UpdatePatcher
{
    return new class($pending) extends UpdatePatcher {
        public function __construct(private readonly int $pendingPatches)
        {
        }

        public function availablePatches(): int
        {
            return $this->pendingPatches;
        }
    };
}

function finalizationWithStubPatcher(UpdatePatcher $stubPatcher): UpdateFinalization
{
    $finalization = new class extends UpdateFinalization {
        public UpdatePatcher $stubPatcher;

        protected function createPatcher(): UpdatePatcher
        {
            return $this->stubPatcher;
        }
    };
    $finalization->stubPatcher = $stubPatcher;

    return $finalization;
}

function seedFinalizedState(Filesystem $filesystem, string $statePath): void
{
    $filesystem->dumpFile(
        $statePath,
        json_encode([
            'status' => 'finalized',
            'finalized_at' => date(DATE_ATOM),
        ], JSON_THROW_ON_ERROR)
    );
}

test('flips a finalized state with pending patches back to pending', function (): void {
    seedFinalizedState($this->updateFinalizationFilesystem, $this->updateFinalizationStatePath);

    $finalization = finalizationWithStubPatcher(stubUpdatePatcherWithPendingPatches(3));
    $state = $finalization->ensureCurrentVersionFinalization();

    expect($state['status'])->toBe('pending')
        ->and($state['finalized_at'])->toBeNull()
        ->and($finalization->isRequired(false))->toBeTrue();

    $persisted = json_decode(
        $this->updateFinalizationFilesystem->readFile($this->updateFinalizationStatePath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    expect($persisted['status'])->toBe('pending');
});

// ClientFence: an update whose patches all applied completes on the next request and reopens the site.
test('completes a finalized state with no pending patches and restores maintenance', function (): void {
    $original = ClientFence\Config::getConfig();
    try {
        $config = $original;
        $config['maintenance_mode'] = ['enabled' => false, 'allowed_urls' => [], 'allowed_ips' => []];
        ClientFence\Config::setConfig($config, false);
        $finalization = finalizationWithStubPatcher(stubUpdatePatcherWithPendingPatches(0));
        $finalization->createPendingState(ClientFence\Version::CLIENTFENCE, '99.0.0');
        $state = json_decode($this->updateFinalizationFilesystem->readFile($this->updateFinalizationStatePath), true, 512, JSON_THROW_ON_ERROR);
        $state['status'] = 'finalized';
        $this->updateFinalizationFilesystem->dumpFile($this->updateFinalizationStatePath, json_encode($state, JSON_THROW_ON_ERROR));
        expect(ClientFence\Config::getConfig()['maintenance_mode']['enabled'])->toBeTrue();

        expect($finalization->ensureCurrentVersionFinalization())->toBeNull();

        $persisted = json_decode($this->updateFinalizationFilesystem->readFile($this->updateFinalizationStatePath), true, 512, JSON_THROW_ON_ERROR);
        expect($persisted['status'])->toBe('complete')
            ->and($persisted['from_version'])->toBe(ClientFence\Version::CLIENTFENCE)
            ->and(ClientFence\Config::getConfig()['maintenance_mode']['enabled'])->toBeFalse()
            ->and($finalization->isRequired(false))->toBeFalse();
    } finally {
        ClientFence\Config::setConfig($original, false);
    }
});

test('leaves a finalized state alone when the patch level cannot be read', function (): void {
    seedFinalizedState($this->updateFinalizationFilesystem, $this->updateFinalizationStatePath);
    $unreadable = new class extends UpdatePatcher {
        public function __construct()
        {
        }

        public function availablePatches(): int
        {
            throw new RuntimeException('database down');
        }
    };

    $state = finalizationWithStubPatcher($unreadable)->ensureCurrentVersionFinalization();

    expect($state['status'])->toBe('finalized');
});

test('leaves a finalized state alone while another request completes it', function (): void {
    seedFinalizedState($this->updateFinalizationFilesystem, $this->updateFinalizationStatePath);
    $lock = fopen($this->updateFinalizationStatePath . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $state = finalizationWithStubPatcher(stubUpdatePatcherWithPendingPatches(0))->ensureCurrentVersionFinalization();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    expect($state['status'])->toBe('finalized');
});

// ClientFence: the finalization state records the ClientFence version, so a new ClientFence
// release on the same FOSSBilling base asks for finalization.
test('a complete state is current only for the same ClientFence version', function (): void {
    $finalization = finalizationWithStubPatcher(stubUpdatePatcherWithPendingPatches(0));
    $complete = new ReflectionMethod($finalization, 'isCompleteForCurrentVersion');
    $base = ['status' => 'complete', 'version' => ClientFence\Version::VERSION];

    expect($complete->invoke($finalization, $base + ['clientfence' => ClientFence\Version::CLIENTFENCE]))->toBeTrue()
        // Written before ClientFence recorded its version: counts as current.
        ->and($complete->invoke($finalization, $base))->toBeTrue()
        ->and($complete->invoke($finalization, $base + ['clientfence' => '0.9.0']))->toBeFalse()
        ->and($complete->invoke($finalization, ['status' => 'complete', 'version' => '0.0.0', 'clientfence' => ClientFence\Version::CLIENTFENCE]))->toBeFalse();
});

test('a complete state written now carries the ClientFence version', function (): void {
    $finalization = finalizationWithStubPatcher(stubUpdatePatcherWithPendingPatches(0));
    $finalization->writeCompleteState();

    $persisted = json_decode($this->updateFinalizationFilesystem->readFile($this->updateFinalizationStatePath), true, 512, JSON_THROW_ON_ERROR);

    expect($persisted['clientfence'])->toBe(ClientFence\Version::CLIENTFENCE)
        ->and($persisted['version'])->toBe(ClientFence\Version::VERSION);
});

test('cancelling a pending update restores the maintenance setting and records the running version', function (): void {
    $original = ClientFence\Config::getConfig();
    try {
        $config = $original;
        $config['maintenance_mode'] = ['enabled' => false, 'allowed_urls' => [], 'allowed_ips' => []];
        ClientFence\Config::setConfig($config, false);
        $finalization = finalizationWithStubPatcher(stubUpdatePatcherWithPendingPatches(0));

        $finalization->createPendingState(ClientFence\Version::CLIENTFENCE, '99.0.0', ['source' => 'auto-update']);
        expect(ClientFence\Config::getConfig()['maintenance_mode']['enabled'])->toBeTrue()
            ->and($finalization->isRequired(false))->toBeTrue();

        $finalization->cancelPendingState();
        $persisted = json_decode($this->updateFinalizationFilesystem->readFile($this->updateFinalizationStatePath), true, 512, JSON_THROW_ON_ERROR);

        expect(ClientFence\Config::getConfig()['maintenance_mode']['enabled'])->toBeFalse()
            ->and($persisted['status'])->toBe('complete')
            ->and($persisted['clientfence'])->toBe(ClientFence\Version::CLIENTFENCE)
            ->and($finalization->isRequired(false))->toBeFalse();
    } finally {
        ClientFence\Config::setConfig($original, false);
    }
});

test('cancelling leaves a finalized update alone', function (): void {
    seedFinalizedState($this->updateFinalizationFilesystem, $this->updateFinalizationStatePath);
    $before = $this->updateFinalizationFilesystem->readFile($this->updateFinalizationStatePath);

    finalizationWithStubPatcher(stubUpdatePatcherWithPendingPatches(0))->cancelPendingState();

    expect($this->updateFinalizationFilesystem->readFile($this->updateFinalizationStatePath))->toBe($before);
});
