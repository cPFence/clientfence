<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Massmailer\Api;

use ClientFence\Mod\Massmailer\Entity\MassmailerMessage;
use ClientFence\PaginationOptions;
use ClientFence\Validation\Api\RequiredParams;

class Admin extends \ClientFence\Api\AbstractApi
{
    /**
     * Get paginated list of active mail messages.
     *
     * @optional string $status - filter list by status
     * @optional string $search - search query to search for mail messages
     */
    public function get_list(array $data): array
    {
        $this->checkPermissions('massmailer', 'view');

        $qb = $this->getService()->getSearchQueryBuilder($data);
        $pager = $this->getDi()['pager']->paginateDoctrineQuery($qb, PaginationOptions::fromArray($data));

        $ids = array_column($pager['list'], 'id');
        $counts = $ids === [] ? [] : $this->getDi()['dbal']->fetchAllKeyValue(
            'SELECT message_id, COUNT(*) FROM ' . \ClientFence\Mod\Massmailer\Service::RECIPIENT_TABLE . ' WHERE message_id IN (?) GROUP BY message_id',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        foreach ($pager['list'] as $key => $item) {
            $item['filter'] = $this->getService()->normalizeFilter($item['filter'] ?? null);
            $item['recipients'] = (int) ($counts[$item['id']] ?? 0);
            $pager['list'][$key] = $item;
        }

        return $pager;
    }

    /**
     * Get mail message by id.
     */
    public function get(array $data): array
    {
        $this->checkPermissions('massmailer', 'view');

        $model = $this->_getMessage($data);

        return $this->getService()->toApiArray($model);
    }

    /**
     * Update mail message.
     *
     * @optional string $subject - mail message title
     * @optional string $content - mail message content
     * @optional string $from_name - mail message email from name
     * @optional string $from_email - mail message email from email
     * @optional array $filter  - filter parameters to select clients
     */
    public function update(array $data): bool
    {
        $this->checkPermissions('massmailer', 'create_and_edit');

        $model = $this->_getMessage($data);
        // ClientFence core patch (ext massmailer): a message being sent or sent stays as it went out.
        if (!in_array($model->getStatus(), [MassmailerMessage::STATUS_DRAFT, MassmailerMessage::STATUS_SCHEDULED], true)) {
            throw new \ClientFence\InformationException('This message was already sent.');
        }

        $this->_setFields($model, $data);
        $model->setUpdatedAt(date('Y-m-d H:i:s'));
        $this->getDi()['em']->flush();

        $this->getDi()['logger']->info('Updated mail message #%s', $model->getId());

        return true;
    }

    /**
     * Create mail message.
     *
     * @optional string $content - mail message content
     */
    #[RequiredParams(['subject' => 'Message subject was not passed'])]
    public function create(array $data): int
    {
        $this->checkPermissions('massmailer', 'create_and_edit');

        $systemService = $this->getDi()['mod_service']('system');
        $company = $systemService->getCompany();

        // ClientFence core patch (ext massmailer): created from the first step with all its fields; the content is Markdown (Service::getParsed()).
        $model = (new MassmailerMessage())
            ->setFromEmail($company['email'])
            ->setFromName($company['name'])
            ->setContent('')
            ->setFilter(json_encode([], JSON_THROW_ON_ERROR))
            ->setStatus(MassmailerMessage::STATUS_DRAFT)
            ->setCreatedAt(date('Y-m-d H:i:s'))
            ->setUpdatedAt(date('Y-m-d H:i:s'));
        $this->_setFields($model, $data);

        $this->getDi()['em']->persist($model);
        $this->getDi()['em']->flush();

        $id = $model->getId();
        if ($id === null) {
            throw new \ClientFence\Exception('Failed to retrieve ID of created mail message.');
        }

        $this->getDi()['logger']->info('Created mail message #%s', $id);

        return $id;
    }

    /**
     * Send test mail message by ID to client.
     */
    public function send_test(array $data): bool
    {
        $this->checkPermissions('massmailer', 'send');

        $model = $this->_getMessage($data);
        $client_id = $this->_getTestClientId();

        if (empty($model->getContent())) {
            throw new \ClientFence\InformationException('Add some content before sending message');
        }

        // ClientFence core patch (ext massmailer): an opted-out test client gets nothing, so staff are told instead of seeing 'sent'.
        if ($this->getService()->sendMessage($model, $client_id, true) === false) {
            throw new \ClientFence\InformationException('The test client opted out of marketing email.');
        }

        $this->getDi()['logger']->info('Sent test mail message #%s to client ', $model->getId());

        return true;
    }

    /**
     * Send mail message by ID.
     */
    public function send(array $data): bool
    {
        $this->checkPermissions('massmailer', 'send');

        $model = $this->_getMessage($data);

        if (empty($model->getContent())) {
            throw new \ClientFence\InformationException('Add some content before sending message');
        }

        // ClientFence core patch (ext massmailer): claimed once, so a second click or a sent message never mails the clients again.
        if (!$this->getService()->startSending($model)) {
            throw new \ClientFence\InformationException('This message was already sent.');
        }
        $this->getService()->deliver($model);

        $this->getDi()['logger']->info('Added mass mail messages #%s to queue', $model->getId());

        return true;
    }

    /**
     * ClientFence core patch (ext massmailer): send the message at a set time; the cron sends it once it is due.
     */
    #[RequiredParams(['id' => 'Message ID was not passed', 'scheduled_at' => 'Pick a date and time'])]
    public function schedule(array $data): bool
    {
        $this->checkPermissions('massmailer', 'send');

        $model = $this->_getMessage($data);
        if (empty($model->getContent())) {
            throw new \ClientFence\InformationException('Add some content before sending message');
        }
        $time = strtotime((string) $data['scheduled_at']);
        if ($time === false || $time <= time()) {
            throw new \ClientFence\InformationException('Pick a date and time in the future.');
        }
        $db = $this->getDi()['dbal'];
        $db->executeStatement(
            'UPDATE mod_massmailer SET status = ?, scheduled_at = ?, updated_at = ? WHERE id = ? AND status IN (?, ?)',
            [MassmailerMessage::STATUS_SCHEDULED, date('Y-m-d H:i:s', $time), date('Y-m-d H:i:s'), $model->getId(), MassmailerMessage::STATUS_DRAFT, MassmailerMessage::STATUS_SCHEDULED]
        );
        if ($db->fetchOne('SELECT status FROM mod_massmailer WHERE id = ?', [$model->getId()]) !== MassmailerMessage::STATUS_SCHEDULED) {
            throw new \ClientFence\InformationException('This message was already sent.');
        }

        $this->getDi()['logger']->info('Scheduled mass mail message #%s for %s', $model->getId(), date('Y-m-d H:i:s', $time));

        return true;
    }

    /**
     * ClientFence core patch (ext massmailer): a scheduled message back to a draft.
     */
    public function cancel_schedule(array $data): bool
    {
        $this->checkPermissions('massmailer', 'send');

        $model = $this->_getMessage($data);
        $db = $this->getDi()['dbal'];
        $db->executeStatement(
            'UPDATE mod_massmailer SET status = ?, scheduled_at = NULL, updated_at = ? WHERE id = ? AND status = ?',
            [MassmailerMessage::STATUS_DRAFT, date('Y-m-d H:i:s'), $model->getId(), MassmailerMessage::STATUS_SCHEDULED]
        );
        if ($db->fetchOne('SELECT status FROM mod_massmailer WHERE id = ?', [$model->getId()]) !== MassmailerMessage::STATUS_DRAFT) {
            throw new \ClientFence\InformationException('This message was already sent.');
        }

        $this->getDi()['logger']->info('Cancelled the schedule of mass mail message #%s', $model->getId());

        return true;
    }

    /**
     * ClientFence core patch (ext massmailer): the delivery report of a sent message.
     */
    public function report(array $data): array
    {
        $this->checkPermissions('massmailer', 'view');

        return $this->getService()->getReport($this->_getMessage($data));
    }

    /**
     * Copy mail message by ID.
     */
    public function copy(array $data): int
    {
        $this->checkPermissions('massmailer', 'create_and_edit');

        $model = $this->_getMessage($data);

        $copy = (new MassmailerMessage())
            ->setFromEmail($model->getFromEmail())
            ->setFromName($model->getFromName())
            ->setSubject(($model->getSubject() ?? '') . ' (Copy)')
            ->setContent($model->getContent())
            ->setFilter($model->getFilter())
            ->setStatus(MassmailerMessage::STATUS_DRAFT)
            ->setCreatedAt(date('Y-m-d H:i:s'))
            ->setUpdatedAt(date('Y-m-d H:i:s'));

        $this->getDi()['em']->persist($copy);
        $this->getDi()['em']->flush();

        $id = $copy->getId();
        if ($id === null) {
            throw new \ClientFence\Exception('Failed to retrieve ID of copied mail message.');
        }

        $this->getDi()['logger']->info('Copied mail message #%s to #%s', $model->getId(), $id);

        return $id;
    }

    /**
     * Get message receivers list.
     */
    public function receivers(array $data): array
    {
        $this->checkPermissions('massmailer', 'view');

        $model = $this->_getMessage($data);

        return $this->getService()->getMessageReceivers($model);
    }

    /**
     * Delete mail message by ID.
     */
    public function delete(array $data): bool
    {
        $this->checkPermissions('massmailer', 'delete');

        $model = $this->_getMessage($data);
        $id = $model->getId();
        if ($model->getStatus() === MassmailerMessage::STATUS_SENDING) {
            throw new \ClientFence\InformationException('This message was already sent.');
        }

        $this->getDi()['em']->remove($model);
        $this->getDi()['em']->flush();
        $this->getDi()['dbal']->delete(\ClientFence\Mod\Massmailer\Service::RECIPIENT_TABLE, ['message_id' => $id]);

        $this->getDi()['logger']->info('Removed mail message #%s', $id);

        return true;
    }

    /**
     * Generate preview text.
     *
     * @return array - parsed subject and content strings
     */
    public function preview(array $data): array
    {
        $this->checkPermissions('massmailer', 'view');

        $model = $this->_getMessage($data);
        // ClientFence core patch (ext massmailer): rendered for a client staff pick from the recipients; the list comes from receivers.
        $client_id = empty($data['client_id']) ? $this->_getTestClientId() : (int) $data['client_id'];
        [$ps, $pc] = $this->getService()->getParsed($model, $client_id);

        return [
            'subject' => $ps,
            'content' => $pc,
        ];
    }

    /**
     * Returns the email associated with the test client.
     */
    public function get_test_client(): string
    {
        $this->checkPermissions('massmailer', 'view');

        try {
            $client = $this->getDi()['mod_service']('client')->get(['id' => $this->_getTestClientId()]);
        } catch (\Exception) {
            return 'Unknown';
        }

        return $client->getEmail() ?? 'Unknown';
    }

    private function _setFields(MassmailerMessage $model, array $data): void
    {
        foreach (['subject', 'from_name', 'from_email'] as $field) {
            // ClientFence core patch (ext massmailer): a value over 255 characters does not fit its column.
            if (isset($data[$field]) && mb_strlen((string) $data[$field]) > 255) {
                throw new \ClientFence\InformationException('Use at most 255 characters.');
            }
        }

        $model->setContent($data['content'] ?? $model->getContent());
        $model->setSubject($data['subject'] ?? $model->getSubject());
        if (isset($data['filter'])) {
            $model->setFilter($this->getService()->serializeFilter($data['filter']));
        }

        if (isset($data['from_name'])) {
            if (empty($data['from_name'])) {
                throw new \ClientFence\InformationException('Message from name cannot be empty');
            }
            $model->setFromName($data['from_name']);
        }

        if (isset($data['from_email'])) {
            $this->getDi()['tools']->validateAndSanitizeEmail($data['from_email']);
            $model->setFromEmail($data['from_email']);
        }
    }

    private function _getTestClientId(): int
    {
        $mod = $this->getDi()['mod']('massmailer');
        $c = $mod->getConfig();

        $required = [
            'test_client_id' => 'Client ID needs to be configured in mass mailer settings.',
        ];
        $this->getDi()['validator']->checkRequiredParamsForArray($required, $c);

        return (int) $c['test_client_id'];
    }

    #[RequiredParams(['id' => 'Message ID was not passed'])]
    private function _getMessage(array $data): MassmailerMessage
    {
        $model = $this->getService()->getMessageRepository()->find((int) $data['id']);
        if (!$model instanceof MassmailerMessage) {
            throw new \ClientFence\InformationException('Message not found');
        }

        return $model;
    }
}
