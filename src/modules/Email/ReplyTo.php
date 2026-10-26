<?php

declare(strict_types=1);

namespace ClientFence\Mod\Email;

/**
 * ClientFence: a Reply-To kept for one queued email (email_queue has no column for it), used instead
 * of the Email settings' Reply-To when the email is sent. Ticket emails of a help desk whose
 * mailbox is imported ask for replies to that help desk (Support\MailImport::replyAddress()), so
 * a client's email reply reaches the ticket. A row lives as long as its queued email.
 */
final class ReplyTo
{
    public const TABLE = 'email_queue_reply_to';

    /** Keeps $address for the queued email; an invalid or empty address keeps nothing. */
    public static function remember(\Pimple\Container $di, int $queueId, ?string $address): void
    {
        $address = trim((string) $address);
        if ($queueId < 1 || $address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        try {
            $di['em']->getConnection()->executeStatement(
                'REPLACE INTO ' . self::TABLE . ' (queue_id, reply_to, created_at) VALUES (?, ?, ?)',
                [$queueId, $address, date('Y-m-d H:i:s')]
            );
        } catch (\Exception $e) {
            // The email still goes out, with the Email settings' Reply-To.
            if (isset($di['logger'])) {
                $di['logger']->setChannel('email')->warning('Reply-To not kept for a queued email: ' . $e->getMessage());
            }
        }
    }

    /** The Reply-To kept for the queued email, if any. */
    public static function find(\Pimple\Container $di, int $queueId): ?string
    {
        try {
            $address = $di['em']->getConnection()->fetchOne('SELECT reply_to FROM ' . self::TABLE . ' WHERE queue_id = ?', [$queueId]);
        } catch (\Exception) {
            return null;
        }

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    /** Drops the row of a sent email, and rows of emails removed from the queue a day ago or more. */
    public static function forget(\Pimple\Container $di, int $queueId): void
    {
        try {
            $di['em']->getConnection()->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE queue_id = ? OR (created_at < ? AND queue_id NOT IN (SELECT id FROM email_queue))',
                [$queueId, date('Y-m-d H:i:s', time() - 86400)]
            );
        } catch (\Exception) {
            // Left for the next sent email to clean up.
        }
    }
}
