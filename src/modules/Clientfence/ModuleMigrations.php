<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

use ClientFence\Exception;

/**
 * ClientFence: numbered database steps a module ships. Its manifest.json names a class in the module's own namespace
 * ("migrations") whose static steps() returns number => callable(\Pimple\Container): void. The level the database is
 * at is kept per module in the setting `migrations_<module>`, as ClientFence's own migrations keep theirs.
 *
 * They run when an extension is activated or upgraded (Extension\Service) and, for core modules, after ClientFence's
 * own migrations (Migrations::apply(), which a new core module's first steps also join as a numbered migration). This
 * class lives in a module so that an update run in place finds it, as it finds Migrations. A released step is never changed and must be safe to run again (an interrupted run repeats
 * it). A new install creates a core module's tables from install/sql/structure.sql and records its last step in
 * install/sql/content.sql.
 */
final class ModuleMigrations
{
    /** @return array<int, callable(\Pimple\Container): void> */
    public static function steps(\Pimple\Container $di, string $module): array
    {
        $class = $di['mod']($module)->getManifest()['migrations'] ?? null;
        if ($class === null) {
            return [];
        }
        // In the module's own namespace, so a module runs only its own steps.
        if (!is_string($class) || stripos($class, 'ClientFence\\Mod\\' . $module . '\\') !== 0 || !is_callable([$class, 'steps'])) {
            throw new Exception('The module :module names migrations it does not ship.', [':module' => $module]);
        }
        $steps = call_user_func([$class, 'steps']);

        return is_array($steps) ? $steps : [];
    }

    /**
     * Runs the module's pending steps in order. Returns the numbers that ran.
     *
     * @return list<int>
     */
    public static function apply(\Pimple\Container $di, string $module): array
    {
        $steps = array_map(static fn (callable $step): \Closure => static fn () => $step($di), self::steps($di, $module));

        return $steps === [] ? [] : Migrations::run($di['pdo'], $steps, 'migrations_' . $module);
    }

    /** An uninstalled module drops its tables, so installing it again runs every step again. */
    public static function forget(\Pimple\Container $di, string $module): void
    {
        $di['dbal']->delete('setting', ['param' => 'migrations_' . $module]);
    }

    public static function pending(\Pimple\Container $di, string $module): int
    {
        $current = Migrations::current($di['pdo'], 'migrations_' . $module);

        return count(array_filter(array_keys(self::steps($di, $module)), static fn (int $n): bool => $n > $current));
    }
}
