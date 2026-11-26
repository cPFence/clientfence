<?php

declare(strict_types=1);

namespace ClientFence;

/**
 * ClientFence: what modules declare in their manifest.json, so the core keeps no list of product types or module hooks.
 * This class is the one place that reads and checks these declarations.
 *
 * The "service" object of a service module (Service<type>) declares its product type:
 * - "lifecycle": "built-in" — orders call the module's action_* methods with the order, as for hosting and domains;
 * - "entity": the Doctrine entity class of its service rows, in the module's own namespace. The core assumes their table
 *   is `service_<type>` with a `client_id` column: deleting a client deletes the service's row there, and moving a service
 *   to another client (Order\ClientMove) updates its `client_id`;
 * - "single_unit": true — one unit per order (no quantity);
 * - "order_config": false — staff cannot edit the order's raw configuration;
 * - "changeable": true — its services renew at the order's price and can move to another product (upgrades, staff
 *   moves, bulk prices); the module's action_change_package($order), if it has one, then applies the new product. It runs
 *   inside the core's database transaction (a paid change also holds the order's renewal lock, the client's money lock
 *   and the order's row): it validates and queues, and never waits on a provider; it throws to cancel the change.
 *   Before a client's change is offered or priced, the module's check_change_package($order, $to), if it has one, may
 *   refuse it (Order\Upgrades: each product and cycle of the client's plan list, an options quote, and the request made
 *   from them). `$order` is the service as it is; `$to` is what the change would set: product_id, period, options and
 *   option_lines, the values action_change_package() then finds on the order and in its config. It returns a reason for
 *   the client (already translated) to refuse, or null to allow. It is called once per offered product and cycle, outside
 *   the money transaction and the order's row lock (a client's request holds only that service's request lock): it reads,
 *   changes nothing and never waits on a provider. A throw refuses (logged). Staff moves and option changes do not call
 *   it: action_change_package() refuses them at once, and nothing is paid;
 * - "import": true — "Already set up" (staff Add New Order) still calls the module's activation (action_activate($order)
 *   with "lifecycle": "built-in", else activate($order, $service)), and the order config then has "import" set (Order\Service::getConfig($order)['import']): the module links the existing
 *   service instead of creating it, as hosting and domains do. Without it the core records the service without calling
 *   the module's activation;
 * - "import_fields": [{"key", "label", "hint"}] — with "import": true, the text inputs staff fill in to identify the
 *   existing service when they tick "Already set up" (one per field; label and the optional hint are translated). The
 *   values, as typed, are in the order config under "_import_fields" (getConfig($order)['_import_fields'][$key]; keys
 *   [a-z][a-z0-9_]*, each once); clients never send or see them (Product\Service::prepareCartProductConfig(), toApiArray()).
 *   A module whose attachOrderConfig() keeps only listed keys must keep "import" and "_import_fields" too;
 * - "icon": an icon of the client theme's sprite for its services in the cart, the services list and the service page.
 *
 * At the manifest root, any module (a service module or not) declares its hooks:
 * - "client_calls": {"method": "permission"} — what an authorized user needs for each client API call of the module
 *   (a permission of Client\Users::PERMISSIONS, a list of them for any one, or '*'); a call not listed is the owner's;
 * - "rate_limits": {"<prefix>_name": {"policy", "limit", "interval"}} — rate limiter policies of the module, named after
 *   its product type (a service module) or its module id (any other module); the core's own policies and config.php win,
 *   then a service module's;
 * - "client_pages": {"page": "permission"} — top-level client URLs whose routes the module registers (AppClient), each with
 *   the permission an authorized user needs to open it (Client\Users::pageDenied()), or null for any signed-in user; the
 *   core's own pages win, and of two modules that declare one page the first by module name keeps it;
 * - "store_calls": {"guest": {"method": "list"}} — guest and client calls a closed store answers instead of running
 *   (Clientfence\Service::storeGate(): "page", "list", "not_found", anything else refused; the core's list wins);
 * - "usage": true — every renewal invoice calls the module's bill_usage($invoice, $order) (Invoice\Service), which bills
 *   usage with Invoice\Service::addUsageLine($invoice, $order, $title, $price). Its contract:
 *   - it is called for the orders of every product type, so the module checks the order's type itself;
 *   - it runs under the order's renewal lock (the cron, staff renew and the client's Renew Now): no provider API calls,
 *     no waiting;
 *   - prices are in the invoice's currency, one line per charge (quantity 1);
 *   - with "Prices include tax" on, the renewal nets the prices after the last line; an invoice the module makes itself
 *     calls Invoice\Service::applyInclusiveTax() once after its last line;
 *   - when it throws, the core removes the usage lines it added in that call, but none of its own writes: it marks usage
 *     billed only after its last line;
 *   - usage lines leave with a cancelled renewal (Order\Service::cancelOpenInvoices()), so before billing again it checks
 *     that the line IDs it stored are still there;
 *   - it is not called for an order paid by a gateway subscription (a fixed amount: a usage line would leave it short).
 *
 * Switched-off modules: their root hooks and their "changeable" do not count. Lifecycle, entity, single_unit, order_config
 * and icon keep working, so the existing orders of a switched-off module still suspend, cancel and show.
 *
 * Any switched-on module's Service may also have health(): rows it adds to System Health (Clientfence\Health), a list
 * of {id, state: "ok"|"warn"|"bad", title, detail, url, params}: title and detail in English with their :name values in
 * params (translated when read, as the core rows), url an admin path (optional). Text is cut to 100 characters (id), 200
 * (title), 1000 (detail), 255 (url) and 500 (each params value, text or a number). The core prefixes each id with the
 * module id ("mod_<module>:<id>"), so no module replaces a core row or another module's row; a "bad" row emails the
 * Super Administrators once, as a core row. It runs from the hourly cron and from staff "Run checks now": it must be
 * fast, with no long provider waits. A throw gives one "bad" row with its message; a module that cannot run is not called
 * (the core's row says why).
 *
 * Any module also declares "module_api" (the hook level it was written for) and "requires" (PHP extensions and version):
 * a module that cannot run on this PHP stays switched on but its code is never loaded (Module::cannotRun()). invalid() checks the known keys'
 * types when a module is switched on or upgraded.
 */
final class ServiceTypes
{
    private const HOOKS = ['client_calls', 'rate_limits', 'client_pages', 'store_calls', 'usage'];

    /** @return array<string, mixed> the declaration of the module of product type `$type`, or an empty array */
    public static function declared(\Pimple\Container $di, ?string $type): array
    {
        $module = 'service' . strtolower((string) $type);
        $declared = $type === null ? null : (self::manifests($di)[$module]['service'] ?? null);
        if (!is_array($declared)) {
            return [];
        }
        // A switched-off module, or one that cannot run on this PHP (Module::cannotRun()), could not apply a product change.
        if (array_key_exists('changeable', $declared) && (!self::switchedOn($di, $module) || $di['mod']($module)->cannotRun() !== null)) {
            unset($declared['changeable']);
        }
        // A malformed list (the manifest edited after the check) would break staff Add New Order.
        if (array_key_exists('import_fields', $declared) && !self::validImportFields($declared)) {
            unset($declared['import_fields']);
        }

        return $declared;
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

        if (!is_string($class) || stripos($class, $namespace) !== 0) {
            return null;
        }
        // class_exists() loads the module's code: a module that cannot run on this PHP fails with the reason instead.
        if (($reason = $di['mod']('service' . $type)->cannotRun()) !== null) {
            throw new InformationException(...$reason);
        }

        return class_exists($class) ? $class : null;
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

    /** @return string|list<string>|null the permission client call `$method` of module `$module` needs (Client\Users::checkCall()) */
    public static function clientPermission(\Pimple\Container $di, string $module, string $method): string|array|null
    {
        $permission = self::lower(self::hooks($di)[$module]['client_calls'] ?? null)[$method] ?? null;
        // Anything but a name or a list of names is not a declaration: the call stays the owner's.
        $valid = is_string($permission) || (is_array($permission) && array_is_list($permission) && array_filter($permission, 'is_string') === $permission);

        return $valid ? $permission : null;
    }

    /** @return array<string, array<mixed>> the rate limiter policies the switched-on modules declare */
    public static function rateLimits(\Pimple\Container $di): array
    {
        return RequestCache::remember($di, 'module_hooks.rate_limits', static function () use ($di): array {
            $policies = ['service' => [], 'other' => []];
            $others = array_filter(array_keys(self::hooks($di)), static fn (string $module): bool => !str_starts_with($module, 'service'));
            foreach (self::hooks($di) as $module => $hooks) {
                $kind = str_starts_with($module, 'service') ? 'service' : 'other';
                $prefix = ($kind === 'service' ? substr($module, 7) : $module) . '_';
                foreach ((array) ($hooks['rate_limits'] ?? []) as $name => $policy) {
                    // Named after the module, so no module takes over another module's policy; a malformed one is left out,
                    // since staff's reset by IP goes through every policy. Module ids may hold "_": the longest prefix owns.
                    $longer = $kind === 'other' && array_filter($others, static fn (string $other): bool => strlen($other) > strlen($module) && str_starts_with((string) $name, $other . '_')) !== [];
                    if (is_string($name) && str_starts_with($name, $prefix) && !$longer && self::validPolicy($policy)) {
                        $policies[$kind][$name] = $policy;
                    }
                }
            }

            // A module named like a product type cannot take over that type's policies.
            return $policies['service'] + $policies['other'];
        });
    }

    /** @return list<string> the product types whose switched-on module declares "changeable": true */
    public static function changeable(\Pimple\Container $di): array
    {
        return RequestCache::remember($di, 'service_type_changeable', static fn (): array => array_values(array_filter(
            self::types($di),
            static fn (string $type): bool => (self::declared($di, $type)['changeable'] ?? null) === true,
        )));
    }

    /** @return list<string> the product types whose service module is switched off */
    public static function switchedOff(\Pimple\Container $di): array
    {
        return RequestCache::remember($di, 'service_type_switched_off', static fn (): array => array_values(array_filter(
            self::types($di),
            static fn (string $type): bool => !self::switchedOn($di, 'service' . $type),
        )));
    }

    public static function isSwitchedOff(\Pimple\Container $di, ?string $type): bool
    {
        return in_array(strtolower((string) $type), self::switchedOff($di), true);
    }

    /** What a closed store answers to `$role`'s call `$method` of module `$module`, or null when the module does not gate it. */
    public static function storeCall(\Pimple\Container $di, string $module, string $role, string $method): ?string
    {
        $calls = self::lower(self::lower(self::hooks($di)[$module]['store_calls'] ?? null)[$role] ?? null);
        if (!array_key_exists($method, $calls)) {
            return null;
        }

        // Anything declared but not a name (null included) still closes the call (storeGate() refuses an unknown answer).
        return is_string($calls[$method]) ? $calls[$method] : 'closed';
    }

    /** @return array<string, array{module: string, permission: ?string}> the top-level client pages of the switched-on modules */
    public static function pages(\Pimple\Container $di): array
    {
        return RequestCache::remember($di, 'module_hooks.client_pages', static function () use ($di): array {
            $pages = [];
            $all = self::hooks($di);
            // The core's pages are never another module's: its own routed or gated pages and its module names (each
            // has its own client routes); core modules claim theirs first (hosting, licence).
            $core = array_flip(\ClientFence\Module::CORE_MODULES);
            // Also the pages the Page module renders from the theme, and (below) another switched-on module's own name: its
            // routes answer there.
            $taken = $core + array_flip(['login', 'signup', 'password-reset'])
                + \ClientFence\AppClient::CORE_PAGES + array_filter(\ClientFence\Mod\Client\Users::PAGES, static fn (string $page): bool => !str_contains($page, '/'), ARRAY_FILTER_USE_KEY);
            foreach (array_intersect_key($all, $core) + $all as $module => $hooks) {
                foreach (self::lower($hooks['client_pages'] ?? null) as $page => $permission) {
                    // Top-level only: a deeper path could open a core page's subpage to authorized users (Users::pageDenied()).
                    if (!is_string($page) || $page === '' || str_contains($page, '/') || !(is_string($permission) || $permission === null)
                        || (!isset($core[$module]) && $page !== $module
                            && (isset($taken[$page]) || (isset(self::manifests($di)[$page]) && self::switchedOn($di, $page))))) {
                        continue;
                    }
                    if (isset($pages[$page])) {
                        $di['logger']->warning("Modules {$pages[$page]['module']} and {$module} both declare the client page /{$page}; {$pages[$page]['module']} keeps it.");

                        continue;
                    }
                    $pages[$page] = ['module' => $module, 'permission' => $permission];
                }
            }

            return $pages;
        });
    }

    /** @return list<string> the switched-on modules (their ids) that declare "usage": true, by module name */
    public static function usage(\Pimple\Container $di): array
    {
        return array_keys(array_filter(self::hooks($di), static fn (array $hooks): bool => ($hooks['usage'] ?? null) === true));
    }

    /**
     * The first problem of module `$module`'s manifest, as [message, params] in English, or null. One check for activation
     * and upgrade (Extension\Service): a known key of the wrong type, or two keys that differ only in letter case in a
     * map matched case-blind. Unknown keys pass (later hooks). At runtime the readers above still ignore a bad value.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public static function invalid(string $module, array $manifest): ?array
    {
        $any = static fn (): bool => true;
        $list = static fn (mixed $v): bool => is_array($v) && array_is_list($v) && array_filter($v, 'is_string') === $v;
        // A JSON object: string keys (an empty one decodes as []), each value valid.
        $map = static fn (callable $valid): \Closure => static fn (mixed $v): bool => is_array($v)
            && array_filter(array_keys($v), 'is_string') === array_keys($v) && array_filter($v, $valid) === $v;
        $rules = [
            'module_api' => 'is_int',
            'requires' => $map($any),
            'requires.extensions' => $list,
            // A plain version: version_compare() would read ">=8.4" as older than any release.
            'requires.php' => static fn (mixed $v): bool => is_string($v) && preg_match('/^\d+(\.\d+){0,2}$/', $v) === 1,
            'sites' => 'is_bool',
            'migrations' => 'is_string',
            'client_calls' => $map(static fn (mixed $p): bool => is_string($p) || $list($p)),
            'rate_limits' => $map(self::validPolicy(...)),
            'client_pages' => $map(static fn (mixed $p): bool => $p === null || is_string($p)),
            'store_calls' => $map($map('is_string')),
            'usage' => 'is_bool',
            'service' => $map($any),
            'service.lifecycle' => 'is_string',
            'service.entity' => 'is_string',
            'service.single_unit' => 'is_bool',
            'service.order_config' => 'is_bool',
            'service.changeable' => 'is_bool',
            'service.import' => 'is_bool',
            'service.icon' => 'is_string',
            'service.import_fields' => static fn (mixed $v): bool => self::validImportFields(['import_fields' => $v] + $manifest['service']),
        ];
        foreach ($rules as $path => $valid) {
            [$key, $sub] = explode('.', $path) + [1 => null];
            // Rules run in order, so a parent read here is a map or absent.
            $value = $sub === null ? $manifest : $manifest[$key] ?? [];
            if (array_key_exists($sub ?? $key, $value) && !$valid($value[$sub ?? $key])) {
                return [\ClientFence\Mod\Clientfence\Health::msg('The manifest.json of the :mod module is not valid: :key has the wrong type.'), [':mod' => $module, ':key' => $path]];
            }
        }
        // The gates lower-case these names (lower()), so "Guest" and "guest" would replace each other.
        $maps = ['client_calls' => $manifest['client_calls'] ?? [], 'client_pages' => $manifest['client_pages'] ?? [], 'store_calls' => $manifest['store_calls'] ?? []];
        foreach ($maps['store_calls'] as $role => $calls) {
            $maps['store_calls.' . $role] = $calls;
        }
        foreach ($maps as $path => $names) {
            if (count(array_change_key_case($names)) !== count($names)) {
                return [\ClientFence\Mod\Clientfence\Health::msg('The manifest.json of the :mod module is not valid: :key has names that differ only in letter case.'), [':mod' => $module, ':key' => $path]];
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> the root hooks of the switched-on modules that declare any, by module id */
    private static function hooks(\Pimple\Container $di): array
    {
        return RequestCache::remember($di, 'module_hooks', static function () use ($di): array {
            $hooks = [];
            foreach (self::manifests($di) as $module => $manifest) {
                $declared = array_intersect_key($manifest, array_flip(self::HOOKS));
                if ($declared !== [] && self::switchedOn($di, $module)) {
                    $hooks[$module] = $declared;
                }
            }
            // By module name: the first module keeps a page two declare, and usage is billed in this order.
            ksort($hooks);

            return $hooks;
        });
    }

    /** @return array<string, array<string, mixed>> the manifest of every module on disk, by module id (lower case) */
    private static function manifests(\Pimple\Container $di): array
    {
        return RequestCache::remember($di, 'module_manifests', static function (): array {
            $manifests = [];
            foreach (glob(PATH_MODS . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') ?: [] as $file) {
                $manifest = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
                if (is_array($manifest)) {
                    $manifests[strtolower(basename(dirname($file)))] = $manifest;
                }
            }
            return $manifests;
        });
    }

    private static function switchedOn(\Pimple\Container $di, string $module): bool
    {
        // Core modules are always on, with no database read.
        return in_array($module, Module::CORE_MODULES, true) || $di['mod_service']('extension')->isExtensionActive('mod', $module);
    }

    /** @return list<string> the product types of the service modules on disk */
    private static function types(\Pimple\Container $di): array
    {
        $modules = array_filter(array_keys(self::manifests($di)), static fn (string $module): bool => str_starts_with($module, 'service'));

        return array_values(array_map(static fn (string $module): string => substr($module, 7), $modules));
    }

    /** @return array<mixed> `$map` with lower-case keys (the gates lower-case the call), or an empty array */
    private static function lower(mixed $map): array
    {
        return is_array($map) ? array_change_key_case($map) : [];
    }

    /** A list of {key, label, hint?} with distinct keys (a letter first: never a number or a JS prototype name), in a "service" that declares "import": true. */
    private static function validImportFields(array $service): bool
    {
        $fields = $service['import_fields'] ?? null;
        if (($service['import'] ?? null) !== true || !is_array($fields) || !array_is_list($fields)) {
            return false;
        }
        $keys = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null) || preg_match('/^[a-z][a-z0-9_]*$/D', $field['key']) !== 1 || isset($keys[$field['key']])
                || !is_string($field['label'] ?? null) || (array_key_exists('hint', $field) && !is_string($field['hint']))) {
                return false;
            }
            $keys[$field['key']] = true;
        }

        return true;
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
