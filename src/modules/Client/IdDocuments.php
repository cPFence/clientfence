<?php

declare(strict_types=1);

namespace ClientFence\Mod\Client;

use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\Notifications;
use ClientFence\Mod\Order\Holds;
use ClientFence\Mod\Support\TicketFiles;
use Doctrine\DBAL\Connection;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ClientFence: ID documents (CF-124; System > Settings > Clients > ID documents, kept in mod_client). Off by default.
 * Staff choose who is asked: all clients who sign up after the rule is saved, clients in chosen groups, or only
 * clients whose order another check holds (hosting network, fraud check). Such a client uploads the front and back
 * of an ID on /identity; a checkout of a client asked by the first two rules waits for it (Order\Holds, reason
 * `id_document`). Staff approve or refuse it on the client's ID documents tab; approving releases the client's
 * checkouts that wait for nothing else, and the client gets an email either way.
 *
 * The files are encrypted with the site key, in a private folder outside the web root
 * (../.clientfence-id-documents/<client>/<random>, 0700/0600), staff download only. They are deleted a number of
 * days after the decision (prune(), from the cron; the record stays) and with the client (forgetClient()).
 * Off costs nothing: the client settings are already read on every client request.
 */
final class IdDocuments
{
    public const TABLE = 'client_id_document';

    public const OFF = 'off';
    public const NEW = 'new';
    public const GROUPS = 'groups';
    public const HELD = 'held';

    public const WAITING = 'waiting';
    public const APPROVED = 'approved';
    public const REFUSED = 'refused';

    /** Types by content: JPG, PNG, WebP and PDF, as payment receipts. */
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_FILES = 2;
    public const PER_HOUR = 5;

    /** The settings (mod_client) and their defaults; Extension\Service::setConfig() keeps them when the page's main form saves. */
    public const DEFAULTS = [
        'id_documents' => self::OFF,
        'id_documents_since' => '',
        'id_documents_groups' => [],
        'id_documents_days' => 30,
        'id_documents_text' => '',
    ];

    /** Bytes encrypted at a time: a 10 MB file never sits in memory whole. */
    private const CHUNK = 786432;

    public static function dir(): string
    {
        return rtrim(getenv('CLIENTFENCE_ID_DOCUMENT_DIR') ?: dirname(PATH_ROOT) . '/.clientfence-id-documents', '/');
    }

    /** @return array{rule: string, since: string, groups: list<int>, days: int, text: string} */
    public static function settings(\Pimple\Container $di): array
    {
        $saved = array_intersect_key((array) $di['mod_config']('client'), self::DEFAULTS) + self::DEFAULTS;
        $rule = (string) $saved['id_documents'];

        return [
            'rule' => in_array($rule, [self::NEW, self::GROUPS, self::HELD], true) ? $rule : self::OFF,
            'since' => (string) $saved['id_documents_since'],
            'groups' => array_map(intval(...), (array) $saved['id_documents_groups']),
            'days' => max(0, (int) $saved['id_documents_days']),
            'text' => (string) $saved['id_documents_text'],
        ];
    }

    /** Staff save the settings (Client settings page, ID documents tab). */
    public static function saveSettings(\Pimple\Container $di, array $data): void
    {
        $rule = (string) ($data['id_documents'] ?? self::OFF);
        if (!in_array($rule, [self::OFF, self::NEW, self::GROUPS, self::HELD], true)) {
            throw new InformationException('Choose who is asked for an ID document.');
        }
        $groups = $rule === self::GROUPS ? Groups::valid($di, (array) ($data['id_documents_groups'] ?? [])) : [];
        if ($rule === self::GROUPS && $groups === []) {
            throw new InformationException('Choose at least one client group.');
        }
        $days = filter_var($data['id_documents_days'] ?? self::DEFAULTS['id_documents_days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3650]]);
        if ($days === false) {
            throw new InformationException('Enter how many days the files are kept after the decision (0 to 3650).');
        }
        $text = trim((string) ($data['id_documents_text'] ?? ''));
        if (mb_strlen($text) > 5000) {
            throw new InformationException('The text for clients can have up to 5000 characters.');
        }
        $old = self::settings($di);
        // "All new clients" are those who sign up after the rule is saved; saving it again keeps that date.
        $since = $rule === self::NEW ? ($old['rule'] === self::NEW && $old['since'] !== '' ? $old['since'] : date('Y-m-d H:i:s')) : '';
        $extension = $di['mod_service']('extension');
        $extension->setConfig(array_merge($extension->getConfig('mod_client'), [
            'id_documents' => $rule,
            'id_documents_since' => $since,
            'id_documents_groups' => $groups,
            'id_documents_days' => $days,
            'id_documents_text' => $text,
        ], ['ext' => 'mod_client']));
        $di['logger']->info('ClientFence: ID document rule set to %s', $rule);
    }

    /**
     * Checkout: a client asked for an ID by the new-clients or groups rule, without an approved one, waits for it
     * (Order\Holds). The held-orders rule asks only once another check holds the order.
     *
     * @return array{reason: string, note: string}|null
     */
    public static function hold(\Pimple\Container $di, int $clientId): ?array
    {
        $settings = self::settings($di);
        if (!in_array($settings['rule'], [self::NEW, self::GROUPS], true)
            || (self::latest($di['dbal'], $clientId)['status'] ?? null) === self::APPROVED || !self::required($di, $clientId, $settings)) {
            return null;
        }

        return ['reason' => Holds::REASON_ID_DOCUMENT, 'note' => ''];
    }

    /** Whether the client should upload an ID now: asked, none approved and none waiting. */
    public static function needed(\Pimple\Container $di, int $clientId): bool
    {
        return self::status($di, $clientId)['needed'];
    }

    /**
     * The client's ID status (client API id_document_status, /identity, the dashboard and order banners): on, needed
     * (upload now), required, the latest send, the text staff wrote and the orders that wait.
     */
    public static function status(\Pimple\Container $di, int $clientId): array
    {
        $settings = self::settings($di);
        if ($settings['rule'] === self::OFF) {
            return ['on' => false, 'needed' => false, 'required' => false, 'latest' => null, 'text' => '', 'held' => []];
        }
        $db = $di['dbal'];
        $latest = self::latest($db, $clientId);
        $required = ($latest['status'] ?? null) !== self::APPROVED && self::required($di, $clientId, $settings);

        return [
            'on' => true,
            'needed' => $required && ($latest['status'] ?? null) !== self::WAITING,
            'required' => $required,
            'latest' => $latest,
            'text' => $settings['text'],
            'held' => $required ? array_map(intval(...), $db->fetchFirstColumn('SELECT client_order_id FROM ' . Holds::TABLE . ' WHERE client_id = ? AND status = ?', [$clientId, Holds::STATUS_HELD])) : [],
            'max_mb' => self::MAX_BYTES / 1024 / 1024,
        ];
    }

    /**
     * The client sends one or two files (front and back). Checks: the client is asked and has none waiting, JPG,
     * PNG, WebP or PDF by content, 10 MB each, at most PER_HOUR files an hour. Staff hear of it in the bell.
     *
     * @param list<mixed> $files
     */
    public static function send(\Pimple\Container $di, int $clientId, array $files): void
    {
        $db = $di['dbal'];
        $settings = self::settings($di);
        $status = self::latest($db, $clientId)['status'] ?? null;
        if ($settings['rule'] === self::OFF || $status === self::APPROVED || !self::required($di, $clientId, $settings)) {
            throw new InformationException('No ID document is needed for your account.');
        }
        if ($status === self::WAITING) {
            throw new InformationException('Your ID document is being checked. We will email you when it is done.');
        }
        $files = array_values(array_filter($files, static fn ($file): bool => $file instanceof UploadedFile));
        if ($files === [] || count($files) > self::MAX_FILES) {
            throw new InformationException('Choose the front of your ID document, and its back if it has one.');
        }
        $recent = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE client_id = ? AND created_at > ?', [$clientId, date('Y-m-d H:i:s', time() - 3600)]);
        if ($recent + count($files) > self::PER_HOUR) {
            throw new InformationException('You sent several files in the last hour. Please try again later.');
        }
        $rows = [];
        foreach ($files as $file) {
            /** @var UploadedFile $file */
            $size = (int) $file->getSize();
            if (!$file->isValid() || $size <= 0) {
                throw new InformationException('The file could not be uploaded. Please try again.');
            }
            if ($size > self::MAX_BYTES) {
                throw new InformationException('Each file must be smaller than :size MB.', [':size' => self::MAX_BYTES / 1024 / 1024]);
            }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
            if (!isset(self::TYPES[$mime])) {
                throw new InformationException('The file must be a JPG, PNG or WebP image or a PDF.');
            }
            $rows[] = [$file, ['client_id' => $clientId, 'name' => TicketFiles::cleanName($file->getClientOriginalName()), 'stored_name' => bin2hex(random_bytes(16)), 'mime' => $mime, 'size' => $size, 'status' => self::WAITING, 'created_at' => date('Y-m-d H:i:s')]];
        }
        $filesystem = $di['filesystem'];
        $filesystem->mkdir(self::dir() . '/' . $clientId, 0700);
        $written = [];
        try {
            foreach ($rows as [$file, $row]) {
                $written[] = self::path($row);
                self::encryptFile($di, $file->getPathname(), self::path($row));
            }
            $db->transactional(static function (Connection $db) use ($rows): void {
                foreach ($rows as [, $row]) {
                    $db->insert(self::TABLE, $row);
                }
            });
        } catch (\Throwable $e) {
            $filesystem->remove($written);

            throw $e;
        }
        $name = $db->fetchAssociative('SELECT first_name, last_name FROM client WHERE id = ?', [$clientId]) ?: [];
        Notifications::staff($di, 'id_document_sent', ['id' => $clientId, 'name' => trim(($name['first_name'] ?? '') . ' ' . ($name['last_name'] ?? ''))], 'client/manage/' . $clientId . '#tab-clientfence-id-documents', 'client');
        $di['logger']->info('ClientFence: client #%s sent an ID document (%d files)', $clientId, count($rows));
    }

    /** The client's ID documents for staff (newest first), without their stored names. */
    public static function listFor(\Pimple\Container $di, int $clientId): array
    {
        return $di['dbal']->fetchAllAssociative(
            'SELECT d.id, d.name, d.mime, d.size, d.status, d.reason, d.created_at, d.decided_at, d.stored_name IS NOT NULL AS has_file, a.name AS admin_name
             FROM ' . self::TABLE . ' d LEFT JOIN admin a ON a.id = d.admin_id WHERE d.client_id = ? ORDER BY d.id DESC',
            [$clientId]
        );
    }

    /** Staff approve the waiting send: the client's checkouts that wait only for it go on, and the client gets an email. */
    public static function approve(\Pimple\Container $di, int $clientId, ?int $adminId): void
    {
        self::decide($di, $clientId, $adminId, self::APPROVED, null);
        self::releaseHolds($di, $clientId, $adminId);
        self::email($di, $clientId, 'mod_client_id_document_approved', '');
    }

    /** Staff refuse the waiting send with the reason the client reads; held orders stay for staff (refused as today). */
    public static function refuse(\Pimple\Container $di, int $clientId, ?int $adminId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new InformationException('Enter the reason the client will read (up to 255 characters).');
        }
        self::decide($di, $clientId, $adminId, self::REFUSED, $reason);
        self::email($di, $clientId, 'mod_client_id_document_refused', $reason);
    }

    /** Approves (Order\Holds::approve()) each held checkout of the client whose only reason is the ID document. */
    public static function releaseHolds(\Pimple\Container $di, int $clientId, ?int $adminId): void
    {
        // One hold row per order; the orders of one checkout share their invoice (a free one: its time).
        $ids = $di['dbal']->fetchFirstColumn('SELECT MIN(id) FROM ' . Holds::TABLE . ' WHERE client_id = ? AND status = ? AND reason = ? GROUP BY invoice_id, created_at', [$clientId, Holds::STATUS_HELD, Holds::REASON_ID_DOCUMENT]);
        foreach ($ids as $id) {
            try {
                Holds::approve($di, (int) $id, $adminId);
            } catch (\Throwable $e) {
                // The others go on; staff still find this one in Orders > Waiting for review.
                $di['logger']->error('ClientFence: held order (hold #%s) not approved with the ID document: %s', $id, $e->getMessage());
            }
        }
    }

    /** A staff download: the file decrypted as it is sent, never stored in the clear or cached. */
    public static function download(\Pimple\Container $di, int $id): StreamedResponse
    {
        $row = $di['dbal']->fetchAssociative('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
        if ($row === false || $row['stored_name'] === null || !$di['filesystem']->exists(self::path($row))) {
            throw new InformationException('File not found', [], 404);
        }
        $crypt = $di['crypt'];
        $path = self::path($row);
        $response = new StreamedResponse(static function () use ($crypt, $path): void {
            self::decryptFile($crypt, $path, static function (string $bytes): void {
                echo $bytes;
            });
        });
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $row['name'], (string) preg_replace('/[^A-Za-z0-9._()\- ]/', '_', $row['name'])));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $di['logger']->setChannel('privacy')->info(sprintf('ID document #%d of client #%d opened by staff', $id, (int) $row['client_id']));

        return $response;
    }

    /** Cron: the files of documents decided more than the setting's days ago go; their records stay. */
    public static function prune(\Pimple\Container $di): int
    {
        $db = $di['dbal'];
        $cutoff = date('Y-m-d H:i:s', time() - self::settings($di)['days'] * 86400);
        $rows = $db->fetchAllAssociative('SELECT id, client_id, stored_name FROM ' . self::TABLE . ' WHERE status IN (?, ?) AND stored_name IS NOT NULL AND decided_at < ?', [self::APPROVED, self::REFUSED, $cutoff]);
        foreach ($rows as $row) {
            $di['filesystem']->remove(self::path($row));
            $db->update(self::TABLE, ['stored_name' => null], ['id' => $row['id']]);
        }

        return count($rows);
    }

    /** A deleted or anonymized client: the files and records go. */
    public static function forgetClient(Connection $db, int $clientId, Filesystem $filesystem): void
    {
        $filesystem->remove(self::dir() . '/' . $clientId);
        $db->delete(self::TABLE, ['client_id' => $clientId]);
    }

    /** Writes the file encrypted: one Crypt value per line, each a base64 chunk (Crypt keeps text only). */
    public static function encryptFile(\Pimple\Container $di, string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = $in === false ? false : fopen($to, 'xb');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }

            throw new \ClientFence\Exception('The ID document could not be stored.');
        }
        try {
            chmod($to, 0600);
            while (($chunk = fread($in, self::CHUNK)) !== false && $chunk !== '') {
                if (fwrite($out, $di['crypt']->encrypt(base64_encode($chunk)) . "\n") === false) {
                    throw new \ClientFence\Exception('The ID document could not be stored.');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** Reads an encrypted file chunk by chunk into $sink. */
    public static function decryptFile(\ClientFence\Crypt $crypt, string $path, callable $sink): void
    {
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new \ClientFence\Exception('The ID document could not be read.');
        }
        try {
            while (($line = fgets($in)) !== false) {
                $plain = $crypt->decrypt(rtrim($line, "\n"));
                $bytes = is_string($plain) ? base64_decode($plain, true) : false;
                if ($bytes === false) {
                    throw new \ClientFence\Exception('The ID document could not be decrypted.');
                }
                $sink($bytes);
            }
        } finally {
            fclose($in);
        }
    }

    private static function path(array $row): string
    {
        return self::dir() . '/' . (int) $row['client_id'] . '/' . $row['stored_name'];
    }

    /** @return array{status: string, reason: ?string, created_at: string, decided_at: ?string}|null the newest send */
    private static function latest(Connection $db, int $clientId): ?array
    {
        $row = $db->fetchAssociative('SELECT status, reason, created_at, decided_at FROM ' . self::TABLE . ' WHERE client_id = ? ORDER BY id DESC LIMIT 1', [$clientId]);

        return $row === false ? null : $row;
    }

    /** Whether the rule asks this client (an approved document is checked by the callers). */
    private static function required(\Pimple\Container $di, int $clientId, array $settings): bool
    {
        $db = $di['dbal'];

        return match ($settings['rule']) {
            self::NEW => $settings['since'] !== '' && (string) $db->fetchOne('SELECT created_at FROM client WHERE id = ?', [$clientId]) >= $settings['since'],
            self::GROUPS => array_intersect(Groups::ids($di, $clientId), $settings['groups']) !== [],
            self::HELD => $db->fetchOne('SELECT 1 FROM ' . Holds::TABLE . ' WHERE client_id = ? AND status = ? AND reason <> ? LIMIT 1', [$clientId, Holds::STATUS_HELD, Holds::REASON_ID_DOCUMENT]) !== false,
            default => false,
        };
    }

    /** Marks the client's waiting send decided; nothing waiting is an error (a second click). */
    private static function decide(\Pimple\Container $di, int $clientId, ?int $adminId, string $status, ?string $reason): void
    {
        $decided = $di['dbal']->executeStatement('UPDATE ' . self::TABLE . ' SET status = ?, reason = ?, admin_id = ?, decided_at = ? WHERE client_id = ? AND status = ?', [$status, $reason, $adminId, date('Y-m-d H:i:s'), $clientId, self::WAITING]);
        if ($decided === 0) {
            throw new InformationException('No ID document of this client is waiting for a decision.');
        }
        $di['logger']->info('ClientFence: ID document of client #%s %s by staff #%s', $clientId, $status, $adminId);
    }

    private static function email(\Pimple\Container $di, int $clientId, string $code, string $reason): void
    {
        try {
            $di['mod_service']('email')->sendTemplate(['to_client' => $clientId, 'code' => $code, 'reason' => $reason]);
        } catch (\Throwable $e) {
            $di['logger']->warning('ID document: client email %s not sent: %s', $code, $e->getMessage());
        }
    }
}
