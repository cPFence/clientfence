<?php

declare(strict_types=1);

/**
 * ClientFence: the release files a site changed (Mod\Clientfence\Customized), found with the file
 * list of the installed release, in a temporary folder standing in for the site.
 */

use ClientFence\Mod\Clientfence\Customized;
use PhpZip\ZipFile;
use Symfony\Component\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->site = sys_get_temp_dir() . '/cf-customized-' . bin2hex(random_bytes(4));
    mkdir($this->site . '/library/ClientFence', 0o755, true);
    mkdir($this->site . '/themes/x', 0o755, true);
    file_put_contents($this->site . '/themes/x/same.twig', 'same');
    file_put_contents($this->site . '/themes/x/changed.twig', 'changed here');
    file_put_contents($this->site . '/themes/x/added.twig', 'not in the release');
    $this->list = fn (string $version): int => file_put_contents($this->site . '/' . Customized::LIST, json_encode(['version' => $version, 'files' => [
        'themes/x/same.twig' => hash('sha256', 'same'),
        'themes/x/changed.twig' => hash('sha256', 'as released'),
        'install/index.php' => hash('sha256', 'removed after the install'),
        '../outside' => hash('sha256', 'never read'),
    ]]));
});

afterEach(function (): void {
    (new Filesystem())->remove($this->site);
});

test('only the release files changed on the site are listed, and zipped', function (): void {
    ($this->list)(ClientFence\Version::CLIENTFENCE);

    expect(Customized::files($this->site))->toBe(['themes/x/changed.twig']);

    $file = Customized::zip($this->site);
    try {
        $zip = (new ZipFile())->openFile($file);
        expect($zip->getListFiles())->toBe(['themes/x/changed.twig'])
            ->and($zip->getEntryContents('themes/x/changed.twig'))->toBe('changed here');
        $zip->close();
    } finally {
        @unlink($file);
    }
});

test('without a list of the installed version nothing can be checked', function (): void {
    expect(Customized::files($this->site))->toBeNull();

    ($this->list)('0.0.1');
    expect(Customized::files($this->site))->toBeNull();
});
