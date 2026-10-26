<?php

declare(strict_types=1);

/**
 * ClientFence: the Palisade theme settings that decide what a page shows (who may see a page while
 * signed out, the side menu items, the footer links), rendered from the theme's own templates.
 */

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PalisadeGuestStub
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    public function __call(string $name, array $args): mixed
    {
        return $this->values[$name] ?? false;
    }
}

/**
 * @param array<string, mixed> $context
 */
function renderPalisade(string $template, array $context): string
{
    $twig = new Environment(new FilesystemLoader(PATH_THEMES . '/palisade/html'), ['strict_variables' => true, 'autoescape' => 'html']);
    $twig->addFilter(new TwigFilter('trans', static fn (string $text): string => $text));
    $twig->addFilter(new TwigFilter('url', static fn (string $path): string => '/' . ltrim($path, '/')));
    $twig->addFunction(new TwigFunction('render_widgets', static fn (): string => ''));

    return $twig->render($template, $context + [
        'client' => null,
        'settings' => [],
        'request_path' => '',
        'guest' => new PalisadeGuestStub(),
    ]);
}

test('guest access: signed-out visitors are gated unless the page or a setting opens it', function (string $path, array $settings, bool $guestTickets, string $expected): void {
    $result = trim(renderPalisade('partials/guest_access.html.twig', [
        'request_path' => $path,
        'settings' => $settings,
        'guest' => new PalisadeGuestStub(['support_guest_tickets_enabled' => $guestTickets, 'extension_is_on' => true]),
    ]));

    expect($result)->toBe($expected);
})->with([
    'dashboard, login required' => ['', ['require_login' => '1'], false, 'gated'],
    'dashboard, no setting saved' => ['', [], false, 'gated'],
    'dashboard, dashboard for guests' => ['', ['require_login' => '0'], false, 'open'],
    'services, login required' => ['order/service', ['require_login' => '1'], true, 'gated'],
    'terms' => ['tos', ['require_login' => '1'], false, 'open'],
    'privacy policy' => ['privacy-policy', ['require_login' => '1'], false, 'open'],
    'news post' => ['news/a-post', ['require_login' => '1'], false, 'open'],
    'invoice link' => ['invoice/abc123', ['require_login' => '1'], false, 'open'],
    'invoice list' => ['invoice', ['require_login' => '1'], false, 'gated'],
    'contact form, guest tickets off' => ['support/contact-us', ['require_login' => '1'], false, 'gated'],
    'contact form, guest tickets on' => ['support/contact-us', ['require_login' => '1'], true, 'open'],
    'guest ticket link, guest tickets on' => ['support/ticket/abc123', ['require_login' => '1'], true, 'open'],
    'look-alike path' => ['newsletter', ['require_login' => '1'], true, 'gated'],
]);

test('guest access: the Network Status page opens to visitors only while it is public', function (): void {
    $access = static fn (array $status): string => trim(renderPalisade('partials/guest_access.html.twig', [
        'request_path' => '/network-status', 'settings' => ['require_login' => '1'],
        'guest' => new PalisadeGuestStub(['support_network_status_settings' => $status]),
    ]));

    expect($access(['enabled' => true, 'public' => true]))->toBe('open')
        ->and($access(['enabled' => true, 'public' => false]))->toBe('gated')
        ->and($access([]))->toBe('gated');
});

test('guest access: news stays gated while the News extension is off', function (): void {
    $render = static fn (string $path): string => trim(renderPalisade('partials/guest_access.html.twig', [
        'request_path' => $path,
        'settings' => ['require_login' => '1'],
        'guest' => new PalisadeGuestStub(['extension_is_on' => false]),
    ]));

    expect($render('news'))->toBe('gated')->and($render('news/a-post'))->toBe('gated')->and($render('tos'))->toBe('open');
});

test('guest access: signed-in clients and allow_guest pages are always open', function (): void {
    expect(trim(renderPalisade('partials/guest_access.html.twig', ['client' => new PalisadeGuestStub(), 'settings' => ['require_login' => '1']])))->toBe('open')
        ->and(trim(renderPalisade('partials/guest_access.html.twig', ['allow_guest' => true, 'settings' => ['require_login' => '1']])))->toBe('open');
});

test('side menu: each "Side Menu Items" setting turns its link off', function (): void {
    $client = new PalisadeGuestStub(['client_user_session' => null]);
    $guest = new PalisadeGuestStub(['clientfence_store_open' => true, 'invoice_funds_enabled' => true, 'extension_is_on' => true, 'custompages_links' => [], 'support_kb_enabled' => true]);
    $render = static fn (array $settings): string => renderPalisade('partial_menu.html.twig', [
        'client' => $client, 'guest' => $guest, 'settings' => $settings, 'current_active_menu' => '',
    ]);

    $all = $render([]);
    foreach (['/order/service', '/invoice', '/support', '/order', '/email', '/client/balance', '/news', '/support/kb', '/users', '/client/profile'] as $url) {
        expect($all)->toContain('href="' . $url . '"');
    }

    $off = $render(['side_menu_dashboard' => '0', 'side_menu_order' => '0', 'side_menu_support' => '0', 'side_menu_services' => '0', 'side_menu_invoices' => '0', 'side_menu_emails' => '0', 'side_menu_payments' => '0', 'side_menu_news' => '0', 'side_menu_kb' => '0']);
    foreach (['href="/"', '/order/service', '/invoice', '"/support"', '"/order"', '/email', '/client/balance', '/news', '/support/kb'] as $gone) {
        expect($off)->not->toContain($gone);
    }
    expect($off)->toContain('href="/users"')->and($off)->toContain('href="/client/profile"');
});

test('side menu: Network Status shows while it is on, for visitors only while it is public', function (): void {
    $client = new PalisadeGuestStub(['client_user_session' => null]);
    $menu = static fn (?object $client, array $status): string => renderPalisade('partial_menu.html.twig', [
        'client' => $client, 'guest' => new PalisadeGuestStub(['support_network_status_settings' => $status]), 'settings' => [], 'current_active_menu' => '',
    ]);

    expect($menu($client, ['enabled' => true, 'public' => false]))->toContain('href="/network-status"')
        ->and($menu($client, ['enabled' => false, 'public' => true]))->not->toContain('network-status')
        ->and($menu(null, ['enabled' => true, 'public' => false]))->not->toContain('network-status')
        ->and($menu(null, ['enabled' => true, 'public' => true]))->toContain('href="/network-status"');
});

test('side menu: visitors see the store and news, and the dashboard only while it is open to guests', function (): void {
    $guest = new PalisadeGuestStub(['clientfence_store_open' => true, 'extension_is_on' => true, 'custompages_links' => [], 'support_kb_enabled' => true, 'support_guest_tickets_enabled' => true]);

    $loginRequired = renderPalisade('partial_menu.html.twig', ['guest' => $guest, 'settings' => ['require_login' => '1'], 'current_active_menu' => '']);
    expect($loginRequired)->toContain('href="/order"')->and($loginRequired)->toContain('href="/news"')
        ->and($loginRequired)->toContain('href="/support/contact-us"')
        ->and($loginRequired)->not->toContain('href="/"')->and($loginRequired)->not->toContain('/support/kb')
        ->and($loginRequired)->not->toContain('/invoice');

    $guestsIn = renderPalisade('partial_menu.html.twig', ['guest' => $guest, 'settings' => ['require_login' => '0'], 'current_active_menu' => '']);
    expect($guestsIn)->toContain('href="/"')->and($guestsIn)->toContain('href="/support/kb"')
        ->and($guestsIn)->not->toContain('/client/profile');
});

test('footer: the signature, and only enabled links with a title, full addresses kept as they are', function (): void {
    $html = renderPalisade('partials/footer.html.twig', [
        'guest' => new PalisadeGuestStub(['system_company' => ['name' => 'Acme', 'signature' => 'Acme Ltd', 'bank_info_pagebottom' => '0']]),
        'settings' => [
            'footer_enabled' => '1',
            'footer_signature' => '',
            'footer_link_1_enabled' => '1', 'footer_link_1_title' => 'Terms', 'footer_link_1_page' => 'tos',
            'footer_link_2_enabled' => 'on', 'footer_link_2_title' => 'Status', 'footer_link_2_page' => 'https://status.example.com',
            'footer_link_3_enabled' => '0', 'footer_link_3_title' => 'Hidden', 'footer_link_3_page' => 'hidden',
            'footer_link_4_enabled' => '1', 'footer_link_4_title' => '', 'footer_link_4_page' => 'untitled',
        ],
    ]);

    expect($html)->toContain('Acme Ltd')
        ->and($html)->toContain('<a href="/tos">Terms</a>')
        ->and($html)->toContain('<a href="https://status.example.com">Status</a>')
        ->and($html)->not->toContain('Hidden')
        ->and($html)->not->toContain('untitled')
        ->and($html)->not->toContain('Payment Information');

    expect(trim(renderPalisade('partials/footer.html.twig', ['settings' => ['footer_enabled' => '0']])))->toBe('');
});
