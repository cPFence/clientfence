<?php

/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

declare(strict_types=1);

use ClientFence\Mod\Currency\Entity\Currency;
use ClientFence\Mod\Email\Entity\EmailTemplate;

use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;
use function Tests\Helpers\moduleService;

function emailTemplate(string $actionCode = '', ?int $id = null, array $data = []): EmailTemplate
{
    $template = new EmailTemplate($actionCode, $id);

    if (array_key_exists('category', $data)) {
        $template->setCategory($data['category']);
    }

    if (array_key_exists('enabled', $data)) {
        $template->setEnabled((bool) $data['enabled']);
    }

    if (array_key_exists('subject', $data)) {
        $template->setSubject($data['subject']);
    }

    if (array_key_exists('content', $data)) {
        $template->setContent($data['content']);
    }

    if (array_key_exists('description', $data)) {
        $template->setDescription($data['description']);
    }

    if (array_key_exists('vars', $data)) {
        $template->setVars($data['vars']);
    }

    if (array_key_exists('is_custom', $data)) {
        $template->setIsCustom((bool) $data['is_custom']);
    }

    if (array_key_exists('is_overridden', $data)) {
        $template->setIsOverridden((bool) $data['is_overridden']);
    }

    if (array_key_exists('last_error', $data)) {
        $template->setLastError($data['last_error']);
    }

    if (array_key_exists('error_checked_at', $data)) {
        $template->setErrorCheckedAt($data['error_checked_at']);
    }

    return $template;
}

/**
 * Build a partial Mockery EntityManager that returns the correct repository mock
 * for each Email entity class requested. Used because the service eagerly fetches
 * all repositories inside setDi().
 *
 * @return Doctrine\ORM\EntityManagerInterface&Mockery\MockInterface
 */
function emailBuildEm(
    ?ClientFence\Mod\Email\Repository\ActivityClientEmailRepository $activityRepo = null,
    ?ClientFence\Mod\Email\Repository\EmailTemplateRepository $templateRepo = null,
    ?ClientFence\Mod\Email\Repository\QueuedEmailRepository $queueRepo = null,
    bool $ignoreMissing = true,
    ?ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository $templateGroupRepo = null,
    ?ClientFence\Mod\Staff\Repository\AdminRepository $adminRepo = null,
) {
    $activityRepo ??= Mockery::mock(ClientFence\Mod\Email\Repository\ActivityClientEmailRepository::class)->shouldIgnoreMissing();
    $templateRepo ??= Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class)->shouldIgnoreMissing();
    $queueRepo ??= Mockery::mock(ClientFence\Mod\Email\Repository\QueuedEmailRepository::class)->shouldIgnoreMissing();
    $templateGroupRepo ??= Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class)->shouldIgnoreMissing();
    $adminRepo ??= Mockery::mock(ClientFence\Mod\Staff\Repository\AdminRepository::class)->shouldIgnoreMissing();

    $em = Mockery::mock(Doctrine\ORM\EntityManagerInterface::class);
    if ($ignoreMissing) {
        $em->shouldIgnoreMissing();
    }
    $em->shouldReceive('getRepository')->andReturnUsing(static fn (string $class): object => match ($class) {
        ClientFence\Mod\Email\Entity\ActivityClientEmail::class => $activityRepo,
        EmailTemplate::class => $templateRepo,
        ClientFence\Mod\Email\Entity\QueuedEmail::class => $queueRepo,
        ClientFence\Mod\Email\Entity\EmailTemplateGroup::class => $templateGroupRepo,
        ClientFence\Mod\Staff\Entity\Admin::class => $adminRepo,
        default => $activityRepo,
    });

    return $em;
}

test('di returns dependency injection container', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();

    $service->setDi($di);

    $result = $service->getDi();
    expect($result)->toBe($di);
});

test('rmByClient removes emails for client', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $repo = Mockery::mock(ClientFence\Mod\Email\Repository\ActivityClientEmailRepository::class);
    $repo->shouldReceive('deleteByClientId')
        ->once()
        ->with(1)
        ->andReturn(3);

    $di['em'] = emailBuildEm($repo);
    $service->setDi($di);

    $client = new Model_Client();
    $client->loadBean(new Tests\Helpers\DummyBean());
    $client->id = 1;

    $result = $service->rmByClient($client);
    expect($result)->toBeTrue();
});

test('ActivityClientEmail toApiArray returns sanitized API array', function (): void {
    $id = 10;
    $client_id = 5;
    $sender = 'sender@example.com';
    $recipients = 'recipient@example.com';
    $subject = 'Subject';
    $content_html = '<script>alert("x")</script><p>HTML</p>';
    $content_text = 'TEXT';
    $created = new DateTime('-1 day');
    $updated = new DateTime();

    $model = new ClientFence\Mod\Email\Entity\ActivityClientEmail();
    \Tests\Helpers\setEntityId($model, $id);
    $model->setClientId($client_id);
    $model->setSender($sender);
    $model->setRecipients($recipients);
    $model->setSubject($subject);
    $model->setContentHtml($content_html);
    $model->setContentText($content_text);
    $model->setCreatedAt($created);
    $model->setUpdatedAt($updated);

    $expected = [
        'id' => $id,
        'client_id' => $client_id,
        'sender' => $sender,
        'recipients' => $recipients,
        'subject' => $subject,
        'content_html' => ClientFence\Tools::sanitizeContent($content_html),
        'content_text' => $content_text,
        'has_attachment' => false,
        'created_at' => $created->format('Y-m-d H:i:s'),
        'updated_at' => $updated->format('Y-m-d H:i:s'),
    ];

    $result = $model->toApiArray();
    expect($result)->toBeArray();
    expect($result)->toBe($expected);
});

test('setVars encrypts and sets variables', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();
    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('encrypt')
        ->atLeast()->once()
        ->andReturn('encrypted-vars');

    $em = emailBuildEm();
    $em->shouldReceive('flush')->atLeast()->once();

    $di['em'] = $em;
    $di['crypt'] = $cryptMock;
    $service->setDi($di);

    $t = emailTemplate();
    $vars = [
        'param1' => 'value1',
    ];

    $result = $service->setVars($t, $vars);
    expect($result)->toBeTrue();
});

test('setVars rejects variables that cannot be encoded', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();
    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldNotReceive('encrypt');

    $em = emailBuildEm();
    $em->shouldNotReceive('flush');

    $di['em'] = $em;
    $di['crypt'] = $cryptMock;
    $service->setDi($di);

    $t = emailTemplate();

    expect(fn (): bool => $service->setVars($t, ['invalid' => INF]))
        ->toThrow(\ClientFence\Exception::class, 'Failed to encode email template variables.');
});

test('getVars decrypts and returns variables', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();
    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('decrypt')
        ->atLeast()->once()
        ->andReturn('{"param1":"value1"}');

    $expected = ['param1' => 'value1'];

    $di['crypt'] = $cryptMock;
    $service->setDi($di);

    $t = emailTemplate(data: ['vars' => 'haNUZYeNjo1oXhH6OkoKuHGPxakyKY10qR3O/DSy9Og=']);

    $result = $service->getVars($t);
    expect($result)->toBeArray();
    expect($result)->toBe($expected);
});

test('getVars supplies the default currency for invoice template previews', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $currencyRepository = Mockery::mock(ClientFence\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepository->shouldReceive('findDefault')->once()->andReturn(new Currency('USD'));
    $currencyService = Mockery::mock(ClientFence\Mod\Currency\Service::class);
    $currencyService->shouldReceive('getCurrencyRepository')->once()->andReturn($currencyRepository);

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['currency' => $currencyService]));
    $service->setDi($di);

    $template = emailTemplate('mod_invoice_paid');

    expect($service->getVars($template))->toBe([
        'invoice' => [
            'total' => 0,
            'currency' => 'USD',
        ],
    ]);
});

test('getVars supplies preview variables for support and staff templates before their first send', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $supportVars = $service->getVars(emailTemplate('mod_support_ticket_staff_open'));
    expect($supportVars)
        ->toHaveKeys(['c.email', 'ticket.subject', 'ticket.priority', 'ticket.client.first_name', 'ticket.messages.0.content'])
        ->not->toHaveKeys(['ticket.client.email', 'ticket.messages.0.author.email']);

    foreach (['mod_support_ticket_staff_open', 'mod_support_ticket_staff_close', 'mod_support_ticket_staff_reply'] as $actionCode) {
        expect($service->getVars(emailTemplate($actionCode))['ticket']['priority'])->toBe(100);
    }

    $staffVars = $service->getVars(emailTemplate('mod_staff_ticket_open'));
    expect($staffVars)
        ->toHaveKeys(['staff.email', 'ticket.priority', 'ticket.client.email', 'ticket.messages.0.content']);

    $passwordResetVars = $service->getVars(emailTemplate('mod_staff_password_reset_approve'));
    expect($passwordResetVars)->toHaveKeys(['c.id', 'c.email', 'c.name']);

    $signupVars = $service->getVars(emailTemplate('mod_staff_client_signup'));
    expect($signupVars)->toHaveKeys(['c.email', 'c.first_name', 'c.last_name', 'staff.email']);
});

test('getVars merges stored examples over preview defaults', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('decrypt')->once()->andReturn('{"ticket":{"subject":"Stored subject"}}');

    $di = container();
    $di['crypt'] = $cryptMock;
    $service->setDi($di);

    $template = emailTemplate('mod_staff_ticket_reply', data: ['vars' => 'encrypted']);
    $vars = $service->getVars($template);

    expect($vars['ticket']['subject'])->toBe('Stored subject')
        ->and($vars)->toHaveKeys(['staff.email', 'ticket.priority', 'ticket.client.email']);
});

test('sendTemplate does not store staff password reset variables', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $template = emailTemplate('mod_staff_password_reset_request', data: ['enabled' => false]);
    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->once()->andReturn($template);
    $crypt = Mockery::mock(ClientFence\Crypt::class);
    $crypt->shouldNotReceive('encrypt');

    $di = container();
    $di['em'] = emailBuildEm(templateRepo: $templateRepo);
    $di['crypt'] = $crypt;
    $service->setDi($di);

    $service->sendTemplate([
        'code' => 'mod_staff_password_reset_request',
        'to' => 'staff@example.com',
        'hash' => 'reset-secret',
    ]);

    expect($template->getVars())->toBeNull();
});

test('sendTemplate masks secrets in saved previews while rendering the full variables', function (): void {
    $secrets = array_fill_keys(['transfer_code', 'api_key', 'license_key', 'password', 'pass', 'hash', 'access_hash'], 'secret');
    $masked = array_fill_keys(array_keys($secrets), '********');
    $vars = $secrets + [
        'order' => ['config' => $secrets + ['domain' => 'example.test', 'require_transfer_code' => true]],
        'items' => [3 => ['config' => $secrets + ['period' => '1Y']]],
    ];
    $template = emailTemplate('mod_staff_client_order', data: ['enabled' => true, 'is_custom' => true, 'subject' => 'Subject', 'content' => 'Body']);
    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->once()->andReturn($template);
    $em = emailBuildEm(templateRepo: $templateRepo);
    $em->shouldReceive('flush')->once();
    $savedVars = null;
    $crypt = Mockery::mock(ClientFence\Crypt::class);
    $crypt->shouldReceive('encrypt')->once()->andReturnUsing(function (string $json) use (&$savedVars): string {
        $savedVars = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return 'encrypted';
    });
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('renderEmailTplString')->once()->with('Body', $vars, null, null)->andReturn('Rendered body');
    $system->shouldReceive('renderEmailSubjectString')->once()->with('Subject', $vars, null, null)->andReturn('Rendered subject');
    $system->shouldReceive('getParamValue')->with('company_email')->andReturn('billing@example.test');
    $system->shouldReceive('getParamValue')->with('company_name')->andReturn('Company');
    $module = Mockery::mock(ClientFence\Module::class);
    $module->shouldReceive('getConfig')->andReturn([]);
    $di = container();
    $di['em'] = $em;
    $di['crypt'] = $crypt;
    $di['mod'] = $di->protect(fn () => $module);
    $di['mod_service'] = $di->protect(fn () => $system);
    $service = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $service->shouldReceive('sendMail')->once()->andReturn(true);
    $service->setDi($di);

    expect($service->sendTemplate(['code' => 'mod_staff_client_order', 'to' => 'staff@example.test'] + $vars))->toBeTrue()
        ->and($savedVars)->toBe($masked + [
            'order' => ['config' => $masked + ['domain' => 'example.test', 'require_transfer_code' => true]],
            'items' => [3 => ['config' => $masked + ['period' => '1Y']]],
        ]);
});

test('sendTemplate sends sign-in emails immediately without keeping queue or history copies', function (string $mailer): void {
    $service = new ClientFence\Mod\Email\Service();
    $template = emailTemplate('mod_staff_password_reset_request', data: ['is_custom' => true]);
    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->once()->andReturn($template);
    $adminRepo = Mockery::mock(ClientFence\Mod\Staff\Repository\AdminRepository::class);
    $adminRepo->shouldReceive('find')->once()->with(7)->andReturn(createEntity(ClientFence\Mod\Staff\Entity\Admin::class, [
        'id' => 7, 'email' => 'staff@example.com', 'name' => 'Staff', 'signature' => '', 'timezone' => 'UTC',
    ]));

    $em = emailBuildEm(templateRepo: $templateRepo, adminRepo: $adminRepo);
    $em->shouldNotReceive('persist');

    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->andReturn('company@example.com');
    $system->shouldReceive('renderEmailSubjectString')->once()->andReturn('Password reset');
    $system->shouldReceive('renderEmailTplString')->once()->andReturn('reset-secret');
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('isExtensionActive')->once()->with('mod', 'demo')->andReturn(false);
    $activity = Mockery::mock(ClientFence\Mod\Activity\Service::class);
    $activity->shouldNotReceive('logEmail');
    $mod = Mockery::mock(ClientFence\Module::class);
    $mod->shouldReceive('getConfig')->andReturn([
        'from_email' => 'company@example.com', 'from_name' => 'Company',
        'log_enabled' => true, 'mailer' => $mailer, 'custom_dsn' => 'null://null',
    ]);

    $di = container();
    $di['em'] = $em;
    $di['mod'] = $di->protect(fn () => $mod);
    $di['mod_service'] = $di->protect(moduleService(['system' => $system, 'extension' => $extension, 'activity' => $activity]));
    $service->setDi($di);

    withAppEnv('prod', fn () => $service->sendTemplate([
        'code' => 'mod_staff_password_reset_request', 'to_admin' => 7, 'hash' => 'reset-secret', 'send_now' => false,
    ]));

    $errors = array_filter($di['logger']->calls, fn (array $call): bool => $call['method'] === 'error');
    expect($errors)->toHaveCount($mailer === 'custom' ? 0 : 1);
})->with(['sent' => ['custom'], 'failed' => ['invalid']]);

test('sendTemplate returns false when template does not exist', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $data = [
        'code' => 'mod_email_test_not_existing',
        'to' => 'example@example.com',
        'default_subject' => 'SUBJECT',
        'default_description' => 'DESCRIPTION',
    ];

    $emailTemplate = emailTemplate();

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn(null);

    $em = emailBuildEm(null, $templateRepo);
    $em->shouldReceive('persist')->atLeast()->once();
    $em->shouldReceive('flush')->atLeast()->once();

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('encrypt')
        ->atLeast()->once();

    $di['em'] = $em;
    $di['crypt'] = $cryptMock;
    $di['api_admin'] = function () use ($di) {
        $api = new ClientFence\Api\Proxy(new Model_Admin());
        $api->setDi($di);

        return $api;
    };

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $di['validator'] = $validatorMock;
    $service->setDi($di);

    $result = $service->sendTemplate($data);

    expect($result)->toBeFalse();
});

test('sendTemplate sends email when template exists', function (): void {
    $data = [
        'code' => 'mod_email_test',
        'to' => 'example@example.com',
        'default_subject' => 'SUBJECT',
        'default_template' => 'TEMPLATE',
        'default_description' => 'DESCRIPTION',
    ];
    $service = new ClientFence\Mod\Email\Service();

    $di = container();

    $emailTemplate = emailTemplate(data: ['enabled' => true]);

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn($emailTemplate);

    $em = emailBuildEm(null, $templateRepo);
    $em->shouldReceive('persist')->atLeast()->once();
    $em->shouldReceive('flush')->atLeast()->once();

    $systemService = Mockery::mock(ClientFence\Mod\System\Service::class);
    $systemService->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturn('value');
    $systemService->shouldReceive('renderEmailTplString', 'renderEmailSubjectString')
        ->atLeast()->once()
        ->andReturn('rendered content');

    $twigStub = Mockery::mock(Twig\Environment::class);

    $di['api_admin'] = function () use ($di) {
        $api = new ClientFence\Api\Proxy(new Model_Admin());
        $api->setDi($di);

        return $api;
    };
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $di['validator'] = $validatorMock;

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('encrypt')
        ->atLeast()->once();

    $modMock = Mockery::mock(ClientFence\Module::class)->makePartial();
    $modMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn([
            'from_name' => 'Test',
            'from_email' => 'test@test.com',
        ]);

    $di['em'] = $em;
    $di['crypt'] = $cryptMock;
    $di['twig'] = $twigStub;
    $di['mod'] = $di->protect(fn () => $modMock);
    $di['mod_service'] = $di->protect(moduleService(['system' => $systemService]));
    $di['tools'] = new ClientFence\Tools();

    $service->setDi($di);

    $result = $service->sendTemplate($data);

    expect($result)->toBeTrue();
});

test('sendTemplate forwards the attachment to the queue and strips it from the stored vars', function (): void {
    $data = [
        'code' => 'mod_email_test',
        'to' => 'example@example.com',
        'default_subject' => 'SUBJECT',
        'default_template' => 'TEMPLATE',
        'default_description' => 'DESCRIPTION',
        'attachment' => [
            'content' => '%PDF-1.4 fake invoice contents',
            'name' => 'Invoice-BB0001.pdf',
            'mime' => 'application/pdf',
        ],
    ];
    $service = new ClientFence\Mod\Email\Service();

    $di = container();

    $emailTemplate = emailTemplate(data: ['enabled' => true]);

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn($emailTemplate);

    /** @var ClientFence\Mod\Email\Entity\QueuedEmail|null $persistedQueue */
    $persistedQueue = null;
    $em = emailBuildEm(null, $templateRepo);
    $em->shouldReceive('persist')
        ->atLeast()->once()
        ->with(Mockery::on(function ($entity) use (&$persistedQueue): bool {
            if ($entity instanceof ClientFence\Mod\Email\Entity\QueuedEmail) {
                $persistedQueue = $entity;
            }

            return true;
        }));
    $em->shouldReceive('flush')->atLeast()->once();

    $systemService = Mockery::mock(ClientFence\Mod\System\Service::class);
    $systemService->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturn('value');
    $systemService->shouldReceive('renderEmailTplString', 'renderEmailSubjectString')
        ->atLeast()->once()
        ->andReturn('rendered content');

    $di['api_admin'] = function () use ($di) {
        $api = new ClientFence\Api\Proxy(new Model_Admin());
        $api->setDi($di);

        return $api;
    };
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $di['validator'] = $validatorMock;

    $encryptedVars = null;
    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('encrypt')
        ->atLeast()->once()
        ->with(Mockery::on(function ($json) use (&$encryptedVars): bool {
            $encryptedVars = $json;

            return true;
        }), Mockery::any())
        ->andReturn('encrypted');

    $modMock = Mockery::mock(ClientFence\Module::class)->makePartial();
    $modMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn([
            'from_name' => 'Test',
            'from_email' => 'test@test.com',
        ]);

    $di['em'] = $em;
    $di['crypt'] = $cryptMock;
    $di['twig'] = Mockery::mock(Twig\Environment::class);
    $di['mod'] = $di->protect(fn () => $modMock);
    $di['mod_service'] = $di->protect(moduleService(['system' => $systemService]));
    $di['tools'] = new ClientFence\Tools();

    $service->setDi($di);

    $result = $service->sendTemplate($data);

    expect($result)->toBeTrue();
    expect($persistedQueue)->not->toBeNull();
    expect($persistedQueue->getAttachmentName())->toBe('Invoice-BB0001.pdf');
    expect($persistedQueue->getAttachmentContent())->toBe('%PDF-1.4 fake invoice contents');
    expect($persistedQueue->getAttachmentMime())->toBe('application/pdf');
    expect($encryptedVars)->not->toContain('fake invoice contents');
});

test('sendTemplate renders the subject as plaintext, not HTML, cut to its 255-character column', function (string $rendered, string $stored): void {
    // Subjects go through renderEmailSubjectString while the body keeps the
    // raw HTML rendering.
    $data = [
        'code' => 'mod_email_test',
        'to' => 'example@example.com',
        'default_subject' => 'SUBJECT',
        'default_template' => 'TEMPLATE',
        'default_description' => 'DESCRIPTION',
    ];
    $service = new ClientFence\Mod\Email\Service();

    $di = container();

    $emailTemplate = emailTemplate(data: ['enabled' => true]);

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn($emailTemplate);

    /** @var ClientFence\Mod\Email\Entity\QueuedEmail|null $persistedQueue */
    $persistedQueue = null;
    $em = emailBuildEm(null, $templateRepo);
    $em->shouldReceive('persist')
        ->atLeast()->once()
        ->with(Mockery::on(function ($entity) use (&$persistedQueue): bool {
            if ($entity instanceof ClientFence\Mod\Email\Entity\QueuedEmail) {
                $persistedQueue = $entity;
            }

            return true;
        }));
    $em->shouldReceive('flush')->atLeast()->once();

    $systemService = Mockery::mock(ClientFence\Mod\System\Service::class);
    $systemService->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturn('value');
    $systemService->shouldReceive('renderEmailTplString')
        ->atLeast()->once()
        ->andReturn('rendered content');
    $systemService->shouldReceive('renderEmailSubjectString')
        ->once()
        ->andReturn($rendered);

    $di['api_admin'] = function () use ($di) {
        $api = new ClientFence\Api\Proxy(new Model_Admin());
        $api->setDi($di);

        return $api;
    };
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $di['validator'] = $validatorMock;

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('encrypt')
        ->atLeast()->once();

    $modMock = Mockery::mock(ClientFence\Module::class)->makePartial();
    $modMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn([
            'from_name' => 'Test',
            'from_email' => 'test@test.com',
        ]);

    $di['em'] = $em;
    $di['crypt'] = $cryptMock;
    $di['twig'] = Mockery::mock(Twig\Environment::class);
    $di['mod'] = $di->protect(fn () => $modMock);
    $di['mod_service'] = $di->protect(moduleService(['system' => $systemService]));
    $di['tools'] = new ClientFence\Tools();

    $service->setDi($di);

    $result = $service->sendTemplate($data);

    expect($result)->toBeTrue();
    expect($persistedQueue)->not->toBeNull();
    expect($persistedQueue->getSubject())->toBe($stored);
    expect($persistedQueue->getContent())->toBe('rendered content');
})->with([
    'plain' => ['[A & B Ltd] Invoice created', '[A & B Ltd] Invoice created'],
    // A long ticket subject plus the "[Company] [Ticket #N] " prefix failed the flush.
    'too long' => ['[Co] ' . str_repeat('é', 300), '[Co] ' . str_repeat('é', 250)],
]);

dataset('sendTemplateExistsStaffProvider', fn (): array => [
    [
        [
            'code' => 'mod_email_test',
            'to' => 'example@example.com',
            'default_subject' => 'SUBJECT',
            'default_template' => 'TEMPLATE',
            'default_description' => 'DESCRIPTION',
            'to_staff' => 1,
        ],
        'never',
        'atLeastOnce',
        'staff@clientfence.com',
    ],
    [
        [
            'code' => 'mod_email_test',
            'to' => 'example@example.com',
            'default_subject' => 'SUBJECT',
            'default_template' => 'TEMPLATE',
            'default_description' => 'DESCRIPTION',
            'to_client' => 1,
        ],
        'atLeastOnce',
        'never',
        // A generic `to` must never redirect a client-bound template: the
        // recipient stays the client's registered email.
        'staff@clientfence.com',
    ],
    [
        [
            'code' => 'mod_client_password_reset_request',
            'default_subject' => 'SUBJECT',
            'default_template' => 'TEMPLATE',
            'default_description' => 'DESCRIPTION',
            'to_client' => 1,
        ],
        'atLeastOnce',
        'never',
        'staff@clientfence.com',
    ],
]);

test('sendTemplate handles to_staff and to_client options', function (array $data, string $clientGetExpects, string $staffResolveExpects, string $expectedRecipient): void {
    $service = new ClientFence\Mod\Email\Service();
    $signInLink = in_array($data['code'], ClientFence\Mod\Email\Service::SIGN_IN_LINK_TEMPLATES, true);

    $di = container();

    $emailTemplate = emailTemplate(data: ['enabled' => true]);

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn($emailTemplate);

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('getGroupIdsForTemplate')->andReturn([3]);

    /** @var ClientFence\Mod\Email\Entity\QueuedEmail|null $persistedQueue */
    $persistedQueue = null;
    $em = emailBuildEm(null, $templateRepo, null, true, $templateGroupRepo);
    $em->shouldReceive('persist')
        ->zeroOrMoreTimes()
        ->with(Mockery::on(function ($entity) use (&$persistedQueue): bool {
            if ($entity instanceof ClientFence\Mod\Email\Entity\QueuedEmail) {
                $persistedQueue = $entity;
            }

            return true;
        }));

    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')
        ->atLeast()->once()
        ->andReturn('value');

    $system->shouldReceive('renderEmailTplString', 'renderEmailSubjectString')->atLeast()->once()->andReturn('value');

    $staffServiceMock = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $groupMemberRepo = Mockery::mock(ClientFence\Mod\Staff\Repository\AdminGroupMemberRepository::class);
    if ($staffResolveExpects === 'atLeastOnce') {
        $groupMemberRepo->shouldReceive('getActiveStaffInGroups')
            ->atLeast()->once()
            ->with([3])
            ->andReturn([
                0 => [
                    'id' => 1,
                    'email' => 'staff@clientfence.com',
                    'name' => 'George',
                    'signature' => '',
                    'timezone' => null,
                ],
            ]);
        $staffServiceMock->shouldReceive('getAdminGroupMemberRepository')->atLeast()->once()->andReturn($groupMemberRepo);
    } else {
        $groupMemberRepo->shouldReceive('getActiveStaffInGroups')->never();
        $staffServiceMock->shouldReceive('getAdminGroupMemberRepository')->never();
    }

    $clientServiceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);

    $clientModel = createEntity(ClientFence\Mod\Client\Entity\Client::class, [
        'email' => 'client@example.com',
        'billing_email' => null,
    ]);
    if ($clientGetExpects === 'atLeastOnce') {
        $clientServiceMock->shouldReceive('get')
            ->atLeast()->once()
            ->andReturn($clientModel);
        $clientApiArray = [
            'id' => 1,
            'email' => 'staff@clientfence.com',
            'first_name' => 'John',
            'last_name' => 'Smith',
        ];
        $clientServiceMock->shouldReceive('toApiArray')
            ->atLeast()->once()
            ->andReturn($clientApiArray);
    } else {
        $clientServiceMock->shouldReceive('get')->never();
        $clientServiceMock->shouldReceive('toApiArray')->never();
    }

    $twigStub = Mockery::mock(Twig\Environment::class);

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    if ($signInLink) {
        $cryptMock->shouldNotReceive('encrypt');
    } else {
        $cryptMock->shouldReceive('encrypt')->atLeast()->once();
    }

    $di['api_admin'] = function () use ($di) {
        $api = new ClientFence\Api\Proxy(new Model_Admin());
        $api->setDi($di);

        return $api;
    };

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $di['validator'] = $validatorMock;

    $modMock = Mockery::mock(ClientFence\Module::class)->makePartial();
    $modMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn([
            'from_name' => 'Test',
            'from_email' => 'test@test.com',
        ]);

    $di['em'] = $em;
    $di['mod'] = $di->protect(fn () => $modMock);
    $di['twig'] = $twigStub;
    $di['crypt'] = $cryptMock;
    $di['mod_service'] = $di->protect(moduleService([
        'staff' => $staffServiceMock,
        'system' => $system,
        'client' => $clientServiceMock,
    ]));
    $di['tools'] = new ClientFence\Tools();

    $service->setDi($di);

    $result = $service->sendTemplate($data);

    expect($result)->toBeTrue();
    if ($signInLink) {
        expect($persistedQueue)->toBeNull();

        return;
    }
    expect($persistedQueue)->not->toBeNull();
    expect($persistedQueue->getRecipient())->toBe($expectedRecipient);
    if (($data['to_client'] ?? 0) > 0) {
        expect($persistedQueue->getClientId())->toBe(1);
    }
})->with('sendTemplateExistsStaffProvider');

dataset('sendTemplateClientBillingProvider', fn (): array => [
    'validated billing override' => [
        ['client_billing_email' => 'billing@example.com'],
        'billing@example.com',
    ],
    // A spoofed override and a generic `to` must not redirect client-bound content.
    'spoofed override and generic to fall back to client email' => [
        ['to' => 'attacker@evil.test', 'client_billing_email' => 'attacker@evil.test'],
        'client@example.com',
    ],
]);

test('sendTemplate only routes client-bound email to a validated billing address', function (array $extra, string $expectedRecipient): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();

    $emailTemplate = emailTemplate(data: ['enabled' => true]);

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn($emailTemplate);

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('getGroupIdsForTemplate')->andReturn([]);

    /** @var ClientFence\Mod\Email\Entity\QueuedEmail|null $persistedQueue */
    $persistedQueue = null;
    $em = emailBuildEm(null, $templateRepo, null, true, $templateGroupRepo);
    $em->shouldReceive('persist')
        ->atLeast()->once()
        ->with(Mockery::on(function ($entity) use (&$persistedQueue): bool {
            if ($entity instanceof ClientFence\Mod\Email\Entity\QueuedEmail) {
                $persistedQueue = $entity;
            }

            return true;
        }));

    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->atLeast()->once()->andReturn('value');
    $system->shouldReceive('renderEmailTplString', 'renderEmailSubjectString')->atLeast()->once()->andReturn('value');

    $clientModel = createEntity(ClientFence\Mod\Client\Entity\Client::class, [
        'email' => 'client@example.com',
        'billing_email' => 'billing@example.com',
    ]);

    $clientServiceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientServiceMock->shouldReceive('get')->atLeast()->once()->andReturn($clientModel);
    $clientServiceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn([
        'id' => 1,
        'email' => 'client@example.com',
        'first_name' => 'John',
        'last_name' => 'Smith',
    ]);

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('encrypt')->atLeast()->once();

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $di['validator'] = $validatorMock;

    $modMock = Mockery::mock(ClientFence\Module::class)->makePartial();
    $modMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([
        'from_name' => 'Test',
        'from_email' => 'test@test.com',
    ]);

    $di['em'] = $em;
    $di['mod'] = $di->protect(fn () => $modMock);
    $di['crypt'] = $cryptMock;
    $di['mod_service'] = $di->protect(moduleService([
        'system' => $system,
        'client' => $clientServiceMock,
    ]));

    $service->setDi($di);

    $result = $service->sendTemplate(array_merge([
        'code' => 'mod_email_test',
        'to_client' => 1,
        'default_subject' => 'SUBJECT',
        'default_template' => 'TEMPLATE',
        'default_description' => 'DESCRIPTION',
    ], $extra));

    expect($result)->toBeTrue();
    expect($persistedQueue)->not->toBeNull();
    expect($persistedQueue->getRecipient())->toBe($expectedRecipient);
    expect($persistedQueue->getClientId())->toBe(1);
})->with('sendTemplateClientBillingProvider');

test('sendTemplate copies a client email to the account\'s contacts of its kind (Client/Contacts.php)', function (string $code, ?array $expected): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn(emailTemplate(data: ['enabled' => true]));
    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('getGroupIdsForTemplate')->andReturn([]);

    $db = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $db->executeStatement('CREATE TABLE client_contact (id INTEGER PRIMARY KEY, client_id INTEGER, first_name TEXT, last_name TEXT, email TEXT, emails TEXT)');
    $db->insert('client_contact', ['client_id' => 1, 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com', 'emails' => 'invoice']);
    $db->insert('client_contact', ['client_id' => 1, 'first_name' => 'Bob', 'last_name' => '', 'email' => 'bob@example.com', 'emails' => 'support']);
    $db->insert('client_contact', ['client_id' => 2, 'first_name' => 'Eve', 'last_name' => '', 'email' => 'eve@example.com', 'emails' => 'invoice']);

    $queued = [];
    $em = emailBuildEm(null, $templateRepo, null, true, $templateGroupRepo);
    $em->shouldReceive('getConnection')->andReturn($db);
    $em->shouldReceive('persist')->with(Mockery::on(function ($entity) use (&$queued): bool {
        if ($entity instanceof ClientFence\Mod\Email\Entity\QueuedEmail) {
            $queued[] = $entity;
        }

        return true;
    }));

    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->andReturn('value');
    $system->shouldReceive('renderEmailTplString', 'renderEmailSubjectString')->andReturn('value');
    $clientServiceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientServiceMock->shouldReceive('get')->andReturn(createEntity(ClientFence\Mod\Client\Entity\Client::class, ['email' => 'client@example.com', 'billing_email' => null]));
    $clientServiceMock->shouldReceive('toApiArray')->andReturn(['id' => 1, 'email' => 'client@example.com', 'first_name' => 'John', 'last_name' => 'Smith']);
    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('encrypt');
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $modMock = Mockery::mock(ClientFence\Module::class)->makePartial();
    $modMock->shouldReceive('getConfig')->andReturn(['from_name' => 'Test', 'from_email' => 'test@test.com']);

    $di['validator'] = $validatorMock;
    $di['em'] = $em;
    $di['mod'] = $di->protect(fn () => $modMock);
    $di['crypt'] = $cryptMock;
    $di['mod_service'] = $di->protect(moduleService(['system' => $system, 'client' => $clientServiceMock]));
    $service->setDi($di);

    expect($service->sendTemplate(['code' => $code, 'to_client' => 1, 'default_subject' => 'S', 'default_template' => 'T', 'default_description' => 'D']))->toBeTrue()
        ->and(array_map(fn ($q) => $q->getRecipient(), $queued))->toBe($expected === null ? [] : ['client@example.com', ...$expected]);
})->with([
    'invoice email' => ['mod_invoice_created', ['ann@example.com']],
    'ticket reply' => ['mod_support_ticket_staff_reply', ['bob@example.com']],
    'domain email, no domain contact' => ['mod_servicedomain_renewed', []],
    'password reset link, never stored' => ['mod_client_password_reset_request', null],
]);

test('sendTemplate sends to a specific admin via to_admin using the Admin entity', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();

    $emailTemplate = emailTemplate(data: ['enabled' => true]);

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn($emailTemplate);

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('getGroupIdsForTemplate')->andReturn([]);

    $admin = createEntity(ClientFence\Mod\Staff\Entity\Admin::class, [
        'id' => 7,
        'email' => 'admin@clientfence.com',
        'name' => 'Root Admin',
        'signature' => '— Root',
        'timezone' => 'UTC',
    ]);

    $adminRepo = Mockery::mock(ClientFence\Mod\Staff\Repository\AdminRepository::class);
    $adminRepo->shouldReceive('find')
        ->once()
        ->with(7)
        ->andReturn($admin);

    /** @var ClientFence\Mod\Email\Entity\QueuedEmail|null $persistedQueue */
    $persistedQueue = null;
    $em = emailBuildEm(null, $templateRepo, null, true, $templateGroupRepo, $adminRepo);
    $em->shouldReceive('persist')
        ->atLeast()->once()
        ->with(Mockery::on(function ($entity) use (&$persistedQueue): bool {
            if ($entity instanceof ClientFence\Mod\Email\Entity\QueuedEmail) {
                $persistedQueue = $entity;
            }

            return true;
        }));

    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->atLeast()->once()->andReturn('value');
    $system->shouldReceive('renderEmailTplString', 'renderEmailSubjectString')->atLeast()->once()->andReturn('value');

    $staffServiceMock = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staffServiceMock->shouldReceive('getAdminGroupMemberRepository')->never();

    $clientServiceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientServiceMock->shouldReceive('get')->never();
    $clientServiceMock->shouldReceive('toApiArray')->never();

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('encrypt')->atLeast()->once();

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $di['validator'] = $validatorMock;

    $modMock = Mockery::mock(ClientFence\Module::class)->makePartial();
    $modMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn([
            'from_name' => 'Test',
            'from_email' => 'test@test.com',
        ]);

    $di['em'] = $em;
    $di['mod'] = $di->protect(fn () => $modMock);
    $di['crypt'] = $cryptMock;
    $di['mod_service'] = $di->protect(moduleService([
        'staff' => $staffServiceMock,
        'system' => $system,
        'client' => $clientServiceMock,
    ]));

    $service->setDi($di);

    $result = $service->sendTemplate([
        'code' => 'mod_email_test',
        'to_admin' => 7,
        'default_subject' => 'SUBJECT',
        'default_template' => 'TEMPLATE',
        'default_description' => 'DESCRIPTION',
    ]);

    expect($result)->toBeTrue();
    expect($persistedQueue)->not->toBeNull();
    expect($persistedQueue->getRecipient())->toBe('admin@clientfence.com');
    expect($persistedQueue->getClientId())->toBeNull();
    expect($persistedQueue->getAdminId())->toBe(7);
});

test('sendTemplate throws when to_admin does not resolve to an admin', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn(emailTemplate(data: ['enabled' => true]));

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);

    $adminRepo = Mockery::mock(ClientFence\Mod\Staff\Repository\AdminRepository::class);
    $adminRepo->shouldReceive('find')->once()->with(999)->andReturn(null);

    $di['em'] = emailBuildEm(null, $templateRepo, null, true, $templateGroupRepo, $adminRepo);

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $di['validator'] = $validatorMock;

    $service->setDi($di);

    $service->sendTemplate([
        'code' => 'mod_email_test',
        'to_admin' => 999,
        'default_subject' => 'SUBJECT',
        'default_template' => 'TEMPLATE',
        'default_description' => 'DESCRIPTION',
    ]);
})->throws(ClientFence\InformationException::class, 'Admin not found');

test('sendTemplate does not send to staff when template has no assigned groups', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();

    $emailTemplate = emailTemplate(data: ['enabled' => true]);

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn($emailTemplate);

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('getGroupIdsForTemplate')->andReturn([]);

    $em = emailBuildEm(null, $templateRepo, null, true, $templateGroupRepo);

    $staffServiceMock = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staffServiceMock->shouldReceive('getAdminGroupMemberRepository')->never();

    $systemService = Mockery::mock(ClientFence\Mod\System\Service::class);
    $systemService->shouldReceive('getParamValue')->atLeast()->once()->andReturn('value');
    $systemService->shouldReceive('renderEmailTplString', 'renderEmailSubjectString')->atLeast()->once()->andReturn('rendered');

    $modMock = Mockery::mock(ClientFence\Module::class)->makePartial();
    $modMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([
        'from_name' => 'Test',
        'from_email' => 'test@test.com',
    ]);

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('encrypt')->atLeast()->once();

    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();

    $twigStub = Mockery::mock(Twig\Environment::class);

    $di['em'] = $em;
    $di['crypt'] = $cryptMock;
    $di['validator'] = $validatorMock;
    $di['twig'] = $twigStub;
    $di['mod'] = $di->protect(fn () => $modMock);
    $di['mod_service'] = $di->protect(moduleService(['staff' => $staffServiceMock, 'system' => $systemService]));

    $service->setDi($di);

    $result = $service->sendTemplate([
        'code' => 'mod_email_test',
        'to_staff' => 1,
        'default_subject' => 'SUBJECT',
        'default_template' => 'TEMPLATE',
        'default_description' => 'DESCRIPTION',
    ]);

    expect($result)->toBeFalse();
});

test('getTemplateGroupIds delegates to the template group repository', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('getGroupIdsForTemplate')->once()->with(5)->andReturn([1, 2]);

    $service->setDi($di);
    $ref = new ReflectionProperty($service, 'templateGroupRepository');
    $ref->setValue($service, $templateGroupRepo);

    $template = emailTemplate(id: 5);

    expect($service->getTemplateGroupIds($template))->toBe([1, 2]);
});

test('addTemplateToGroup assigns a template to an existing staff group', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $template = emailTemplate(id: 5);
    $group = new ClientFence\Mod\Staff\Entity\AdminGroup();
    Tests\Helpers\setEntityId($group, 3);

    $adminGroupRepo = Mockery::mock(ClientFence\Mod\Staff\Repository\AdminGroupRepository::class);
    $adminGroupRepo->shouldReceive('find')->once()->with(3)->andReturn($group);

    $staffService = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staffService->shouldReceive('getAdminGroupRepository')->andReturn($adminGroupRepo);

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('findAssociation')->once()->with(5, 3)->andReturn(null);

    $em = emailBuildEm(null, null, null, true, $templateGroupRepo);
    $em->shouldReceive('persist')->once()->with(Mockery::type(ClientFence\Mod\Email\Entity\EmailTemplateGroup::class));
    $em->shouldReceive('flush')->atLeast()->once();

    $di['em'] = $em;
    $di['mod_service'] = $di->protect(moduleService(['staff' => $staffService]));
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    expect($service->addTemplateToGroup($template, 3))->toBeTrue();
});

test('addTemplateToGroup is idempotent when the association already exists', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $template = emailTemplate(id: 5);
    $group = new ClientFence\Mod\Staff\Entity\AdminGroup();
    Tests\Helpers\setEntityId($group, 3);

    $adminGroupRepo = Mockery::mock(ClientFence\Mod\Staff\Repository\AdminGroupRepository::class);
    $adminGroupRepo->shouldReceive('find')->once()->with(3)->andReturn($group);

    $staffService = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staffService->shouldReceive('getAdminGroupRepository')->andReturn($adminGroupRepo);

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('findAssociation')->once()->with(5, 3)
        ->andReturn(new ClientFence\Mod\Email\Entity\EmailTemplateGroup($template, 3));

    $em = emailBuildEm(null, null, null, true, $templateGroupRepo);
    $em->shouldReceive('persist')->never();

    $di['em'] = $em;
    $di['mod_service'] = $di->protect(moduleService(['staff' => $staffService]));
    $service->setDi($di);

    expect($service->addTemplateToGroup($template, 3))->toBeTrue();
});

test('addTemplateToGroup throws when the staff group does not exist', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $template = emailTemplate(id: 5);

    $adminGroupRepo = Mockery::mock(ClientFence\Mod\Staff\Repository\AdminGroupRepository::class);
    $adminGroupRepo->shouldReceive('find')->once()->with(3)->andReturn(null);

    $staffService = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staffService->shouldReceive('getAdminGroupRepository')->andReturn($adminGroupRepo);

    $di['mod_service'] = $di->protect(moduleService(['staff' => $staffService]));
    $service->setDi($di);

    $service->addTemplateToGroup($template, 3);
})->throws(ClientFence\InformationException::class, 'Staff group not found');

test('removeTemplateFromGroup removes an existing association', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $template = emailTemplate(id: 5);
    $association = new ClientFence\Mod\Email\Entity\EmailTemplateGroup($template, 3);

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('findAssociation')->once()->with(5, 3)->andReturn($association);

    $em = emailBuildEm(null, null, null, true, $templateGroupRepo);
    $em->shouldReceive('remove')->once()->with($association);
    $em->shouldReceive('flush')->atLeast()->once();

    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    expect($service->removeTemplateFromGroup($template, 3))->toBeTrue();
});

test('removeTemplateFromGroup is a no-op when no association exists', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $template = emailTemplate(id: 5);

    $templateGroupRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateGroupRepository::class);
    $templateGroupRepo->shouldReceive('findAssociation')->once()->with(5, 3)->andReturn(null);

    $em = emailBuildEm(null, null, null, true, $templateGroupRepo);
    $em->shouldReceive('remove')->never();

    $di['em'] = $em;
    $service->setDi($di);

    expect($service->removeTemplateFromGroup($template, 3))->toBeTrue();
});

test('assignAllGroupsToTemplate links a template to every existing staff group', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $groupA = new ClientFence\Mod\Staff\Entity\AdminGroup();
    Tests\Helpers\setEntityId($groupA, 10);
    $groupB = new ClientFence\Mod\Staff\Entity\AdminGroup();
    Tests\Helpers\setEntityId($groupB, 20);

    $adminGroupRepo = Mockery::mock(ClientFence\Mod\Staff\Repository\AdminGroupRepository::class);
    $adminGroupRepo->shouldReceive('findAll')->once()->andReturn([$groupA, $groupB]);

    $staffService = Mockery::mock(ClientFence\Mod\Staff\Service::class);
    $staffService->shouldReceive('getAdminGroupRepository')->andReturn($adminGroupRepo);

    $em = emailBuildEm();
    $persistedGroupIds = [];
    $em->shouldReceive('persist')->andReturnUsing(function ($entity) use (&$persistedGroupIds): void {
        if ($entity instanceof ClientFence\Mod\Email\Entity\EmailTemplateGroup) {
            $persistedGroupIds[] = $entity->getAdminGroupId();
        }
    });
    $em->shouldReceive('flush')->atLeast()->once();

    $di['em'] = $em;
    $di['mod_service'] = $di->protect(moduleService(['staff' => $staffService]));
    $service->setDi($di);

    $template = emailTemplate('mod_staff_client_order', 1);

    $ref = new ReflectionMethod($service, 'assignAllGroupsToTemplate');
    $ref->invoke($service, $template);

    expect($persistedGroupIds)->toBe([10, 20]);
});

test('resend resends email', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();

    $emailSettings = [
        'mailer' => 'sendmail',
        'smtp_authentication' => 'login',
        'smtp_host' => null,
        'smtp_security' => null,
        'smtp_port' => null,
        'smtp_username' => null,
        'smtp_password' => null,
    ];

    $isExtensionActiveReturn = false;
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('isExtensionActive')
        ->atLeast()->once()
        ->andReturn($isExtensionActiveReturn);

    $config = [];
    $di['mod_config'] = $di->protect(fn ($modName): array => $config);
    $di['mod_service'] = $di->protect(fn (): Mockery\MockInterface => $extension);
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $model = new ClientFence\Mod\Email\Entity\ActivityClientEmail();
    \Tests\Helpers\setEntityId($model, 1);
    $model->setClientId(1);
    $model->setSender('sender@exemple.com');
    $model->setRecipients('recipient@example.com');
    $model->setSubject('Email Title');
    $model->setContentHtml('<b>Content</b>');
    $model->setContentText('Content');

    $result = $service->resend($model);

    expect($result)->toBeTrue();
});

test('resend sends logged email without a client to its stored recipients', function (): void {
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('isExtensionActive')->once()->with('mod', 'demo')->andReturn(false);
    $client = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $client->shouldNotReceive('get');
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->once()->with('company_name')->andReturn('Company');

    $di = container();
    $di['mod_service'] = $di->protect(moduleService(['extension' => $extension, 'client' => $client, 'system' => $system]));
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $service->shouldReceive('sendMail')->once()
        ->with('guest@example.test', 'sender@example.test', 'Subject', '<p>Body</p>', '', 'Company', null, null, false, false, null)
        ->andReturn(true);
    $service->setDi($di);

    $email = new ClientFence\Mod\Email\Entity\ActivityClientEmail();
    \Tests\Helpers\setEntityId($email, 1);
    $email->setSender('sender@example.test');
    $email->setRecipients('guest@example.test');
    $email->setSubject('Subject');
    $email->setContentHtml('<p>Body</p>');

    expect(withAppEnv('prod', fn (): bool => $service->resend($email)))->toBeTrue();
});

test('templateToApiArray returns API array for template', function (): void {
    $id = 1;
    $action_code = 'code';
    $category = 'category';
    $enabled = 1;
    $subject = 'Subject';
    $description = 'Description';
    $content = 'content';

    $model = emailTemplate($action_code, $id, [
        'category' => $category,
        'enabled' => $enabled,
        'subject' => $subject,
        'description' => $description,
        'content' => $content,
    ]);

    $expected = [
        'id' => $id,
        'action_code' => $action_code,
        'category' => $category,
        'enabled' => true,
        'subject' => $subject,
        'description' => $description,
        'is_custom' => false,
        'has_default' => false,
        'is_overridden' => false,
    ];

    $service = new ClientFence\Mod\Email\Service();
    $result = $service->templateToApiArray($model);

    expect($result)->toBeArray();
    expect($result)->toBe($expected);
});

test('templateToApiArray returns deep array with vars', function (): void {
    $id = 1;
    $action_code = 'code';
    $category = 'category';
    $enabled = 1;
    $subject = 'Subject';
    $description = 'Description';
    $content = 'content';

    $model = emailTemplate($action_code, $id, [
        'category' => $category,
        'enabled' => $enabled,
        'subject' => $subject,
        'description' => $description,
        'content' => $content,
    ]);

    $expected = [
        'id' => $id,
        'action_code' => $action_code,
        'category' => $category,
        'enabled' => true,
        'subject' => $subject,
        'description' => $description,
        'is_custom' => false,
        'has_default' => false,
        'is_overridden' => false,
        'content' => $content,
        'vars' => [
            'param1' => 'value1',
        ],
        'subject_override' => $subject,
        'content_override' => $content,
        'has_error' => false,
        'last_error' => null,
    ];

    $serviceMock = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $serviceMock->shouldReceive('getVars')
        ->atLeast()->once()
        ->andReturn(['param1' => 'value1']);

    $result = $serviceMock->templateToApiArray($model, true);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('vars');
    expect($result['vars'])->toBeArray();

    expect($result)->toBe($expected);
});

dataset('template_updateProvider', fn (): array => [
    [
        [
            'id' => 5,
            'enabled' => 1,
            'category' => 'Category',
            'subject' => null,
            'content' => null,
        ],
        'never',
    ],
    [
        [
            'id' => 5,
            'enabled' => 1,
            'category' => 'Category',
            'subject' => 'Subject',
            'content' => 'Content',
        ],
        'atLeastOnce',
    ],
]);

test('updateTemplate updates template', function (array $data, string $templateRenderExpects): void {
    $id = 1;
    $model = emailTemplate(id: $id);

    $emailService = new ClientFence\Mod\Email\Service();

    $loggerStub = new Tests\Helpers\TestLogger();

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('decrypt')
        ->never();
    $configMock = ['salt' => md5(random_bytes(13))];

    $twigStub = Mockery::mock(Twig\Environment::class);

    $di = container();
    $di['logger'] = $loggerStub;
    $di['crypt'] = $cryptMock;
    $di['config'] = $configMock;
    $di['twig'] = $twigStub;

    $systemServiceMock = Mockery::mock(ClientFence\Mod\System\Service::class);
    if ($templateRenderExpects === 'atLeastOnce') {
        $systemServiceMock->shouldReceive('renderEmailTplString')
            ->atLeast()->once();
    } else {
        $systemServiceMock->shouldReceive('renderEmailTplString')->never();
    }

    $di['mod_service'] = $di->protect(moduleService(['system' => $systemServiceMock]));

    $emailService->setDi($di);

    $templateModel = emailTemplate();

    $result = $emailService->updateTemplate($templateModel, $data['enabled'], $data['category'], $data['subject'], @$data['content']);
    expect($result)->toBeTrue();
})->with('template_updateProvider');

test('templateCreate creates new template', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $em = emailBuildEm();
    $em->shouldReceive('persist')->atLeast()->once();
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $data = [
        'action_code' => 'Action_code',
        'subject' => 'Subject',
        'content' => 'Content',
        'category' => 'category',
    ];

    $result = $service->templateCreate($data['action_code'], $data['subject'], $data['content'], 1, $data['category']);

    expect($result)->toBeInstanceOf(EmailTemplate::class);
    expect($result->getActionCode())->toBe($data['action_code']);
});

test('templateBatchRegenerate resets existing built-in templates without module discovery', function (): void {
    $template = emailTemplate('mod_invoice_created', 1, [
        'subject' => 'Legacy subject',
        'content' => '{{ invoice.total|money(invoice.currency) }}',
        'is_overridden' => true,
        'last_error' => 'Unknown "money" filter',
    ]);
    $customTemplate = emailTemplate('mod_invoice_paid', 2, [
        'content' => 'Custom template content',
        'is_custom' => true,
    ]);

    $templateRepository = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepository->shouldReceive('findOneByActionCode')->never();
    $templateRepository->shouldReceive('findAll')->once()->andReturn([$template, $customTemplate]);

    $em = emailBuildEm(templateRepo: $templateRepository);
    $em->shouldReceive('flush')->once();

    $di = container();
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service = new ClientFence\Mod\Email\Service();
    $service->setDi($di);

    expect($service->templateBatchRegenerate())->toBeTrue()
        ->and($template->isOverridden())->toBeFalse()
        ->and($template->getSubject())->not->toBe('Legacy subject')
        ->and($template->getContent())->not->toContain('|money')
        ->and($template->hasError())->toBeFalse()
        ->and($customTemplate->getContent())->toBe('Custom template content');

    $logMessages = array_map(static fn (array $call): string => $call['params'][0], $di['logger']->calls);
    expect($logMessages)
        ->not->toContain('Synced file-backed email templates for installed modules.')
        ->toContain('Regenerated 1 existing file-backed email templates.');
});

test('templateBatchDisable disables all templates', function (): void {
    $service = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $service->shouldReceive('templateBatchGenerate')->once()->withNoArgs()->andReturnTrue();

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $result = $service->templateBatchDisable();

    expect($result)->toBeTrue();
});

test('templateBatchEnable enables all templates', function (): void {
    $service = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $service->shouldReceive('templateBatchGenerate')->once()->withNoArgs()->andReturnTrue();

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $result = $service->templateBatchEnable();

    expect($result)->toBeTrue();
});

test('batchSend processes email queue', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $queueModel = new ClientFence\Mod\Email\Entity\QueuedEmail();
    \Tests\Helpers\setEntityId($queueModel, 1);
    $queueModel->setPriority(10);
    $queueModel->setTries(10);
    $queueModel->setSubject('subject');
    $queueModel->setClientId(1);
    $queueModel->setSender('sender@example.com');
    $queueModel->setRecipient('receiver@example.com');
    $queueModel->setContent('content');
    $queueModel->setFromName('From Name');
    $queueModel->setToName('To Name');

    $queueRepo = Mockery::mock(ClientFence\Mod\Email\Repository\QueuedEmailRepository::class);
    $queueRepo->shouldReceive('findDueBatch')
        ->once()
        ->with(0)
        ->andReturn([$queueModel]);

    $em = emailBuildEm(null, null, $queueRepo);
    $em->shouldReceive('flush')->atLeast()->once();

    $modMock = Mockery::mock(ClientFence\Module::class);
    $modMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn([
            'log_enabled' => 1,
            'cancel_after' => 1,
        ]);

    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $isExtensionActiveReturn = false;
    $extension->shouldReceive('isExtensionActive')
        ->atLeast()->once()
        ->andReturn($isExtensionActiveReturn);

    $di = container();
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(function ($name) use ($extension) {
        if ($name == 'extension') {
            return $extension;
        }
    });
    $di['mod'] = $di->protect(fn () => $modMock);

    $service->setDi($di);

    $result = $service->batchSend();

    expect($result)->toBeNull();
});

test('sendMail queues email for sending', function (): void {
    $em = emailBuildEm();
    $em->shouldReceive('persist')->atLeast()->once();
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $di['logger'] = new Tests\Helpers\TestLogger();
    $modMock = Mockery::mock('\stdClass');
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $di['mod'] = $di->protect(fn (): Mockery\MockInterface => $modMock);
    $di['mod_service'] = $di->protect(function ($name) use ($extension) {
        if ($name == 'system') {
        } elseif ($name == 'extension') {
            return $extension;
        }
    });

    $service = new ClientFence\Mod\Email\Service();
    $service->setDi($di);

    $to = 'receiver@example.com';
    $from = 'sender@example.com';
    $subject = 'Important message';
    $content = 'content';
    $result = $service->sendMail($to, $from, $subject, $content);
    expect($result)->toBeTrue();
});

test('sendMail queues the given attachment onto the queued email', function (): void {
    $em = emailBuildEm();
    /** @var ClientFence\Mod\Email\Entity\QueuedEmail|null $persistedQueue */
    $persistedQueue = null;
    $em->shouldReceive('persist')
        ->atLeast()->once()
        ->with(Mockery::on(function ($queue) use (&$persistedQueue): bool {
            if ($queue instanceof ClientFence\Mod\Email\Entity\QueuedEmail) {
                $persistedQueue = $queue;
            }

            return true;
        }));
    $em->shouldReceive('flush')->atLeast()->once();

    $di = container();
    $di['em'] = $em;

    $service = new ClientFence\Mod\Email\Service();
    $service->setDi($di);

    $attachment = [
        'content' => '%PDF-1.4 fake invoice contents',
        'name' => 'Invoice-BB0001.pdf',
        'mime' => 'application/pdf',
    ];

    $result = $service->sendMail('receiver@example.com', 'sender@example.com', 'Invoice created', 'content', null, null, null, null, false, false, $attachment);

    expect($result)->toBeTrue();
    expect($persistedQueue)->not->toBeNull();
    expect($persistedQueue->getAttachmentName())->toBe('Invoice-BB0001.pdf');
    expect($persistedQueue->getAttachmentContent())->toBe('%PDF-1.4 fake invoice contents');
    expect($persistedQueue->getAttachmentMime())->toBe('application/pdf');
});

test('getBrokenTemplateCount returns count from repository', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $repoMock = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class)->shouldIgnoreMissing();
    $repoMock->shouldReceive('countBroken')
        ->once()
        ->andReturn(3);

    $service->setDi($di);

    $ref = new ReflectionProperty($service, 'templateRepository');
    $ref->setValue($service, $repoMock);

    expect($service->getBrokenTemplateCount())->toBe(3);
});

test('validateAllTemplates reports invalid templates', function (): void {
    $serviceMock = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $di = container();

    $validTemplate = emailTemplate('mod_email_valid', 1, [
        'enabled' => true,
        'subject' => 'Hello',
        'content' => 'World',
    ]);

    $invalidTemplate = emailTemplate('mod_email_broken', 2, [
        'enabled' => true,
        'subject' => 'Hello',
        'content' => 'Broken',
    ]);

    $repoMock = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class)->shouldIgnoreMissing();
    $repoMock->shouldReceive('findAll')
        ->once()
        ->andReturn([$validTemplate, $invalidTemplate]);

    $systemMock = Mockery::mock();
    $systemMock->shouldReceive('renderEmailTplString')
        ->times(3)
        ->andReturnUsing(function (string $template, array $vars): string {
            expect($vars)->toBe([]);

            if ($template === 'Broken') {
                throw new ClientFence\InformationException('Email template syntax error: Unknown "filter" filter');
            }

            return $template;
        });

    $emMock = emailBuildEm();
    $emMock->shouldReceive('flush')->once();

    $di['mod_service'] = $di->protect(function ($name) use ($systemMock) {
        if ($name === 'System' || $name === 'system') {
            return $systemMock;
        }
    });
    $di['em'] = $emMock;

    $serviceMock->setDi($di);

    $ref = new ReflectionProperty($serviceMock, 'templateRepository');
    $ref->setValue($serviceMock, $repoMock);

    $result = $serviceMock->validateAllTemplates();

    expect($result['valid'])->toBe(1);
    expect($result['invalid'])->toBe(1);
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['action_code'])->toBe('mod_email_broken');
    expect($di['logger']->calls)->toContainEqual([
        'method' => 'warning',
        'params' => ['Email template validation failed for "mod_email_broken": Email template syntax error: Unknown "filter" filter', []],
    ]);
});

test('validateAllTemplates reports stored variable errors instead of aborting', function (): void {
    $service = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $template = emailTemplate('mod_email_broken_vars', 1, [
        'subject' => 'Hello',
        'content' => 'World',
    ]);

    $repository = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class)->shouldIgnoreMissing();
    $repository->shouldReceive('findAll')->once()->andReturn([$template]);
    $service->shouldReceive('getVars')->once()->with($template)->andThrow(new RuntimeException('Unable to decrypt template variables'));

    $systemService = Mockery::mock();
    $systemService->shouldNotReceive('renderEmailTplString');

    $em = emailBuildEm();
    $em->shouldReceive('flush')->once();

    $di = container();
    $di['em'] = $em;
    $di['mod_service'] = $di->protect(moduleService(['system' => $systemService]));
    $service->setDi($di);

    $reflection = new ReflectionProperty($service, 'templateRepository');
    $reflection->setValue($service, $repository);

    expect($service->validateAllTemplates())->toMatchArray([
        'valid' => 0,
        'invalid' => 1,
        'errors' => [[
            'id' => 1,
            'action_code' => 'mod_email_broken_vars',
            'error' => 'Unable to decrypt template variables',
        ]],
    ]);
});

test('validateAllTemplates clears previous errors on valid templates', function (): void {
    $serviceMock = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $di = container();

    $template = emailTemplate('mod_email_recovery', 1, [
        'enabled' => true,
        'subject' => 'Hello',
        'content' => 'World',
        'last_error' => 'Previous error',
    ]);

    $repoMock = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class)->shouldIgnoreMissing();
    $repoMock->shouldReceive('findAll')
        ->once()
        ->andReturn([$template]);

    $systemMock = Mockery::mock();
    $systemMock->shouldReceive('renderEmailTplString')
        ->twice()
        ->andReturnArg(0);

    $emMock = emailBuildEm();
    $emMock->shouldReceive('flush')->once();

    $di['mod_service'] = $di->protect(function ($name) use ($systemMock) {
        if ($name === 'System' || $name === 'system') {
            return $systemMock;
        }
    });
    $di['em'] = $emMock;

    $serviceMock->setDi($di);

    $ref = new ReflectionProperty($serviceMock, 'templateRepository');
    $ref->setValue($serviceMock, $repoMock);

    $result = $serviceMock->validateAllTemplates();

    expect($result['valid'])->toBe(1);
    expect($result['invalid'])->toBe(0);
    expect($template->hasError())->toBeFalse();
});

test('validateAllTemplates renders templates with stored vars to enforce sandbox policy', function (): void {
    $serviceMock = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $di = container();

    $template = emailTemplate('mod_email_sandbox', 1, [
        'enabled' => true,
        'subject' => 'Hello {{ name }}',
        'content' => '{{ content|disallowed_filter }}',
        'vars' => 'encrypted-vars',
    ]);

    $repoMock = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class)->shouldIgnoreMissing();
    $repoMock->shouldReceive('findAll')
        ->once()
        ->andReturn([$template]);

    $cryptMock = Mockery::mock('ClientFence\Crypt');
    $cryptMock->shouldReceive('decrypt')
        ->once()
        ->with('encrypted-vars', Mockery::type('string'))
        ->andReturn('{"name":"Ada","content":"Body"}');

    $systemMock = Mockery::mock();
    $systemMock->shouldReceive('renderEmailTplString')
        ->once()
        ->with('{{ content|disallowed_filter }}', ['name' => 'Ada', 'content' => 'Body'])
        ->andThrow(new ClientFence\InformationException('Email template contains disallowed Twig syntax: Filter "disallowed_filter" is not allowed'));
    $systemMock->shouldReceive('renderEmailTplString')
        ->with('Hello {{ name }}', Mockery::any())
        ->never();

    $emMock = emailBuildEm();
    $emMock->shouldReceive('flush')->once();

    $di['crypt'] = $cryptMock;
    $di['mod_service'] = $di->protect(function ($name) use ($systemMock) {
        if ($name === 'System' || $name === 'system') {
            return $systemMock;
        }
    });
    $di['em'] = $emMock;

    $serviceMock->setDi($di);

    $ref = new ReflectionProperty($serviceMock, 'templateRepository');
    $ref->setValue($serviceMock, $repoMock);

    $result = $serviceMock->validateAllTemplates();

    expect($result['valid'])->toBe(0);
    expect($result['invalid'])->toBe(1);
    expect($template->hasError())->toBeTrue();
    expect($template->getLastError())->toContain('disallowed Twig syntax');
});

test('templateCreate validates subject and content', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $systemMock = Mockery::mock();
    $systemMock->shouldReceive('renderEmailTplString')
        ->once()
        ->withArgs(fn ($tpl): bool => str_contains((string) $tpl, 'valid subject'))
        ->andReturn('rendered');
    $systemMock->shouldReceive('renderEmailTplString')
        ->once()
        ->withArgs(fn ($tpl): bool => str_contains((string) $tpl, 'valid content'))
        ->andReturn('rendered');

    $emMock = emailBuildEm();
    $emMock->shouldReceive('persist')->once();
    $emMock->shouldReceive('flush')->once();

    $di['mod_service'] = $di->protect(function ($name) use ($systemMock) {
        if ($name === 'System' || $name === 'system') {
            return $systemMock;
        }
    });
    $di['em'] = $emMock;
    $di['logger'] = new Tests\Helpers\TestLogger();

    $service->setDi($di);

    $template = $service->templateCreate('mod_test_code', 'valid subject', 'valid content', 1, 'test');

    expect($template)->toBeInstanceOf(EmailTemplate::class);
});

test('templateCreate throws on invalid content', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $systemMock = Mockery::mock();
    $systemMock->shouldReceive('renderEmailTplString')
        ->once()
        ->andReturn('rendered');
    $systemMock->shouldReceive('renderEmailTplString')
        ->once()
        ->andThrow(new ClientFence\InformationException('Email template syntax error: Unknown "bad_filter" filter'));

    $di['mod_service'] = $di->protect(function ($name) use ($systemMock) {
        if ($name === 'System' || $name === 'system') {
            return $systemMock;
        }
    });

    $service->setDi($di);

    $service->templateCreate('mod_test_broken', 'subject', '{{ x|bad_filter }}', 1);
})->throws(ClientFence\InformationException::class, 'Email template syntax error');

test('EmailTemplate hasError returns true when lastError is set', function (): void {
    $template = new EmailTemplate('test_code', 1);

    expect($template->hasError())->toBeFalse();

    $template->setLastError('Some error message');
    expect($template->hasError())->toBeTrue();

    $template->clearError();
    expect($template->hasError())->toBeFalse();
    expect($template->getLastError())->toBeNull();
    expect($template->getErrorCheckedAt())->toBeNull();
});

test('EmailTemplate toApiArray includes has_error and last_error in deep mode', function (): void {
    $template = emailTemplate('mod_test_code', 1, [
        'enabled' => true,
        'subject' => 'Test Subject',
        'content' => 'Test Content',
        'is_custom' => true,
        'last_error' => 'Some syntax error',
    ]);

    $serviceMock = Mockery::mock(ClientFence\Mod\Email\Service::class)->makePartial();
    $serviceMock->shouldReceive('getVars')
        ->atLeast()->once()
        ->andReturn([]);

    $result = $serviceMock->templateToApiArray($template, true);

    expect($result['has_error'])->toBeTrue();
    expect($result['last_error'])->toBe('Some syntax error');
});

test('queuedAttachmentToArray returns null when the queue has no attachment', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $queue = new ClientFence\Mod\Email\Entity\QueuedEmail();

    $ref = new ReflectionMethod($service, 'queuedAttachmentToArray');
    $result = $ref->invoke($service, $queue);

    expect($result)->toBeNull();
});

test('queuedAttachmentToArray converts a queued attachment into a mail-ready array', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $queue = new ClientFence\Mod\Email\Entity\QueuedEmail();
    $queue->setAttachmentName('Invoice-BB0001.pdf');
    $queue->setAttachmentContent('%PDF-1.4 fake invoice contents');
    $queue->setAttachmentMime('application/pdf');

    $ref = new ReflectionMethod($service, 'queuedAttachmentToArray');
    $result = $ref->invoke($service, $queue);

    expect($result)->toBe([
        'content' => '%PDF-1.4 fake invoice contents',
        'name' => 'Invoice-BB0001.pdf',
        'mime' => 'application/pdf',
    ]);
});

test('loggedAttachmentToArray returns null when the logged email has no attachment', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $email = new ClientFence\Mod\Email\Entity\ActivityClientEmail();

    $ref = new ReflectionMethod($service, 'loggedAttachmentToArray');
    $result = $ref->invoke($service, $email);

    expect($result)->toBeNull();
});

test('loggedAttachmentToArray converts a logged attachment into a mail-ready array', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $email = new ClientFence\Mod\Email\Entity\ActivityClientEmail();
    $email->setAttachmentName('Invoice-BB0001.pdf');
    $email->setAttachmentContent('%PDF-1.4 fake invoice contents');
    $email->setAttachmentMime('application/pdf');

    $ref = new ReflectionMethod($service, 'loggedAttachmentToArray');
    $result = $ref->invoke($service, $email);

    expect($result)->toBe([
        'content' => '%PDF-1.4 fake invoice contents',
        'name' => 'Invoice-BB0001.pdf',
        'mime' => 'application/pdf',
    ]);
});

test('parseBccAddresses returns validated addresses and logs invalid ones', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $ref = new ReflectionMethod($service, 'parseBccAddresses');
    $result = $ref->invoke($service, ' billing@example.com ,not-an-email, accounts@example.com,');

    expect($result)->toBe(['billing@example.com', 'accounts@example.com']);

    $logMessages = array_map(static fn (array $call): string => $call['params'][0], $di['logger']->calls);
    expect($logMessages)->toContain('Skipping invalid Bcc address: not-an-email');
});

test('parseBccAddresses returns an empty array for a blank setting', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $di = container();
    $di['logger'] = new Tests\Helpers\TestLogger();
    $service->setDi($di);

    $ref = new ReflectionMethod($service, 'parseBccAddresses');
    $result = $ref->invoke($service, '  ,  ');

    expect($result)->toBe([]);
});

// A sensitive email (sign-in links) never reads the Bcc setting; an invalid address shows whether it was read.
test('_sendFromQueue skips the configured Bcc for sensitive emails', function (bool $sensitive, int $warnings): void {
    $queue = new ClientFence\Mod\Email\Entity\QueuedEmail();
    \Tests\Helpers\setEntityId($queue, 1);
    $queue->setSubject('subject');
    $queue->setSender('sender@example.com');
    $queue->setRecipient('receiver@example.com');
    $queue->setContent('content');

    $em = emailBuildEm();
    $em->shouldReceive('flush');
    $modMock = Mockery::mock(ClientFence\Module::class);
    $modMock->shouldReceive('getConfig')->andReturn(['mailer' => 'sendmail', 'bcc_email' => 'not-an-email']);
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('isExtensionActive')->andReturn(false);

    $di = container();
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(fn ($name) => $name == 'extension' ? $extension : null);
    $di['mod'] = $di->protect(fn () => $modMock);
    $service = new ClientFence\Mod\Email\Service();
    $service->setDi($di);

    (new ReflectionMethod($service, '_sendFromQueue'))->invoke($service, $queue, false, $sensitive);

    expect(array_filter(
        $di['logger']->calls,
        static fn (array $call): bool => $call['method'] === 'warning' && str_contains((string) $call['params'][0], 'Skipping invalid Bcc address')
    ))->toHaveCount($warnings);
})->with(['sensitive' => [true, 0], 'normal' => [false, 1]]);

test('_sendFromQueue only logs an invalid Bcc address once per service instance', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $buildQueue = static function (int $id): ClientFence\Mod\Email\Entity\QueuedEmail {
        $queue = new ClientFence\Mod\Email\Entity\QueuedEmail();
        \Tests\Helpers\setEntityId($queue, $id);
        $queue->setSubject('subject');
        $queue->setSender('sender@example.com');
        $queue->setRecipient('receiver@example.com');
        $queue->setContent('content');

        return $queue;
    };

    $em = emailBuildEm();
    $em->shouldReceive('flush')->atLeast()->once();

    $modMock = Mockery::mock(ClientFence\Module::class);
    $modMock->shouldReceive('getConfig')
        ->atLeast()->once()
        ->andReturn([
            'mailer' => 'sendmail',
            'bcc_email' => 'billing@example.com,not-an-email',
        ]);

    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('isExtensionActive')->atLeast()->once()->andReturn(false);

    $di = container();
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(function ($name) use ($extension) {
        if ($name == 'extension') {
            return $extension;
        }
    });
    $di['mod'] = $di->protect(fn () => $modMock);

    $service->setDi($di);

    $ref = new ReflectionMethod($service, '_sendFromQueue');
    $ref->invoke($service, $buildQueue(1));
    $ref->invoke($service, $buildQueue(2));

    $bccWarnings = array_filter(
        $di['logger']->calls,
        static fn (array $call): bool => $call['method'] === 'warning' && str_contains((string) $call['params'][0], 'Skipping invalid Bcc address')
    );

    expect($bccWarnings)->toHaveCount(1);
});

// ClientFence (QA r3 O-02): an email cut off while 'sending' is retried with a try counted, and an \Error marks it failed.
test('_sendFromQueue counts a retry of a cut-off send and marks an \\Error as failed', function (): void {
    $service = new ClientFence\Mod\Email\Service();

    $queue = new ClientFence\Mod\Email\Entity\QueuedEmail();
    \Tests\Helpers\setEntityId($queue, 7);
    $queue->setSubject('subject');
    $queue->setSender('sender@example.com');
    $queue->setRecipient('receiver@example.com');
    $queue->setContent('content');
    $queue->setStatus('sending');

    $connection = Mockery::mock(Doctrine\DBAL\Connection::class);
    $connection->shouldReceive('fetchOne')->andThrow(new Error('boom'));
    $em = emailBuildEm();
    $em->shouldReceive('getConnection')->andReturn($connection);

    $modMock = Mockery::mock(ClientFence\Module::class);
    $modMock->shouldReceive('getConfig')->andReturn(['mailer' => 'sendmail', 'cancel_after' => 5]);
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->shouldReceive('isExtensionActive')->andReturn(false);

    $di = container();
    $di['em'] = $em;
    $di['logger'] = new Tests\Helpers\TestLogger();
    $di['mod_service'] = $di->protect(fn ($name) => $name === 'extension' ? $extension : null);
    $di['mod'] = $di->protect(fn () => $modMock);
    $service->setDi($di);

    (new ReflectionMethod($service, '_sendFromQueue'))->invoke($service, $queue);

    expect($queue->getStatus())->toBe(ClientFence\Mod\Email\Entity\QueuedEmail::STATUS_FAILED)
        ->and($queue->getTries())->toBe(2);
});

test('a generated template renders with strict variables, so it can be saved unchanged', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $content = (fn (): string => $this->_getVarsString())->call($service);
    $twig = new Twig\Environment(new Twig\Loader\ArrayLoader(['t' => $content]), ['strict_variables' => true]);
    $twig->addFilter(new Twig\TwigFilter('markdown_to_html', fn (string $text): string => $text, ['is_safe' => ['html']]));
    $twig->addGlobal('ClientFenceVersion', '1.0.0');
    $twig->addGlobal('guest', ['system_company' => ['name' => 'Acme'], 'system_email' => ['signature' => 'Thanks']]);

    $html = $twig->render('t');

    expect($html)->toContain('1.0.0')->toContain('Acme')->toContain(date('Y-m-d'));
});

test('sendTemplate renders client-bound email in the client language', function (): void {
    $service = new ClientFence\Mod\Email\Service();
    $di = container();

    $emailTemplate = emailTemplate(data: ['enabled' => true]);

    $templateRepo = Mockery::mock(ClientFence\Mod\Email\Repository\EmailTemplateRepository::class);
    $templateRepo->shouldReceive('findOneByActionCode')->andReturn($emailTemplate);

    $em = emailBuildEm(null, $templateRepo);
    $em->shouldReceive('persist')->atLeast()->once();
    $em->shouldReceive('flush')->atLeast()->once();

    $seenLocales = [];
    $captureLocale = function (string $tpl, array $vars, ?string $timezone = null, ?string $locale = null) use (&$seenLocales): string {
        $seenLocales[] = $locale;

        return 'value';
    };
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->atLeast()->once()->andReturn('value');
    $system->shouldReceive('renderEmailTplString')->atLeast()->once()->andReturnUsing($captureLocale);
    $system->shouldReceive('renderEmailSubjectString')->atLeast()->once()->andReturnUsing($captureLocale);

    $clientServiceMock = Mockery::mock(ClientFence\Mod\Client\Service::class);
    $clientServiceMock->shouldReceive('get')->atLeast()->once()->andReturn(createEntity(ClientFence\Mod\Client\Entity\Client::class));
    $clientServiceMock->shouldReceive('toApiArray')->atLeast()->once()->andReturn([
        'id' => 1,
        'email' => 'client@example.com',
        'first_name' => 'John',
        'last_name' => 'Smith',
        'lang' => 'es_ES',
        'timezone' => 'UTC',
    ]);
    $staffServiceMock = Mockery::mock(ClientFence\Mod\Staff\Service::class);

    $cryptMock = Mockery::mock(ClientFence\Crypt::class);
    $cryptMock->shouldReceive('encrypt')->atLeast()->once();

    $modMock = Mockery::mock(ClientFence\Module::class)->makePartial();
    $modMock->shouldReceive('getConfig')->atLeast()->once()->andReturn([
        'from_name' => 'Test',
        'from_email' => 'test@test.com',
    ]);

    $di['api_admin'] = function () use ($di) {
        $api = new ClientFence\Api\Proxy(\Tests\Helpers\admin());
        $api->setDi($di);

        return $api;
    };
    $validatorMock = Mockery::mock(ClientFence\Validate::class);
    $validatorMock->shouldReceive('checkRequiredParamsForArray')->byDefault();
    $di['validator'] = $validatorMock;

    $di['em'] = $em;
    $di['crypt'] = $cryptMock;
    $di['mod'] = $di->protect(fn () => $modMock);
    $di['mod_service'] = $di->protect(moduleService([
        'staff' => $staffServiceMock,
        'system' => $system,
        'client' => $clientServiceMock,
    ]));
    $di['tools'] = new ClientFence\Tools();

    $service->setDi($di);

    $result = $service->sendTemplate(['code' => 'mod_email_test', 'to_client' => 1]);

    expect($result)->toBeTrue();
    expect($seenLocales)->not->toBeEmpty();
    foreach ($seenLocales as $locale) {
        expect($locale)->toBe('es_ES');
    }
});
