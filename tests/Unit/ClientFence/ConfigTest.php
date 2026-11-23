<?php

declare(strict_types=1);

use ClientFence\Config;
use Symfony\Component\Filesystem\Path;

// Issue #80: config.php holds the database password and salt, so no copy of it may stay in the web root.
test('a config write keeps no config.old.php and removes one an older version left', function (): void {
    $old = Path::changeExtension(PATH_CONFIG, 'old.php');
    $config = Config::getConfig();
    file_put_contents($old, '<?php return [];');

    try {
        Config::setConfig($config, false);
        expect(file_exists($old))->toBeFalse()
            ->and(Config::getConfig())->toBe($config);

        Config::setConfig($config, false);
        expect(file_exists($old))->toBeFalse();
    } finally {
        @unlink($old);
    }
});
