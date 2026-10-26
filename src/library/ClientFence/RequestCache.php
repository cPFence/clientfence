<?php

declare(strict_types=1);

namespace ClientFence;

/**
 * ClientFence: values worked out once per request (one DI container, `request_cache`): settings,
 * module configurations, staff permissions. Module services are new objects on every
 * `mod_service()` call, so their own properties cannot hold such values. Whoever changes a value
 * forgets its keys. A container without `request_cache` (most unit tests) caches nothing.
 */
final class RequestCache
{
    public static function remember(\Pimple\Container $di, string $key, callable $make): mixed
    {
        if (!isset($di['request_cache'])) {
            return $make();
        }
        $cache = $di['request_cache'];
        if (!$cache->offsetExists($key)) {
            $cache[$key] = $make();
        }

        return $cache[$key];
    }

    /** Forgets every value whose key starts with `$prefix`. */
    public static function forget(\Pimple\Container $di, string $prefix): void
    {
        if (!isset($di['request_cache'])) {
            return;
        }
        $cache = $di['request_cache'];
        foreach (array_keys($cache->getArrayCopy()) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($cache[$key]);
            }
        }
    }
}
