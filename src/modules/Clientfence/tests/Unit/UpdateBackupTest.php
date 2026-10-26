<?php

declare(strict_types=1);

/**
 * ClientFence: the file side of the backup System → Update makes (Mod\Clientfence\UpdateBackup),
 * in a temporary folder standing in for the site.
 */

use ClientFence\Mod\Clientfence\UpdateBackup;
use PhpZip\ZipFile;
use Symfony\Component\Filesystem\Filesystem;

function updateBackupZip(string $file, array $entries): string
{
    $zip = new ZipFile();
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->saveAsFile($file);
    $zip->close();

    return $file;
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/cf-backup-' . bin2hex(random_bytes(4));
    mkdir($this->root . '/site/lib', 0o755, true);
});

afterEach(function (): void {
    (new Filesystem())->remove($this->root);
});

test('files the release cannot write are found before anything changes', function (): void {
    $site = $this->root . '/site';
    file_put_contents($site . '/lib/a.php', 'old');
    mkdir($site . '/lib/folder');
    file_put_contents($site . '/plain', 'a file, not a folder');
    $archive = updateBackupZip($this->root . '/release.zip', [
        'lib/a.php' => 'new',
        'lib/new/b.php' => 'added in a new folder',
        'lib/folder' => 'a file where a folder is',
        'plain/c.php' => 'inside a file',
    ]);

    expect(UpdateBackup::unwritable($archive, $site))->toEqualCanonicalizing(['lib/folder', 'plain/c.php']);
});

test('putting files back goes on past a file it cannot write and names it', function (): void {
    $site = $this->root . '/site';
    file_put_contents($site . '/lib/a.php', 'new');
    file_put_contents($site . '/lib/same.php', 'same');
    mkdir($site . '/lib/folder');
    file_put_contents($site . '/lib/added.php', 'added by the release');
    $files = updateBackupZip($this->root . '/files.zip', [
        'lib/folder' => 'cannot be written over a folder',
        'lib/a.php' => 'old',
        'lib/same.php' => 'same',
    ]);
    $backup = ['dir' => $this->root, 'database' => '', 'files' => $files, 'replaced' => 3, 'added' => ['lib/added.php', 'lib/never-written.php']];

    expect(fn () => UpdateBackup::restoreFiles($backup, $site))->toThrow(RuntimeException::class, 'could not be put back: lib/folder');
    expect(file_get_contents($site . '/lib/a.php'))->toBe('old')
        ->and(file_get_contents($site . '/lib/same.php'))->toBe('same')
        ->and(file_exists($site . '/lib/added.php'))->toBeFalse();
});

test('putting every file back succeeds quietly', function (): void {
    $site = $this->root . '/site';
    file_put_contents($site . '/lib/a.php', 'new');
    $files = updateBackupZip($this->root . '/files.zip', ['lib/a.php' => 'old']);

    UpdateBackup::restoreFiles(['dir' => $this->root, 'database' => '', 'files' => $files, 'replaced' => 1, 'added' => []], $site);

    expect(file_get_contents($site . '/lib/a.php'))->toBe('old');
});

test('a new update backup removes the older ones and nothing else', function (): void {
    $dir = $this->root . '/backups';
    $make = function (string $name) use ($dir): string {
        mkdir("$dir/$name/sub", 0o700, true);
        file_put_contents("$dir/$name/database.sql.gz", 'dump');
        file_put_contents("$dir/$name/sub/files.zip", 'files');

        return "$dir/$name";
    };
    $make('update-1.0.0-alpha.4-to-1.0.0-alpha.6-20261018-120731');
    $make('update-1.0.0-alpha.5-to-1.0.0-alpha.6-20261018-110000');
    $new = $make('update-1.0.0-alpha.6-to-1.0.0-alpha.7-20261018-132700');
    // Not the updater's: a daily backup, a folder of the owner's and a link to another folder.
    file_put_contents("$dir/db_backup_2026-10-18_105502_3d88174c505d8657.sql.gz", 'daily');
    $make('update-notes');
    mkdir($this->root . '/elsewhere');
    file_put_contents($this->root . '/elsewhere/keep.txt', 'keep');
    symlink($this->root . '/elsewhere', "$dir/update-1.0.0-to-1.0.1-20260101-000000");

    $removed = UpdateBackup::prune($new);

    expect($removed)->toEqualCanonicalizing(['update-1.0.0-alpha.4-to-1.0.0-alpha.6-20261018-120731', 'update-1.0.0-alpha.5-to-1.0.0-alpha.6-20261018-110000'])
        ->and(array_values(array_diff(scandir($dir), ['.', '..'])))->toEqualCanonicalizing([
            'db_backup_2026-10-18_105502_3d88174c505d8657.sql.gz',
            'update-1.0.0-alpha.6-to-1.0.0-alpha.7-20261018-132700',
            'update-1.0.0-to-1.0.1-20260101-000000',
            'update-notes',
        ])
        ->and(file_get_contents("$new/sub/files.zip"))->toBe('files')
        ->and(file_get_contents($this->root . '/elsewhere/keep.txt'))->toBe('keep')
        ->and(UpdateBackup::prune($new))->toBe([]);
});

test('a file whose content is unchanged is not written again', function (): void {
    mkdir($this->root . '/site', 0o755, true);
    file_put_contents($this->root . '/site/.htaccess', 'rules');
    file_put_contents($this->root . '/site/edited.php', 'mine');
    touch($this->root . '/site/.htaccess', 1000000000);
    $zip = new ZipFile();
    $zip->addFromString('.htaccess', 'rules');
    $zip->addFromString('edited.php', 'release');
    $zip->addFromString('new/file.php', 'new');

    UpdateBackup::extract($zip, $this->root . '/site');
    $zip->close();

    // Unchanged: left alone (LiteSpeed restarts when .htaccess is written). Different or new: written.
    expect(filemtime($this->root . '/site/.htaccess'))->toBe(1000000000)
        ->and(file_get_contents($this->root . '/site/edited.php'))->toBe('release')
        ->and(file_get_contents($this->root . '/site/new/file.php'))->toBe('new');
});

test('a file written short (a full disk) fails the unpacking, so the old files are put back', function (): void {
    mkdir($this->root . '/site', 0o755, true);
    // As PhpZip on a full disk: the write runs short and only a notice is raised.
    $zip = new class extends ZipFile {
        public function extractTo(string $destDir, $entries = null, array $options = [], ?array &$extractedEntries = []): ZipFile
        {
            parent::extractTo($destDir, $entries, $options, $extractedEntries);
            file_put_contents($destDir . '/index.php', '<?php echo');

            return $this;
        }
    };
    $zip->addFromString('index.php', '<?php echo "ok";');

    expect(fn () => UpdateBackup::extract($zip, $this->root . '/site'))->toThrow(RuntimeException::class, 'not written in full');
    $zip->close();
});

test('extracted files are dated now, not at the time the zip stored', function (): void {
    $source = $this->root . '/future.twig';
    file_put_contents($source, 'template');
    touch($source, time() + 3 * 3600);
    $zip = new ZipFile();
    $zip->addFile($source, 'lib/future.twig');

    UpdateBackup::extract($zip, $this->root . '/site');
    $zip->close();

    expect(file_get_contents($this->root . '/site/lib/future.twig'))->toBe('template')
        ->and(filemtime($this->root . '/site/lib/future.twig'))->toBeLessThanOrEqual(time());
});
