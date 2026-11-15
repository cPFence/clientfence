<?php

declare(strict_types=1);

/**
 * ClientFence: ID documents (Client\IdDocuments) on in-memory SQLite with the real Crypt and a temporary folder:
 * who is asked (new clients, groups, held orders), the checkout hold, the upload checks (type, size, files an
 * hour), the encrypted private file and its download, the cron removal after the set days and the erase with the
 * client. Approving releasing only the checkouts that wait for nothing else is in Order's HoldsTest.
 */

use ClientFence\InformationException;
use ClientFence\Mod\Client\IdDocuments;
use ClientFence\Mod\Order\Holds;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** A 1x1 PNG. */
function idDocumentPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
}

/** Client 9 signed up on 2026-11-10, client 8 long before; client 9 is in group 3. */
function idDocumentsDb(): Connection
{
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, created_at TEXT)');
    $db->executeStatement('CREATE TABLE client_group_members (client_id INTEGER, client_group_id INTEGER)');
    $db->executeStatement('CREATE TABLE client_order_hold (id INTEGER PRIMARY KEY AUTOINCREMENT, client_order_id INTEGER, client_id INTEGER, invoice_id INTEGER, reason TEXT, note TEXT, status TEXT, admin_id INTEGER, created_at TEXT, updated_at TEXT)');
    $db->executeStatement('CREATE TABLE client_id_document (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT NOT NULL, stored_name TEXT, mime TEXT NOT NULL, size INTEGER NOT NULL, status TEXT NOT NULL, reason TEXT, admin_id INTEGER, created_at TEXT NOT NULL, decided_at TEXT)');
    $db->executeStatement('CREATE TABLE admin (id INTEGER PRIMARY KEY, name TEXT)');
    $db->insert('client', ['id' => 9, 'first_name' => 'Ann', 'last_name' => 'Buyer', 'created_at' => '2026-11-10 09:00:00']);
    $db->insert('client', ['id' => 8, 'first_name' => 'Old', 'last_name' => 'Client', 'created_at' => '2020-01-01 00:00:00']);
    $db->insert('client_group_members', ['client_id' => 9, 'client_group_id' => 3]);

    return $db;
}

function idDocumentsDi(Connection $db, array $config): Pimple\Container
{
    $di = new Pimple\Container();
    $di['dbal'] = $db;
    $di['mod_config'] = $di->protect(fn (string $name): array => $name === 'client' ? $config : []);
    $di['filesystem'] = new Filesystem();
    $di['crypt'] = new ClientFence\Crypt();
    $di['logger'] = new Tests\Helpers\TestLogger();

    return $di;
}

/** Runs $test with the ID document folder in a temporary place; removes it after. */
function withIdDocumentDir(callable $test): void
{
    $dir = sys_get_temp_dir() . '/cf-id-documents-' . bin2hex(random_bytes(4));
    putenv('CLIENTFENCE_ID_DOCUMENT_DIR=' . $dir);

    try {
        $test($dir);
    } finally {
        putenv('CLIENTFENCE_ID_DOCUMENT_DIR');
        (new Filesystem())->remove($dir);
    }
}

/** A file to upload, as the browser would send it. */
function idDocumentUpload(string $bytes, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'cf-id');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

test('the three rules: clients who signed up after the rule, clients in the groups, clients with an order held by another check', function (): void {
    $db = idDocumentsDb();
    $new = idDocumentsDi($db, ['id_documents' => 'new', 'id_documents_since' => '2026-11-01 00:00:00']);
    $groups = idDocumentsDi($db, ['id_documents' => 'groups', 'id_documents_groups' => [3]]);
    $held = idDocumentsDi($db, ['id_documents' => 'held']);

    expect(IdDocuments::status($new, 9)['needed'])->toBeTrue()
        ->and(IdDocuments::status($new, 8)['needed'])->toBeFalse()
        ->and(IdDocuments::status($groups, 9)['needed'])->toBeTrue()
        ->and(IdDocuments::status($groups, 8)['needed'])->toBeFalse()
        ->and(IdDocuments::status($held, 9)['needed'])->toBeFalse();
    // An order held for the network asks for an ID; one held only for the ID document does not count here.
    Holds::add($db, [5], 9, null, Holds::REASON_NETWORK, 'HETZNER-AS (AS24940)');
    expect(IdDocuments::status($held, 9))->toMatchArray(['needed' => true, 'held' => [5]]);
});

test('off: nothing is asked and no table is read', function (): void {
    $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $di = idDocumentsDi($db, []);

    expect(IdDocuments::hold($di, 9))->toBeNull()
        ->and(IdDocuments::status($di, 9)['on'])->toBeFalse();
});

test('a checkout of an asked client waits for the ID document until one is approved; the held-orders rule adds no hold', function (): void {
    $db = idDocumentsDb();
    $di = idDocumentsDi($db, ['id_documents' => 'new', 'id_documents_since' => '2026-11-01 00:00:00']);

    expect(IdDocuments::hold($di, 9))->toBe(['reason' => Holds::REASON_ID_DOCUMENT, 'note' => ''])
        ->and(IdDocuments::hold($di, 8))->toBeNull()
        ->and(IdDocuments::hold(idDocumentsDi($db, ['id_documents' => 'held']), 9))->toBeNull();
    $db->insert('client_id_document', ['client_id' => 9, 'name' => 'id.png', 'mime' => 'image/png', 'size' => 1, 'status' => 'approved', 'created_at' => '2026-11-11 10:00:00']);
    expect(IdDocuments::hold($di, 9))->toBeNull();
});

test('a sent file is stored encrypted in the private folder and staff get the same bytes back', function (): void {
    withIdDocumentDir(function (string $dir): void {
        $db = idDocumentsDb();
        $di = idDocumentsDi($db, ['id_documents' => 'groups', 'id_documents_groups' => [3]]);
        $png = idDocumentPng();

        IdDocuments::send($di, 9, [idDocumentUpload($png, '../front.png'), null]);
        $row = $db->fetchAssociative('SELECT * FROM client_id_document');
        $path = $dir . '/9/' . $row['stored_name'];
        $stored = (string) file_get_contents($path);
        $back = '';
        IdDocuments::decryptFile($di['crypt'], $path, function (string $bytes) use (&$back): void {
            $back .= $bytes;
        });
        ob_start();
        IdDocuments::download($di, (int) $row['id'])->sendContent();
        $downloaded = (string) ob_get_clean();

        expect($row)->toMatchArray(['name' => 'front.png', 'mime' => 'image/png', 'size' => strlen($png), 'status' => 'waiting'])
            ->and(fileperms($path) & 0777)->toBe(0600)
            ->and(fileperms($dir . '/9') & 0777)->toBe(0700)
            ->and($stored)->toStartWith('v2:')
            ->and($stored)->not->toContain(substr(base64_encode($png), 0, 16))
            ->and($back)->toBe($png)
            ->and($downloaded)->toBe($png)
            // A second send waits for the decision.
            ->and(fn () => IdDocuments::send($di, 9, [idDocumentUpload($png, 'again.png')]))->toThrow(InformationException::class, 'being checked');
    });
});

test('only JPG, PNG, WebP or PDF by content, 10 MB each, two files, five an hour', function (): void {
    withIdDocumentDir(function (): void {
        $db = idDocumentsDb();
        $di = idDocumentsDi($db, ['id_documents' => 'groups', 'id_documents_groups' => [3]]);
        $png = idDocumentPng();
        $big = idDocumentUpload('', 'big.pdf');
        $handle = fopen($big->getPathname(), 'r+');
        ftruncate($handle, IdDocuments::MAX_BYTES + 1);
        fclose($handle);
        $big = new UploadedFile($big->getPathname(), 'big.pdf', null, null, true);

        expect(fn () => IdDocuments::send($di, 9, [idDocumentUpload('<?php echo 1;', 'id.png')]))->toThrow(InformationException::class, 'JPG, PNG or WebP')
            ->and(fn () => IdDocuments::send($di, 9, [$big]))->toThrow(InformationException::class, 'smaller than')
            ->and(fn () => IdDocuments::send($di, 9, [idDocumentUpload($png, 'a.png'), idDocumentUpload($png, 'b.png'), idDocumentUpload($png, 'c.png')]))->toThrow(InformationException::class, 'front')
            ->and(fn () => IdDocuments::send($di, 8, [idDocumentUpload($png, 'a.png')]))->toThrow(InformationException::class, 'No ID document is needed');
        foreach (range(1, 4) as $i) {
            $db->insert('client_id_document', ['client_id' => 9, 'name' => "old{$i}.png", 'mime' => 'image/png', 'size' => 1, 'status' => 'refused', 'created_at' => date('Y-m-d H:i:s', time() - 600)]);
        }
        expect(fn () => IdDocuments::send($di, 9, [idDocumentUpload($png, 'a.png'), idDocumentUpload($png, 'b.png')]))->toThrow(InformationException::class, 'last hour')
            ->and((int) $db->fetchOne("SELECT COUNT(*) FROM client_id_document WHERE status = 'waiting'"))->toBe(0);
    });
});

test('the cron removes the files the set days after the decision and keeps the records', function (): void {
    withIdDocumentDir(function (string $dir): void {
        $db = idDocumentsDb();
        $di = idDocumentsDi($db, ['id_documents' => 'groups', 'id_documents_groups' => [3], 'id_documents_days' => 30]);
        mkdir($dir . '/9', 0700, true);
        foreach (['old' => 31, 'recent' => 10] as $name => $days) {
            file_put_contents("{$dir}/9/{$name}", 'x');
            $db->insert('client_id_document', ['client_id' => 9, 'name' => "{$name}.png", 'stored_name' => $name, 'mime' => 'image/png', 'size' => 1, 'status' => 'approved', 'created_at' => '2026-10-01 10:00:00', 'decided_at' => date('Y-m-d H:i:s', time() - $days * 86400)]);
        }

        expect(IdDocuments::prune($di))->toBe(1)
            ->and(file_exists("{$dir}/9/old"))->toBeFalse()
            ->and(file_exists("{$dir}/9/recent"))->toBeTrue()
            ->and($db->fetchAllKeyValue('SELECT name, stored_name FROM client_id_document ORDER BY id'))->toBe(['old.png' => null, 'recent.png' => 'recent']);
    });
});

test('a deleted or anonymized client takes its ID documents with it', function (): void {
    withIdDocumentDir(function (string $dir): void {
        $db = idDocumentsDb();
        mkdir($dir . '/9', 0700, true);
        file_put_contents($dir . '/9/file', 'x');
        $db->insert('client_id_document', ['client_id' => 9, 'name' => 'id.png', 'stored_name' => 'file', 'mime' => 'image/png', 'size' => 1, 'status' => 'waiting', 'created_at' => '2026-11-11 10:00:00']);
        $db->insert('client_id_document', ['client_id' => 8, 'name' => 'other.png', 'mime' => 'image/png', 'size' => 1, 'status' => 'approved', 'created_at' => '2026-11-11 10:00:00']);

        IdDocuments::forgetClient($db, 9, new Filesystem());

        expect(is_dir($dir . '/9'))->toBeFalse()
            ->and($db->fetchFirstColumn('SELECT client_id FROM client_id_document'))->toBe([8]);
    });
});

test('refusing needs a reason the client reads; nothing waiting is an error', function (): void {
    $db = idDocumentsDb();
    $calls = [];
    $di = idDocumentsDi($db, ['id_documents' => 'groups', 'id_documents_groups' => [3]]);
    $email = new class($calls) {
        public function __construct(private array &$calls)
        {
        }

        public function sendTemplate(array $email): bool
        {
            $this->calls[] = [$email['code'], $email['to_client'], $email['reason']];

            return true;
        }
    };
    $di['mod_service'] = $di->protect(fn () => $email);
    $db->insert('client_id_document', ['client_id' => 9, 'name' => 'id.png', 'mime' => 'image/png', 'size' => 1, 'status' => 'waiting', 'created_at' => '2026-11-11 10:00:00']);

    expect(fn () => IdDocuments::refuse($di, 9, 1, ' '))->toThrow(InformationException::class, 'reason');
    IdDocuments::refuse($di, 9, 1, 'The photo is blurred.');

    expect($db->fetchAssociative('SELECT status, reason, admin_id FROM client_id_document'))->toBe(['status' => 'refused', 'reason' => 'The photo is blurred.', 'admin_id' => 1])
        ->and($calls)->toBe([['mod_client_id_document_refused', 9, 'The photo is blurred.']])
        ->and(IdDocuments::status($di, 9)['needed'])->toBeTrue()
        ->and(fn () => IdDocuments::refuse($di, 9, 1, 'Again'))->toThrow(InformationException::class, 'waiting');
});
