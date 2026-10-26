<?php

declare(strict_types=1);

/**
 * ClientFence: the settings the admin Ctrl+K search finds (Mod\Clientfence\SettingsIndex): the
 * committed index matches the templates, and what a staff member may see of it.
 */

use ClientFence\Mod\Clientfence\SettingsIndex;

test('settings-index.json is up to date (run php tools/build-settings-index.php)', function (): void {
    $built = SettingsIndex::build(dirname(__DIR__, 3));

    expect(json_decode((string) file_get_contents(SettingsIndex::FILE), true))->toBe($built)
        ->and($built)->toContain(['mod' => 'system', 'tab' => 'tab-signin', 'kind' => 'field', 'label' => 'Ask for an email PIN'])
        ->and($built)->toContain(['mod' => 'staff', 'tab' => 'tab-settings', 'kind' => 'field', 'label' => 'Allowed IPs. One Per Line']);
});

test('every extra word names a page or tab of the index', function (): void {
    $keys = [];
    foreach (json_decode((string) file_get_contents(SettingsIndex::FILE), true) as $entry) {
        $keys[$entry['mod']] = true;
        $keys[$entry['mod'] . '#' . $entry['tab']] = true;
    }

    expect(array_diff(array_keys(SettingsIndex::WORDS), array_keys($keys)))->toBe([]);
});

test('tabs, sections and fields are read with their tab; pop-ups and list filters are not', function (): void {
    $dir = sys_get_temp_dir() . '/settings-index-' . bin2hex(random_bytes(4));
    mkdir($dir . '/Demo/templates/admin', 0o777, true);
    file_put_contents($dir . '/Demo/templates/admin/mod_demo_settings.html.twig', <<<'TWIG'
        <a class="nav-link" href="#tab-one" data-bs-toggle="tab">{{ 'First'|trans }}</a>
        <button class="nav-link" data-bs-target="#tab-two" data-bs-toggle="tab" type="button"><svg class="icon"></svg> {{ 'Second'|trans }}</button>
        <div class="tab-pane fade{% if x %} show{% endif %}" id="tab-one">
            <h3 class="card-title">{{ 'Section'|trans }}</h3>
            <form method="get"><label class="form-label">{{ 'Filter'|trans }}</label></form>
            <div class="mb-3"><label class="form-label" for="a">{{ 'It\'s a field'|trans }}</label></div>
            {{ include('mod_demo_part.html.twig') }}
        </div>
        <div class="tab-pane" id="tab-two"><span class="form-check-label">{{ 'Switch'|trans }}</span></div>
        <label class="form-label">{{ 'From %ip%'|trans }}</label>
        <div class="modal fade"><label class="form-label">{{ 'New record'|trans }}</label></div>
        <label class="form-label">{{ 'Below the tabs'|trans }}</label>
        TWIG);
    file_put_contents($dir . '/Demo/templates/admin/mod_demo_part.html.twig', '<div><label class="col-form-label">{{ \'Included\'|trans }}</label></div>');

    try {
        expect(SettingsIndex::build($dir))->toBe([
            ['mod' => 'demo', 'tab' => 'tab-one', 'kind' => 'tab', 'label' => 'First'],
            ['mod' => 'demo', 'tab' => 'tab-two', 'kind' => 'tab', 'label' => 'Second'],
            ['mod' => 'demo', 'tab' => 'tab-one', 'kind' => 'section', 'label' => 'Section'],
            ['mod' => 'demo', 'tab' => 'tab-one', 'kind' => 'field', 'label' => 'It\'s a field'],
            ['mod' => 'demo', 'tab' => 'tab-one', 'kind' => 'field', 'label' => 'Included'],
            ['mod' => 'demo', 'tab' => 'tab-two', 'kind' => 'field', 'label' => 'Switch'],
            ['mod' => 'demo', 'tab' => '', 'kind' => 'field', 'label' => 'Below the tabs'],
        ]);
    } finally {
        array_map(unlink(...), glob($dir . '/Demo/templates/admin/*') ?: []);
        rmdir($dir . '/Demo/templates/admin');
        rmdir($dir . '/Demo/templates');
        rmdir($dir . '/Demo');
        rmdir($dir);
    }
});

test('staff see only the settings pages they may open', function (): void {
    $di = new Pimple\Container();
    $di['mod_service'] = $di->protect(fn () => new class {
        public function isExtensionActive(string $type, string $id): bool
        {
            return $id !== 'cron';
        }

        public function hasManagePermission(string $module): void
        {
            if ($module === 'email') {
                throw new ClientFence\InformationException('No');
            }
        }
    });
    $di['mod'] = $di->protect(fn (string $name) => new class($name) {
        public function __construct(private readonly string $name)
        {
        }

        public function getManifest(): array
        {
            return ['name' => ucfirst($this->name) . ' module'];
        }
    });

    $index = SettingsIndex::forAdmin($di);

    expect(array_filter($index, fn (array $e): bool => str_starts_with($e['url'], 'extension/settings/email') || str_starts_with($e['url'], 'extension/settings/cron')))->toBe([])
        ->and($index)->toContain([
            'label' => 'Ask for an email PIN',
            'kind' => 'field',
            'page' => 'System module',
            'tab' => 'Sign-in security',
            'words' => SettingsIndex::WORDS['system#tab-signin'],
            'url' => 'extension/settings/system#tab-signin',
        ]);
});
