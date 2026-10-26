<?php

declare(strict_types=1);

/**
 * Writes src/modules/Clientfence/settings-index.json, the settings the admin Ctrl+K search finds
 * (SettingsIndex.php). Run it after changing a settings template; a unit test fails until then.
 *
 *   php tools/build-settings-index.php
 */
require dirname(__DIR__) . '/src/modules/Clientfence/SettingsIndex.php';

$entries = ClientFence\Mod\Clientfence\SettingsIndex::build(dirname(__DIR__) . '/src/modules');
file_put_contents(ClientFence\Mod\Clientfence\SettingsIndex::FILE, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo count($entries) . " settings entries\n";
