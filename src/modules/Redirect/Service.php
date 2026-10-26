<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Redirect;

use ClientFence\Mod\Extension\Entity\ExtensionMeta;
use ClientFence\Mod\Extension\Repository\ExtensionMetaRepository;
use ClientFence\Exception;

class Service implements \ClientFence\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    private ?ExtensionMetaRepository $extensionMetaRepository = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        $this->extensionMetaRepository = isset($this->di['em'])
            ? $this->di['em']->getRepository(ExtensionMeta::class)
            : null;
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
                'display_name' => __trans('View redirects'),
                'description' => __trans('Allows the staff member to view redirect rules.'),
            ],
            'create_and_edit' => [
                'type' => 'bool',
                'display_name' => __trans('Create and edit redirects'),
                'description' => __trans('Allows the staff member to create and edit redirects.'),
            ],
            'delete' => [
                'type' => 'bool',
                'display_name' => __trans('Delete redirects'),
                'description' => __trans('Allows the staff member to delete redirects.'),
            ],
            'manage_settings' => [],
        ];
    }

    public function getExtensionMetaRepository(): ExtensionMetaRepository
    {
        if ($this->extensionMetaRepository === null) {
            if ($this->di === null) {
                throw new Exception('The dependency injection container has not been set.');
            }

            $this->extensionMetaRepository = $this->di['em']->getRepository(ExtensionMeta::class);
        }

        return $this->extensionMetaRepository;
    }

    /**
     * ClientFence: the module's own settings share the redirects' rows (extension_meta, key 'config',
     * written by the Extension module), so that row is never a redirect. Listing it as one broke the
     * settings page (its target is empty).
     */
    public const SETTINGS_KEY = 'config';

    public function getRedirects(): array
    {
        $redirects = $this->getExtensionMetaRepository()->findByExtensionAndScope('mod_redirect', null, null, null, ['id' => 'ASC']);
        $redirects = array_filter($redirects, fn (ExtensionMeta $redirect): bool => $redirect->getMetaKey() !== self::SETTINGS_KEY);

        return array_values(array_map($this->toApiArray(...), $redirects));
    }

    public function getRedirectByPath($path): ?array
    {
        if ((string) $path === self::SETTINGS_KEY) {
            return null;
        }
        $redirect = $this->getExtensionMetaRepository()->findOneByExtensionAndScope('mod_redirect', (string) $path);

        return $redirect instanceof ExtensionMeta ? $this->toApiArray($redirect) : null;
    }

    public function get(int $id): ExtensionMeta
    {
        $redirect = $this->getExtensionMetaRepository()->findOneByExtensionAndId('mod_redirect', $id);
        if (!$redirect instanceof ExtensionMeta || $redirect->getMetaKey() === self::SETTINGS_KEY) {
            throw new Exception('Redirect not found');
        }

        return $redirect;
    }

    public function create(string $path, string $target, mixed $type = 301, mixed $enabled = true): int
    {
        $path = $this->validatePath($path);
        $this->assertPathFree($path, null);

        $redirect = (new ExtensionMeta())
            ->setExtension('mod_redirect')
            ->setMetaKey($path)
            ->setMetaValue($this->encode($this->validateTarget($target), $this->validateType($type), $this->validateEnabled($enabled)));

        $this->di['em']->persist($redirect);
        $this->di['em']->flush();

        $id = $redirect->getId();
        if ($id === null) {
            throw new Exception('Failed to create redirect.');
        }

        return $id;
    }

    public function update(ExtensionMeta $redirect, array $data): bool
    {
        if (isset($data['path'])) {
            $path = $this->validatePath((string) $data['path']);
            $this->assertPathFree($path, $redirect->getId());
            $redirect->setMetaKey($path);
        }

        $current = $this->toApiArray($redirect);
        $redirect->setMetaValue($this->encode(
            isset($data['target']) ? $this->validateTarget((string) $data['target']) : $current['target'],
            isset($data['type']) ? $this->validateType($data['type']) : $current['type'],
            isset($data['enabled']) ? $this->validateEnabled($data['enabled']) : $current['enabled'],
        ));

        $this->di['em']->flush();

        return true;
    }

    public function delete(ExtensionMeta $redirect): bool
    {
        $this->di['em']->remove($redirect);
        $this->di['em']->flush();

        return true;
    }

    public function toApiArray(ExtensionMeta $redirect): array
    {
        // ClientFence core patch (ext redirect): meta_value is {"target", "type", "enabled"}; a row that is not reads as switched off.
        $value = json_decode((string) $redirect->getMetaValue(), true);

        return [
            'id' => $redirect->getId(),
            'path' => $redirect->getMetaKey(),
            'target' => (string) ($value['target'] ?? ''),
            'type' => (int) ($value['type'] ?? 301),
            'enabled' => (bool) ($value['enabled'] ?? false),
        ];
    }

    private function encode(string $target, int $type, bool $enabled): string
    {
        return json_encode(['target' => $target, 'type' => $type, 'enabled' => $enabled], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function assertPathFree(string $path, ?int $id): void
    {
        $existing = $this->getExtensionMetaRepository()->findOneByExtensionAndScope('mod_redirect', $path);
        if ($existing instanceof ExtensionMeta && $existing->getId() !== $id) {
            throw new Exception('A redirect for this path already exists.');
        }
    }

    public function validateType(mixed $type): int
    {
        if (!in_array($type, [301, 302, '301', '302'], true)) {
            throw new Exception('Redirect type must be 301 or 302.');
        }

        return (int) $type;
    }

    public function validateEnabled(mixed $enabled): bool
    {
        $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new Exception('Invalid redirect status.');
        }

        return $enabled;
    }

    public function validateTarget(string $target): string
    {
        $target = trim($target);

        if ($target === '' || strpbrk($target, "\r\n") !== false) {
            throw new Exception('Invalid redirect target.');
        }

        $scheme = parse_url($target, PHP_URL_SCHEME);
        if (is_string($scheme) && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new Exception('Only HTTP and HTTPS redirect targets are allowed.');
        }

        return $target;
    }

    public function validatePath(string $path): string
    {
        $path = trim($path, '/');

        // ClientFence core patch (ext redirect): only characters a request path carries as typed; a space, ":" (a route parameter) or "?" never matched.
        if (preg_match('#^[A-Za-z0-9._~!$&\'()*+,;=@%/-]+$#', $path) !== 1) {
            throw new Exception('Invalid redirect path.');
        }

        if (str_contains($path, '..')) {
            throw new Exception('Redirect path must not contain path traversal sequences.');
        }

        if ($path === self::SETTINGS_KEY) {
            throw new Exception('This path is reserved.');
        }

        return $path;
    }
}
