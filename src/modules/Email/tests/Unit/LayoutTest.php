<?php

declare(strict_types=1);

use ClientFence\Mod\Email\Layout;

use function Tests\Helpers\container;

test('a message goes between the layout header and footer, with the CSS in the head', function (): void {
    $source = Layout::wrap('<p>Hello</p>', ['layout_header' => '<body>[top]', 'layout_footer' => '[end]</body>', 'layout_css' => 'p { color: red; }']);

    expect($source)->toContain("<style>\np { color: red; }\n</style>")
        ->and(strpos($source, '[top]'))->toBeLessThan(strpos($source, '{{ email_message }}'))
        ->and(strpos($source, '{{ email_message }}'))->toBeLessThan(strpos($source, '[end]'))
        ->and($source)->toStartWith('{% set email_message %}<p>Hello</p>{% endset %}');
});

test('a template that is a whole page of its own is sent as it is', function (): void {
    $page = "<!DOCTYPE html>\n<html lang=\"en\"><body>Old design</body></html>";

    expect(Layout::wrap($page, Layout::defaults()))->toBe($page);
});

test('an empty layout setting is the built-in default', function (): void {
    $layout = Layout::fromConfig(['layout_header' => '  ', 'layout_footer' => '<p>Own footer</p>', 'signature' => 'x']);

    expect($layout['layout_header'])->toBe(Layout::defaults()['layout_header'])
        ->and($layout['layout_footer'])->toBe('<p>Own footer</p>')
        ->and(array_keys($layout))->toBe(Layout::KEYS);
});

test('a saved layout that fails sends the email in the built-in layout', function (): void {
    $di = container();
    $di['mod'] = $di->protect(fn (): object => new class {
        public function getConfig(): array
        {
            return ['layout_footer' => '{{ broken'];
        }
    });
    $logger = Mockery::mock(ClientFence\Monolog::class);
    $logger->allows('setChannel')->andReturnSelf();
    $logger->expects('warning')->once();
    $di['logger'] = $logger;
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->expects('renderEmailTplString')->twice()->andReturnUsing(static function (string $tpl): string {
        if (str_contains($tpl, '{{ broken')) {
            throw new ClientFence\InformationException('Email template syntax error');
        }

        return 'sent';
    });
    $di['mod_service'] = $di->protect(fn (): object => $system);
    $service = new ClientFence\Mod\Email\Service();
    $service->setDi($di);

    expect($service->renderMessage('<p>Hi</p>', []))->toBe('sent');
});

test('saving keeps a part equal to the default empty, so it follows the default', function (): void {
    $di = container();
    $saved = null;
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->allows('getConfig')->with('mod_email')->andReturn(['signature' => 'Thanks']);
    $extension->expects('setConfig')->once()->andReturnUsing(static function (array $data) use (&$saved): bool {
        $saved = $data;

        return true;
    });
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->expects('checkEmailTplSyntax')->once();
    $di['mod_service'] = $di->protect(fn (string $name): object => strtolower($name) === 'extension' ? $extension : $system);
    $service = new ClientFence\Mod\Email\Service();
    $service->setDi($di);

    $service->saveLayout(['layout_header' => str_replace("\n", "\r\n", Layout::defaults()['layout_header']), 'layout_footer' => '<p>Own</p>', 'layout_css' => '']);

    expect($saved)->toBe(['signature' => 'Thanks', 'layout_header' => '', 'layout_footer' => '<p>Own</p>', 'layout_css' => '', 'ext' => 'mod_email']);
});

test('a layout that fails names the box that fails', function (): void {
    $di = container();
    $extension = Mockery::mock(ClientFence\Mod\Extension\Service::class);
    $extension->expects('setConfig')->never();
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    $system->allows('checkEmailTplSyntax')->andReturnUsing(static function (string $tpl): void {
        if (str_contains($tpl, 'include')) {
            throw new ClientFence\InformationException('Tag "include" is not allowed');
        }
    });
    $di['mod_service'] = $di->protect(fn (string $name): object => strtolower($name) === 'extension' ? $extension : $system);
    $service = new ClientFence\Mod\Email\Service();
    $service->setDi($di);

    expect(fn () => $service->saveLayout(['layout_header' => '', 'layout_footer' => "<p>{% include 'x' %}</p>", 'layout_css' => '']))
        ->toThrow(ClientFence\InformationException::class, 'Footer: Tag "include" is not allowed');
});

test('a block opened in one box and closed in another names the box whose own error it is', function (): void {
    $di = container();
    $system = Mockery::mock(ClientFence\Mod\System\Service::class);
    // As Twig: the unknown filter fails wherever it is; the header alone also fails, for its unclosed if.
    $system->allows('checkEmailTplSyntax')->andReturnUsing(static function (string $tpl): void {
        if (str_contains($tpl, 'defualt')) {
            throw new ClientFence\InformationException('Unknown "defualt" filter');
        }
        if (str_contains($tpl, '{% if') && !str_contains($tpl, 'endif')) {
            throw new ClientFence\InformationException('Unexpected end of template');
        }
    });
    $di['mod_service'] = $di->protect(fn (string $name): object => $system);
    $service = new ClientFence\Mod\Email\Service();
    $service->setDi($di);

    expect(fn () => $service->saveLayout(['layout_header' => '{% if locale %}', 'layout_footer' => "{{ locale|defualt('') }}{% endif %}", 'layout_css' => '']))
        ->toThrow(ClientFence\InformationException::class, 'Footer: Unknown "defualt" filter');
});
