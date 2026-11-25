<?php

declare(strict_types=1);

/**
 * ClientFence: every email template a module ships loads under the email sandbox
 * (ClientFence\Twig\EmailPolicy), which renders them when they are sent. A disallowed tag, filter
 * or function (capitalize, replace, ...) only shows when the email is sent: the send fails and the
 * email is lost.
 */

use ClientFence\Twig\EmailPolicy;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** The email environment's own filters and functions (TwigFactory::createEmailEnvironment()), as stubs. */
function emailSandboxEnvironment(?Closure $trans = null, bool $markdown = false): Environment
{
    $twig = new Environment(new ArrayLoader(), ['autoescape' => 'html']);
    $twig->addExtension(new SandboxExtension(EmailPolicy::create(), true));
    if ($markdown) {
        // The real Markdown converter of the portal (raw HTML escaped).
        $di = Tests\Helpers\container();
        $theme = Mockery::mock(ClientFence\Mod\Theme\Service::class);
        $theme->allows('getDefaultMarkdownAttributes')->andReturn([]);
        $di['mod_service'] = $di->protect(fn (string $module) => $theme);
        $twig->addExtension(new Twig\Extra\Markdown\MarkdownExtension());
        $twig->addRuntimeLoader(new Twig\RuntimeLoader\FactoryRuntimeLoader([Twig\Extra\Markdown\MarkdownRuntime::class => fn () => new Twig\Extra\Markdown\MarkdownRuntime(new ClientFence\Twig\Markdown\ClientFenceMarkdown($di, false))]));
    }
    foreach (array_diff([
        'format_currency', 'format_date', 'format_datetime', 'format_number', 'format_time', 'currency_name',
        'currency_symbol', 'country_name', 'url', 'daysleft', 'trans', 'trans_html', 'period_title', 'markdown_to_html',
    ], $markdown ? ['markdown_to_html'] : []) as $filter) {
        $twig->addFilter(new TwigFilter($filter, $filter === 'trans' && $trans ? $trans : static fn (mixed $value, mixed ...$options): mixed => $value, ['is_variadic' => true]));
    }
    $twig->addFunction(new TwigFunction('country_names', static fn (): array => []));

    return $twig;
}

dataset('shipped email templates', function (): array {
    $files = glob(dirname(__DIR__, 4) . '/src/modules/*/templates/email/*.html.twig') ?: [];
    sort($files);

    return array_combine(array_map(static fn (string $f): string => basename(dirname($f, 3)) . '/' . basename($f), $files), array_map(static fn (string $f): array => [$f], $files));
});

test('shipped email templates use only what the email sandbox allows', function (string $file): void {
    $twig = emailSandboxEnvironment();

    // Rendering runs the sandbox's checks of the template's tags, filters and functions.
    $twig->createTemplate((string) file_get_contents($file), basename($file))->render([]);

    expect(true)->toBeTrue();
})->with('shipped email templates');

test('shipped email templates are the message only and render in the email layout', function (string $file): void {
    // CF-234: the layout (Email\Layout) adds the page; a template that kept its own page would skip the layout.
    preg_match('/{%.?block content.?%}((.*?\n)+){%.?endblock.?%}/m', (string) file_get_contents($file), $mc);
    $html = emailSandboxEnvironment()->createTemplate(ClientFence\Mod\Email\Layout::wrap($mc[1], ClientFence\Mod\Email\Layout::defaults()), basename($file))->render([]);

    expect(ClientFence\Mod\Email\Layout::isPage($mc[1]))->toBeFalse()
        ->and(substr_count($html, '<html'))->toBe(1)
        ->and($html)->toContain('</body>');
})->with('shipped email templates');

test('shipped email subjects fit the email_template.subject column', function (string $file): void {
    // The installer and the template sync store the subject block's source in varchar(255): longer fails the install.
    preg_match('#{%.?block subject.?%}((.*?)+){%.?endblock.?%}#', (string) file_get_contents($file), $ms);

    expect(mb_strlen($ms[1] ?? ''))->toBeLessThanOrEqual(255);
})->with('shipped email templates');

test('shipped emails show no empty box when the optional values are missing', function (string $file): void {
    // A domain without nameservers got a "Nameservers" heading over an empty box: each box shows only with something in it.
    // These list one box per item and are only sent with items (BulkPrices: a client's services; Health::notify(): failing checks).
    $sentWithItems = ['mod_order_price_changed.html.twig', 'mod_clientfence_health_alert.html.twig'];
    $html = emailSandboxEnvironment()->createTemplate((string) file_get_contents($file), basename($file))->render([]);
    expect($html)->not->toBe('');
    if (in_array(basename($file), $sentWithItems, true)) {
        return;
    }
    preg_match_all('#<table[^>]*background-color:\#f8fafc.*?</table>\s*</td></tr>\s*</table>#s', $html, $boxes);

    foreach ($boxes[0] as $box) {
        expect(trim(html_entity_decode(strip_tags($box))))->not->toBe('');
    }
})->with('shipped email templates');

test('ticket emails translate the ticket status', function (string $file): void {
    $twig = emailSandboxEnvironment(static fn (mixed $value, mixed ...$options): string => '[' . $value . ']');
    $template = $twig->createTemplate((string) file_get_contents(dirname(__DIR__, 4) . '/src/modules/' . $file), basename($file));

    expect($template->render(['ticket' => ['status' => 'closed']]))->toContain('[Closed]')
        ->and($template->render(['ticket' => ['status' => 'on_hold']]))->toContain('[Answered]');
})->with([
    'Support/templates/email/mod_support_ticket_open.html.twig', 'Support/templates/email/mod_support_ticket_staff_open.html.twig',
    'Support/templates/email/mod_support_ticket_staff_reply.html.twig', 'Support/templates/email/mod_support_ticket_staff_close.html.twig',
    'Support/templates/email/mod_support_helpdesk_ticket_open.html.twig', 'Staff/templates/email/mod_staff_ticket_open.html.twig',
    'Staff/templates/email/mod_staff_ticket_reply.html.twig',
]);

test('the staff reply email shows the reply just posted', function (): void {
    $file = dirname(__DIR__, 4) . '/src/modules/Support/templates/email/mod_support_ticket_staff_reply.html.twig';
    $html = emailSandboxEnvironment()->createTemplate((string) file_get_contents($file), 'reply')
        ->render(['ticket' => ['messages' => [['content' => 'The question'], ['content' => 'The <b>answer</b>']]]]);

    expect($html)->toContain('The &lt;b&gt;answer&lt;/b&gt;')->not->toContain('The question');
});

test('ticket emails with a staff message show it as formatted text, with its HTML escaped', function (string $file, array $messages): void {
    $html = emailSandboxEnvironment(null, true)->createTemplate((string) file_get_contents(dirname(__DIR__, 4) . '/src/modules/Support/templates/email/' . $file), $file)
        ->render(['ticket' => ['messages' => $messages]]);

    expect($html)->toContain('<strong>bold part</strong>')->not->toContain('**bold part**')
        ->toContain('&lt;script&gt;')->not->toContain('<script>alert');
})->with([
    'staff reply' => ['mod_support_ticket_staff_reply.html.twig', [['content' => 'The question'], ['content' => "A **bold part**\n\n<script>alert(1)</script>"]]],
    'staff opened' => ['mod_support_ticket_staff_open.html.twig', [['content' => "A **bold part**\n\n<script>alert(1)</script>"]]],
]);

test('staff assigned and escalated emails translate the status and priority', function (string $file): void {
    $twig = emailSandboxEnvironment(static fn (mixed $value, mixed ...$options): string => '[' . $value . ']');
    $html = $twig->createTemplate((string) file_get_contents(dirname(__DIR__, 4) . '/src/modules/Staff/templates/email/' . $file), $file)
        ->render(['ticket' => ['status_title' => 'Answered', 'priority_title' => 'High']]);

    expect($html)->toContain('[Answered]')->toContain('[High]');
})->with(['mod_staff_ticket_assigned.html.twig', 'mod_staff_ticket_escalated.html.twig']);

test('the check catches a disallowed filter', function (): void {
    expect(fn () => emailSandboxEnvironment()->createTemplate("{{ 'on_hold'|replace({'_': ' '})|capitalize }}", 'bad')->render([]))
        ->toThrow(Twig\Sandbox\SecurityError::class);
});

test('the failed staff sign-in email shows what failed, the address typed, the IP address and the hostname', function (array $vars, array $shown): void {
    $twig = emailSandboxEnvironment(static fn (mixed $value, array $options = []): string => strtr((string) $value, $options[0] ?? []));
    $html = $twig->createTemplate((string) file_get_contents(dirname(__DIR__, 4) . '/src/modules/Staff/templates/email/mod_staff_clientfence_signin_failed.html.twig'), 'failed')
        ->render($vars + ['attempted_at' => '2026-11-25 08:00:00', 'username' => '<b>x</b>@example.test', 'ip' => '203.0.113.9', 'admin_url' => 'https://billing.example.test/admin/']);

    expect($html)->toContain('2026-11-25 08:00:00')->toContain('&lt;b&gt;x&lt;/b&gt;@example.test')->not->toContain('<b>x</b>')
        ->toContain('href="https://billing.example.test/admin/"');
    foreach ($shown as $text) {
        expect($html)->toContain($text);
    }
})->with([
    'a wrong password, with a reverse name' => [['failed' => 'password', 'hostname' => 'host.example.net'], ['a wrong password or an unknown email address', 'Hostname</td><td style="padding:0 8px 4px;">host.example.net<']],
    'a wrong password, no reverse name' => [['failed' => 'password', 'hostname' => '203.0.113.9'], ['Hostname</td><td style="padding:0 8px 4px;">203.0.113.9<']],
    'a wrong code' => [['failed' => 'code', 'hostname' => 'h', 'login' => ['name' => 'Second Admin', 'email' => 'second@example.test']], ['The password of Second Admin (second@example.test) was right, but the authenticator code was wrong.']],
    'a wrong PIN' => [['failed' => 'pin', 'hostname' => 'h', 'login' => ['name' => 'Second Admin', 'email' => 'second@example.test']], ['The password of Second Admin (second@example.test) was right, but the email PIN was wrong.']],
]);
