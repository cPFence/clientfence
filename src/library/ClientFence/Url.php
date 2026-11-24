<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence;
class Url implements \ClientFence\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    protected ?string $baseUri = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function setBaseUri($baseUri): void
    {
        $this->baseUri = $baseUri;
    }

    /**
     * Generates a URL.
     */
    public function get(string $uri): string
    {
        return $this->base() . $uri;
    }

    public function link(?string $uri = null, ?array $params = []): string
    {
        $uri ??= '';
        $uri = trim($uri, '/');
        $link = $this->base() . $uri;
        if (!empty($params)) {
            $link .= '?' . http_build_query($params);
        }

        return $link;
    }

    public function adminLink(?string $uri, ?array $params = []): string
    {
        $uri ??= '';
        $uri = trim($uri, '/');
        $uri = ADMIN_PREFIX . '/' . $uri;

        return $this->link($uri, $params);
    }

    // ClientFence core patch: without a set base, the site of each call (ClientFence\Site::current(): a record's inside within()).
    private function base(): string
    {
        return $this->baseUri ?? ($this->di === null ? '' : Site::current($this->di)->url);
    }

    public static function normalizeLinkPath(?string $uri = null): string
    {
        $uri = trim((string) $uri);
        if ($uri === '' || $uri === '/') {
            return '';
        }

        $uri = ltrim($uri, '/');
        $adminPrefix = defined('ADMIN_PREFIX') ? trim((string) ADMIN_PREFIX, '/') : '';
        if ($adminPrefix !== '' && str_starts_with($uri, $adminPrefix . '/')) {
            return substr($uri, strlen($adminPrefix) + 1);
        }

        if ($uri === $adminPrefix) {
            return '';
        }

        return $uri;
    }
}
