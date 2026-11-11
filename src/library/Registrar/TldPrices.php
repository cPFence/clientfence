<?php

declare(strict_types=1);

/**
 * ClientFence: an optional registrar adapter capability, the registrar's price list
 * (Servicedomain/PriceSync.php). Adapters without it show "Not supported by this registrar".
 */
interface Registrar_TldPrices
{
    /**
     * The registrar's cost of ONE year per action, in the registrar's currency. A null or
     * non-positive price means the registrar does not offer that action.
     *
     * `prices` is keyed by TLD with its leading dot (".com"); `remaining` lists the TLDs not priced in
     * this call, to ask for again (registrars that price TLDs one by one work in slices).
     *
     * @param list<string> $tlds only these TLDs (with the leading dot); [] = every TLD the account can sell
     *
     * Shape: {currency: string, prices: array<string, {register: ?float, renew: ?float, transfer: ?float}>,
     * remaining: list<string>}. Left loose for PHPStan: PriceSync checks it, adapters may be third-party.
     *
     * @return array<string, mixed>
     *
     * @throws Registrar_Exception
     */
    public function getTldPrices(array $tlds = []): array;
}
