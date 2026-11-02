<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Api;

use ClientFence\Exception;
use ClientFence\InformationException;
use ClientFence\InjectionAwareInterface;
use ClientFence\Validation\Api\RequiredParams;
use Pimple\Container;

final class Dispatcher implements InjectionAwareInterface
{
    protected ?Container $di = null;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public function dispatch(object $identity, string $method, array $data = []): mixed
    {
        return $this->dispatchWithArguments($identity, $method, [$data]);
    }

    public function dispatchWithArguments(object $identity, string $method, array $arguments = []): mixed
    {
        if (!str_contains($method, '_')) {
            throw new Exception('Method :method must contain underscore', [':method' => $method], 710);
        }

        $role = Identity::typeFromObject($identity);
        $parts = explode('_', $method);
        $mod = strtolower($parts[0]);
        unset($parts[0]);
        $methodName = implode('_', $parts);

        if (empty($mod)) {
            throw new Exception('Invalid module name', null, 714);
        }

        $extensionService = $this->getDi()['mod']('extension')->getService();
        if (!$extensionService->isExtensionActive('mod', $mod)) {
            throw new Exception('ClientFence module :mod is not installed/activated', [':mod' => $mod], 715);
        }

        /*
         * Disable permission checks when an update is pending finalization.
         *
         * This is to make sure update finalization still works when there are changes to the
         * permission system and patches need to be applied before everything starts working again.
         */
        // ClientFence core patch: the admin layout builds its menu with extension_get_navigation and asks
        // system_is_allowed before showing links. Both answer only for the caller, so every staff
        // member may call them; without this only Super Administrators could open the admin area.
        $clientfenceArgs = isset($arguments[0]) && is_array($arguments[0]) ? $arguments[0] : [];
        // system_template_exists only says whether an admin template file exists (order and product pages ask it).
        // ClientFence core patch: extension_config_get/config_save check the module's own "Manage settings" permission (hasManagePermission()):
        // without this, that permission did nothing for staff without Extensions access.
        $clientfenceOpenCall = in_array(strtolower($method), ['extension_get_navigation', 'system_template_exists', 'extension_config_get', 'extension_config_save'], true)
            || (strtolower($method) === 'system_is_allowed' && is_string($clientfenceArgs['mod'] ?? null)
                && preg_match('/^[a-z][a-z0-9_]*$/', $clientfenceArgs['mod']) === 1);
        if ($role === 'admin' && !$clientfenceOpenCall && !$this->isAllowedAdminFinalizationCall($mod, $method)) {
            $staffService = $this->getDi()['mod_service']('Staff');
            if (!$staffService->hasPermission($identity, $mod)) {
                throw new Exception('You do not have access to the :mod module', [':mod' => $mod], 725);
            }
        }

        $apiClass = '\ClientFence\Mod\\' . ucfirst($mod) . '\Api\\' . ucfirst($role);
        if (!class_exists($apiClass)) {
            throw new Exception(':type API call :method does not exist in module :module', [':type' => ucfirst($role), ':method' => $methodName, ':module' => $mod], 740);
        }

        $api = new $apiClass();
        if (!$api instanceof AbstractApi) {
            throw new Exception('Api class must be an instance of ClientFence\Api\AbstractApi', null, 730);
        }

        $module = $this->getDi()['mod']($mod);
        $api->setDi($this->di);
        $api->setMod($module);
        $api->setIdentity($identity);
        $api->setIp($this->getDi()['request']->getClientIp());
        if ($module->hasService()) {
            $api->setService($this->getDi()['mod_service']($mod));
        }

        if (!method_exists($api, $methodName) || !is_callable([$api, $methodName])) {
            $reflector = new \ReflectionClass($api);
            if (!$reflector->hasMethod('__call')) {
                throw new Exception(':type API call :method does not exist in module :module', [':type' => ucfirst($role), ':method' => $methodName, ':module' => $mod], 740);
            }
        }

        // ClientFence core patch: authorized users. A signed-in authorized user makes only the client
        // calls the user's permissions allow, and gets the profile and dashboard trimmed to them
        // (modules/Client/Users.php CALLS).
        $clientfenceUsers = $role === 'client';
        if ($clientfenceUsers) {
            \ClientFence\Mod\Client\Users::checkCall($this->getDi(), $identity, $mod, $methodName, isset($arguments[0]) && is_array($arguments[0]) ? $arguments[0] : []);
        }

        $data = isset($arguments[0]) && is_array($arguments[0]) ? $arguments[0] : [];

        // ClientFence core patch: store gate. Guest and client calls that show or fill the store
        // (products, cart, checkout, domain search) run only when the ClientFence module opens the
        // store to the caller (modules/Clientfence/Service.php "Store gate").
        if ($role !== 'admin' && isset(\ClientFence\Mod\Clientfence\Service::STORE_CALLS[$role][$mod])) {
            $gate = \ClientFence\Mod\Clientfence\Service::storeGate($this->getDi(), $role, $mod, $methodName, $identity, $data);
            if ($gate !== null) {
                return $gate['result'];
            }
        }

        $this->validateRequiredParams($api, $methodName, $data);

        $result = $api->{$methodName}(...$this->normalizeArguments($api, $methodName, $arguments));

        // ClientFence core patch: authorized users (see above): what the call returns, trimmed.
        // ClientFence core patch: authorized users. Staff see which user wrote a ticket message.
        if ($role === 'admin') {
            $result = \ClientFence\Mod\Client\Users::filterAdminResult($this->getDi(), $mod, $methodName, $result);
        }

        return $clientfenceUsers ? \ClientFence\Mod\Client\Users::filterResult($this->getDi(), $identity, $mod, $methodName, $result) : $result;
    }

    private function isAllowedAdminFinalizationCall(string $class, string $method): bool
    {
        $finalization = $this->di['update_finalization'];

        return $finalization->isRequired() && $finalization->isAdminApiCallAllowed($class, $method);
    }

    /**
     * Validate required parameters for an API method using attributes.
     *
     * @param AbstractApi $api        The API instance
     * @param string      $methodName The method name
     * @param array       $data       The data array passed to the method
     *
     * @throws InformationException If required parameters are missing
     */
    public function validateRequiredParams(AbstractApi $api, string $methodName, array $data): void
    {
        try {
            $reflection = new \ReflectionMethod($api, $methodName);
        } catch (\ReflectionException) {
            return;
        }

        $attributes = $reflection->getAttributes(RequiredParams::class);

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            foreach ($instance->params as $paramName => $errorMessage) {
                if (!isset($data[$paramName])) {
                    throw new InformationException($errorMessage);
                }

                if (is_string($data[$paramName]) && strlen(trim($data[$paramName])) === 0) {
                    throw new InformationException($errorMessage);
                }

                if (!is_numeric($data[$paramName]) && empty($data[$paramName])) {
                    throw new InformationException($errorMessage);
                }
            }
        }
    }

    private function normalizeArguments(AbstractApi $api, string $methodName, array $arguments): array
    {
        try {
            $reflection = new \ReflectionMethod($api, $methodName);
        } catch (\ReflectionException) {
            return $arguments;
        }

        if (!empty($arguments) && $arguments !== [[]]) {
            return $arguments;
        }

        $parameters = $reflection->getParameters();
        if (empty($parameters)) {
            return [];
        }

        $firstParameter = $parameters[0];
        if ($firstParameter->isOptional()) {
            if ($arguments === [[]] && $firstParameter->isDefaultValueAvailable() && is_array($firstParameter->getDefaultValue())) {
                return $arguments;
            }

            return [];
        }

        return [[]];
    }
}
