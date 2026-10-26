<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Massmailer;

use ClientFence\Mod\Email\Entity\QueuedEmail;
use ClientFence\Mod\Massmailer\Entity\MassmailerMessage;
use ClientFence\Mod\Massmailer\Repository\MassmailerMessageRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use ClientFence\Enums\ClientOrderStatusEnum;
use ClientFence\Enums\ClientStatusEnum;
use ClientFence\Environment;
use ClientFence\InformationException;

class Service implements \ClientFence\InjectionAwareInterface
{
    public const string RECIPIENT_TABLE = 'mod_massmailer_recipient';
    private const string RECIPIENT_PENDING = 'pending';
    private const string RECIPIENT_QUEUED = 'queued';
    private const string RECIPIENT_SKIPPED = 'skipped';
    private const string RECIPIENT_FAILED = 'failed';
    private const string FILTER_CLIENT_STATUS = 'client_status';
    private const string FILTER_CLIENT_GROUPS = 'client_groups';
    private const string FILTER_HAS_ORDER = 'has_order';
    private const string FILTER_HAS_ORDER_WITH_STATUS = 'has_order_with_status';
    private const array FILTER_KEYS = [
        self::FILTER_CLIENT_STATUS,
        self::FILTER_CLIENT_GROUPS,
        self::FILTER_HAS_ORDER,
        self::FILTER_HAS_ORDER_WITH_STATUS,
    ];
    private const array CLIENT_STATUSES = [
        ClientStatusEnum::ACTIVE->value,
        ClientStatusEnum::SUSPENDED->value,
        ClientStatusEnum::CANCELED->value,
    ];
    private const array ORDER_STATUSES = [
        ClientOrderStatusEnum::PENDING_SETUP->value,
        ClientOrderStatusEnum::FAILED_SETUP->value,
        ClientOrderStatusEnum::FAILED_RENEW->value,
        ClientOrderStatusEnum::ACTIVE->value,
        ClientOrderStatusEnum::CANCELED->value,
        ClientOrderStatusEnum::SUSPENDED->value,
    ];

    protected ?\Pimple\Container $di = null;
    protected ?MassmailerMessageRepository $messageRepository = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        $this->messageRepository = $this->di['em']->getRepository(MassmailerMessage::class);
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getMessageRepository(): MassmailerMessageRepository
    {
        if ($this->messageRepository === null) {
            if ($this->di === null) {
                throw new \ClientFence\Exception('The dependency injection container has not been set.');
            }

            $this->messageRepository = $this->di['em']->getRepository(MassmailerMessage::class);
        }

        return $this->messageRepository;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View mass mail messages'),
                'description' => __trans('Allows the staff member to view mass mail messages and recipients.'),
            ],
            'create_and_edit' => [
                'type' => 'bool',
                'display_name' => __trans('Create and edit mass mail messages'),
                'description' => __trans('Allows the staff member to create and edit mass mail messages.'),
            ],
            'send' => [
                'type' => 'bool',
                'display_name' => __trans('Send mass mail'),
                'description' => __trans('Allows the staff member to send mass mail messages to clients.'),
            ],
            'delete' => [
                'type' => 'bool',
                'display_name' => __trans('Delete mass mail messages'),
                'description' => __trans('Allows the staff member to delete mass mail messages.'),
            ],
            'manage_settings' => [],
        ];
    }

    public function install(): void
    {
        $extensionService = $this->di['mod_service']('extension');

        $sql = '
        CREATE TABLE IF NOT EXISTS `mod_massmailer` (
        `id` bigint(20) NOT NULL AUTO_INCREMENT,
        `from_email` varchar(255) DEFAULT NULL,
        `from_name` varchar(255) DEFAULT NULL,
        `subject` varchar(255) DEFAULT NULL,
        `content` text DEFAULT NULL,
        `filter` text DEFAULT NULL,
        `status` varchar(255) DEFAULT NULL,
        `sent_at` varchar(35) DEFAULT NULL,
        `created_at` varchar(35) DEFAULT NULL,
        `updated_at` varchar(35) DEFAULT NULL,
        PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;
        ';
        $this->di['dbal']->executeStatement($sql);

        // default config values
        $extensionService->setConfig(['ext' => 'mod_massmailer', 'limit' => '2', 'interval' => '10', 'test_client_id' => 1]);
    }

    public function getSearchQueryBuilder(array $data): \Doctrine\ORM\QueryBuilder
    {
        return $this->getMessageRepository()->getSearchQueryBuilder($data);
    }

    public function getMessageReceivers(MassmailerMessage $model): array
    {
        $filter = $this->normalizeFilter($model->getFilter(), true);

        $query = $this->di['dbal']->createQueryBuilder();
        $query
            ->select('DISTINCT c.id', 'c.first_name', 'c.last_name', 'c.email')
            ->from('client', 'c')
            ->leftJoin('c', 'client_order', 'co', 'co.client_id = c.id')
            ->orderBy('c.id', 'DESC');

        $this->appendInCondition($query, 'c.status', 'client_status', $filter[self::FILTER_CLIENT_STATUS] ?? [], ArrayParameterType::STRING);
        $this->appendInCondition($query, 'c.client_group_id', 'client_groups', $filter[self::FILTER_CLIENT_GROUPS] ?? [], ArrayParameterType::INTEGER);
        $this->appendInCondition($query, 'co.product_id', 'has_order', $filter[self::FILTER_HAS_ORDER] ?? [], ArrayParameterType::INTEGER);
        $this->appendInCondition($query, 'co.status', 'has_order_with_status', $filter[self::FILTER_HAS_ORDER_WITH_STATUS] ?? [], ArrayParameterType::STRING);
        // ClientFence core patch (ext massmailer): clients who opted out of marketing email are flagged, skipped and counted in the report (Client/Privacy.php).
        if (\ClientFence\Mod\Client\Privacy::optOutOn($this->di)) {
            $query->leftJoin('c', \ClientFence\Mod\Client\Privacy::OPTOUT_TABLE, 'mo', 'mo.client_id = c.id')->addSelect('mo.client_id AS opted_out');
        } else {
            $query->addSelect('NULL AS opted_out');
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'email' => (string) $row['email'],
            'opted_out' => $row['opted_out'] !== null,
        ], $query->executeQuery()->fetchAllAssociative());
    }

    // ClientFence core patch (ext massmailer): the message is claimed once (draft or scheduled to sending) with its recipients, so it is never sent twice.
    public function startSending(MassmailerMessage $model): bool
    {
        $receivers = $this->getMessageReceivers($model);
        $now = date('Y-m-d H:i:s');

        return $this->di['dbal']->transactional(static function (Connection $db) use ($model, $receivers, $now): bool {
            $claimed = $db->executeStatement(
                'UPDATE mod_massmailer SET status = ?, updated_at = ? WHERE id = ? AND status IN (?, ?)',
                [MassmailerMessage::STATUS_SENDING, $now, $model->getId(), MassmailerMessage::STATUS_DRAFT, MassmailerMessage::STATUS_SCHEDULED]
            );
            if ($claimed === 0) {
                return false;
            }
            foreach ($receivers as $receiver) {
                $db->insert(self::RECIPIENT_TABLE, [
                    'message_id' => $model->getId(),
                    'client_id' => $receiver['id'],
                    'status' => $receiver['opted_out'] ? self::RECIPIENT_SKIPPED : self::RECIPIENT_PENDING,
                ]);
            }

            return true;
        });
    }

    // ClientFence core patch (ext massmailer): each recipient is claimed before its email is queued, so the cron can finish a send cut off half way without mailing anyone twice.
    public function deliver(MassmailerMessage $model): void
    {
        $db = $this->di['dbal'];
        $pending = $db->fetchFirstColumn('SELECT client_id FROM ' . self::RECIPIENT_TABLE . ' WHERE message_id = ? AND status = ? ORDER BY client_id', [$model->getId(), self::RECIPIENT_PENDING]);
        foreach ($pending as $clientId) {
            $key = ['message_id' => $model->getId(), 'client_id' => (int) $clientId];
            if ($db->update(self::RECIPIENT_TABLE, ['status' => self::RECIPIENT_QUEUED], $key + ['status' => self::RECIPIENT_PENDING]) === 0) {
                continue;
            }

            try {
                $queueId = $this->sendMessage($model, (int) $clientId);
                $db->update(self::RECIPIENT_TABLE, ['status' => $queueId === false ? self::RECIPIENT_SKIPPED : self::RECIPIENT_QUEUED, 'queue_id' => $queueId ?: null], $key);
            } catch (\Throwable $e) {
                $db->update(self::RECIPIENT_TABLE, ['status' => self::RECIPIENT_FAILED], $key);
                $this->di['logger']->setChannel('massmailer')->error(sprintf('Mass mail #%d to client #%d failed: %s', $model->getId(), $clientId, $e->getMessage()));
            }
        }

        $now = date('Y-m-d H:i:s');
        $db->executeStatement('UPDATE mod_massmailer SET status = ?, sent_at = ?, updated_at = ? WHERE id = ? AND status = ?', [MassmailerMessage::STATUS_SENT, $now, $now, $model->getId(), MassmailerMessage::STATUS_SENDING]);
    }

    public function sendDue(): void
    {
        $db = $this->di['dbal'];
        $due = $db->fetchFirstColumn('SELECT id FROM mod_massmailer WHERE status = ? AND scheduled_at <= ?', [MassmailerMessage::STATUS_SCHEDULED, date('Y-m-d H:i:s')]);
        foreach ($due as $id) {
            $model = $this->getMessageRepository()->find((int) $id);
            if (!$model instanceof MassmailerMessage) {
                continue;
            }

            try {
                $this->startSending($model);
            } catch (\Throwable $e) {
                // ClientFence core patch (ext massmailer): a filter that no longer works (a deleted client group) makes it a draft again, not an error every run.
                $db->executeStatement('UPDATE mod_massmailer SET status = ?, scheduled_at = NULL WHERE id = ? AND status = ?', [MassmailerMessage::STATUS_DRAFT, $id, MassmailerMessage::STATUS_SCHEDULED]);
                $this->di['logger']->setChannel('massmailer')->error(sprintf('Scheduled mass mail #%d was not sent: %s', $id, $e->getMessage()));
            }
        }

        foreach ($db->fetchFirstColumn('SELECT id FROM mod_massmailer WHERE status = ?', [MassmailerMessage::STATUS_SENDING]) as $id) {
            $model = $this->getMessageRepository()->find((int) $id);
            if ($model instanceof MassmailerMessage) {
                $this->deliver($model);
            }
        }
    }

    public static function onBeforeAdminCronRun(\ClientFence\Event $event): void
    {
        $di = $event->getDi();

        try {
            $di['mod_service']('massmailer')->sendDue();
        } catch (\Throwable $e) {
            $di['logger']->setChannel('massmailer')->error('Mass mail: sending scheduled messages failed: ' . $e->getMessage());
        }
    }

    public function getReport(MassmailerMessage $model): array
    {
        $rows = $this->di['dbal']->fetchAllAssociative(
            'SELECT r.client_id, r.status, q.id AS queue_id, q.status AS queue_status, c.first_name, c.last_name, c.email
            FROM ' . self::RECIPIENT_TABLE . ' r
            LEFT JOIN email_queue q ON q.id = r.queue_id
            LEFT JOIN client c ON c.id = r.client_id
            WHERE r.message_id = ? ORDER BY r.client_id DESC',
            [$model->getId()]
        );

        $counts = ['sent' => 0, 'queued' => 0, 'failed' => 0, 'skipped' => 0];
        $recipients = [];
        foreach ($rows as $row) {
            // ClientFence core patch (ext massmailer): the email queue drops an email once it is sent.
            $status = match (true) {
                $row['status'] === self::RECIPIENT_PENDING => 'queued',
                $row['status'] !== self::RECIPIENT_QUEUED => $row['status'],
                $row['queue_id'] === null => 'sent',
                $row['queue_status'] === QueuedEmail::STATUS_FAILED => 'failed',
                default => 'queued',
            };
            ++$counts[$status];
            $recipients[] = [
                'client_id' => (int) $row['client_id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'email' => (string) $row['email'],
                'status' => $status,
            ];
        }

        return ['counts' => $counts, 'recipients' => $recipients];
    }

    public function normalizeFilter(mixed $filter, bool $strict = false): array
    {
        if (is_string($filter)) {
            $filter = trim($filter);
            if ($filter === '') {
                return [];
            }

            try {
                $filter = json_decode($filter, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->handleInvalidFilter('filter', $strict);
            }
        }

        if ($filter === null) {
            return [];
        }

        if (!is_array($filter)) {
            return $this->handleInvalidFilter('filter', $strict);
        }

        $unknownKeys = array_diff(array_keys($filter), self::FILTER_KEYS);
        if ($strict && $unknownKeys !== []) {
            return $this->handleInvalidFilter((string) reset($unknownKeys), $strict);
        }

        $normalized = [];
        $normalized[self::FILTER_CLIENT_STATUS] = $this->normalizeEnumFilterValues(
            $filter[self::FILTER_CLIENT_STATUS] ?? [],
            self::CLIENT_STATUSES,
            self::FILTER_CLIENT_STATUS,
            $strict
        );
        $normalized[self::FILTER_CLIENT_GROUPS] = $this->normalizeIdFilterValues(
            $filter[self::FILTER_CLIENT_GROUPS] ?? [],
            'client_group',
            self::FILTER_CLIENT_GROUPS,
            $strict
        );
        $normalized[self::FILTER_HAS_ORDER] = $this->normalizeIdFilterValues(
            $filter[self::FILTER_HAS_ORDER] ?? [],
            'product',
            self::FILTER_HAS_ORDER,
            $strict
        );
        $normalized[self::FILTER_HAS_ORDER_WITH_STATUS] = $this->normalizeEnumFilterValues(
            $filter[self::FILTER_HAS_ORDER_WITH_STATUS] ?? [],
            self::ORDER_STATUSES,
            self::FILTER_HAS_ORDER_WITH_STATUS,
            $strict
        );

        return array_filter($normalized, static fn (array $values): bool => $values !== []);
    }

    public function serializeFilter(mixed $filter): string
    {
        return json_encode($this->normalizeFilter($filter, true), JSON_THROW_ON_ERROR);
    }

    public function getParsed(MassmailerMessage $model, int $client_id): array
    {
        $clientService = $this->di['mod_service']('client');
        $systemService = $this->di['mod_service']('system');

        $client = $clientService->get(['id' => $client_id]);
        $clientArr = $clientService->toApiArray($client, true, null);

        $vars = ['c' => $clientArr];
        $ps = $systemService->renderEmailSubjectString($model->getSubject(), $vars);
        // ClientFence core patch (ext massmailer): the rich editor writes Markdown; the signature is HTML, so it goes after.
        $pc = $systemService->renderEmailTplString("{% apply markdown_to_html %}\n" . $model->getContent() . "\n{% endapply %}\n{{ guest.system_email.signature }}", $vars);

        return [$ps, $pc];
    }

    public function sendMessage(MassmailerMessage $model, int $client_id, bool $sendNow = false): int|false|null
    {
        // ClientFence core patch: an opted-out client gets no marketing email (also one queued
        // before the opt-out); the others get an unsubscribe footer and headers (Client/Privacy.php).
        $unsubscribe = null;
        if (\ClientFence\Mod\Client\Privacy::optOutOn($this->di)) {
            if (\ClientFence\Mod\Client\Privacy::optedOut($this->di['em']->getConnection(), $client_id)) {
                return false;
            }
            $unsubscribe = \ClientFence\Mod\Client\Privacy::unsubscribeUrl($this->di, $client_id);
        }

        [$ps, $pc] = $this->getParsed($model, $client_id);
        if ($unsubscribe !== null) {
            $pc .= \ClientFence\Mod\Client\Privacy::footer($unsubscribe);
        }

        $clientService = $this->di['mod_service']('client');

        $client = $clientService->get(['id' => $client_id]);

        $data = [
            'to' => $client->getEmail(),
            'to_name' => $client->getFirstName() . ' ' . $client->getLastName(),
            'from' => $model->getFromEmail(),
            'from_name' => $model->getFromName(),
            'subject' => $ps,
            'content' => $pc,
            'client_id' => $client_id,
        ];

        $extensionService = $this->di['mod_service']('extension');
        if ($extensionService->isExtensionActive('mod', 'demo')) {
            throw new InformationException('Disabled for security reasons (Demo mode enabled)');
        }

        if (!Environment::isProduction()) {
            // @phpstan-ignore if.alwaysFalse
            if (DEBUG) {
                error_log('Skip email sending. Application ENV: ' . Environment::getCurrentEnvironment());
            }

            return null;
        }

        $emailService = $this->di['mod_service']('email');
        $emailService->sendMail($data['to'], $data['from'], $data['subject'], $data['content'], $data['to_name'], $data['from_name'], $data['client_id'], null, $sendNow, unsubscribe: $unsubscribe);

        // ClientFence core patch (ext massmailer): the queued email's id for the delivery report (sendMail() returns none); a test sent at once has left the queue.
        return $sendNow ? null : (int) $this->di['dbal']->fetchOne('SELECT MAX(id) FROM email_queue WHERE client_id = ?', [$client_id]);
    }

    public function toApiArray(MassmailerMessage|array $row): array
    {
        if ($row instanceof MassmailerMessage) {
            $row = $row->toApiArray();
        }

        $row['filter'] = $this->normalizeFilter($row['filter'] ?? null);

        return $row;
    }

    public function sendMail(array $params): void
    {
        $model = $this->getMessageRepository()->find((int) $params['msg_id']);
        if (!$model instanceof MassmailerMessage) {
            throw new InformationException('Mass mail message not found');
        }
        $this->sendMessage($model, (int) $params['client_id']);
    }

    private function normalizeEnumFilterValues(mixed $values, array $allowedValues, string $field, bool $strict): array
    {
        if ($values === null || $values === []) {
            return [];
        }

        if (!is_array($values)) {
            return $this->handleInvalidFilter($field, $strict);
        }

        $selectedValues = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                return $this->handleInvalidFilter($field, $strict);
            }

            $value = trim((string) $value);
            if ($value === '' || !in_array($value, $allowedValues, true)) {
                return $this->handleInvalidFilter($field, $strict);
            }

            $selectedValues[$value] = true;
        }

        $normalized = [];
        foreach ($allowedValues as $allowedValue) {
            if (isset($selectedValues[$allowedValue])) {
                $normalized[] = $allowedValue;
            }
        }

        return $normalized;
    }

    private function normalizeIdFilterValues(mixed $values, string $table, string $field, bool $strict): array
    {
        if ($values === null || $values === []) {
            return [];
        }

        if (!is_array($values)) {
            return $this->handleInvalidFilter($field, $strict);
        }

        $normalized = [];
        foreach ($values as $value) {
            if (is_int($value)) {
            } elseif (is_string($value) && ctype_digit($value)) {
                $value = (int) $value;
            } else {
                return $this->handleInvalidFilter($field, $strict);
            }

            if ($value < 1) {
                return $this->handleInvalidFilter($field, $strict);
            }

            $normalized[$value] = $value;
        }

        $normalized = array_values($normalized);
        sort($normalized);

        $existingIds = $this->getExistingIds($table, $normalized);
        if (count($existingIds) !== count($normalized)) {
            return $this->handleInvalidFilter($field, $strict);
        }

        return $existingIds;
    }

    private function getExistingIds(string $table, array $ids): array
    {
        $query = $this->di['dbal']->createQueryBuilder();
        $query
            ->select('id')
            ->from($table)
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER);

        $rows = $query->executeQuery()->fetchAllAssociative();
        $existingIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        sort($existingIds);

        return array_values(array_unique($existingIds));
    }

    private function appendInCondition(\Doctrine\DBAL\Query\QueryBuilder $query, string $column, string $parameterName, array $filterValues, ArrayParameterType $parameterType): void
    {
        if ($filterValues === []) {
            return;
        }

        $query
            ->andWhere(sprintf('%s IN (:%s)', $column, $parameterName))
            ->setParameter($parameterName, $filterValues, $parameterType);
    }

    private function handleInvalidFilter(string $field, bool $strict): array
    {
        if ($strict) {
            throw new InformationException(sprintf('Mass mail filter contains invalid values for "%s"', $field));
        }

        return [];
    }
}
