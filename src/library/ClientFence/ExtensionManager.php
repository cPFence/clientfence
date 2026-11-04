<?php

declare(strict_types=1);
/**
 * Copyright 2022-2026 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence;

class ExtensionManager implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    final public const string TYPE_MOD = 'mod';
    final public const string TYPE_THEME = 'theme';
    final public const string TYPE_PG = 'payment-gateway';
    final public const string TYPE_SM = 'server-manager';
    final public const string TYPE_DR = 'domain-registrar';
    final public const string TYPE_HOOK = 'hook';
    final public const string TYPE_TRANSLATION = 'translation';

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Fetch extension details from the FOSSBilling extension directory.
     *
     * @param string $id The extension identifier (e.g. Example)
     *
     * @return array The extension details
     *
     * @throws Exception
     */
    public function getExtension(string $id): array
    {
        $manifest = $this->makeRequest($id);

        if (empty($manifest)) {
            throw new Exception('Unable to fetch the extension details from the FOSSBilling extension directory.');
        }

        return $manifest;
    }

    /**
     * Fetch the list of releases of an extension from the FOSSBilling extension directory.
     *
     * @param string $id The extension identifier (e.g. Example)
     *
     * @return array The list of releases of the extension
     *
     * @throws Exception
     */
    public function getExtensionReleases(string $id): array
    {
        $releases = $this->getExtension($id)['releases'];

        if (empty($releases) || !is_array($releases)) {
            throw new Exception('An error occurred when fetching the extensions releases');
        }

        return $releases;
    }

    /**
     * Fetch the latest release of an extension from the FOSSBilling extension directory.
     *
     * @param string $id The extension identifier (e.g. Example)
     *
     * @return array The latest release of the extension
     *
     * @throws Exception
     */
    public function getLatestExtensionRelease(string $id): array
    {
        $releases = $this->getExtensionReleases($id);
        $latest = reset($releases);

        if (empty($latest) || !is_array($latest)) {
            throw new Exception('Unable to fetch the latest extension release.');
        }

        return $latest;
    }

    /**
     * Fetch the list of extensions from the FOSSBilling extension directory.
     *
     * @param string $type The extension type (e.g. mod) - optional
     *
     * @return array The list of extensions
     *
     */
    public function getExtensionList(?string $type = null): array
    {
        $params = [];

        if (!empty($type)) {
            $params['type'] = $type;
        }

        return $this->makeRequest('list', $params);
    }

    /**
     * Check if the latest version of an extension is compatible with the current FOSSBilling version.
     *
     * @param string $extension The extension identifier (e.g. Example)
     *
     * @return bool True if the extension is compatible, false otherwise
     */
    public function isExtensionCompatible(string $extension): bool
    {
        $latest = $this->getLatestExtensionRelease($extension);

        if (Config::getProperty('update_branch', 'release') === 'release') {
            if (version_compare(Version::VERSION, $latest['min_fossbilling_version'], '<')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Make a request to the FOSSBilling extension directory.
     *
     * @param string $endpoint The API endpoint to call (e.g. list)
     * @param array  $params   The array of parameters to pass to the API endpoint
     *
     * @return array The API response
     *
     * @throws Exception
     */
    public function makeRequest(string $endpoint, array $params = []): array
    {
        // ClientFence: FOSSBilling's extension directory is not contacted (an empty list; nothing to
        // install from it).
        if ($endpoint === 'list') {
            return [];
        }

        throw new InformationException('The FOSSBilling extension directory is not used by ClientFence.', null, 746);
    }
}
