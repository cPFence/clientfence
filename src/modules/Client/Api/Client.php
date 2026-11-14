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
 *Client management.
 */

namespace ClientFence\Mod\Client\Api;

use ClientFence\Mod\Client\Entity\Client as ClientEntity;
use ClientFence\Mod\Client\Entity\ClientBalance;
use ClientFence\InformationException;
use ClientFence\PaginationOptions;
use ClientFence\Mod\Client\Affiliates\Affiliates;
use ClientFence\Mod\Client\Affiliates\Payouts;
use ClientFence\Mod\Client\Google;
use ClientFence\Mod\Client\TwoFactor;
use ClientFence\Mod\Client\Contacts;
use ClientFence\Mod\Client\SupportPin;
use ClientFence\Mod\Client\Users;

class Client extends \ClientFence\Api\AbstractApi
{
    /**
     * Get payments information.
     *
     * @return array
     */
    public function balance_get_list($data)
    {
        $service = $this->getDi()['mod_service']('Client', 'Balance');
        $client = $this->getClientEntity($this->getIdentity());
        $data['client_id'] = $client->getId();

        return $this->getDi()['pager']->paginateMappedQuery(
            $service->getSearchQueryBuilder($data),
            PaginationOptions::fromArray($data),
            fn (ClientBalance $balance): array => $service->toApiArray($balance, $client),
        );
    }

    /**
     * Get client balance.
     *
     * @return float
     */
    public function balance_get_total()
    {
        // ClientFence core patch: the spendable credit, without what is set aside for unpaid invoices.
        return $this->getDi()['mod_service']('Invoice')->getFreeCredit((int) $this->getClientEntity($this->getIdentity())->getId());
    }

    public function is_taxable()
    {
        return $this->getService()->isClientTaxable($this->getClientEntity($this->getIdentity()));
    }

    public function resend_email_verification()
    {
        $client = $this->getClientEntity($this->getIdentity());

        $emailApproved = $client->getEmailApproved();
        if ($emailApproved) {
            // Email is already validated, so we don't need to do so again
            return true;
        }

        $this->getDi()['rate_limiter']->consumeOrThrow('client_email_verification_resend_ip', (string) $this->getIp());
        $clientId = $client->getId();
        $this->getDi()['rate_limiter']->consumeOrThrow('client_email_verification_resend_account', 'client:' . $clientId);

        return $this->getService()->sendEmailConfirmationForClient($client);
    }

    private function getClientEntity(object $identity): ClientEntity
    {
        if ($identity instanceof ClientEntity) {
            return $identity;
        }

        if (!$identity instanceof \Model_Client) {
            throw new InformationException('Client identity not found');
        }

        $client = $this->getDi()['em']->getRepository(ClientEntity::class)->find((int) $identity->id);
        if (!$client instanceof ClientEntity) {
            throw new InformationException('Client not found');
        }

        return $client;
    }

    /**
     * ClientFence: the signed-in authorized user (Users.php): null for the account owner, else the
     * user, the account, the permissions and the accounts the user can switch to. The client
     * area shows the user's name and hides what the permissions do not allow.
     */
    public function user_session(): ?array
    {
        return Users::sessionView($this->getDi());
    }

    /**
     * Moves the signed-in authorized user to another account the user is linked to (`client_id`).
     */
    public function user_switch($data): bool
    {
        $di = $this->getDi();
        Users::switchTo($di, (int) ($data['client_id'] ?? 0));

        return true;
    }

    /**
     * The Users page (the account owner and owner links only: not listed in Users::CALLS): the
     * users, pending invitations and the permissions offered.
     */
    public function users_get_list(): array
    {
        return Users::usersPage($this->getDi(), $this->onClientId());
    }

    /**
     * Invites `email` with `permissions` (WHMCS names) by email.
     */
    public function users_invite($data): bool
    {
        Users::invite($this->getDi(), $this->onClientId(), $data['email'] ?? '', $data['permissions'] ?? []);

        return true;
    }

    public function users_invite_resend($data): bool
    {
        Users::resendInvite($this->getDi(), $this->onClientId(), (int) ($data['id'] ?? 0));

        return true;
    }

    public function users_invite_cancel($data): bool
    {
        Users::cancelInvite($this->getDi(), $this->onClientId(), (int) ($data['id'] ?? 0));

        return true;
    }

    /**
     * Changes a user's `permissions` on the account (`user_id`).
     */
    public function users_update($data): bool
    {
        Users::ownerUpdate($this->getDi(), $this->onClientId(), (int) ($data['user_id'] ?? 0), $data['permissions'] ?? []);

        return true;
    }

    /**
     * Removes a user's access to the account (`user_id`).
     */
    public function users_remove($data): bool
    {
        Users::ownerRemove($this->getDi(), $this->onClientId(), (int) ($data['user_id'] ?? 0));

        return true;
    }

    /**
     * ClientFence: the Contacts page (Contacts.php; the owner, owner links and users with the
     * `contacts` permission): the account's contacts and the email kinds they can get.
     */
    public function contacts_get_list(): array
    {
        Contacts::ensure($this->getDi());

        return Contacts::page($this->getDi(), $this->onClientId());
    }

    /**
     * Adds a contact, or changes one (`id`): names, `email`, address fields, `phone_cc`, `phone`,
     * `tax_id` and `emails` (the kinds of email it gets a copy of). Returns the contact id.
     */
    public function contacts_save($data): int
    {
        Contacts::ensure($this->getDi());

        return Contacts::save($this->getDi(), $this->onClientId(), is_array($data) ? $data : []);
    }

    /**
     * Removes a contact (`id`).
     */
    public function contacts_delete($data): bool
    {
        Contacts::ensure($this->getDi());
        Contacts::delete($this->getDi(), $this->onClientId(), (int) ($data['id'] ?? 0));

        return true;
    }

    /**
     * The signed-in authorized user's own name (`first_name`, `last_name`).
     */
    public function user_profile_update($data): bool
    {
        $this->onClientId();
        Users::updateOwnProfile($this->getDi(), $data);

        return true;
    }

    /**
     * The signed-in authorized user's own password (`current_password`, `new_password`,
     * `confirm_password`).
     */
    public function user_password_change($data): bool
    {
        $this->onClientId();
        Users::changeOwnPassword($this->getDi(), $data, (string) $this->getIp());

        return true;
    }

    /**
     * Two-factor sign-in of the signed-in login (the owner's, or an authorized user's own;
     * TwoFactor.php): {enabled, backup, enabled_at}.
     */
    public function twofactor_status(): array
    {
        return TwoFactor::status($this->getDi(), (int) $this->getIdentity()->id);
    }

    /**
     * Google sign-in of the signed-in login (Google.php): {on, link, error}; link is null or
     * {email, created_at, last_used_at}.
     */
    public function google_status(): array
    {
        return Google::status($this->getDi(), (int) $this->getIdentity()->id);
    }

    /**
     * Starts linking a Google account (`password`): {url}, the Google page to go to.
     */
    public function google_link($data): array
    {
        return ['url' => Google::startLink($this->getDi(), (int) $this->getIdentity()->id, is_array($data) ? $data : [], (string) $this->getIp())];
    }

    /**
     * An account made by Google sign-up saves the profile fields the portal requires (the
     * names listed on /google-sign-in/profile; `phone_cc` with `phone`).
     */
    public function google_profile_update($data): bool
    {
        Google::saveProfile($this->getDi(), (int) $this->getIdentity()->id, is_array($data) ? $data : []);

        return true;
    }

    /**
     * An account made by Google sign-up chooses its password (`password`, `password_confirm`),
     * which lets it unlink Google (lock-out protection).
     */
    public function google_password($data): bool
    {
        Google::choosePassword($this->getDi(), (int) $this->getIdentity()->id, is_array($data) ? $data : []);

        return true;
    }

    /**
     * Removes the signed-in login's Google link (refused while Google is the only way in).
     */
    public function google_unlink(): bool
    {
        Google::unlink($this->getDi(), (int) $this->getIdentity()->id);

        return true;
    }

    /**
     * Starts setting up two-factor sign-in: {secret, uri} for the authenticator app.
     */
    public function twofactor_setup(): array
    {
        return TwoFactor::setup($this->getDi(), (int) $this->getIdentity()->id);
    }

    /**
     * Turns two-factor sign-in on (`code` from the app, `password`): {backup_code}, shown once.
     */
    public function twofactor_enable($data): array
    {
        return TwoFactor::enable($this->getDi(), (int) $this->getIdentity()->id, is_array($data) ? $data : [], (string) $this->getIp());
    }

    /**
     * Turns two-factor sign-in off (`password`, and `code` from the app or the backup code).
     */
    public function twofactor_disable($data): bool
    {
        TwoFactor::disable($this->getDi(), (int) $this->getIdentity()->id, is_array($data) ? $data : [], (string) $this->getIp());

        return true;
    }

    /**
     * A new backup code (`password`): {backup_code}; the old one stops working.
     */
    public function twofactor_backup($data): array
    {
        return TwoFactor::newBackup($this->getDi(), (int) $this->getIdentity()->id, is_array($data) ? $data : [], (string) $this->getIp());
    }

    private function onClientId(): int
    {
        return (int) $this->getIdentity()->id;
    }

    /**
     * ClientFence core patch: the Privacy tab of the profile (Privacy.php): which tools are on and whether
     * the client gets marketing email.
     *
     * @return array{tools: bool, optout: bool, marketing: bool}
     */
    public function privacy_status(): array
    {
        $di = $this->getDi();

        return [
            'tools' => \ClientFence\Mod\Client\Privacy::toolsOn($di),
            'optout' => \ClientFence\Mod\Client\Privacy::optOutOn($di),
            'marketing' => !\ClientFence\Mod\Client\Privacy::optedOut($di['em']->getConnection(), (int) $this->getIdentity()->id),
        ];
    }

    /** ClientFence: marketing email on (1) or off (0), while the opt-out is on. */
    public function privacy_marketing_set($data): bool
    {
        if (!\ClientFence\Mod\Client\Privacy::optOutOn($this->getDi())) {
            throw new InformationException('Marketing email settings are not available.');
        }
        $on = \ClientFence\Tools::normalizeBoolean($data['marketing'] ?? true, true);
        \ClientFence\Mod\Client\Privacy::setOptOut($this->getDi(), (int) $this->getIdentity()->id, !$on, 'profile');

        return true;
    }

    /** ClientFence core patch: the account's Support PIN for staff to confirm who they talk to (SupportPin.php). */
    public function support_pin(): string
    {
        return SupportPin::get($this->getDi(), $this->onClientId());
    }

    /** Makes a new random Support PIN and returns it. */
    public function support_pin_new(): string
    {
        return SupportPin::renew($this->getDi(), $this->onClientId());
    }

    /**
     * ClientFence core patch: the Affiliates page (Client/Affiliates): whether the program is on, whether the client may
     * join, the signup text and the client's affiliate account (link, counts, balances), or null before it joins.
     */
    public function affiliate_get(): array
    {
        $di = $this->getDi();
        $settings = Affiliates::settings($di);

        return [
            'enabled' => $settings['enabled'],
            'join' => $settings['enabled'] && $settings['join'],
            'signup_text' => $settings['signup_text'],
            'cookie_days' => $settings['cookie_days'],
            'affiliate' => $settings['enabled'] ? Affiliates::get($di, $this->onClientId()) : null,
        ];
    }

    /** Joins the affiliate program (the account owner and owner links only: not listed in Users::CALLS). */
    public function affiliate_join(): bool
    {
        $settings = Affiliates::settings($this->getDi());
        if (!$settings['enabled'] || !$settings['join']) {
            throw new InformationException('The affiliate program is not open for sign-ups.');
        }
        Affiliates::activate($this->getDi(), $this->onClientId(), null);

        return true;
    }

    /** The orders the affiliate referred (product, status and what it earned; never who ordered), paginated. */
    public function affiliate_referrals($data): array
    {
        return Affiliates::referrals($this->getDi(), $this->ownAffiliateId(), is_array($data) ? $data : [], false);
    }

    /** The affiliate's ledger, paginated. */
    public function affiliate_entries($data): array
    {
        return Affiliates::entries($this->getDi(), $this->ownAffiliateId(), is_array($data) ? $data : [], false);
    }

    /** Asks for a payout of `amount` with the `details` of how to pay (the account owner and owner links only); returns its id. */
    public function affiliate_payout_request($data): int
    {
        $this->ownAffiliateId();

        return Payouts::request($this->getDi(), $this->onClientId(), $data['amount'] ?? null, $data['details'] ?? null);
    }

    private function ownAffiliateId(): int
    {
        $di = $this->getDi();
        $affiliate = Affiliates::settings($di)['enabled'] ? Affiliates::forClient($di['dbal'], $this->onClientId()) : false;

        return $affiliate !== false ? (int) $affiliate['id'] : throw new InformationException('The affiliate program is not available.');
    }

    /** ClientFence: asks staff to delete the account (a ticket); returns the ticket id. */
    public function privacy_request_deletion(): int
    {
        if (!\ClientFence\Mod\Client\Privacy::toolsOn($this->getDi())) {
            throw new InformationException('Account deletion requests are not available. Please open a ticket.');
        }

        return \ClientFence\Mod\Client\Privacy::requestDeletion($this->getDi(), (int) $this->getIdentity()->id);
    }
}
