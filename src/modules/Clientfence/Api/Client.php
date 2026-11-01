<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence\Api;

use ClientFence\Mod\Client\Users;
use ClientFence\Mod\Clientfence\Notifications;

class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * The bell of the client area: the newest notifications of the account and how many are
     * unread. An authorized user only sees the ones whose page their permissions open.
     */
    public function notifications_get(): array
    {
        $di = $this->getDi();
        // shortcut: users of one account share the read marks; per-user marks if users ask for it.
        $items = array_values(array_filter(
            Notifications::latest($di, Notifications::CLIENT, $this->clientId(), static fn (string $path): string => $path, 50),
            static fn (array $item): bool => Users::pageDenied($di, $item['url']) === null
        ));
        // Count visible unread notices beyond the bell's short list too.
        $unread = count(array_filter(
            Notifications::unreadRows($di, Notifications::CLIENT, $this->clientId()),
            static fn (array $item): bool => Users::pageDenied($di, $item['url']) === null
        ));
        $items = array_map(static fn (array $item): array => ['url' => $di['url']->link($item['url'])] + $item, array_slice($items, 0, Notifications::LIST_SIZE));

        return ['unread' => $unread, 'items' => $items];
    }

    /** Marks one notification (`id`) or all of them as read. */
    public function notifications_read($data): int
    {
        $di = $this->getDi();
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0 && !$this->visible($id)) {
            return 0;
        }

        // Shared read marks must leave the pages a limited user cannot open unread.
        if ($id <= 0 && ($context = Users::context($di)) !== null && !$context['owner']) {
            $read = 0;
            foreach (Notifications::unreadRows($di, Notifications::CLIENT, $this->clientId()) as $item) {
                if (Users::pageDenied($di, $item['url']) === null) {
                    $read += Notifications::markRead($di, Notifications::CLIENT, $this->clientId(), $item['id']);
                }
            }

            return $read;
        }

        return Notifications::markRead($di, Notifications::CLIENT, $this->clientId(), $id > 0 ? $id : null);
    }

    /** Hides one notification (`id`) from the bell. */
    public function notifications_dismiss($data): int
    {
        $id = (int) ($data['id'] ?? 0);

        return $id > 0 && $this->visible($id) ? Notifications::dismiss($this->getDi(), Notifications::CLIENT, $this->clientId(), $id) : 0;
    }

    /** Whether the signed-in user's bell lists this notification (an authorized user sees only the pages they may open). */
    private function visible(int $id): bool
    {
        $url = Notifications::url($this->getDi(), Notifications::CLIENT, $this->clientId(), $id);

        return $url !== null && Users::pageDenied($this->getDi(), $url) === null;
    }

    /**
     * The tax the client's invoices get (name and rate; rate 0 when tax is off or the client is
     * exempt), so the checkout shows the amount the invoice will ask for. With prices that include
     * tax (`inclusive`) the invoice asks for the price itself, or for an exempt client the price less
     * `deduct`, the rate of their location (Invoice\Service::applyInclusiveTax()).
     *
     * @return array{name: string, rate: float, inclusive: bool, deduct: float}
     */
    public function tax(): array
    {
        $di = $this->getDi();
        $client = $di['db']->load('Client', $this->clientId());
        if (!$client instanceof \Model_Client || !$client->id) {
            return ['name' => '', 'rate' => 0.0, 'inclusive' => false, 'deduct' => 0.0];
        }
        $name = '';
        $rate = (float) $di['mod_service']('Invoice', 'Tax')->getTaxRateForClient($client, $name);
        $inclusive = $di['mod_service']('invoice')->pricesIncludeTax();
        $deduct = $inclusive && $rate <= 0 ? (float) $di['mod_service']('Invoice', 'Tax')->getLocationTaxRate($client) : 0.0;

        return ['name' => (string) $name, 'rate' => max(0.0, $rate), 'inclusive' => $inclusive, 'deduct' => max(0.0, $deduct)];
    }

    private function clientId(): int
    {
        $identity = $this->getIdentity();

        return (int) ($identity->id ?? 0);
    }
}
