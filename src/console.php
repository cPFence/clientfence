#!/usr/bin/env php
<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
if (php_sapi_name() !== 'cli') {
    exit('This script can only be run from the command line.');
}

// ClientFence core patch: system:update refuses root before loading, which already writes root-owned cache files that break later updates.
if (in_array('system:update', $argv, true) && function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Do not run system:update as root: the files it writes would belong to root, and later updates would fail. Run it as the site's user (for example with sudo -u).\n");
    exit(1);
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'load.php';

use ClientFence\Version;
use Symfony\Component\Console\Application;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

$di = include Path::join(PATH_ROOT, 'di.php');

$di['translate']();

$application = new Application();
$filesystem = new Filesystem();

// Setting the application constraints
$application->setName('ClientFence');
$application->setVersion(Version::CLIENTFENCE . ' (based on FOSSBilling ' . Version::VERSION . ')');

$modules = $di['mod']('extension')->getCoreModules();

// Check if the config file exists. If it does, the database is likely already initialized and this will work.
if ($filesystem->exists(PATH_CONFIG)) {
    // Try to load the modules from the database. If this fails, the database might not initialized yet. We will use the list of the core modules instead.
    try {
        $modules = $di['mod_service']('extension')->getCoreAndActiveModules();
    } catch (Exception) {
        // Do nothing
    }
}

// Dynamically load the commands from the modules
foreach ($modules as $module) {
    // Our manifests declare the names in lowercase, but the module directories start with an uppercase letter.
    $cap = ucfirst((string) $module);

    $commandsPath = Path::join(PATH_ROOT, 'modules', $cap, 'Commands');

    // Skip if Commands directory doesn't exist
    if (!$filesystem->exists($commandsPath)) {
        continue;
    }

    $finder = new Finder();
    $finder->files()->in($commandsPath)->name('*.php');

    foreach ($finder as $file) {
        $command = $file->getFilenameWithoutExtension();
        $class = "ClientFence\\Mod\\{$cap}\\Commands\\{$command}";

        $command = new $class();
        $command->setDi($di);
        $application->addCommand($command);
    }
}

$application->run();
