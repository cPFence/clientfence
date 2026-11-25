<?php

declare(strict_types=1);

/**
 * ClientFence: a ticket message keeps its line breaks (ticket_to_html), as WHMCS shows a reply, while other
 * Markdown (knowledge base, products, news) keeps CommonMark's soft breaks; staff see a client's reply name as a
 * link to the client.
 */

use ClientFence\Twig\Extension\ClientFenceExtension;
use ClientFence\Twig\Markdown\ClientFenceMarkdown;
use Tests\Support\StrictTemplateRenderer;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/** The portal's Twig filters for Markdown, with the real converters. */
function ticketMarkdownTwig(): Environment
{
    $di = Tests\Helpers\container();
    $theme = Mockery::mock(ClientFence\Mod\Theme\Service::class);
    $theme->allows('getDefaultMarkdownAttributes')->andReturn([]);
    $di['mod_service'] = $di->protect(fn (string $module) => $theme);

    $twig = new Environment(new ArrayLoader(), ['autoescape' => 'html']);
    $twig->addExtension(new MarkdownExtension());
    $twig->addExtension(new AttributeExtension(ClientFenceExtension::class));
    $twig->addRuntimeLoader(new FactoryRuntimeLoader([
        MarkdownRuntime::class => fn () => new MarkdownRuntime(new ClientFenceMarkdown($di, false)),
        ClientFenceExtension::class => fn () => new ClientFenceExtension($di),
    ]));

    return $twig;
}

test('a ticket message keeps each line break, other Markdown does not', function (): void {
    $twig = ticketMarkdownTwig();
    $text = "Disk Quota: 20 GB\nBandwidth: Unlimited\nEmail Accounts: 10";

    expect($twig->createTemplate('{{ text|ticket_to_html }}')->render(['text' => $text]))
        ->toBe("<p>Disk Quota: 20 GB<br />\nBandwidth: Unlimited<br />\nEmail Accounts: 10</p>\n")
        ->and($twig->createTemplate('{{ text|markdown_to_html }}')->render(['text' => $text]))
        ->toBe("<p>Disk Quota: 20 GB\nBandwidth: Unlimited\nEmail Accounts: 10</p>\n");
});

test('a ticket message still reads its Markdown, with raw HTML escaped', function (): void {
    $html = ticketMarkdownTwig()->createTemplate('{{ text|ticket_to_html }}')->render(['text' => "Your plan:\n\n- **Disk** 20 GB\n- [Docs](https://example.com/docs)\n\n```\nline one\nline two\n```\n\n<script>alert(1)</script>"]);

    expect($html)->toContain('<li><strong>Disk</strong> 20 GB</li>')
        ->toContain('<a href="https://example.com/docs">Docs</a>')
        ->toContain("<pre><code>line one\nline two\n</code></pre>")
        ->toContain('&lt;script&gt;')->not->toContain('<script>');
});

/** The admin ticket page of a client's ticket, with $overrides on top, for staff with the rights $can gives. */
function renderAdminTicket(array $overrides = [], ?Closure $can = null): string
{
    return (new StrictTemplateRenderer())->renderTemplate(PATH_MODS . '/Support/templates/admin/mod_support_ticket.html.twig', array_replace_recursive([
        'app_area' => 'admin',
        'request' => ['ajax' => true, 'id' => 1],
        'profile' => ['id' => 1, 'name' => 'Test Admin', 'email' => 'admin@example.com', 'signature' => ''],
        'canned_delay_message' => '',
        'request_message' => null,
        'ticket' => [
            'id' => 1, 'status' => 'open', 'subject' => 'Plan details', 'priority' => 6, 'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00', 'support_helpdesk_id' => 1, 'client_id' => 7, 'rel_type' => null, 'rel_id' => null,
            'rel_task' => null, 'rel_new_value' => null, 'rel_status' => null, 'notes' => [], 'replies' => 2, 'first' => null,
            'rel' => ['id' => null, 'type' => null, 'task' => null, 'new_value' => null, 'status' => null],
            'helpdesk' => ['id' => 1, 'name' => 'Helpdesk', 'can_reopen' => true, 'signature' => ''],
            'client' => ['id' => 7, 'first_name' => 'Test', 'last_name' => 'Client'],
            'author' => ['id' => 7, 'role' => 'client', 'name' => 'Test Client', 'first_name' => 'Test', 'last_name' => 'Client', 'email' => 'client@example.com'],
            'messages' => [],
        ],
    ], $overrides), hasPermission: $can);
}

test('staff see a reply by the client or its user as a link to the client (with client access) and can edit every reply', function (): void {
    $message = static fn (int $id, string $role, string $name, ?int $clientId, array $extra = []): array => [
        'id' => $id, 'content' => 'Message ' . $id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        'client_id' => $clientId, 'admin_id' => $role === 'admin' ? 1 : null,
        'author' => ['role' => $role, 'name' => $name, 'email' => 'someone@example.com'] + $extra,
    ];
    $messages = ['ticket' => ['messages' => [
        $message(1, 'client', 'Test Client', 7),
        $message(2, 'admin', 'Test Admin', null),
        $message(3, 'client', 'Jane User (authorized user)', 7, ['clientfence_user' => true]),
    ]]];
    $html = renderAdminTicket($messages, static fn (string $module, ?string $permission = null): bool => true);
    $noClientAccess = renderAdminTicket($messages, static fn (string $module, ?string $permission = null): bool => $module !== 'client');

    $document = new DOMDocument();
    @$document->loadHTML($html, LIBXML_NONET);
    $xpath = new DOMXPath($document);
    $nameLink = static fn (int $id): string => $xpath->evaluate('string(//*[@id="message-' . $id . '"]//div[contains(@class, "card-header")]//a[contains(@class, "fw-semibold")]/@href)');

    expect($nameLink(1))->toBe('client/manage/7')
        ->and($nameLink(3))->toBe('client/manage/7')
        ->and($nameLink(2))->toBe('')
        ->and($xpath->evaluate('string(//*[@id="message-2"]//span[contains(@class, "fw-semibold")])'))->toBe('Test Admin')
        ->and(str_contains($noClientAccess, '<a class="fw-semibold"'))->toBeFalse()
        ->and($html)->toContain('data-bs-target="#editMessage1"')->toContain('id="editMessage1"')->toContain('data-bs-target="#editMessage2"');
});

test('staff see the client\'s services on the ticket, each opening in a new tab; a guest ticket has none', function (): void {
    $order = static fn (int $id, string $title, string $status): array => [
        'id' => $id, 'title' => $title, 'status' => $status, 'total' => 10, 'currency' => 'USD', 'period' => null,
        'created_at' => '2026-01-01 00:00:00', 'expires_at' => '2026-02-01 00:00:00',
    ];
    $admin = new Tests\Support\PermissiveStub(['order_get_list' => ['total' => 12, 'list' => [$order(41, 'Web Hosting', 'active'), $order(42, 'example.com', 'canceled')]]]);
    $all = static fn (string $module, ?string $permission = null): bool => true;
    $links = static function (string $html): array {
        $document = new DOMDocument();
        @$document->loadHTML($html, LIBXML_NONET);
        $rows = [];
        foreach ((new DOMXPath($document))->query('//*[@data-ticket-services]//tbody//a') as $a) {
            $rows[] = [trim($a->textContent), $a->getAttribute('href'), $a->getAttribute('target'), $a->getAttribute('rel')];
        }

        return $rows;
    };

    $html = renderAdminTicket(['admin' => $admin], $all);
    $guest = renderAdminTicket(['admin' => $admin, 'ticket' => ['client_id' => null, 'author' => ['id' => null, 'role' => 'guest']]], $all);
    $ordersOnly = renderAdminTicket(['admin' => $admin], static fn (string $module, ?string $permission = null): bool => $module !== 'client');

    expect($links($html))->toBe([
        ['Web Hosting', 'order/manage/41', '_blank', 'noopener'],
        ['example.com', 'order/manage/42', '_blank', 'noopener'],
    ])
        ->and($html)->toContain('client/manage/7#tab-orders')
        ->and($guest)->not->toContain('data-ticket-services')
        ->and($links($ordersOnly))->toHaveCount(2)
        ->and($ordersOnly)->not->toContain('client/manage/7#tab-orders');
});
