<?php

declare(strict_types=1);

// ClientFence: the last installer page gives the config.php mode System Health asks for, and the real Health path.
test('the installer result page asks for config.php mode 600 and names the System Health tab', function (): void {
    $page = (string) file_get_contents(dirname(__DIR__, 2) . '/src/install/assets/result.html.twig');

    expect($page)->toContain('chmod 600 {{ config_file_path }}')
        ->not->toContain('chmod 644')
        ->toContain('System → Settings → System → System Health')
        ->not->toContain('System → Health warns');
});
