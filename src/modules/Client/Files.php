<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use ClientFence\Mod\Support\TicketFiles;
use Doctrine\DBAL\Connection;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class Files
{
    public static function dir(): string
    {
        return dirname(PATH_ROOT) . '/.clientfence-client-files';
    }

    private static function path(array $row): string
    {
        return self::dir() . '/' . $row['client_id'] . '/' . $row['stored_name'];
    }

    public static function getList(\Pimple\Container $di, int $clientId): array
    {
        return $di['dbal']->fetchAllAssociative(
            'SELECT f.id, f.client_id, f.admin_id, f.name, f.size, f.mime, f.created_at, a.name AS author FROM client_file f LEFT JOIN admin a ON a.id = f.admin_id WHERE f.client_id = ? ORDER BY f.created_at DESC, f.id DESC',
            [$clientId]
        );
    }

    public static function upload(\Pimple\Container $di, int $clientId, int $adminId, mixed $file): array
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new InformationException('Choose a file to attach.');
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > TicketFiles::MAX_BYTES) {
            throw new InformationException('Each file must be smaller than :size MB.', [':size' => TicketFiles::MAX_BYTES / 1024 / 1024]);
        }
        $name = TicketFiles::cleanName($file->getClientOriginalName());
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (!TicketFiles::typeFits(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $mime)) {
            throw new InformationException('This type of file cannot be attached. Allowed: :types.', [':types' => implode(', ', array_keys(TicketFiles::TYPES))]);
        }
        $db = $di['dbal'];
        if ($db->fetchOne('SELECT id FROM client WHERE id = ?', [$clientId]) === false) {
            throw new InformationException('Client not found');
        }
        $row = [
            'client_id' => $clientId,
            'admin_id' => $adminId,
            'name' => $name,
            'stored_name' => bin2hex(random_bytes(16)),
            'size' => $size,
            'mime' => $mime,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $filesystem = $di['filesystem'];
        $filesystem->mkdir(self::dir() . '/' . $clientId, 0700);
        $file->move(self::dir() . '/' . $clientId, $row['stored_name']);
        try {
            $filesystem->chmod(self::path($row), 0600);
            $db->insert('client_file', $row);
        } catch (\Throwable $e) {
            $filesystem->remove(self::path($row));

            throw $e;
        }
        $row['id'] = (int) $db->lastInsertId();
        unset($row['stored_name']);

        return $row;
    }

    public static function delete(\Pimple\Container $di, int $id): void
    {
        $row = $di['dbal']->fetchAssociative('SELECT * FROM client_file WHERE id = ?', [$id]);
        if ($row === false) {
            throw new InformationException('File not found');
        }
        $di['filesystem']->remove(self::path($row));
        $di['dbal']->delete('client_file', ['id' => $id]);
    }

    public static function download(\Pimple\Container $di, int $id): BinaryFileResponse
    {
        $row = $di['dbal']->fetchAssociative('SELECT * FROM client_file WHERE id = ?', [$id]);
        if ($row === false || !$di['filesystem']->exists(self::path($row))) {
            throw new InformationException('File not found', [], 404);
        }
        $response = new BinaryFileResponse(self::path($row));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $row['name'], (string) preg_replace('/[^A-Za-z0-9._()\- ]/', '_', $row['name']));
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public static function forgetClient(Connection $db, int $clientId, Filesystem $filesystem): void
    {
        $filesystem->remove(self::dir() . '/' . $clientId);
        $db->delete('client_file', ['client_id' => $clientId]);
    }
}
