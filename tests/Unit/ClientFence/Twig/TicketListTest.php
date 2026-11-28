<?php

declare(strict_types=1);

/**
 * ClientFence: the staff ticket list has the WHMCS column order, shows the last reply time under the subject
 * (nothing for a ticket without messages) and names the notes icon.
 */

use Tests\Support\PermissiveStub;
use Tests\Support\StrictTemplateRenderer;

test('the staff ticket list has the WHMCS columns, the last reply time and a named notes icon', function (): void {
    $ticket = static fn (int $id, ?string $lastReply, array $notes): array => [
        'id' => $id, 'subject' => 'Ticket ' . $id, 'replies' => 1, 'status' => 'open', 'priority' => 100, 'priority_level' => 'medium',
        'updated_at' => '2026-01-02 00:00:00', 'last_reply_at' => $lastReply, 'notes' => $notes,
        'helpdesk' => ['name' => 'Billing'], 'assigned_to' => ['name' => 'Test Admin'],
        'author' => ['id' => 7, 'role' => 'client', 'name' => 'Test Client', 'email' => 'client@example.com'],
    ];
    $admin = new PermissiveStub([
        'support_ticket_pinned_ids' => [],
        'support_ticket_get_list' => ['total' => 2, 'pages' => 1, 'page' => 1, 'per_page' => 25, 'list' => [
            $ticket(1, '2026-01-01 10:00:00', [['note' => 'One'], ['note' => 'Two']]),
            $ticket(2, null, []),
        ]],
    ]);

    $html = (new StrictTemplateRenderer())->renderTemplate(PATH_MODS . '/Support/templates/admin/mod_support_tickets.html.twig', [
        'request' => new PermissiveStub(['ajax' => true]),
        'admin' => $admin,
    ]);
    $document = new DOMDocument();
    @$document->loadHTML($html, LIBXML_NONET);
    $xpath = new DOMXPath($document);
    $texts = static fn (string $query): array => array_map(static fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query($query)));
    $lastReply = static fn (int $row): array => $texts('//table//tbody/tr[' . $row . ']/td[3]/span[contains(@class, "text-muted")]');

    expect($texts('//table/thead//th'))->toBe(['', 'Helpdesk', 'Subject', 'Author', 'Status', 'Priority', 'Assigned To', ''])
        ->and($texts('//table//tbody/tr[1]/td[2]'))->toBe(['Billing'])
        ->and($texts('//table//tbody/tr[1]/td[4]//a[contains(@href, "client/manage/7")]'))->toContain('Test Client')
        ->and($lastReply(1))->toHaveCount(1)
        ->and($lastReply(1)[0])->toMatch('/^Last reply \S.* ago$/')
        ->and($xpath->evaluate('string(//table//tbody/tr[1]/td[3]/span[contains(@class, "text-muted")]/@title)'))->toContain('Jan 1, 2026')
        ->and($lastReply(2))->toBe([])
        ->and($xpath->evaluate('string(//a[contains(@class, "show-notes")]/@title)'))->toBe('Staff notes (2): click to read')
        ->and($xpath->evaluate('string(//a[contains(@class, "show-notes")]/@aria-label)'))->toBe('Staff notes (2): click to read')
        ->and($xpath->query('//a[contains(@class, "show-notes")]'))->toHaveCount(1);
});
