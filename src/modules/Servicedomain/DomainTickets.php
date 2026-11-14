<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicedomain;

use ClientFence\InformationException;
use ClientFence\Mod\Support\Entity\SupportTicket;

/**
 * ClientFence: the Email registrar works in ticket mode (GitHub #25).
 *
 * Each domain action (register, transfer, renew, delete, nameservers, contacts, lock, privacy, transfer code)
 * opens or updates one support ticket per domain: a task "domain" on the domain's order, whose rel_new_value
 * holds the requests waiting (action => values; a newer request of an action replaces the older one). The
 * stored domain keeps its values until staff mark the requests done (Set Task Complete on the ticket, or Mark
 * Done on the admin domain page): then the values are stored and the client gets a reply on the ticket. While
 * support tickets are off, a request is refused with a message to contact support.
 */
final class DomainTickets
{
    public const TASK = 'domain';

    /** Opens or updates the domain's ticket with the request, in the client's name. */
    public static function request(\Pimple\Container $di, \Model_ServiceDomain $model, string $action, array $values = []): void
    {
        $support = $di['mod_service']('support');
        $domain = $model->sld . $model->tld;
        if (!$support->ticketsEnabled()) {
            // Orders, renewals and the cron must not fail on a shop without tickets: they go through as the old adapter did.
            if (in_array($action, ['register', 'transfer', 'renew', 'delete'], true)) {
                self::apply($model, $action, $values);
                $model->updated_at = date('Y-m-d H:i:s');
                $di['db']->store($model);
                $di['logger']->warning('Domain %s: support tickets are off, so no ticket was opened for the %s: make it at your registrar', $domain, $action);

                return;
            }
            $di['logger']->warning('Domain %s: the %s request was refused because support tickets are off', $domain, $action);
            $url = $support->supportUrl();

            throw $url === null
                ? new InformationException('Please contact us to make this change to :domain.', [':domain' => $domain])
                : new InformationException('Please contact us at :url to make this change to :domain.', [':domain' => $domain, ':url' => $url]);
        }

        $order = self::order($di, $model);
        $client = $di['db']->getExistingModelById('Client', $model->client_id, 'Client not found');
        $ticket = self::ticket($di, $model, $order);
        $pending = self::pendingOf($ticket);
        // Two renewals before staff act renew for both periods.
        if ($action === 'renew' && isset($pending['renew'])) {
            $values['period'] = (int) ($values['period'] ?? 1) + (int) ($pending['renew']['period'] ?? 1);
        }
        $pending[$action] = $values;
        $json = json_encode($pending, JSON_THROW_ON_ERROR);
        $content = self::describe($domain, $action, $values);

        if ($ticket instanceof SupportTicket) {
            $ticket->setRelStatus(SupportTicket::REL_STATUS_PENDING)->setRelNewValue($json);
            $support->ticketReply($ticket, $client, $content);
        } else {
            $ticket = (new SupportTicket())
                ->setClientId((int) $client->id)
                ->setSubject(__trans('Domain requests: :domain', [':domain' => $domain]))
                ->setSupportHelpdesk($support->getHelpdeskRepository()->getDefault())
                ->setStatus(SupportTicket::STATUS_OPEN)
                ->setRelType(SupportTicket::REL_TYPE_ORDER)
                ->setRelId((int) $order->id)
                ->setRelTask(self::TASK)
                ->setRelStatus(SupportTicket::REL_STATUS_PENDING)
                ->setRelNewValue($json);
            $em = $di['em'];
            $em->wrapInTransaction(function () use ($em, $support, $ticket, $client, $content): void {
                $em->persist($ticket);
                $em->flush();
                $support->messageCreateForTicket($ticket, $client, $content);
            });
            $di['events_manager']->fire(['event' => 'onAfterClientOpenTicket', 'params' => ['id' => $ticket->getId()]]);
        }

        $di['logger']->info('Domain %s: %s requested in ticket #%s', $domain, $action, $ticket->getId());
    }

    /**
     * The requests waiting for staff, for the domain pages: ['ticket_id' => id, 'actions' => [action => text]], or null.
     */
    public static function pending(\Pimple\Container $di, \Model_ServiceDomain $model): ?array
    {
        $order = $di['mod_service']('order')->getServiceOrder($model);
        $ticket = $order instanceof \Model_ClientOrder ? self::ticket($di, $model, $order) : null;
        $pending = self::pendingOf($ticket);
        if (!$ticket instanceof SupportTicket || $pending === []) {
            return null;
        }
        $actions = [];
        foreach ($pending as $action => $values) {
            $actions[$action] = self::describe($model->sld . $model->tld, (string) $action, (array) $values);
        }

        return ['ticket_id' => $ticket->getId(), 'actions' => $actions];
    }

    /** Mark Done on the admin domain page; staff may give the transfer code and the expiry date. */
    public static function done(\Pimple\Container $di, \Model_ServiceDomain $model, \Model_Admin $admin, ?string $code = null, ?string $expiresAt = null): void
    {
        $ticket = self::ticket($di, $model, self::order($di, $model));
        $pending = self::pendingOf($ticket);
        if (!$ticket instanceof SupportTicket || $pending === []) {
            throw new InformationException('This domain has no requests waiting.');
        }
        self::complete($di, $model, $ticket, $pending, $admin, $code, $expiresAt);
    }

    /** Set Task Complete on the domain's ticket (Support\Service::ticketTaskComplete()). */
    public static function doneFromTicket(\Pimple\Container $di, SupportTicket $ticket, \Model_Admin $admin): void
    {
        $order = $di['db']->getExistingModelById('ClientOrder', (int) $ticket->getRelId(), 'Order not found');
        $model = $di['mod_service']('order')->getOrderService($order);
        if (!$model instanceof \Model_ServiceDomain) {
            throw new InformationException('The domain of this ticket was not found.');
        }
        self::complete($di, $model, $ticket, self::pendingOf($ticket), $admin);
    }

    private static function complete(\Pimple\Container $di, \Model_ServiceDomain $model, SupportTicket $ticket, array $pending, \Model_Admin $admin, ?string $code = null, ?string $expiresAt = null): void
    {
        $code = trim((string) $code);
        $expiry = trim((string) $expiresAt) === '' ? null : strtotime((string) $expiresAt);
        if ($expiry === false) {
            throw new InformationException('Check the expiry date.');
        }

        $domain = $model->sld . $model->tld;
        $done = [];
        foreach ($pending as $action => $values) {
            $values = (array) $values;
            self::apply($model, (string) $action, $values);
            $done[] = self::describe($domain, (string) $action, $values);
        }
        if ($code !== '') {
            $model->transfer_code = $code;
        }
        if ($expiry !== null) {
            $model->expires_at = date('Y-m-d H:i:s', $expiry);
        }
        $model->updated_at = date('Y-m-d H:i:s');
        $di['db']->store($model);

        $reply = __trans('Your requests for :domain are done:', [':domain' => $domain]) . "\n\n" . implode("\n\n", $done);
        if ($code !== '') {
            $reply .= "\n\n" . __trans('Transfer code (EPP code): :code', [':code' => $code]);
        }
        $ticket->markTaskComplete();
        $di['mod_service']('support')->ticketReply($ticket, $admin, $reply);

        $di['logger']->info('Domain %s: requests in ticket #%s marked done', $domain, $ticket->getId());
    }

    /** What a request done changes on the domain (not stored here). */
    private static function apply(\Model_ServiceDomain $model, string $action, array $values): void
    {
        $years = max(1, (int) ($values['period'] ?? $model->period ?? 1));
        if ($action === 'register') {
            $model->registered_at = date('Y-m-d H:i:s');
            $model->expires_at = date('Y-m-d H:i:s', strtotime("+{$years} years"));
        } elseif ($action === 'renew') {
            $from = $model->expires_at ? strtotime((string) $model->expires_at) : time();
            $model->expires_at = date('Y-m-d H:i:s', strtotime("+{$years} years", $from ?: time()));
        } elseif (in_array($action, ['nameservers', 'contacts', 'lock', 'privacy'], true)) {
            foreach ($values as $field => $value) {
                $model->{$field} = $value;
            }
        }
    }

    /** The request as ticket text: a title line, then its values as a list. */
    private static function describe(string $domain, string $action, array $values): string
    {
        $p = [':domain' => $domain];
        $title = match ($action) {
            'register' => __trans('Register :domain', $p),
            'transfer' => __trans('Transfer :domain to us', $p),
            'renew' => __trans('Renew :domain', $p),
            'delete' => __trans('Delete :domain at the registrar', $p),
            'nameservers' => __trans('Change the nameservers of :domain', $p),
            'contacts' => __trans('Update the contact details of :domain', $p),
            'lock' => empty($values['locked']) ? __trans('Unlock :domain', $p) : __trans('Lock :domain', $p),
            'privacy' => empty($values['privacy']) ? __trans('Turn off privacy protection for :domain', $p) : __trans('Turn on privacy protection for :domain', $p),
            'transfer_code' => __trans('Send the transfer code (EPP code) of :domain', $p),
            default => $action . ': ' . $domain,
        };
        $labels = [
            'period' => __trans('Years'),
            'ns1' => __trans('Nameserver 1'),
            'ns2' => __trans('Nameserver 2'),
            'ns3' => __trans('Nameserver 3'),
            'ns4' => __trans('Nameserver 4'),
            'contact_first_name' => __trans('First Name'),
            'contact_last_name' => __trans('Last Name'),
            'contact_email' => __trans('Email'),
            'contact_company' => __trans('Company'),
            'contact_address1' => __trans('Address Line 1'),
            'contact_address2' => __trans('Address Line 2'),
            'contact_city' => __trans('City'),
            'contact_state' => __trans('State'),
            'contact_postcode' => __trans('Zip'),
            'contact_country' => __trans('Country'),
            'contact_phone_cc' => __trans('Phone Country Code'),
            'contact_phone' => __trans('Phone Number'),
        ];
        $lines = [];
        foreach ($labels as $field => $label) {
            if (isset($values[$field]) && trim((string) $values[$field]) !== '') {
                $lines[] = '- ' . $label . ': ' . $values[$field];
            }
        }

        return $lines === [] ? $title : $title . "\n\n" . implode("\n", $lines);
    }

    private static function pendingOf(?SupportTicket $ticket): array
    {
        if (!$ticket instanceof SupportTicket || $ticket->getRelStatus() !== SupportTicket::REL_STATUS_PENDING) {
            return [];
        }
        $pending = json_decode((string) $ticket->getRelNewValue(), true);

        return is_array($pending) ? $pending : [];
    }

    /** The domain's one ticket (the newest, when staff opened another by hand). */
    private static function ticket(\Pimple\Container $di, \Model_ServiceDomain $model, \Model_ClientOrder $order): ?SupportTicket
    {
        return $di['mod_service']('support')->getSupportTicketRepository()->findOneBy([
            'clientId' => (int) $model->client_id,
            'relType' => SupportTicket::REL_TYPE_ORDER,
            'relId' => (int) $order->id,
            'relTask' => self::TASK,
        ], ['id' => 'DESC']);
    }

    private static function order(\Pimple\Container $di, \Model_ServiceDomain $model): \Model_ClientOrder
    {
        $order = $di['mod_service']('order')->getServiceOrder($model);

        return $order instanceof \Model_ClientOrder ? $order : throw new \ClientFence\Exception('Domain order not found');
    }
}
