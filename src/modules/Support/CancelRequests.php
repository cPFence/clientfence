<?php

declare(strict_types=1);

namespace ClientFence\Mod\Support;

use ClientFence\InformationException;
use ClientFence\Mod\Support\Entity\SupportTicket;
use ClientFence\Tools;
use Pimple\Container;

/**
 * ClientFence: cancellation requests, as WHMCS.
 *
 * The client's request (a ticket with a pending "cancel" task) says when the service ends: Immediate,
 * or End of billing period (kept in the ticket's rel_new_value). Staff accept it on the ticket page,
 * or deny it (the task is set complete). With "Cancellation requests: processed automatically" on
 * (Orders settings, WHMCS Automation Settings), the cron accepts each request at its next run: an
 * Immediate one cancels the service then, an End of billing period one schedules the cancellation at
 * the end of the paid period. Requests made before the type existed stay with staff.
 */
final class CancelRequests
{
    public const IMMEDIATE = 'immediate';
    public const END_OF_PERIOD = 'end_of_period';

    /**
     * Refuses a type the client cannot choose for the order.
     */
    public static function assertType(Container $di, ?\Model_ClientOrder $order, string $type): void
    {
        if (!in_array($type, [self::IMMEDIATE, self::END_OF_PERIOD], true)) {
            throw new \ClientFence\Exception('Invalid cancellation type.');
        }
        if ($type === self::END_OF_PERIOD && (!$order instanceof \Model_ClientOrder || !$di['mod_service']('order')->canCancelAtPeriodEnd($order))) {
            throw new InformationException('This service cannot be cancelled at the end of its period. Choose Immediate, or open a ticket.');
        }
    }

    /**
     * Cancels the service of a pending request now or at the end of its period, and completes the task.
     */
    public static function accept(Container $di, SupportTicket $ticket): void
    {
        $type = $ticket->getRelNewValue();
        if ($ticket->getRelType() !== SupportTicket::REL_TYPE_ORDER || $ticket->getRelTask() !== SupportTicket::REL_TASK_CANCEL
            || $ticket->getRelStatus() !== SupportTicket::REL_STATUS_PENDING || !in_array($type, [self::IMMEDIATE, self::END_OF_PERIOD], true)) {
            throw new InformationException('This ticket has no pending cancellation request.');
        }
        $orderService = $di['mod_service']('order');
        $order = $orderService->findByClientIdAndOrderId((int) $ticket->getClientId(), (int) $ticket->getRelId());
        // Staff deleted or cancelled it already: nothing is left to do.
        if ($order instanceof \Model_ClientOrder && $order->status !== 'canceled') {
            $reason = 'Cancellation request (ticket #' . $ticket->getId() . ')';
            if ($type === self::END_OF_PERIOD) {
                $orderService->scheduleCancellationFromOrder($order, $reason);
            } else {
                $orderService->cancelFromOrder($order, $reason);
            }
        }
        $ticket->markTaskComplete();
        $di['em']->flush();
        $di['logger']->info('Accepted the cancellation request of ticket #%s', $ticket->getId());
    }

    /**
     * The cron: accepts the pending requests when the setting is on; a request that fails stays for staff.
     */
    public static function run(Container $di): void
    {
        if (!Tools::normalizeBoolean($di['mod']('order')->getConfig()['cancel_requests_auto'] ?? false)) {
            return;
        }
        $ids = $di['em']->getConnection()->fetchFirstColumn(
            'SELECT id FROM support_ticket WHERE rel_type = ? AND rel_task = ? AND rel_status = ? AND rel_new_value IN (?, ?) ORDER BY id',
            [SupportTicket::REL_TYPE_ORDER, SupportTicket::REL_TASK_CANCEL, SupportTicket::REL_STATUS_PENDING, self::IMMEDIATE, self::END_OF_PERIOD],
        );
        foreach ($ids as $id) {
            $ticket = $di['em']->find(SupportTicket::class, (int) $id);
            if (!$ticket instanceof SupportTicket) {
                continue;
            }
            try {
                self::accept($di, $ticket);
            } catch (\Throwable $e) {
                $di['logger']->error('Support: the cancellation request of ticket #%s was not processed: %s', $id, $e->getMessage());
            }
        }
    }
}
