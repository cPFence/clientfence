<?php

declare(strict_types=1);

namespace ClientFence;

/**
 * ClientFence: what a service module declares about its product type in the "service" object of its manifest.json,
 * so the core keeps no list of product types:
 * - "lifecycle": "built-in" — orders call the module's action_* methods with the order, as for hosting and domains;
 * - "entity": the Doctrine entity class of its service rows, in the module's own namespace;
 * - "single_unit": true — one unit per order (no quantity);
 * - "order_config": false — staff cannot edit the order's raw configuration;
 * - "client_calls": {"method": "permission"} — what an authorized user needs for each client API call of the module
 *   (a permission of Client\Users::PERMISSIONS, a list of them for any one, or '*'); a call not listed is the owner's;
 * - "rate_limits": {"<type>_name": {"policy", "limit", "interval"}} — rate limiter policies of the module, named after
 *   its type (Security\RateLimiter; the core's own policies and config.php win);
 * - "client_pages": ["hosting"] — top-level client URLs whose routes the module registers (AppClient; the core's own
 *   pages win), while the module is switched on;
 * - "store_calls": {"guest": {"method": "list"}} — guest and client calls a closed store answers instead of running
 *   (Clientfence\Service::storeGate(): "page", "list", "not_found", anything else refused; the core's list wins);
 * - "changeable": true — its services renew at the order's price and can move to another product (upgrades, staff
 *   moves, bulk prices); the module's action_change_package($order), if it has one, then applies the new product;
 * - "usage": true — its services' renewals call the module's bill_usage($order, $invoice), which bills the usage with
 *   Invoice\Service::addUsageLine();
 * - "icon": an icon of the client theme's sprite for its services in the cart and on the service page.
 */
final class ServiceTypes
{
    /** @return array<string, mixed> the declaration of the module of product type `$type`, or an empty array */
    public static function declared(\Pimple\Container $di, ?string $type): array
    {
        if ($type === null || !ctype_alnum($type)) {
            return [];
        }

        return RequestCache::remember($di, 'service_type.' . $type, static function () use ($type): array {
            $file = PATH_MODS . DIRECTORY_SEPARATOR . 'Service' . strtolower($type) . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifest = is_file($file) && is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
            $declared = is_array($manifest) ? ($manifest['service'] ?? null) : null;

            return is_array($declared) ? $declared : [];
        });
    }

    public static function builtIn(\Pimple\Container $di, ?string $type): bool
    {
        return (self::declared($di, $type)['lifecycle'] ?? null) === 'built-in';
    }

    public static function entity(\Pimple\Container $di, ?string $type): ?string
    {
        $class = self::declared($di, $type)['entity'] ?? null;
        // In the module's own namespace, so that typeOfEntity() finds the type back from the class.
        $namespace = 'ClientFence\\Mod\\Service' . $type . '\\';

        return is_string($class) && stripos($class, $namespace) === 0 && class_exists($class) ? $class : null;
    }

    /** The product type whose declared entity `$service` is (a Doctrine proxy counts as its entity), or null. */
    public static function typeOfEntity(\Pimple\Container $di, object $service): ?string
    {
        foreach ([$service::class, ...array_values(class_parents($service))] as $class) {
            if (preg_match('/^ClientFence\\\\Mod\\\\Service([A-Za-z0-9]+)\\\\/', $class, $m) === 1 && self::entity($di, strtolower($m[1])) === $class) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    public static function singleUnit(\Pimple\Container $di, ?string $type): bool
    {
        return (self::declared($di, $type)['single_unit'] ?? false) === true;
    }

    public static function orderConfigEditable(\Pimple\Container $di, ?string $type): bool
    {
        return (self::declared($di, $type)['order_config'] ?? true) !== false;
    }

    /** @return string|list<string>|null the permission a client call of the module needs (Client\Users::checkCall()) */
    public static function clientPermission(\Pimple\Container $di, ?string $type, string $method): string|array|null
    {
        $permission = (self::declared($di, $type)['client_calls'] ?? [])[$method] ?? null;
        // Anything but a name or a list of names is not a declaration: the call stays the owner's.
        $valid = is_string($permission) || (is_array($permission) && array_is_list($permission) && array_filter($permission, 'is_string') === $permission);

        return $valid ? $permission : null;
    }

    /** @return array<string, array<mixed>> the rate limiter policies every service module declares */
    public static function rateLimits(\Pimple\Container $di): array
    {
        return RequestCache::remember($di, 'service_type_rate_limits', static function () use ($di): array {
            $policies = [];
            foreach (self::types() as $type) {
                foreach ((array) (self::declared($di, $type)['rate_limits'] ?? []) as $name => $policy) {
                    // Named after the type, so no module takes over another module's policy; a malformed one is left out,
                    // since staff's reset by IP goes through every policy.
                    if (is_string($name) && str_starts_with($name, $type . '_') && self::validPolicy($policy)) {
                        $policies[$name] = $policy;
                    }
                }
            }

            return $policies;
        });
    }

    /** @return list<string> the product types whose module declares "changeable": true */
    public static function changeable(\Pimple\Container $di): array
    {
        return RequestCache::remember($di, 'service_type_changeable', static fn (): array => array_values(array_filter(
            self::types(),
            static fn (string $type): bool => (self::declared($di, $type)['changeable'] ?? null) === true,
        )));
    }

    /** What a closed store answers to `$role`'s call `$method` of the module, or null when the module does not gate it. */
    public static function storeCall(\Pimple\Container $di, ?string $type, string $role, string $method): ?string
    {
        $calls = (self::declared($di, $type)['store_calls'] ?? [])[$role] ?? [];
        if (!is_array($calls) || !array_key_exists($method, $calls)) {
            return null;
        }

        // Anything declared but not a name (null included) still closes the call (storeGate() refuses an unknown answer).
        return is_string($calls[$method]) ? $calls[$method] : 'closed';
    }

    /** The switched-on service module (its id) that declares `$page` among its top-level client pages, or null. */
    public static function pageModule(\Pimple\Container $di, string $page): ?string
    {
        foreach (self::types() as $type) {
            if (in_array($page, (array) (self::declared($di, $type)['client_pages'] ?? []), true)
                && $di['mod_service']('extension')->isExtensionActive('mod', 'service' . $type)) {
                return 'service' . $type;
            }
        }

        return null;
    }

    /** @return list<string> the product types of the service modules on disk */
    private static function types(): array
    {
        return array_map(
            static fn (string $file): string => strtolower(substr(basename(dirname($file)), strlen('Service'))),
            glob(PATH_MODS . DIRECTORY_SEPARATOR . 'Service*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [],
        );
    }

    /** What Symfony's RateLimiterFactory accepts (an unknown policy, limit or interval would throw there). */
    private static function validPolicy(mixed $policy): bool
    {
        if (!is_array($policy) || !in_array($policy['policy'] ?? null, ['token_bucket', 'fixed_window', 'sliding_window', 'no_limit'], true)
            || !is_int($policy['limit'] ?? null) || $policy['limit'] < 1 || !is_string($policy['interval'] ?? null)) {
            return false;
        }

        try {
            $start = new \DateTimeImmutable('@0');

            return $start->modify('+' . $policy['interval']) > $start;
        } catch (\DateMalformedStringException) {
            return false;
        }
    }
}
