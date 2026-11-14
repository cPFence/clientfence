<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
class Registrar_Adapter_Email extends Registrar_AdapterAbstract
{
    protected $config;

    public function __construct($options)
    {
        // ClientFence core patch: ticket mode needs no email address (an older config keeps its unused one).
        $this->config['use_rdap'] = (bool) ($options['use_rdap'] ?? $options['use_whois'] ?? false);
    }

    public static function getConfig(): array
    {
        return [
            'label' => __trans('This registrar opens a support ticket for each domain request (registration, transfer, renewal, nameservers, contacts, lock, privacy, transfer code). You make the change at your real registrar, then mark the request done on the ticket or the domain page: the domain is updated and the client is told.'),
            'form' => [
                'use_rdap' => ['radio', [
                    'multiOptions' => ['1' => __trans('Yes'), '0' => __trans('No')],
                    'default' => '0',
                    'label' => __trans('Use RDAP Registry Lookups to Check for Domain Availability'),
                ],
                ],
            ],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain)
    {
        $this->getLog()->debug('Checking domain availability: ' . $domain->getName());

        if (!$this->config['use_rdap']) {
            throw new Registrar_Exception(':type: registrar is unable to :action:', [':type:' => 'Email', ':action:' => 'determine domain availability'], 3001);
        }

        $result = $this->getRdap()->isDomainAvailable($domain->getName());
        if ($result !== null) {
            return $result;
        }

        throw new Registrar_Exception('Unable to determine the availability of :domain via RDAP', [':domain' => $domain->getName()]);
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $this->getLog()->debug('Checking if domain can be transferred: ' . $domain->getName());

        if (!$this->config['use_rdap']) {
            return true;
        }

        if ($this->getRdap()->isDomainAvailable($domain->getName()) ?? false) {
            throw new Registrar_Exception('Domain :domain is not registered, so it cannot be transferred. You may be able to register it instead.', [':domain' => $domain->getName()]);
        }

        return true;
    }

    // ClientFence core patch: ticket mode (GitHub #25): Servicedomain\Service opens the domain's support ticket for every
    // domain action (Servicedomain/DomainTickets.php) and never reaches these: no email is sent and nothing fakes success.
    public function modifyNs(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    public function transferDomain(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    public function getDomainDetails(Registrar_Domain $domain)
    {
        return $domain;
    }

    public function deleteDomain(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    public function registerDomain(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    public function renewDomain(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    public function modifyContact(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    public function enablePrivacyProtection(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    public function disablePrivacyProtection(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $this->ticketsOnly();
    }

    public function lock(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    public function unlock(Registrar_Domain $domain)
    {
        $this->ticketsOnly();
    }

    private function ticketsOnly(): never
    {
        throw new Registrar_Exception('The Email registrar takes domain requests in support tickets, opened from the domain pages.');
    }
}
