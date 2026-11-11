<?php

declare(strict_types=1);

namespace ClientFence;

use ClientFence\Mod\Order\Entity\Order;
use ClientFence\Mod\Product\Entity\Product;

/**
 * ClientFence: client IP self-service for software licences and API keys (CF-56), the rules both
 * modules share. The product's Configuration tab sets "Servers (IP addresses)" (ip_seats: how many
 * single IP addresses a service holds; empty or 0: no limit, the module's own rules), "IP changes
 * per 30 days" (ip_changes: empty no limit, 0 only staff), "Bind on first check" (ip_bind) and
 * "Changes need staff approval" (ip_approval). They are read from the product each time, so a
 * stricter product, or a move to another one, applies to existing services.
 *
 * Adding an IP address into a free place is free; removing one, or resetting a licence, is one
 * change: free for an IP added in the last 24 hours that no check has used yet (a typo; a used one
 * would let one place move between servers every day) and while the list holds more IPs than the
 * product allows. Staff changes never count. With approval on, a client's change waits as the
 * one request of the service. A list over the limit keeps passing, but nothing is added to it.
 *
 * The state (each IP's added date, the IPs a check has used, the client's changes of the last 30 days and the waiting request)
 * is the `ip_state` key of the service's own config JSON; IPs without a date count as added long ago.
 */
final class IpSeats
{
    public const ADD = 'add';
    public const REMOVE = 'remove';
    public const RESET = 'reset';
    private const WINDOW = 30 * 86400;
    private const TYPO = 86400;

    /**
     * @param list<string>        $ips
     * @param array<string, int>  $added ip => when it was added
     * @param array<string, bool> $seen  ip => a check has used it
     * @param list<int>           $used  when the client's counted changes were made
     * @param array<string, mixed>|null $pending {type, ip, cost, at}
     */
    private function __construct(
        public readonly int $seats,
        public readonly ?int $changes,
        public readonly bool $bind,
        public readonly bool $approval,
        public array $ips,
        private array $added,
        private array $seen,
        private array $used,
        public ?array $pending,
    ) {
    }

    /** The rules of `$product` (its config) for a service with these IPs and config JSON. */
    public static function of(array $product, bool $bindDefault, array $ips, ?string $config): self
    {
        $state = (json_decode($config ?? '', true) ?: [])['ip_state'] ?? [];
        $changes = $product['ip_changes'] ?? '';

        return new self(
            self::seatsOf($product),
            is_numeric($changes) ? max(0, (int) $changes) : null,
            Tools::normalizeBoolean($product['ip_bind'] ?? $bindDefault),
            Tools::normalizeBoolean($product['ip_approval'] ?? false),
            array_values(array_map(self::spelling(...), $ips)),
            $state['added'] ?? [],
            $state['seen'] ?? [],
            $state['changes'] ?? [],
            $state['pending'] ?? null,
        );
    }

    public static function seatsOf(array $product): int
    {
        return max(0, (int) ($product['ip_seats'] ?? 0));
    }

    /**
     * The config of the order's product as it is now. While a plan change waits for payment, the smaller
     * server limit of the two holds, so the list cannot outgrow the plan it moves to (Upgrades::apply()).
     */
    public static function productConfig(\Pimple\Container $di, ?Order $order): array
    {
        // From the row, not the entity: a plan change may have moved the order since it was loaded.
        $config = self::configOf($di, $order === null ? null : (int) $di['dbal']->fetchOne('SELECT product_id FROM client_order WHERE id = ?', [$order->getId()]));
        $to = $order === null ? false : $di['dbal']->fetchOne('SELECT u.to_product_id FROM ' . Mod\Order\Upgrades::TABLE . ' u JOIN invoice i ON i.id = u.invoice_id WHERE u.order_id = ? AND u.status = ? AND i.status = ? ORDER BY u.id DESC LIMIT 1', [$order->getId(), Mod\Order\Upgrades::STATUS_PENDING, \Model_Invoice::STATUS_UNPAID]);
        $toSeats = $to === false ? 0 : self::seatsOf(self::configOf($di, (int) $to));
        if ($toSeats > 0 && (self::seatsOf($config) === 0 || $toSeats < self::seatsOf($config))) {
            $config['ip_seats'] = $toSeats;
        }

        return $config;
    }

    /**
     * Runs `$change` with the order's row locked, as Upgrades::apply() locks it, and `$service` read again, so
     * a plan change and the IP changes of one service never overlap (adds while a downgrade applies).
     */
    public static function locked(\Pimple\Container $di, ?Order $order, object $service, callable $change): mixed
    {
        $db = $di['em']->getConnection();

        return $db->transactional(static function () use ($di, $db, $order, $service, $change): mixed {
            if ($order !== null && $db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
                $db->fetchOne('SELECT id FROM client_order WHERE id = ? FOR UPDATE', [$order->getId()]);
            }
            $di['em']->refresh($service);

            return $change();
        });
    }

    private static function configOf(\Pimple\Container $di, ?int $productId): array
    {
        $product = $productId ? $di['em']->getRepository(Product::class)->find($productId) : null;

        return $product instanceof Product ? (json_decode($product->getConfig() ?? '', true) ?: []) : [];
    }

    public function limited(): bool
    {
        return $this->seats > 0;
    }

    /**
     * A licence or key check from `$ip`: passes when listed, or takes a free place when binding is on.
     * Returns null when it fails, else whether the state changed (to be saved).
     */
    public function check(string $ip, int $now): ?bool
    {
        $ip = self::spelling($ip);
        if (in_array($ip, $this->ips, true)) {
            $changed = !isset($this->seen[$ip]);
            $this->seen[$ip] = true;

            return $changed;
        }
        if (!$this->bind || count($this->ips) >= $this->seats) {
            return null;
        }
        $this->ips[] = $ip;
        $this->added[$ip] = $now;
        $this->seen[$ip] = true;

        return true;
    }

    /**
     * A client's add, remove or reset, or staff's approve, refuse or clear, or the client's withdraw;
     * returns what happened: {event: applied|asked|approved|refused|withdrawn|cleared, type, ip, done
     * (the change was made), reason}.
     */
    public function act(string $action, string $ip, string $reason, int $now): array
    {
        if (in_array($action, [self::ADD, self::REMOVE, self::RESET], true)) {
            $request = $this->ask($action, $ip, $now);
            if ($this->approval) {
                $this->pending = $request;

                return $this->event('asked', $request);
            }
            $this->apply($request, $now);

            return $this->event('applied', $request, true);
        }
        if ($action === 'clear') {
            $this->used = [];

            return $this->event('cleared', ['type' => '', 'ip' => '']);
        }
        $request = $this->pending ?? throw new InformationException('There is no IP address request waiting.');
        $this->pending = null;
        if ($action === 'approve') {
            // Applied only while it still can be (a check may have taken the free place meanwhile).
            try {
                // Counted as it is now: an IP a check used while the request waited is no longer a free typo.
                $request['cost'] = $this->cost($request['type'], $request['ip'], $now);
                $this->capCheck($request['cost'], $now);
            } catch (InformationException $e) {
                return $this->event('refused', $request, false, $e->getMessage());
            }
            $this->apply($request, $now);

            return $this->event('approved', $request, true);
        }

        return $this->event($action === 'refuse' ? 'refused' : 'withdrawn', $request, false, trim($reason));
    }

    /** Staff set the list: IPs they keep keep their date, new ones are added now. */
    public function setList(array $ips, int $now): void
    {
        $ips = array_values(array_unique(array_map(self::spelling(...), $ips)));
        $added = [];
        foreach ($ips as $ip) {
            $added[$ip] = in_array($ip, $this->ips, true) ? ($this->added[$ip] ?? 0) : $now;
        }
        $this->ips = $ips;
        $this->added = $added;
    }

    /** The service config JSON with this state in it. */
    public function config(?string $config): string
    {
        $all = json_decode($config ?? '', true) ?: [];
        $all['ip_state'] = [
            'added' => array_intersect_key($this->added, array_flip($this->ips)),
            'seen' => array_intersect_key($this->seen, array_flip($this->ips)),
            'changes' => $this->recent(time()),
            'pending' => $this->pending,
        ];

        return (string) json_encode($all);
    }

    /** What the service pages show. */
    public function view(int $now): array
    {
        $used = $this->recent($now);
        $left = $this->changes === null ? null : max(0, $this->changes - count($used));
        $date = static fn (?int $time): ?string => $time ? date('Y-m-d H:i:s', $time) : null;

        return [
            'ip_seats' => $this->seats,
            'ips' => array_map(fn (string $ip): array => [
                'ip' => $ip,
                'added_at' => $date($this->added[$ip] ?? null),
                'free' => $this->typo($ip, $now),
            ], $this->ips),
            'changes' => $this->changes,
            'changes_used' => count($used),
            'changes_left' => $left,
            'next_free_change' => $this->changes ? $date($this->nextFree($now)) : null,
            'pending' => $this->pending === null ? null : ['type' => $this->pending['type'], 'ip' => $this->pending['ip'], 'at' => $date($this->pending['at'])],
            'approval' => $this->approval,
            'bind' => $this->bind,
        ];
    }

    /**
     * What an IP action answers staff: an approval that could no longer be applied was refused (and
     * saved, with the client told), so it says why instead of "Done".
     */
    public static function approved(string $action, array $event): bool
    {
        if ($action === 'approve' && !$event['done']) {
            throw new InformationException('The change no longer fits, so it was refused and the client was told: :reason', [':reason' => $event['reason']]);
        }

        return true;
    }

    /** History, bell and email for what act() returned. `$order` is the service's order. */
    public static function tell(\Pimple\Container $di, Order $order, array $event): void
    {
        $what = match ($event['type']) {
            self::ADD => 'add IP ' . $event['ip'],
            self::REMOVE => 'remove IP ' . $event['ip'],
            default => 'reset the license',
        };
        $note = match ($event['event']) {
            'applied' => 'Client IP change: ' . $what,
            'asked' => 'Client IP change requested (waiting for staff approval): ' . $what,
            'withdrawn' => 'Client withdrew the IP change request: ' . $what,
            'approved' => 'Staff approved the IP change request: ' . $what,
            'refused' => 'Staff refused the IP change request: ' . $what . ($event['reason'] !== '' ? '. Reason: ' . $event['reason'] : ''),
            default => 'Staff cleared the client\'s IP change count',
        };
        $di['mod_service']('order')->saveStatusChange($order, $note);

        $id = (int) $order->getId();
        $params = ['id' => $id, 'title' => (string) $order->getTitle()];
        if ($event['event'] === 'asked') {
            Mod\Clientfence\Notifications::staff($di, 'ip_request', $params, 'order/manage/' . $id, 'order');
        }
        if (!in_array($event['event'], ['approved', 'refused'], true)) {
            return;
        }
        $approved = $event['event'] === 'approved';
        Mod\Clientfence\Notifications::client($di, (int) $order->getClientId(), $approved ? 'ip_request_approved' : 'ip_request_refused', $params, 'order/service/manage/' . $id);
        try {
            $di['mod_service']('email')->sendTemplate([
                'to_client' => (int) $order->getClientId(),
                'code' => 'mod_order_ip_request',
                'order' => $params,
                'request' => ['type' => $event['type'], 'ip' => $event['ip']],
                'approved' => $approved,
                'reason' => $event['reason'],
            ]);
        } catch (\Throwable $e) {
            $di['logger']->warning('IP change request of order #%s: client email not sent: %s', $id, $e->getMessage());
        }
    }

    /** A client's change, checked: {type, ip, cost, at}. */
    private function ask(string $type, string $ip, int $now): array
    {
        if ($this->pending !== null) {
            throw new InformationException('A change of these IP addresses is already waiting for approval.');
        }
        if ($type !== self::ADD && $this->changes === 0) {
            throw new InformationException('Only our staff can change these IP addresses. Please open a ticket.');
        }
        if ($type === self::ADD) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new InformationException('This is not a single IP address: :ip', [':ip' => $ip]);
            }
            $ip = self::spelling($ip);
        }
        $cost = $this->cost($type, $ip, $now);
        $this->capCheck($cost, $now);

        return ['type' => $type, 'ip' => $ip, 'cost' => $cost, 'at' => $now];
    }

    /** Refuses a counted change once the client's changes of the last 30 days reach the product's number. */
    private function capCheck(int $cost, int $now): void
    {
        $free = $cost > 0 ? $this->nextFree($now) : null;
        if ($free !== null) {
            $date = (new \IntlDateFormatter(null, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE))->format($free);
            throw new InformationException('You have used your :count IP changes for 30 days. The next one is free on :date.', [':count' => $this->changes, ':date' => $date]);
        }
    }

    /** When the next counted change is allowed, or null when one is allowed now (or there is no limit). */
    private function nextFree(int $now): ?int
    {
        $used = $this->recent($now);
        if (!$this->changes || count($used) < $this->changes) {
            return null;
        }
        sort($used);

        // The change that must leave the 30 days so fewer than `changes` are left (the allowance may have been lowered).
        return $used[count($used) - $this->changes] + self::WINDOW;
    }

    /** One spelling per address (2001:DB8::1, 2001:0db8:0:0:0:0:0:1 and 2001:db8::1 are one place). */
    private static function spelling(string $ip): string
    {
        $packed = @inet_pton(trim($ip));

        return $packed === false ? $ip : (string) inet_ntop($packed);
    }

    /** Whether the change can be made now, and what it counts (0 or 1). */
    private function cost(string $type, string $ip, int $now): int
    {
        if ($type === self::RESET) {
            return 1;
        }
        if (!$this->limited()) {
            throw new InformationException('This plan has no server limit, so its IP addresses are not added or removed one at a time.');
        }
        if ($type === self::ADD) {
            if (in_array($ip, $this->ips, true)) {
                throw new InformationException('This IP address is already on the list.');
            }
            if (count($this->ips) >= $this->seats) {
                throw new InformationException('All :count places are in use. Remove an IP address first.', [':count' => $this->seats]);
            }

            return 0;
        }
        if (!in_array($ip, $this->ips, true)) {
            throw new InformationException('This IP address is not on the list.');
        }

        return count($this->ips) > $this->seats || $this->typo($ip, $now) ? 0 : 1;
    }

    private function typo(string $ip, int $now): bool
    {
        return !isset($this->seen[$ip]) && $now - ($this->added[$ip] ?? 0) < self::TYPO;
    }

    private function apply(array $request, int $now): void
    {
        if ($request['type'] === self::ADD) {
            $this->ips[] = $request['ip'];
            $this->added[$request['ip']] = $now;
        } elseif ($request['type'] === self::REMOVE) {
            $this->ips = array_values(array_diff($this->ips, [$request['ip']]));
        } else {
            $this->ips = [];
        }
        if ($request['cost'] > 0) {
            $this->used[] = $now;
        }
    }

    /** @return list<int> */
    private function recent(int $now): array
    {
        return array_values(array_filter($this->used, static fn (int $time): bool => $time > $now - self::WINDOW));
    }

    private function event(string $event, array $request, bool $done = false, string $reason = ''): array
    {
        return ['event' => $event, 'type' => $request['type'], 'ip' => $request['ip'], 'done' => $done, 'reason' => $reason];
    }
}
