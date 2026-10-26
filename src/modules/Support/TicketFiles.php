<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\Mod\Client\Users;

use ClientFence\InformationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * ClientFence: ticket attachments: clients,
 * authorized users with `tickets` and staff attach files to a new ticket or a reply.
 *
 * Upload first, then send: the ticket forms upload each chosen file at once (upload(): checked,
 * stored, waiting in this browser session) and send their ids with the message as
 * `ticket_files[]`. When FOSSBilling has saved the message (events onAfter*OpenTicket and
 * onAfter*ReplyTicket, Support\Service), attach() gives the waiting files to it. A file that never
 * gets a message is removed after a day.
 *
 * Files live outside the web root (dirFor(): next to the FOSSBilling folder,
 * ~/.clientfence-ticket-files by default; CLIENTFENCE_TICKET_FILES_DIR overrides it), one
 * folder per client, under a random id without extension. Table support_ticket_file keeps
 * the name, type, size and who sent it. The type is read from the content and must fit the
 * name's extension (TYPES); pages show images and download everything else, never run or
 * sniffed by the browser (response()). Clients open them at /ticket-file/<id> (their own
 * tickets only), staff in the admin area.
 *
 * Staff with the support permission remove a file (remove(): the file goes, the message shows
 * it was removed); prune() (cron) removes files older than the "keep ticket files" setting
 * (ticket_files_days, default 180, 0 = keep) and files whose ticket is gone.
 */
final class TicketFiles
{
    /** Days a sent file is kept by default (setting ticket_files_days, Support settings; 0: kept). */
    public const KEEP_DAYS = 180;
    public const MAX_FILES = 5;
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Uploads per login an hour (clients and users; staff UPLOADS_PER_HOUR_STAFF). */
    public const UPLOADS_PER_HOUR = 30;
    public const UPLOADS_PER_HOUR_STAFF = 120;
    /** Files a browser session may hold before they are sent. */
    public const MAX_WAITING = 10;
    public const WAITING_HOURS = 24;
    /** Extension => the types its content may have (WHMCS's list plus csv; never SVG or HTML). */
    public const TYPES = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'txt' => 'text', 'log' => 'text', 'csv' => 'text',
        'zip' => ['application/zip'],
        'rar' => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'],
        'tar' => ['application/x-tar'],
    ];
    /** Shown on the page instead of downloaded. */
    private const IMAGES = ['image/jpeg', 'image/png', 'image/gif'];
    /** Text files (logs, notes, CSV) may hold anything readable; they are always sent as plain text. */
    private const TEXT_TYPES = ['application/json', 'application/xml', 'application/csv', 'application/x-ndjson'];

    /**
     * What the upload fields need: the extensions, the number and the size of files.
     */
    public static function rules(): array
    {
        return [
            'extensions' => array_keys(self::TYPES),
            'accept' => implode(',', array_map(static fn (string $e): string => '.' . $e, array_keys(self::TYPES))),
            'max_files' => self::MAX_FILES,
            'max_bytes' => self::MAX_BYTES,
            'max_mb' => (int) (self::MAX_BYTES / 1024 / 1024),
        ];
    }

    public static function dir(): string
    {
        return rtrim((string) (getenv('CLIENTFENCE_TICKET_FILES_DIR') ?: dirname(PATH_ROOT) . '/.clientfence-ticket-files'), '/');
    }

    private static function path(int $clientId, string $token): string
    {
        return self::dir() . '/' . $clientId . '/' . $token;
    }

    private static function sessionHash(\Pimple\Container $di): string
    {
        return hash('sha256', 'clientfence-ticket-files|' . (string) $di['session']->getId());
    }

    /**
     * Stores an uploaded file for the signed-in client (or authorized user) and keeps it waiting
     * for the next ticket message of this browser session. $ticketId: the ticket of a reply
     * (null for a new ticket). Returns {id, name, size, size_label}.
     */
    public static function uploadForClient(\Pimple\Container $di, int $clientId, ?int $ticketId, mixed $file): array
    {
        if ($ticketId !== null && $di['em']->getConnection()->fetchOne('SELECT id FROM support_ticket WHERE id = ? AND client_id = ?', [$ticketId, $clientId]) === false) {
            throw new InformationException('Ticket not found');
        }
        [$kind, $uploaderId] = self::clientUploader($di, $clientId);

        return self::store($di, $file, $clientId, $ticketId, $kind, $uploaderId, self::UPLOADS_PER_HOUR);
    }

    /**
     * Stores an uploaded file for a staff reply to a ticket. Returns {id, name, size, size_label}.
     */
    public static function uploadForStaff(\Pimple\Container $di, int $adminId, int $ticketId, mixed $file): array
    {
        $clientId = $di['em']->getConnection()->fetchOne('SELECT client_id FROM support_ticket WHERE id = ?', [$ticketId]);
        if ($clientId === false || $clientId === null) {
            throw new InformationException('Ticket not found');
        }

        return self::store($di, $file, (int) $clientId, $ticketId, 'admin', $adminId, self::UPLOADS_PER_HOUR_STAFF);
    }

    private static function store(\Pimple\Container $di, mixed $file, int $clientId, ?int $ticketId, string $kind, int $uploaderId, int $perHour): array
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new InformationException('Choose a file to attach.');
        }
        $db = $di['em']->getConnection();
        $session = self::sessionHash($di);
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM support_ticket_file WHERE uploader_kind = ? AND uploader_id = ? AND created_at > ?', [$kind, $uploaderId, date('Y-m-d H:i:s', time() - 3600)]) >= $perHour) {
            throw new InformationException('You attached many files in the last hour. Please try again later.');
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM support_ticket_file WHERE session_hash = ? AND message_id IS NULL', [$session]) >= self::MAX_WAITING) {
            throw new InformationException('Send your message first: at most :count files can wait to be sent.', [':count' => self::MAX_WAITING]);
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InformationException('Each file must be smaller than :size MB.', [':size' => self::MAX_BYTES / 1024 / 1024]);
        }
        $name = self::cleanName((string) $file->getClientOriginalName());
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if (!self::typeFits($extension, $mime)) {
            throw new InformationException('This type of file cannot be attached. Allowed: :types.', [':types' => implode(', ', array_keys(self::TYPES))]);
        }

        $token = bin2hex(random_bytes(16));
        $folder = self::dir() . '/' . $clientId;
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new \ClientFence\Exception('The file could not be stored.');
        }
        $file->move($folder, $token);
        chmod($folder . '/' . $token, 0600);
        $now = date('Y-m-d H:i:s');
        try {
            $db->insert('support_ticket_file', [
                'token' => $token,
                'ticket_id' => $ticketId,
                'message_id' => null,
                'client_id' => $clientId,
                'uploader_kind' => $kind,
                'uploader_id' => $uploaderId,
                'session_hash' => $session,
                'name' => $name,
                'mime' => $mime,
                'size' => $size,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            @unlink($folder . '/' . $token);

            throw $e;
        }

        return ['id' => $token, 'name' => $name, 'size' => $size, 'size_label' => self::sizeLabel($size)];
    }

    /**
     * Whether a file's content type fits its extension.
     */
    public static function typeFits(string $extension, string $mime): bool
    {
        $allowed = self::TYPES[$extension] ?? null;
        if ($allowed === null) {
            return false;
        }
        if ($allowed === 'text') {
            return str_starts_with($mime, 'text/') || in_array($mime, self::TEXT_TYPES, true);
        }

        return in_array($mime, $allowed, true);
    }

    /**
     * A file name to show: letters (any language), digits and . _ - ( ) and spaces; at most 120
     * characters, keeping the extension.
     */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace(['/[^\p{L}\p{N} ._()\-]+/u', '/\s+/u'], ['_', ' '], $name), " .\t");
        $extension = (string) pathinfo($name, PATHINFO_EXTENSION);
        $base = (string) pathinfo($name, PATHINFO_FILENAME);
        $base = mb_substr($base, 0, 120 - mb_strlen($extension) - 1) ?: 'file';

        return $extension !== '' ? $base . '.' . strtolower($extension) : $base;
    }

    public static function sizeLabel(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? number_format($bytes / 1024 / 1024, 1) . ' MB' : max(1, (int) ceil($bytes / 1024)) . ' KB';
    }

    /**
     * Gives the files a ticket form sent (`ticket_files[]` of the request) to the message just
     * saved: the newest client message of the ticket ($staff false) or the newest staff message
     * ($staff true). Only files this browser session uploaded for this client, by the author of
     * that message, still waiting, at most MAX_FILES. Never fails the message itself.
     */
    public static function attachFromRequest(\Pimple\Container $di, int $ticketId, bool $staff): int
    {
        if ($ticketId <= 0) {
            return 0;
        }
        try {
            $payload = $di['request']->getPayload()->all();
        } catch (\Throwable) {
            return 0;
        }
        $tokens = $payload['ticket_files'] ?? [];
        if (!is_array($tokens) || $tokens === []) {
            return 0;
        }

        try {
            return self::attach($di, $ticketId, $staff, $tokens);
        } catch (\Throwable $e) {
            $di['logger']->error('ClientFence: ticket #%s attachments failed: %s', $ticketId, $e->getMessage());

            return 0;
        }
    }

    public static function attach(\Pimple\Container $di, int $ticketId, bool $staff, array $tokens): int
    {
        $tokens = array_slice(array_values(array_unique(array_filter(array_map(
            static fn ($t): string => is_string($t) && preg_match('/^[a-f0-9]{32}$/', $t) === 1 ? $t : '',
            $tokens
        )))), 0, self::MAX_FILES);
        if ($tokens === []) {
            return 0;
        }
        $db = $di['em']->getConnection();
        $clientId = $db->fetchOne('SELECT client_id FROM support_ticket WHERE id = ?', [$ticketId]);
        if ($clientId === false || $clientId === null) {
            return 0;
        }
        $message = $db->fetchAssociative(
            $staff
                ? 'SELECT id, admin_id FROM support_ticket_message WHERE support_ticket_id = ? AND admin_id IS NOT NULL ORDER BY id DESC LIMIT 1'
                : 'SELECT id, client_id FROM support_ticket_message WHERE support_ticket_id = ? AND admin_id IS NULL AND client_id = ? ORDER BY id DESC LIMIT 1',
            $staff ? [$ticketId] : [$ticketId, (int) $clientId]
        );
        if ($message === false) {
            return 0;
        }
        $already = (int) $db->fetchOne('SELECT COUNT(*) FROM support_ticket_file WHERE message_id = ?', [(int) $message['id']]);
        $room = max(0, self::MAX_FILES - $already);
        $attached = 0;
        $session = self::sessionHash($di);
        foreach (array_slice($tokens, 0, $room) as $token) {
            $params = [(int) $message['id'], $ticketId, date('Y-m-d H:i:s'), $token, $session, (int) $clientId, $ticketId];
            $sql = 'UPDATE support_ticket_file SET message_id = ?, ticket_id = ?, attached_at = ?
                     WHERE token = ? AND session_hash = ? AND client_id = ? AND message_id IS NULL AND removed_at IS NULL
                       AND (ticket_id IS NULL OR ticket_id = ?)';
            if ($staff) {
                $sql .= " AND uploader_kind = 'admin' AND uploader_id = ?";
                $params[] = (int) $message['admin_id'];
            } else {
                $sql .= " AND uploader_kind IN ('client', 'user')";
            }
            $attached += $db->executeStatement($sql, $params);
        }

        return $attached;
    }

    /**
     * Attachments of an imported email (MailImport) given to a ticket message, under the same
     * rules as an upload: at most MAX_FILES files of MAX_BYTES, types from TYPES whose content
     * fits the name. Guest tickets keep theirs under client 0 (staff see them). Returns the names
     * of the files that were not kept.
     *
     * @param array<int, array{name: string, mime: string, content: string}> $attachments
     *
     * @return string[]
     */
    public static function attachFromMail(\Pimple\Container $di, int $clientId, int $ticketId, int $messageId, array $attachments): array
    {
        $skipped = [];
        $kept = 0;
        $db = $di['em']->getConnection();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($attachments as $attachment) {
            $name = self::cleanName($attachment['name']);
            $size = strlen($attachment['content']);
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            $mime = (string) $finfo->buffer($attachment['content']);
            if ($kept >= self::MAX_FILES || $size === 0 || $size > self::MAX_BYTES || !self::typeFits($extension, $mime)) {
                $skipped[] = $name;

                continue;
            }
            $token = bin2hex(random_bytes(16));
            $folder = self::dir() . '/' . $clientId;
            if ((!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) || file_put_contents($folder . '/' . $token, $attachment['content']) !== $size) {
                @unlink($folder . '/' . $token);
                $skipped[] = $name;

                continue;
            }
            chmod($folder . '/' . $token, 0600);
            $now = date('Y-m-d H:i:s');
            try {
                $db->insert('support_ticket_file', [
                    'token' => $token,
                    'ticket_id' => $ticketId,
                    'message_id' => $messageId,
                    'client_id' => $clientId,
                    'uploader_kind' => 'mail',
                    'uploader_id' => 0,
                    'session_hash' => '',
                    'name' => $name,
                    'mime' => $mime,
                    'size' => $size,
                    'created_at' => $now,
                    'attached_at' => $now,
                ]);
            } catch (\Throwable $e) {
                @unlink($folder . '/' . $token);

                throw $e;
            }
            ++$kept;
        }

        return $skipped;
    }

    /**
     * Throws away a file the session uploaded but did not send (the × beside a chosen file).
     */
    public static function discard(\Pimple\Container $di, string $token, string $kind, int $uploaderId): void
    {
        $db = $di['em']->getConnection();
        $row = $db->fetchAssociative(
            "SELECT id, client_id, token FROM support_ticket_file WHERE token = ? AND session_hash = ? AND message_id IS NULL AND uploader_kind = ? AND uploader_id = ?",
            [$token, self::sessionHash($di), $kind, $uploaderId]
        );
        if ($row === false) {
            throw new InformationException('File not found');
        }
        @unlink(self::path((int) $row['client_id'], (string) $row['token']));
        $db->delete('support_ticket_file', ['id' => (int) $row['id']]);
    }

    /**
     * The uploader of a client-area request: the authorized user, else the owner.
     *
     * @return array{0: string, 1: int}
     */
    public static function clientUploader(\Pimple\Container $di, int $clientId): array
    {
        $context = Users::context($di);

        return $context !== null ? ['user', (int) $context['user_id']] : ['client', $clientId];
    }

    /**
     * A ticket (support_ticket_get, client or staff) with each message's files as
     * `ticket_files`: {id, name, size_label, image, removed, removed_reason}.
     */
    public static function withFiles(\Pimple\Container $di, array $ticket): array
    {
        $ids = array_values(array_filter(array_map(static fn ($m): int => is_array($m) ? (int) ($m['id'] ?? 0) : 0, $ticket['messages'] ?? [])));
        if ($ids === []) {
            return $ticket;
        }
        try {
            $rows = $di['em']->getConnection()->fetchAllAssociative(
                'SELECT token, message_id, name, mime, size, removed_at, removed_reason FROM support_ticket_file
                  WHERE ticket_id = ? AND message_id IN (' . implode(',', $ids) . ') ORDER BY id',
                [(int) ($ticket['id'] ?? 0)]
            );
        } catch (\Throwable) {
            return $ticket;
        }
        $byMessage = [];
        foreach ($rows as $row) {
            $byMessage[(int) $row['message_id']][] = [
                'id' => (string) $row['token'],
                'name' => (string) $row['name'],
                'size_label' => self::sizeLabel((int) $row['size']),
                'image' => in_array($row['mime'], self::IMAGES, true),
                'removed' => $row['removed_at'] !== null,
                'removed_reason' => $row['removed_reason'],
            ];
        }
        foreach ($ticket['messages'] as $i => $message) {
            $ticket['messages'][$i]['ticket_files'] = $byMessage[(int) ($message['id'] ?? 0)] ?? [];
        }

        return $ticket;
    }

    /**
     * A sent file by id (not removed), with its ticket's client: the row, or null.
     */
    public static function find(\Pimple\Container $di, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        $row = $di['em']->getConnection()->fetchAssociative(
            'SELECT * FROM support_ticket_file WHERE token = ? AND message_id IS NOT NULL AND removed_at IS NULL',
            [$token]
        );

        return $row !== false && is_file(self::path((int) $row['client_id'], $token)) ? $row : null;
    }

    /**
     * The HTTP response for a file (find()): images shown, everything else downloaded; text as
     * plain text; never run or sniffed by the browser.
     */
    public static function response(array $row): Response
    {
        $mime = (string) $row['mime'];
        $image = in_array($mime, self::IMAGES, true);
        $type = self::TYPES[strtolower((string) pathinfo((string) $row['name'], PATHINFO_EXTENSION))] ?? null;
        $contentType = $type === 'text' ? 'text/plain; charset=utf-8' : ($image || $mime === 'application/pdf' ? $mime : 'application/octet-stream');
        $name = (string) $row['name'];
        $ascii = (string) preg_replace('/[^A-Za-z0-9._()\- ]+/', '_', $name);

        return new Response((string) file_get_contents(self::path((int) $row['client_id'], (string) $row['token'])), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => ($image ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $ascii) . "\"; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Staff remove a sent file (a client posted a password, or it is not wanted): the file is
     * deleted, the message shows it was removed. Logged under the client.
     */
    public static function remove(\Pimple\Container $di, string $token, ?int $adminId): void
    {
        $db = $di['em']->getConnection();
        $row = $db->fetchAssociative('SELECT * FROM support_ticket_file WHERE token = ? AND message_id IS NOT NULL AND removed_at IS NULL', [$token]);
        if ($row === false) {
            throw new InformationException('File not found');
        }
        @unlink(self::path((int) $row['client_id'], $token));
        $db->update('support_ticket_file', ['removed_at' => date('Y-m-d H:i:s'), 'removed_by' => $adminId, 'removed_reason' => 'staff'], ['id' => (int) $row['id']]);
        Users::activity($di, [(int) $row['client_id']], sprintf('Ticket #%d: attachment %s removed by staff', (int) $row['ticket_id'], $row['name']), $adminId);
    }

    /**
     * Cron: removes sent files older than the "keep ticket files" setting, files whose ticket or
     * message is gone and files never sent. Returns how many files went.
     */
    public static function prune(\Pimple\Container $di): int
    {
        $db = $di['em']->getConnection();
        $removed = 0;
        $setting = (string) ($di['mod_config']('support')['ticket_files_days'] ?? '');
        $days = max(0, $setting === '' ? self::KEEP_DAYS : (int) $setting);
        if ($days > 0) {
            foreach ($db->fetchAllAssociative('SELECT id, client_id, token FROM support_ticket_file WHERE message_id IS NOT NULL AND removed_at IS NULL AND created_at < ? LIMIT 500', [date('Y-m-d H:i:s', time() - $days * 86400)]) as $row) {
                @unlink(self::path((int) $row['client_id'], (string) $row['token']));
                $db->update('support_ticket_file', ['removed_at' => date('Y-m-d H:i:s'), 'removed_reason' => 'expired'], ['id' => (int) $row['id']]);
                ++$removed;
            }
        }
        $gone = $db->fetchAllAssociative(
            'SELECT f.id, f.client_id, f.token FROM support_ticket_file f
               LEFT JOIN support_ticket_message m ON m.id = f.message_id
              WHERE (f.message_id IS NOT NULL AND m.id IS NULL) OR (f.message_id IS NULL AND f.created_at < ?)
              LIMIT 500',
            [date('Y-m-d H:i:s', time() - self::WAITING_HOURS * 3600)]
        );
        foreach ($gone as $row) {
            @unlink(self::path((int) $row['client_id'], (string) $row['token']));
            $db->delete('support_ticket_file', ['id' => (int) $row['id']]);
            ++$removed;
        }

        return $removed;
    }

    /**
     * A deleted client takes its ticket files with it (Users::clientDeleted).
     */
    public static function forgetClient(\Doctrine\DBAL\Connection $db, int $clientId): void
    {
        try {
            $db->delete('support_ticket_file', ['client_id' => $clientId]);
        } catch (\Throwable) {
            // No table yet.
        }
        $folder = self::dir() . '/' . $clientId;
        foreach (glob($folder . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($folder);
    }
}
