<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

/**
 * Client management.
 */

namespace ClientFence\Mod\Client\Api;

use ClientFence\Mod\Client\Entity\Client;
use ClientFence\Mod\Client\Entity\ClientBalance;
use ClientFence\Mod\Client\Entity\ClientGroup;
use ClientFence\Mod\Client\Entity\ClientPasswordReset;
use ClientFence\InformationException;
use ClientFence\PaginationOptions;
use ClientFence\Tools;
use ClientFence\Validation\Api\RequiredParams;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Locales;
use ClientFence\Mod\Client\Contacts;
use ClientFence\Mod\Client\Google;
use ClientFence\Mod\Client\TwoFactor;
use ClientFence\Mod\Client\Users;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get a list of clients.
     *
     * @param array $data filtering options
     *
     * @return array list of clients in a paginated manner
     */
    public function get_list($data)
    {
        $this->checkPermissions('client', 'view');

        $service = $this->getService();
        $repository = $service->getClientRepository();
        $queryBuilder = $repository->getSearchQueryBuilder($data);
        $pager = $this->getDi()['pager']->paginateDoctrineQuery(
            $queryBuilder,
            PaginationOptions::fromArray($data),
            $this->getIdentity(),
        );

        $context = $repository->getListContext(array_column($pager['list'], 'id'));

        foreach ($pager['list'] as $key => $clientArr) {
            $clientContext = $context[(int) $clientArr['id']] ?? ['balance' => 0.0, 'group' => null];
            $pager['list'][$key]['balance'] = round($clientContext['balance'] - $this->getDi()['mod_service']('Invoice')->getReservedCredit((int) $clientArr['id']), 2);
            $pager['list'][$key]['group'] = $clientContext['group'];
        }

        return $pager;
    }

    /**
     * Get client ID/name pairs.
     *
     * @param array $data Filtering options
     *
     * @return array List of client ID/name pairs
     */
    public function get_pairs($data)
    {
        $this->checkPermissions('client', 'view');

        $service = $this->getDi()['mod_service']('client');

        return $service->getPairs($data);
    }

    /**
     * Get client by id or email. Email is also unique in database.
     *
     * @optional string $email - client email
     *
     * @return array - client details
     */
    public function get($data)
    {
        $this->checkPermissions('client', 'view');

        $service = $this->getService();
        $client = $service->get($data);
        $includeSensitive = $this->getDi()['mod_service']('Staff')->hasPermission(null, 'client', 'manage_api_keys');

        $details = $service->toApiArray($client, true, $this->getIdentity(), $includeSensitive);
        // ClientFence core patch: imported clients can have login history without a stored profile IP.
        $login = $this->getDi()['dbal']->fetchAssociative(
            'SELECT ip, created_at FROM activity_client_history WHERE client_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$details['id']]
        );
        $details['last_login_ip'] = ($login === false ? $details['ip'] : $login['ip']) ?: null;
        $details['last_login_at'] = $login['created_at'] ?? null;

        return $details;
    }

    /**
     * Login to clients area with client id.
     *
     * @optional bool $strip_admin_identity - Also remove the admin identity from the current
     *                                        session, in the same request that sets the client
     *                                        identity. Without this, impersonating a client and
     *                                        then dropping admin access requires a second,
     *                                        separate API call — which is unsafe with session ID
     *                                        rotation enabled, since the session ID returned by
     *                                        this call may already be stale by the time the
     *                                        second call uses it, leaving the session in an
     *                                        inconsistent state.
     *
     * @return array - client details
     */
    #[RequiredParams(['id' => 'ID required'])]
    public function login($data)
    {
        $this->checkPermissions('client', 'impersonate_login');

        $client = $this->getDi()['em']->getRepository(Client::class)->find($data['id']) ?? throw new InformationException('Client not found');

        $service = $this->getDi()['mod_service']('client');
        $result = $service->toSessionArray($client);

        $session = $this->getDi()['session'];
        $session->set('client_id', $client->getId());
        // ClientFence core patch: authorized users. Staff sign in as the account owner.
        \ClientFence\Mod\Client\Users::startSession($this->getDi(), null);
        $this->getDi()['logger']->info('Logged in as client #%s', $client->getId());

        if (Tools::normalizeBoolean($data['strip_admin_identity'] ?? false)) {
            $session->destroy('admin');
            $this->getDi()['logger']->info('Stripped admin identity from session after logging in as client #%s', $client->getId());
        }

        return $result;
    }

    /**
     * Creates new client.
     *
     * @optional string $password - client password
     * @optional string $billing_email - optional address for invoice notifications
     * @optional string $auth_type - client authorization type. Default null
     * @optional string $last_name - client last name
     * @optional string $aid - Custom client ID. If you import clients from other systems you can use this field to store the existing customer ID.
     * @optional string $group_id - client group id
     * @optional string $status - client status: "active, suspended, canceled"
     * @optional string $created_at - ISO 8601 date for client creation date
     * @optional string $gender - Gender - values: male|female|nonbinary|other
     * @optional string $country - Country
     * @optional string $city - city
     * @optional string $birthday - Birthday
     * @optional string $company - Company
     * @optional string $company_vat - Company VAT number
     * @optional string $company_number - Company number
     * @optional string $type - Identifies client type: company or individual
     * @optional string $address_1 - Address line 1
     * @optional string $address_2 - Address line 2
     * @optional string $postcode - zip or postcode
     * @optional string $state - country state
     * @optional string $phone - Phone number
     * @optional string $phone_cc - Phone country code
     * @optional string $notes - Notes about client. Visible for admin only
     * @optional string $lang - Client language
     * @optional string $timezone - IANA timezone identifier (e.g. "America/New_York"). Used to localize dates and times shown to the client.
     * @optional string $custom_1 - Custom field 1
     * @optional string $custom_2 - Custom field 2
     * @optional string $custom_3 - Custom field 3
     * @optional string $custom_4 - Custom field 4
     * @optional string $custom_5 - Custom field 5
     * @optional string $custom_6 - Custom field 6
     * @optional string $custom_7 - Custom field 7
     * @optional string $custom_8 - Custom field 8
     * @optional string $custom_9 - Custom field 9
     * @optional string $custom_10 - Custom field 10
     * @optional string $custom_11 - Custom field 11
     * @optional string $custom_12 - Custom field 12
     * @optional string $custom_13 - Custom field 13
     * @optional string $custom_14 - Custom field 14
     * @optional string $custom_15 - Custom field 15
     * @optional string $custom_16 - Custom field 16
     * @optional string $custom_17 - Custom field 17
     * @optional string $custom_18 - Custom field 18
     * @optional string $custom_19 - Custom field 19
     * @optional string $custom_20 - Custom field 20
     *
     * @return int - client id
     */
    #[RequiredParams(['email' => 'Email required', 'first_name' => 'First name is required'])]
    public function create($data)
    {
        $this->checkPermissions('client', 'create');

        $validator = $this->getDi()['validator'];
        $data['email'] = $this->getDi()['tools']->validateAndSanitizeEmail($data['email']);
        if (array_key_exists('billing_email', $data)) {
            $data['billing_email'] = empty($data['billing_email'])
                ? null
                : $this->getDi()['tools']->validateAndSanitizeEmail($data['billing_email']);
        }
        $data['send_welcome_email'] = Tools::normalizeBoolean($data['send_welcome_email'] ?? true, true);

        $service = $this->getService();
        if ($service->emailAlreadyRegistered($data['email'])) {
            throw new InformationException('This email address is already registered.');
        }

        $password = trim((string) ($data['password'] ?? ''));
        $status = $data['status'] ?? Client::ACTIVE;
        if (!$data['send_welcome_email'] && $password === '') {
            throw new InformationException('A password is required when the welcome email is disabled.');
        }

        if ($data['send_welcome_email'] && $status !== Client::ACTIVE) {
            throw new InformationException('Welcome email can only be sent for active clients.');
        }

        if ($password !== '') {
            $validator->isPasswordStrong($data['password']);
        }

        $this->getDi()['events_manager']->fire(['event' => 'onBeforeAdminClientCreate', 'params' => $data]);
        $id = $service->adminCreateClient($data);
        $this->getDi()['events_manager']->fire(['event' => 'onAfterAdminClientCreate', 'params' => $data]);

        return $id;
    }

    /**
     * ClientFence core patch: anonymizes a client (Privacy::anonymize): the person is erased, the money
     * records stay. Needs the privacy tools on and the "delete" permission.
     */
    #[RequiredParams(['id' => 'Client ID is missing'])]
    public function privacy_anonymize($data): bool
    {
        $this->checkPermissions('client', 'delete');
        if (!\ClientFence\Mod\Client\Privacy::toolsOn($this->getDi())) {
            throw new InformationException('The privacy tools are off (System → Settings → Clients → Privacy).');
        }
        $admin = $this->getIdentity();
        \ClientFence\Mod\Client\Privacy::anonymize($this->getDi(), (int) $data['id'], (int) $admin->id);

        return true;
    }

    /**
     * Deletes client from system.
     */
    #[RequiredParams(['id' => 'Client ID is missing'])]
    public function delete($data): bool
    {
        $this->checkPermissions('client', 'delete');

        $id = $data['id'];
        if ((!is_int($id) && !is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InformationException('Invalid client ID');
        }

        $model = $this->getDi()['em']->getRepository(Client::class)->find((int) $id) ?? throw new InformationException('Client not found');

        $clientId = $model->getId();

        $this->getDi()['events_manager']->fire(['event' => 'onBeforeAdminClientDelete', 'params' => ['id' => $clientId]]);

        $this->getService()->remove($model);
        $this->getDi()['events_manager']->fire(['event' => 'onAfterAdminClientDelete', 'params' => ['id' => $clientId]]);

        $this->getDi()['logger']->info('Removed client #%s', $clientId);

        return true;
    }

    /**
     * Update client profile.
     *
     * @optional string $email - client email
     * @optional string $billing_email - optional address for invoice notifications
     * @optional string $first_name - client first_name
     * @optional string $last_name - client last_name
     * @optional string $status - client status
     * @optional string $aid - Custom client ID. Usually used by import tools to store an existing customer ID.
     * @optional string $gender - Gender - values: male|female|nonbinary|other
     * @optional string $country - Country
     * @optional string $city - city
     * @optional string $birthday - Birthday
     * @optional string $company - Company
     * @optional string $company_vat - Company VAT number
     * @optional string $company_number - Company number
     * @optional string $type - Identifies client type: company or individual
     * @optional string $address_1 - Address line 1
     * @optional string $address_2 - Address line 2
     * @optional string $postcode - zip or postcode
     * @optional string $state - country state
     * @optional string $phone - Phone number
     * @optional string $phone_cc - Phone country code
     * @optional string $lang - Client language
     * @optional string $timezone - IANA timezone identifier (e.g. "America/New_York"). Used to localize dates and times shown to the client.
     * @optional string $notes - Notes about client. Visible for admin only
     * @optional string $custom_1 - Custom field 1
     * @optional string $custom_2 - Custom field 2
     * @optional string $custom_3 - Custom field 3
     * @optional string $custom_4 - Custom field 4
     * @optional string $custom_5 - Custom field 5
     * @optional string $custom_6 - Custom field 6
     * @optional string $custom_7 - Custom field 7
     * @optional string $custom_8 - Custom field 8
     * @optional string $custom_9 - Custom field 9
     * @optional string $custom_10 - Custom field 10
     * @optional string $custom_11 - Custom field 11
     * @optional string $custom_12 - Custom field 12
     * @optional string $custom_13 - Custom field 13
     * @optional string $custom_14 - Custom field 14
     * @optional string $custom_15 - Custom field 15
     * @optional string $custom_16 - Custom field 16
     * @optional string $custom_17 - Custom field 17
     * @optional string $custom_18 - Custom field 18
     * @optional string $custom_19 - Custom field 19
     * @optional string $custom_20 - Custom field 20
     */
    #[RequiredParams(['id' => 'Client ID was not passed'])]
    public function update($data = []): bool
    {
        $this->checkPermissions('client', 'edit_profile');

        // ClientFence core patch (0.8.9 port): staff address edits share the confirmation lock until flush.
        return $this->getDi()['dbal']->transactional(function () use ($data): bool {
            $this->getDi()['dbal']->executeStatement('UPDATE client SET email_approved = email_approved WHERE id = ?', [$data['id']]);

            return $this->updateClient($data);
        });
    }

    private function updateClient(array $data): bool
    {
        $client = $this->getDi()['em']->getRepository(Client::class)->find($data['id']) ?? throw new InformationException('Client not found');

        $service = $this->getDi()['mod_service']('client');
        $oldEmail = (string) $client->getEmail();
        $oldVat = $client->getCompanyVat();
        $oldCountry = $client->getCountry();

        // ClientFence core patch: sign-in email changes can redirect password resets.
        if (array_key_exists('email', $data) && strcasecmp((string) $data['email'], $oldEmail) !== 0) {
            $this->checkPermissions('client', 'change_password');
        }

        if (!is_null($data['email'] ?? null)) {
            $email = $data['email'];
            $email = $this->getDi()['tools']->validateAndSanitizeEmail($email);
            if ($service->emailAlreadyRegistered($email, $client)) {
                throw new InformationException('This email address is already registered.');
            }
        }

        if (array_key_exists('billing_email', $data)) {
            $data['billing_email'] = empty($data['billing_email'])
                ? null
                : $this->getDi()['tools']->validateAndSanitizeEmail($data['billing_email']);
        }

        if (!empty($data['birthday'])) {
            $this->getDi()['validator']->isBirthdayValid($data['birthday']);
        }

        if (($data['birthday'] ?? null) === '') {
            unset($data['birthday']);
        }

        $currency = $data['currency'] ?? null;
        if ($currency && $service->canChangeCurrency($client, $currency)) {
            $client->setCurrency($currency);
        }

        $this->getDi()['events_manager']->fire(['event' => 'onBeforeAdminClientUpdate', 'params' => $data]);

        // Special handling for the phone country codes
        $phoneCountryCode = $data['phone_cc'] ?? $client->getPhoneCc();
        if (!empty($phoneCountryCode)) {
            $client->setPhoneCc((string) Tools::validatePhoneCC($phoneCountryCode));
        }

        // Special handling for the phone number itself
        $phone = $data['phone'] ?? $client->getPhone();
        if (!empty($phone) && is_string($phone)) {
            $client->setPhone(Tools::validatePhoneNumber($phone));
        }

        $previousStatus = $client->getStatus();

        if (!empty($data['country']) && !Countries::exists($data['country'])) {
            throw new InformationException('Invalid country code: :code', [':code' => $data['country']]);
        }

        if (!empty($data['lang']) && !Locales::exists($data['lang'])) {
            throw new InformationException('Invalid locale code: :code', [':code' => $data['lang']]);
        }

        if (array_key_exists('timezone', $data) && $data['timezone'] !== null && $data['timezone'] !== '' && !in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            throw new InformationException('Invalid timezone: :tz', [':tz' => $data['timezone']]);
        }

        $simpleFields = [
            'email' => 'setEmail',
            'first_name' => 'setFirstName',
            'last_name' => 'setLastName',
            'aid' => 'setAid',
            'gender' => 'setGender',
            'company' => 'setCompany',
            'company_vat' => 'setCompanyVat',
            'address_1' => 'setAddress1',
            'address_2' => 'setAddress2',
            'notes' => 'setNotes',
            'country' => 'setCountry',
            'postcode' => 'setPostcode',
            'state' => 'setState',
            'city' => 'setCity',
            'status' => 'setStatus',
            'company_number' => 'setCompanyNumber',
            'type' => 'setType',
            'lang' => 'setLang',
            'timezone' => 'setTimezone',
            'custom_1' => 'setCustom1',
            'custom_2' => 'setCustom2',
            'custom_3' => 'setCustom3',
            'custom_4' => 'setCustom4',
            'custom_5' => 'setCustom5',
            'custom_6' => 'setCustom6',
            'custom_7' => 'setCustom7',
            'custom_8' => 'setCustom8',
            'custom_9' => 'setCustom9',
            'custom_10' => 'setCustom10',
            'custom_11' => 'setCustom11',
            'custom_12' => 'setCustom12',
            'custom_13' => 'setCustom13',
            'custom_14' => 'setCustom14',
            'custom_15' => 'setCustom15',
            'custom_16' => 'setCustom16',
            'custom_17' => 'setCustom17',
            'custom_18' => 'setCustom18',
            'custom_19' => 'setCustom19',
            'custom_20' => 'setCustom20',
        ];

        foreach ($simpleFields as $field => $setter) {
            if (array_key_exists($field, $data)) {
                $client->{$setter}($data[$field]);
            }
        }

        $groupField = array_key_exists('group_id', $data)
            ? 'group_id'
            : (array_key_exists('client_group_id', $data) ? 'client_group_id' : null);
        if ($groupField !== null) {
            $groupValue = $data[$groupField];
            if (empty($groupValue)) {
                $client->setClientGroupId(null);
            } else {
                $groupId = filter_var($groupValue, FILTER_VALIDATE_INT);
                if ($groupId === false || $groupId <= 0) {
                    throw new InformationException('Invalid client group ID: :id', [':id' => $groupValue]);
                }

                $this->getDi()['em']->getRepository(ClientGroup::class)->find($groupId)
                    ?? throw new InformationException('Client group not found');
                $client->setClientGroupId($groupId);
            }
        }

        if (array_key_exists('email_approved', $data)) {
            $client->setEmailApproved((bool) $data['email_approved']);
        }
        // ClientFence core patch: a new address is unconfirmed, also when staff change it (the form posts the old
        // "confirmed" back, and a confirmed address may take over that address's existing panel login).
        if (strcasecmp($oldEmail, (string) $client->getEmail()) !== 0) {
            $this->getDi()['dbal']->executeStatement('UPDATE client SET email_approved = 0 WHERE id = ?', [$client->getId()]);
            $service->revokeEmailConfirmations((int) $client->getId());
            // ClientFence core patch: client email edits revoke reset links sent to the old address.
            $this->getDi()['em']->getRepository(ClientPasswordReset::class)->deleteResetsForClient((int) $client->getId());
            $client->setEmailApproved(false);
        }

        if (array_key_exists('tax_exempt', $data)) {
            $client->setTaxExempt((bool) $data['tax_exempt']);
        }

        if (array_key_exists('birthday', $data) && $data['birthday'] !== null && $data['birthday'] !== '') {
            $client->setBirthday(new \DateTime($data['birthday']));
        } elseif (array_key_exists('birthday', $data)) {
            $client->setBirthday(null);
        }

        if (array_key_exists('created_at', $data) && $data['created_at'] !== null && $data['created_at'] !== '') {
            $client->setCreatedAt(new \DateTime($data['created_at']));
        } elseif (array_key_exists('created_at', $data)) {
            $client->setCreatedAt(null);
        }

        if (array_key_exists('billing_email', $data)) {
            $client->setBillingEmail($data['billing_email']);
        }
        // ClientFence core patch: EU VAT numbers are checked with VIES and set the tax exemption (Invoice/EuVat.php).
        \ClientFence\Mod\Invoice\EuVat::apply($this->getDi(), $client, $oldVat, $oldCountry, array_key_exists('company_vat', $data));

        if ($client->getStatus() !== Client::ACTIVE) {
            $client->setApiToken(null);
        }

        $this->getDi()['em']->persist($client);
        $this->getDi()['em']->flush();

        if ($client->getStatus() !== Client::ACTIVE && $previousStatus === Client::ACTIVE) {
            $profileService = $this->getDi()['mod_service']('profile');
            $profileService->invalidateSessions('client', (int) $client->getId());
        }

        $this->getDi()['events_manager']->fire(['event' => 'onAfterAdminClientUpdate', 'params' => ['id' => $client->getId()]]);

        $this->getDi()['logger']->info('Updated client #%s profile', $client->getId());

        return true;
    }

    /**
     * Change client password.
     */
    #[RequiredParams(['id' => 'ID required', 'password' => 'Password required', 'password_confirm' => 'Password confirmation required'])]
    public function change_password(array $data): bool
    {
        $this->checkPermissions('client', 'change_password');

        $this->getDi()['validator']->passwordsMatch($data);

        $this->getDi()['validator']->isPasswordStrong($data['password']);

        $client = $this->getDi()['em']->getRepository(Client::class)->find($data['id']) ?? throw new InformationException('Client not found');

        $this->getDi()['events_manager']->fire(['event' => 'onBeforeAdminClientPasswordChange', 'params' => ['id' => $client->getId()]]);

        $this->getDi()['em']->getRepository(ClientPasswordReset::class)->changePassword($client, $this->getDi()['password']->hashIt($data['password']));

        $profileService = $this->getDi()['mod_service']('profile');
        $profileService->invalidateSessions('client', (int) $data['id']);

        $this->getDi()['events_manager']->fire(['event' => 'onAfterAdminClientPasswordChange', 'params' => ['id' => $client->getId()]]);

        $this->getDi()['logger']->info('Changed client #%s password', $client->getId());

        return true;
    }

    /**
     * Returns list of client payments.
     *
     * @return array
     */
    public function balance_get_list($data)
    {
        $this->checkPermissions('client', 'manage_balance');

        $service = $this->getDi()['mod_service']('Client', 'Balance');
        [$q, $params] = $service->getSearchQuery($data);
        $pager = $this->getDi()['pager']->getPaginatedResultSet($q, $params, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $pager['list'][$key] = [
                'id' => $item['id'],
                'description' => $item['description'],
                'amount' => $item['amount'],
                'currency' => $item['currency'],
                'created_at' => $item['created_at'],
            ];
        }

        return $pager;
    }

    /**
     * Remove row from clients balance.
     */
    #[RequiredParams(['id' => 'Client ID was not passed'])]
    public function balance_delete($data): bool
    {
        $this->checkPermissions('client', 'manage_balance');

        $model = $this->getDi()['em']->getRepository(ClientBalance::class)->find($data['id']) ?? throw new InformationException('Balance line not found');

        $id = $model->getId();
        $client_id = $model->getClientId();
        $amount = $model->getAmount();

        $this->getDi()['em']->remove($model);
        $this->getDi()['em']->flush();

        $this->getDi()['logger']->info('Removed line %s from client #%s balance for %s', $id, $client_id, $amount);

        return true;
    }

    /**
     * Adds funds to clients balance.
     *
     * @optional string $type - Related item type
     * @optional string $rel_id - Related item id
     */
    #[RequiredParams(['id' => 'Client ID required', 'amount' => 'Amount is required', 'description' => 'Description is required'])]
    public function balance_add_funds($data): bool
    {
        $this->checkPermissions('client', 'manage_balance');

        $client = $this->getDi()['em']->getRepository(Client::class)->find($data['id']) ?? throw new InformationException('Client not found');

        $service = $this->getDi()['mod_service']('client');
        $service->addFunds($client, $data['amount'], $data['description'], $data);

        return true;
    }

    // ClientFence core patch: summary cards and staff records use the permissions of their owning modules.
    #[RequiredParams(['id' => 'Client ID is missing'])]
    public function summary($data): array
    {
        $this->checkPermissions('client', 'view');
        $this->checkPermissions('invoice', 'view');
        $this->checkPermissions('order', 'view');
        $this->checkPermissions('support', 'view');

        return $this->getService()->summary((int) $data['id']);
    }

    #[RequiredParams(['id' => 'Client ID required', 'amount' => 'Amount is required', 'description' => 'Description is required'])]
    public function balance_deduct($data): bool
    {
        $this->checkPermissions('client', 'manage_balance');
        $client = $this->getDi()['em']->getRepository(Client::class)->find($data['id']) ?? throw new InformationException('Client not found');

        $this->getDi()['mod_service']('Client', 'Balance')->deductByAdmin($client, $data['amount'], $data['description']);

        return true;
    }

    #[RequiredParams(['id' => 'Client ID is missing'])]
    public function close($data): array
    {
        $this->checkPermissions('client', 'edit_profile');
        $this->checkPermissions('order', 'manage');
        $client = $this->getDi()['em']->getRepository(Client::class)->find($data['id']) ?? throw new InformationException('Client not found');

        return ['cancelled' => $this->getService()->close($client)];
    }

    #[RequiredParams(['client_id' => 'Client ID is missing'])]
    public function note_get_list($data): array
    {
        $this->checkPermissions('client', 'view');

        return \ClientFence\Mod\Client\Notes::getList($this->getDi(), (int) $data['client_id']);
    }

    #[RequiredParams(['client_id' => 'Client ID is missing', 'note' => 'Note is required'])]
    public function note_create($data): int
    {
        $this->checkPermissions('client', 'edit_profile');

        return \ClientFence\Mod\Client\Notes::create($this->getDi(), (int) $data['client_id'], (int) $this->getIdentity()->id, $data['note']);
    }

    #[RequiredParams(['id' => 'Note ID is missing', 'note' => 'Note is required', 'pinned' => 'Pinned is required'])]
    public function note_update($data): bool
    {
        $this->checkPermissions('client', 'edit_profile');
        \ClientFence\Mod\Client\Notes::update($this->getDi(), (int) $data['id'], $data['note'], Tools::normalizeBoolean($data['pinned']));

        return true;
    }

    #[RequiredParams(['id' => 'Note ID is missing'])]
    public function note_delete($data): bool
    {
        $this->checkPermissions('client', 'edit_profile');
        \ClientFence\Mod\Client\Notes::delete($this->getDi(), (int) $data['id']);

        return true;
    }

    #[RequiredParams(['client_id' => 'Client ID is missing'])]
    public function file_get_list($data): array
    {
        $this->checkPermissions('client', 'view');

        return \ClientFence\Mod\Client\Files::getList($this->getDi(), (int) $data['client_id']);
    }

    #[RequiredParams(['client_id' => 'Client ID is missing'])]
    public function file_upload($data): array
    {
        $this->checkPermissions('client', 'edit_profile');

        return \ClientFence\Mod\Client\Files::upload($this->getDi(), (int) $data['client_id'], (int) $this->getIdentity()->id, $this->getDi()['request']->files->get('file'));
    }

    #[RequiredParams(['id' => 'File ID is missing'])]
    public function file_delete($data): bool
    {
        $this->checkPermissions('client', 'edit_profile');
        \ClientFence\Mod\Client\Files::delete($this->getDi(), (int) $data['id']);

        return true;
    }

    /**
     * Remove password reminders which were not confirmed in 2 hours.
     */
    public function batch_expire_password_reminders(): bool
    {
        $this->checkPermissions('client', 'delete');

        $service = $this->getDi()['mod_service']('client');
        $expired = $service->getExpiredPasswordReminders();
        foreach ($expired as $model) {
            $this->getDi()['em']->remove($model);
        }
        $this->getDi()['em']->flush();

        $this->getDi()['logger']->info('Executed action to delete expired clients password reminders');

        return true;
    }

    /**
     * Get list of clients logins history.
     *
     * @optional int $client_id - filter by client
     *
     * @return array
     */
    public function login_history_get_list($data)
    {
        $this->checkPermissions('client', 'view_login_history');

        [$q, $params] = $this->getService()->getHistorySearchQuery($data);
        $pager = $this->getDi()['pager']->getPaginatedResultSet($q, $params, PaginationOptions::fromArray($data));

        foreach ($pager['list'] as $key => $item) {
            $pager['list'][$key] = [
                'id' => $item['id'],
                'ip' => $item['ip'],
                'created_at' => $item['created_at'],
                'client' => [
                    'id' => $item['client_id'],
                    'first_name' => $item['first_name'],
                    'last_name' => $item['last_name'],
                    'email' => $item['email'],
                ],
            ];
        }

        return $pager;
    }

    /**
     * Return client statuses with counter.
     *
     * @return array
     */
    public function get_statuses($data)
    {
        $this->checkPermissions('client', 'view');

        $service = $this->getDi()['mod_service']('client');

        return $service->counter();
    }

    /**
     * Return client groups. Id and title pairs.
     *
     * @return array
     */
    public function group_get_pairs($data)
    {
        $this->checkPermissions('client', 'view');

        $service = $this->getDi()['mod_service']('client');

        return $service->getGroupPairs();
    }

    /**
     * Create new clients group.
     *
     * @return int $id - newly created group id
     */
    #[RequiredParams(['title' => 'Group title is missing'])]
    public function group_create($data)
    {
        $this->checkPermissions('client', 'manage_groups');

        return $this->getService()->createGroup($data);
    }

    /**
     * Update client group.
     *
     * @optional string $title - new group title
     */
    #[RequiredParams(['id' => 'Group ID is missing'])]
    public function group_update($data): bool
    {
        $this->checkPermissions('client', 'manage_groups');

        $model = $this->getDi()['em']->getRepository(ClientGroup::class)->find($data['id']) ?? throw new InformationException('Group not found');

        $model->setTitle($data['title'] ?? $model->getTitle());
        $this->getDi()['em']->persist($model);
        $this->getDi()['em']->flush();

        return true;
    }

    /**
     * Delete client group.
     *
     * @return bool
     */
    #[RequiredParams(['id' => 'Group ID is missing'])]
    public function group_delete($data)
    {
        $this->checkPermissions('client', 'manage_groups');

        $model = $this->getDi()['em']->getRepository(ClientGroup::class)->find($data['id']) ?? throw new InformationException('Group not found');

        $clients = $this->getDi()['em']->getRepository(Client::class)->findBy(['clientGroupId' => $data['id']]);

        if (Tools::safeCount($clients) > 0) {
            throw new InformationException('Group has clients assigned. Please reassign them first.');
        }

        return $this->getService()->deleteGroup($model);
    }

    /**
     * Get client group details.
     */
    #[RequiredParams(['id' => 'Group ID is missing'])]
    public function group_get($data): array
    {
        $this->checkPermissions('client', 'manage_groups');

        $model = $this->getDi()['em']->getRepository(ClientGroup::class)->find($data['id']) ?? throw new InformationException('Group not found');

        return [
            'id' => $model->getId(),
            'title' => $model->getTitle(),
            'created_at' => $model->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updated_at' => $model->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Deletes clients with given IDs.
     */
    #[RequiredParams(['ids' => 'IDs were not passed'])]
    public function batch_delete($data): bool
    {
        $this->checkPermissions('client', 'bulk_delete');

        foreach ($data['ids'] as $id) {
            $this->delete(['id' => $id]);
        }

        return true;
    }

    public function export_csv($data): Response
    {
        $this->checkPermissions('client', 'view');
        $this->checkPermissions('client', 'export');

        $data['headers'] ??= [];

        return $this->getService()->exportCSV($data['headers']);
    }

    /**
     * ClientFence: the authorized users of a client account (`client_id`; Users.php). Staff who can open
     * clients may read them; changes need the client "edit profile" permission.
     */
    public function user_list($data): array
    {
        $this->checkPermissions('client');

        return Users::listFor($this->getDi(), (int) ($data['client_id'] ?? 0));
    }

    /**
     * The Users tab of the client page (`client_id`): users, pending invitations, the
     * permissions offered and whether ClientFence is on.
     */
    public function user_page($data): array
    {
        $this->checkPermissions('client');

        return Users::staffPage($this->getDi(), (int) ($data['client_id'] ?? 0));
    }

    /**
     * Gives a user access to a client account: `client_id`, `email`, `permissions` (WHMCS names),
     * `owner`; for a new user also `first_name`, `last_name` and `password`. Returns the user id.
     */
    public function user_create($data): int
    {
        $this->checkPermissions('clientfence', 'manage_settings');

        return Users::add($this->getDi(), (int) ($data['client_id'] ?? 0), $data);
    }

    /**
     * Invites `email` to the account (`client_id`) with `permissions`, by email; the person
     * chooses a name and password (an existing user accepts with the user's password).
     */
    public function user_invite($data): bool
    {
        $this->staffChange();
        $this->checkPermissions('client', 'change_password');
        Users::invite($this->getDi(), (int) ($data['client_id'] ?? 0), $data['email'] ?? '', $data['permissions'] ?? [], $this->adminId());

        return true;
    }

    /**
     * Sends a pending invitation (`id`) of the account (`client_id`) again, with a new link.
     */
    public function user_invite_resend($data): bool
    {
        $this->staffChange();
        Users::resendInvite($this->getDi(), (int) ($data['client_id'] ?? 0), (int) ($data['id'] ?? 0), $this->adminId());

        return true;
    }

    /**
     * Cancels a pending invitation (`id`) of the account (`client_id`).
     */
    public function user_invite_cancel($data): bool
    {
        $this->staffChange();
        Users::cancelInvite($this->getDi(), (int) ($data['client_id'] ?? 0), (int) ($data['id'] ?? 0), $this->adminId());

        return true;
    }

    /**
     * Changes a user's `permissions` and `owner` (full access, like the owner) on one account
     * (`user_id`, `client_id`) and the user's `status` (active or disabled: sign-in on every
     * account).
     */
    public function user_update($data): bool
    {
        $this->staffChange();
        // The access form always sends `owner`; only an actual owner change needs the password permission.
        if (isset($data['owner']) && (int) !empty($data['owner']) !== (int) $this->getDi()['dbal']->fetchOne('SELECT owner FROM client_user_account WHERE user_id = ? AND client_id = ?', [(int) ($data['user_id'] ?? 0), (int) ($data['client_id'] ?? 0)])) {
            $this->checkPermissions('client', 'change_password');
        }
        $change = array_intersect_key(is_array($data) ? $data : [], array_flip(['permissions', 'owner', 'status']));
        Users::update($this->getDi(), (int) ($data['user_id'] ?? 0), (int) ($data['client_id'] ?? 0), $change, $this->adminId());

        return true;
    }

    /**
     * Removes a user's access to an account (`user_id`, `client_id`); a user left without
     * accounts is deleted.
     */
    public function user_remove($data): bool
    {
        $this->staffChange();
        Users::remove($this->getDi(), (int) ($data['user_id'] ?? 0), (int) ($data['client_id'] ?? 0), $this->adminId());

        return true;
    }

    /**
     * Sends a user of the account (`user_id`, `client_id`) a password reset link.
     */
    public function user_password_reset($data): bool
    {
        $this->staffChange();
        Users::staffSendReset($this->getDi(), (int) ($data['user_id'] ?? 0), (int) ($data['client_id'] ?? 0), (int) $this->adminId());

        return true;
    }

    /**
     * Switches off two-factor sign-in of the account owner (`client_id`) or of a user of the
     * account (`client_id`, `user_id`), for someone who lost the phone and the backup code. Needs
     * the client "edit profile" and "change password" permissions; works with ClientFence off too,
     * as the code is still asked then.
     */
    public function twofactor_disable($data): bool
    {
        $this->checkPermissions('client', 'edit_profile');
        $this->checkPermissions('client', 'change_password');
        $userId = isset($data['user_id']) && (string) $data['user_id'] !== '' ? (int) $data['user_id'] : null;
        TwoFactor::staffDisable($this->getDi(), (int) ($data['client_id'] ?? 0), $userId, (int) $this->adminId());

        return true;
    }

    /**
     * Staff remove a login's Google link (`client_id`; `user_id` for an authorized user of it).
     */
    public function google_unlink($data): bool
    {
        $this->checkPermissions('client', 'edit_profile');
        $userId = isset($data['user_id']) && (string) $data['user_id'] !== '' ? (int) $data['user_id'] : null;
        Google::staffUnlink($this->getDi(), (int) ($data['client_id'] ?? 0), $userId, (int) $this->adminId());

        return true;
    }

    /**
     * The Sign-in settings (Google sign-in): {on, client_id, secret_saved, redirect_uri}; the
     * secret itself is never returned.
     */
    public function signin_get(): array
    {
        $this->manageSettings('client');

        return Google::settings($this->getDi());
    }

    /**
     * Saves the Sign-in settings: `google_signin` (0/1), `google_mode` (both, signin, signup),
     * `google_terms_url`, `google_privacy_url`, `google_client_id`,
     * `google_client_secret` (empty keeps the saved one).
     */
    public function signin_update($data): bool
    {
        $this->manageSettings('client');
        Google::saveSettings($this->getDi(), is_array($data) ? $data : []);

        return true;
    }

    /**
     * ClientFence: the Contacts tab of the client page (`client_id`; Contacts.php): the account's
     * contacts and the email kinds they can get. Staff who can open clients may read them.
     */
    public function contact_page($data): array
    {
        $this->checkPermissions('client');
        Contacts::ensure($this->getDi());

        return Contacts::page($this->getDi(), (int) ($data['client_id'] ?? 0));
    }

    /**
     * Adds a contact to the account (`client_id`), or changes one (`id`); fields as the client's
     * contacts_save. Returns the contact id.
     */
    public function contact_save($data): int
    {
        $this->staffChange();
        Contacts::ensure($this->getDi());

        return Contacts::save($this->getDi(), (int) ($data['client_id'] ?? 0), is_array($data) ? $data : [], $this->adminId());
    }

    /**
     * Removes a contact (`id`) of the account (`client_id`).
     */
    public function contact_delete($data): bool
    {
        $this->staffChange();
        Contacts::ensure($this->getDi());
        Contacts::delete($this->getDi(), (int) ($data['client_id'] ?? 0), (int) ($data['id'] ?? 0), $this->adminId());

        return true;
    }

    /**
     * ClientFence: staff changes to a client's users need the client "edit profile" permission.
     */
    private function staffChange(): void
    {
        $this->checkPermissions('client', 'edit_profile');
    }

    /**
     * The settings permission of the module whose settings page shows the setting.
     */
    private function manageSettings(string $module): void
    {
        $this->getDi()['mod_service']('extension')->hasManagePermission('mod_' . $module);
    }

    private function adminId(): ?int
    {
        $identity = $this->getIdentity();

        return $identity instanceof \Model_Admin ? (int) $identity->id : null;
    }
}
