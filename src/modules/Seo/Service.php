<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Seo;

use ClientFence\InjectionAwareInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Service implements InjectionAwareInterface
{
    public const INDEXNOW_URL = 'https://api.indexnow.org/indexnow';

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View SEO information'),
                'description' => __trans('Allows the staff member to view SEO status.'),
            ],
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage SEO'),
                'description' => __trans('Allows the staff member to submit the sitemap to search engines.'),
            ],
            'manage_settings' => [],
        ];
    }

    public function install(): void
    {
        $this->saveConfig([]);
    }

    public function getInfo(): array
    {
        $config = $this->di['mod_config']('seo');
        $key = $config['indexnow_key'] ?? null;

        return [
            'sitemap_url' => SYSTEM_URL . 'sitemap.xml',
            'key' => $key,
            'key_url' => $key !== null ? SYSTEM_URL . $key . '.txt' : null,
            'last_submit' => $this->getLastSubmit(),
            'google_verification' => $config['google_verification'] ?? '',
            'bing_verification' => $config['bing_verification'] ?? '',
        ];
    }

    public function getVerification(): array
    {
        $config = $this->di['mod_config']('seo');

        return ['google' => $config['google_verification'] ?? '', 'bing' => $config['bing_verification'] ?? ''];
    }

    public function saveVerification(array $data): void
    {
        $codes = [];
        foreach (['google_verification', 'bing_verification'] as $name) {
            $code = $data[$name] ?? '';
            if (!is_string($code) || preg_match('/\A[A-Za-z0-9_-]{0,100}\z/', $code = trim($code)) !== 1) {
                throw new \ClientFence\InformationException('Enter only the code: letters, digits, - and _.');
            }
            $codes[$name] = $code;
        }
        $this->saveConfig($codes);
    }

    /**
     * Submits the sitemap's addresses to IndexNow when the sitemap changed since the last
     * successful submit (always when forced).
     *
     * @return array|null the result, or null when nothing was submitted
     */
    public function submit(bool $forced = false): ?array
    {
        $key = $this->di['mod_config']('seo')['indexnow_key'] ?? null;
        $last = $this->getLastSubmit();
        // ClientFence core patch (ext seo): a failed submit is retried after a day, not on every cron run.
        if ($key === null || (!$forced && $last !== null && !$last['ok'] && time() - strtotime((string) $last['at']) < 86400)) {
            return null;
        }

        $http = $this->di['http_client'];
        $result = ['at' => date('Y-m-d H:i:s'), 'ok' => false, 'status' => 0, 'count' => 0, 'error' => '', 'hash' => ''];
        try {
            $sitemap = $http->request('GET', SYSTEM_URL . 'sitemap.xml', ['timeout' => 10])->getContent();
            $result['hash'] = hash('sha256', $sitemap);
            if (!$forced && $last !== null && $last['ok'] && $last['hash'] === $result['hash']) {
                return null;
            }

            preg_match_all('#<loc>\s*(.*?)\s*</loc>#s', $sitemap, $matches);
            $urls = array_slice(array_values(array_unique(array_map(fn (string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_XML1), $matches[1]))), 0, 10000);
            $result['count'] = count($urls);
            if ($urls === []) {
                $result['error'] = 'The sitemap lists no pages.';
            } else {
                $result['status'] = $http->request('POST', self::INDEXNOW_URL, [
                    'timeout' => 10,
                    'json' => [
                        'host' => parse_url(SYSTEM_URL, PHP_URL_HOST),
                        'key' => $key,
                        'keyLocation' => SYSTEM_URL . $key . '.txt',
                        'urlList' => $urls,
                    ],
                ])->getStatusCode();
                $result['ok'] = in_array($result['status'], [200, 202], true);
            }
        } catch (ExceptionInterface $e) {
            $result['error'] = $e->getMessage();
        }

        if (!$result['ok']) {
            error_log('IndexNow submit failed: ' . ($result['error'] ?: 'HTTP ' . $result['status']));
        }
        $this->di['mod_service']('system')->setParamValue('mod_seo_indexnow', json_encode($result));

        return $result;
    }

    public function getLastSubmit(): ?array
    {
        $saved = json_decode((string) $this->di['mod_service']('system')->getParamValue('mod_seo_indexnow', ''), true);

        return is_array($saved) ? $saved : null;
    }

    private function saveConfig(array $changes): void
    {
        $extension = $this->di['mod_service']('extension');
        $config = [...$extension->getConfig('mod_seo'), ...$changes];
        $config['indexnow_key'] ??= bin2hex(random_bytes(16));
        $extension->setConfig($config);
    }

    public static function onBeforeAdminCronRun(\ClientFence\Event $event): bool
    {
        try {
            $event->getDi()['mod_service']('seo')->submit();
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        return true;
    }
}
