<?php

declare(strict_types=1);

/**
 * ClientFence: Security → Settings, the config.php parts (Mod\Clientfence\SecuritySettings).
 */

use ClientFence\Config;
use ClientFence\InformationException;
use ClientFence\Mod\Clientfence\SecuritySettings;

test('addresses and ranges are read one per line; junk and too-wide ranges are refused', function (): void {
    expect(SecuritySettings::ranges(" 203.0.113.5\n2606:4700::/32 , 104.16.0.0/13\n\n203.0.113.5"))->toBe(['203.0.113.5', '2606:4700::/32', '104.16.0.0/13'])
        ->and(SecuritySettings::ranges(''))->toBe([]);
    foreach (['example.com', '10.0.0.0/7', '0.0.0.0/0', '2001:db8::/15', '203.0.113.5/33', '203.0.113.5/x'] as $bad) {
        expect(fn () => SecuritySettings::ranges($bad))->toThrow(InformationException::class);
    }
});

test('each section saves its own config.php keys', function (): void {
    $before = Config::getConfig();
    $di = new Pimple\Container();
    $di['logger'] = new class {
        public function info(mixed ...$args): void
        {
        }
    };

    try {
        SecuritySettings::save($di, ['section' => 'proxies', 'proxies_on' => '1', 'proxies' => "173.245.48.0/20\n2606:4700::/32", 'headers' => 'aws_elb'], 1);
        SecuritySettings::save($di, ['section' => 'access', 'whitelist' => '198.51.100.7', 'api_ips' => '203.0.113.0/24'], 1);
        SecuritySettings::save($di, ['section' => 'sessions', 'fingerprint' => '0'], 1);

        expect(Config::getProperty('security.trusted_proxies'))->toBe(['enabled' => true, 'proxies' => ['173.245.48.0/20', '2606:4700::/32'], 'headers' => 'aws_elb'])
            ->and(Config::getProperty('rate_limiter.whitelist_ips'))->toBe(['198.51.100.7'])
            ->and(Config::getProperty('api.allowed_ips'))->toBe(['203.0.113.0/24'])
            ->and(Config::getProperty('security.perform_session_fingerprinting'))->toBeFalse()
            ->and(fn () => SecuritySettings::save($di, ['section' => 'proxies', 'proxies_on' => '1', 'proxies' => ''], 1))->toThrow(InformationException::class)
            ->and(fn () => SecuritySettings::save($di, ['section' => 'proxies', 'proxies_on' => '1', 'proxies' => '10.0.0.1', 'headers' => 'traefik'], 1))->toThrow(InformationException::class);
    } finally {
        Config::setConfig($before, false);
    }
});
