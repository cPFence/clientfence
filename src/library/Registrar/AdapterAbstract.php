<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
abstract class Registrar_AdapterAbstract
{
    protected $_log;

    /**
     * Are we in test mode ?
     *
     * @var bool
     */
    protected $_testMode = false;

    /**
     * Related order.
     */
    protected ?Model_ClientOrder $_order = null;

    /**
     * Lazily created RDAP client, shared by all availability checks of the adapter.
     */
    protected ?Registrar_Rdap $_rdap = null;

    /**
     * Return array with configuration.
     *
     * Must be overridden in adapter class
     *
     * @return array
     */
    abstract public static function getConfig();

    /**
     * Config field names whose stored values must be hidden in the API and admin UI.
     * Adapters should mark the relevant fields in their {@see self::getConfig()} form
     * with `'secret' => true` instead of overriding this; it exists as an escape hatch
     * for fields that need masking but are not declared through the form schema.
     *
     * @return string[]
     */
    public static function getSecretFields(): array
    {
        return [];
    }

    /**
     * ClientFence core patch: the details a TLD's registry needs at registration (WHMCS "additional domain fields"),
     * asked for on the order forms and passed on with Registrar_Domain::getFields(); $action is register or transfer.
     * Field name => ['label' => text, 'required' => bool, optional 'options' => [value sent to the registrar => label]];
     * empty for none.
     *
     * @return array<string, array{label: string, required?: bool, options?: array<string, string>}>
     */
    public static function getDomainFields(string $tld, string $action = 'register'): array
    {
        return [];
    }

    /** ClientFence: the .us registry's nexus and purpose questions (nexus_category, nexus_country, app_purpose), with its codes as values. */
    protected static function usNexusFields(): array
    {
        return [
            'nexus_category' => ['label' => __trans('US nexus (connection to the United States)'), 'required' => true, 'options' => [
                'C11' => __trans('US citizen'), 'C12' => __trans('Permanent resident of the US'),
                'C21' => __trans('US organisation'), 'C31' => __trans('Foreign organisation that regularly does business in the US'),
                'C32' => __trans('Foreign organisation with an office in the US'),
            ]],
            'nexus_country' => ['label' => __trans('Country of the foreign organisation (two-letter code, for C31 and C32)')],
            'app_purpose' => ['label' => __trans('Purpose of the domain'), 'required' => true, 'options' => [
                'P1' => __trans('Business for profit'), 'P2' => __trans('Non-profit business, club, association or religious organisation'),
                'P3' => __trans('Personal use'), 'P4' => __trans('Education'), 'P5' => __trans('Government'),
            ]],
        ];
    }

    /**
     * Checks if a domain is available for registration.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to check
     *
     * @return bool True if the domain is available, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while checking the domain availability
     */
    abstract public function isDomainAvailable(Registrar_Domain $domain);

    /**
     * Checks if a domain can be transferred to the registrar.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to check
     *
     * @return bool True if the domain can be transferred, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while checking the domain transferability
     */
    abstract public function isDomaincanBeTransferred(Registrar_Domain $domain);

    /**
     * Modifies the name servers for a domain.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to update, including the new name servers
     *
     * @return bool True if the name servers were modified successfully, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while modifying the name servers
     */
    abstract public function modifyNs(Registrar_Domain $domain);

    /**
     * Modifies the contact information for a domain.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to update, including the new contact information
     *
     * @return bool True if the contact information was modified successfully, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while modifying the contact information
     */
    abstract public function modifyContact(Registrar_Domain $domain);

    /**
     * Transfers a domain to the registrar.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to transfer, including the domain transfer code
     *
     * @return bool True if the domain was transferred successfully, otherwise the adapter should throw an exceptions
     *
     * @throws Registrar_Exception if there was an error while transferring the domain
     */
    abstract public function transferDomain(Registrar_Domain $domain);

    /**
     * Returns the details of a registered domain.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to query
     *
     * @return Registrar_Domain domain object containing the updated details of the registered domain
     *
     * @throws Registrar_Exception if the domain is not registered or there was an error while retrieving the domain details
     */
    abstract public function getDomainDetails(Registrar_Domain $domain);

    /**
     * Returns the domain transfer code (also known as the EPP code or auth code) for a domain.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain
     *
     * @return string the domain transfer code
     *
     * @throws Registrar_Exception if there was an error while retrieving the domain transfer code
     */
    abstract public function getEpp(Registrar_Domain $domain);

    /**
     * Registers a domain with the registrar.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to register
     *
     * @return bool True if the domain was registered successfully, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while registering the domain
     */
    abstract public function registerDomain(Registrar_Domain $domain);

    /**
     * Renews a domain registration with the registrar.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to renew
     *
     * @return bool True if the domain was renewed successfully, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while renewing the domain
     */
    abstract public function renewDomain(Registrar_Domain $domain);

    /**
     * Deletes a domain from the registrar.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to delete
     *
     * @return bool True if the domain was deleted successfully, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while deleting the domain
     */
    abstract public function deleteDomain(Registrar_Domain $domain);

    /**
     * Enables privacy protection for a domain.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain for which to enable privacy protection
     *
     * @return bool True if privacy protection was enabled successfully, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while enabling privacy protection
     */
    abstract public function enablePrivacyProtection(Registrar_Domain $domain);

    /**
     * Disables privacy protection for a domain.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain for which to disable privacy protection
     *
     * @return bool True if privacy protection was disabled successfully, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while disabling privacy protection
     */
    abstract public function disablePrivacyProtection(Registrar_Domain $domain);

    /**
     * Locks a domain to prevent transfer to another registrar.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to lock
     *
     * @return bool True if the domain was locked successfully, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while locking the domain
     */
    abstract public function lock(Registrar_Domain $domain);

    /**
     * Unlocks a domain to allow transfer to another registrar.
     *
     * @param Registrar_Domain $domain domain object containing the details of the domain to unlock
     *
     * @return bool True if the domain was unlocked successfully, otherwise the adapter should throw an exception
     *
     * @throws Registrar_Exception if there was an error while unlocking the domain
     */
    abstract public function unlock(Registrar_Domain $domain);

    /**
     * Sets the logger object to use for logging messages.
     *
     * @param \ClientFence\Log $log the logger object to use
     *
     * @return Registrar_AdapterAbstract the current adapter object, for method chaining
     */
    public function setLog(\ClientFence\Log $log)
    {
        $this->_log = $log;

        return $this;
    }

    /**
     * Gets the logger object currently in use for logging messages.
     *
     * @return \ClientFence\Log the logger object
     */
    public function getLog()
    {
        $log = $this->_log;
        if (!$log instanceof \ClientFence\Log) {
            $log = new \ClientFence\Log();
            $log->addWriter(new \ClientFence\LogDb('Model_ActivitySystem'));
        }

        return $log;
    }

    /**
     * Creates and returns an interface for the Symfony HTTP client.
     */
    public function getHttpClient(): Symfony\Contracts\HttpClient\HttpClientInterface
    {
        return Symfony\Component\HttpClient\HttpClient::create(['bindto' => BIND_TO]);
    }

    /**
     * Creates an RDAP client for registry-based domain availability lookups.
     */
    protected function getRdap(): Registrar_Rdap
    {
        return $this->_rdap ??= new Registrar_Rdap($this->getHttpClient(), $this->getLog());
    }

    /**
     * Enables test mode for the adapter.
     *
     * @return Registrar_AdapterAbstract the current adapter object, for method chaining
     */
    public function enableTestMode()
    {
        $this->_testMode = true;

        return $this;
    }

    /**
     * Sets the order related to the domain.
     */
    public function setOrder(Model_ClientOrder $order): static
    {
        $this->_order = $order;

        return $this;
    }
}
