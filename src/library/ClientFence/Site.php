<?php

declare(strict_types=1);

namespace ClientFence;

use ClientFence\Http\ApiResponseFactory;
use ClientFence\Http\RequestFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ClientFence: the site the portal works for. The primary site is the portal URL in config.php (SYSTEM_URL). A
 * switched-on module can serve the portal on more hostnames: its manifest.json declares "sites": true and its Service
 * has site(string $host): ?\ClientFence\Site, which for the request's host (host[:port], lower case) answers:
 * - null: not its host (the next module by name is asked; a host no module serves gets the primary site);
 * - a Site on exactly that host: an http(s) base URL with a trailing slash and no query, `host` as asked, and its own
 *   identifier of the site as `key` (at most 50 characters); the core keeps it as "<module>/<identifier>", so two
 *   modules' sites never share a key (the 50 characters keep it short enough to store with a record);
 * - throws SiteUnavailableException: its host, but it cannot serve it now; that request gets a 503 page
 *   (unavailable()), never the primary site, so a visitor never sees another site in its place.
 * Any other exception or answer is logged and the next module is asked. While a switched-on site module cannot run on
 * this PHP (Module::cannotRun()), every host but the primary one gets the 503. The primary host and the console (cron, the
 * command line) never ask a module. site() runs while $di['site'] is being worked out, so it must not read it, nor
 * what is built on it ($di['url'], System\Service::getCompany()).
 *
 * $di['site'] is the request's site, worked out once at the start of each web request (load.php). Read it with:
 * - ofRequest(): the site the browser is on; what must match the browser (origin and Referer checks, passkeys,
 *   routing paths) uses it, and within() never changes it;
 * - current(): the site the portal is working for (links, addresses, assets, gateway return and notice URLs): the
 *   request's site, or inside within() the site of the record the work is for.
 *
 * The site of a record. The client, client_order, invoice (credit notes too), invoice_quote, support_ticket and
 * email_queue tables have a `site` column: the stored key of the site the row was made on, NULL for the primary site
 * (and every row of an install without a site module, which writes none). It is written once, when the row is made
 * (stamp()): inside on() the stored key of the record it is made for, else the current site's key, else (on the primary
 * site: cron, the console, staff on the primary site, the API) the key of the row's client, so a ticket from email
 * piping or a staff order is on its client's site; a client has no parent, and the email queue keeps the site its email
 * was built on. An invoice made for a record is on that record's site (on()): an order's invoice (each renewal, from
 * cron, staff Renew, the client's Renew Now or a gateway subscription payment; staff issuing a new order's invoice; an
 * upgrade or option change) on the order's, and renewals put together on one invoice only with orders of the same site;
 * a refund invoice or credit note (a part refund, a downgrade's credit) on the invoice it is for; an accepted quote's
 * invoice on the quote's; a Mass Pay invoice on the site of the invoices it pays, which must all be on one site. Other
 * invoices (checkout, Add funds, staff's free-form invoice) are on the current site, else the client's. The import
 * (Clientfence\Whmcs) writes none. The core changes a stored site in one place only: staff moving a service to another
 * client (Order\ClientMove) give the service and its add-ons the new client's site; its invoices, tickets and quotes
 * stay the old client's, with their sites.
 * - forRecord($di, $type, $id): the site of a record ('client', 'order', 'invoice', 'quote', 'ticket'): its own key,
 *   else its client's, through byKey();
 * - byKey($di, $key): a stored key back to its Site: the module the key names (switched on, "sites": true, able to run)
 *   answers siteByKey(string $key): ?\ClientFence\Site for its own identifier (the key without "<module>/"), with the
 *   site of that identifier under the same rules as site() (an http(s) base URL, `host` as in that URL, the same `key`).
 *   NULL, an unknown or switched-off module, one that cannot run, and any other answer give the primary site (logged
 *   once per request);
 * - within($di, $site, $fn): runs $fn with current() as $site (restored after it, also when it throws; nests);
 * - on($di, $type, $id, $fn): within() the site of a record, and what is made inside it takes that record's stored key,
 *   read in SQL, not through byKey(): a switched-off module's key is kept, never replaced by the primary site.
 * The core builds on a record's site: a client's email (the invoice, order, ticket or quote it is about, else its
 * client: Email\Service::sendTemplate(); the staff's Send Email to a client; mass mail) with the links made for it
 * before (confirmation, feedback, network status, affiliate and quote links), the queued row keeping that site, and
 * the invoice, credit note, quote and statement PDFs. Staff emails and the admin links made for them are on the
 * primary site, where staff sign in, as is a cron run, also one started through another site's host. Left on the
 * primary site on purpose: the gateway addresses shown to staff, the IPN addresses of cron charges, the sitemap and
 * the health and web server checks; on the request's site: OAuth callbacks and the staff bell entries. A queued
 * email is sent as it was built.
 *
 * Events on a new record (params): onAfterNewInvoice (id) for every invoice, credit note and refund invoice made,
 * once its lines are added (a combined renewal invoice may get more lines after it; onAfterInvoiceCreate is the
 * Invoice module's own "invoice created" email listener); onAfterQuoteCreate (id); onAfterOrderCreate (id, client_id)
 * for each order of a checkout, of a staff order and of the API; onAfterImport (system, client, order, invoice,
 * ticket: lists of the imported ids) when an import finishes. They fire isolated: a listener that throws is logged and
 * the record is still made (its own nested transaction rolls back to its savepoint). They fire inside the transaction
 * that makes the record when there is one (a staff order's total preview makes and rolls back its records): keep a
 * listener's own rows in the same database, and do no outside work there. Write them with $di['dbal'], never through the
 * shared entity manager: a failed flush closes it, and the caller's own save (checkout's) then fails.
 *
 * Which modules serve sites is kept between requests in $di['cache'] (stamp() and forRecord() run for every new record
 * and client email); it is read again after an extension is activated, deactivated, removed or upgraded (all saved
 * through Doctrine: Doctrine\SiteStamp), after a core update (it clears the caches), or when the cache expires.
 */
final readonly class Site
{
    /** The record types whose table keeps the site a row was made on (column `site`). */
    public const array RECORDS = ['client' => 'client', 'order' => 'client_order', 'invoice' => 'invoice', 'quote' => 'invoice_quote', 'ticket' => 'support_ticket'];

    /** The $di['cache'] key of the site modules' list (providers()). */
    private const string PROVIDERS = 'clientfence_site_providers';

    private const string KEY = '/^[a-z0-9_-]{1,50}\z/';

    /**
     * @param string      $url  the base URL with a trailing slash: scheme, host[:port] and path, as SYSTEM_URL
     * @param string      $host host[:port] as the request shows it
     * @param string|null $key  null for the primary site, else "<module>/<the module's own identifier of the site>"
     */
    public function __construct(public string $url, public string $host, public ?string $key = null)
    {
    }

    public static function primary(): self
    {
        return new self(SYSTEM_URL, self::hostOf(SYSTEM_URL));
    }

    /** The request's site (the browser is on it), or the primary one in a container without it. */
    public static function ofRequest(?\Pimple\Container $di): self
    {
        $site = isset($di['site']) ? $di['site'] : null;

        return $site instanceof self ? $site : self::primary();
    }

    /** The site the portal is working for: the record's inside within(), else the request's (ofRequest()). */
    public static function current(?\Pimple\Container $di): self
    {
        $within = isset($di['site_within']) ? $di['site_within'] : null;

        return $within instanceof self ? $within : self::ofRequest($di);
    }

    /** Runs `$fn` with `$site` as current(), then restores the one before (nested calls each restore their own). */
    public static function within(\Pimple\Container $di, self $site, callable $fn): mixed
    {
        $before = isset($di['site_within']) ? $di['site_within'] : null;
        $di['site_within'] = $site;
        try {
            return $fn();
        } finally {
            if ($before === null) {
                unset($di['site_within']);
            } else {
                $di['site_within'] = $before;
            }
        }
    }

    /** The site of a record (a RECORDS type): its own stored key, else its client's; the primary site without one. */
    public static function forRecord(\Pimple\Container $di, string $type, int $id): self
    {
        return self::byKey($di, self::storedKey($di, $type, $id));
    }

    /**
     * Runs `$fn` for a record (a RECORDS type): inside it current() is the record's site (forRecord()), and a record made
     * there takes this record's stored key (stamp()), also while that key's module is switched off. Restored after it,
     * also when it throws; nests. Without a site module it only runs `$fn`.
     */
    public static function on(\Pimple\Container $di, string $type, int $id, callable $fn): mixed
    {
        if (self::providers($di) === []) {
            return $fn();
        }
        $key = self::storedKey($di, $type, $id);
        $site = self::byKey($di, $key);
        $before = isset($di['site_on']) ? $di['site_on'] : null;
        $di['site_on'] = [$site, $key];
        try {
            return self::within($di, $site, $fn);
        } finally {
            if ($before === null) {
                unset($di['site_on']);
            } else {
                $di['site_on'] = $before;
            }
        }
    }

    /**
     * The stored key of a record (a RECORDS type): its own, else its client's; null for the primary site. Nothing is read
     * without a site module.
     */
    public static function storedKey(\Pimple\Container $di, string $type, int $id): ?string
    {
        $table = self::RECORDS[$type] ?? null;
        // Without a site module no row has a key: nothing is read.
        if ($table === null || $id < 1 || self::providers($di) === []) {
            return null;
        }
        try {
            $key = $table === 'client'
                ? $di['dbal']->fetchOne('SELECT site FROM client WHERE id = ?', [$id])
                : $di['dbal']->fetchOne("SELECT COALESCE(r.site, c.site) FROM `{$table}` r LEFT JOIN client c ON c.id = r.client_id WHERE r.id = ?", [$id]);
        } catch (\Throwable $e) {
            $di['logger']->warning(sprintf('The site of %s #%d could not be read: %s', $type, $id, $e->getMessage()));

            return null;
        }

        return is_string($key) ? $key : null;
    }

    /** Whether a site module is switched on: without one every record is on the primary site. */
    public static function active(\Pimple\Container $di): bool
    {
        return self::providers($di) !== [];
    }

    /** The site of a stored key (null: the primary site); see the class comment. Worked out once per request. */
    public static function byKey(\Pimple\Container $di, ?string $key): self
    {
        if ($key === null) {
            return self::primary();
        }

        return RequestCache::remember($di, 'site.key.' . $key, static function () use ($di, $key): self {
            [$module, $own] = explode('/', $key, 2) + [1 => ''];
            $problem = in_array($module, self::providers($di), true) ? null : 'its module is not switched on or serves no sites';
            try {
                if ($problem === null && ($reason = $di['mod']($module)->cannotRun()) !== null) {
                    $problem = strtr(...$reason);
                }
                if ($problem === null) {
                    $site = $di['mod_service']($module)->siteByKey($own);
                    if ($site instanceof self && $site->key === $own && self::serves($site, self::hostOf($site->url))) {
                        return new self($site->url, $site->host, $key);
                    }
                    $problem = 'its module answered with no valid site';
                }
            } catch (\Throwable $e) {
                $problem = $e->getMessage();
            }
            $di['logger']->warning(sprintf('The site %s is not available, the primary site is used: %s', $key, $problem));

            return self::primary();
        });
    }

    /**
     * Writes the site of a row just made in `$table` (a RECORDS table or email_queue), as the class comment says.
     * Nothing is written without a site module. A failure is logged: the row stays on the primary site.
     */
    public static function stamp(\Pimple\Container $di, string $table, int $id): void
    {
        if ($id < 1 || (!in_array($table, self::RECORDS, true) && $table !== 'email_queue') || self::providers($di) === []) {
            return;
        }
        $key = self::current($di)->key;
        try {
            if ($table === 'client' || $table === 'email_queue') {
                if ($key !== null) {
                    $di['dbal']->update($table, ['site' => $key], ['id' => $id]);
                }

                return;
            }
            // Inside on() (and not a within() nested in it): the stored key of the record this row is made for.
            $on = isset($di['site_on']) ? $di['site_on'] : null;
            if (is_array($on) && $on[0] === self::current($di)) {
                $key = $on[1];
            }
            $di['dbal']->executeStatement("UPDATE `{$table}` SET site = COALESCE(?, (SELECT c.site FROM client c WHERE c.id = `{$table}`.client_id)) WHERE id = ?", [$key, $id]);
        } catch (\Throwable $e) {
            $di['logger']->warning(sprintf('The site of %s #%d was not saved: %s', $table, $id, $e->getMessage()));
        }
    }

    /**
     * The 503 answer for a web request whose host a module cannot serve now (SiteUnavailableException), or null. Run
     * first thing on every web request (load.php), so nothing is worked out on such a host before the answer.
     */
    public static function unavailable(\Pimple\Container $di, Request $request): ?Response
    {
        try {
            $site = self::ofRequest($di);
        } catch (SiteUnavailableException) {
            // The host is a module's, but it cannot serve it now.
            $site = null;
        }
        if ($site !== null) {
            return null;
        }
        $message = 'This site is not available right now. Please try again later.';
        // API calls get the API's own error format, as during maintenance.
        if (preg_match('#^/api(/|$)#', RequestFactory::getRoutePath($request)) === 1) {
            return (new ApiResponseFactory())->error(new SiteUnavailableException($message, 503));
        }

        // The update page's style (load.php): no link, name or asset of another site.
        return new Response('<!doctype html><html><head><meta charset="utf-8"><title>Not available</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:4rem 1rem;"><h1>Not available</h1><p>' . $message . '</p></body></html>', 503, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** scheme://host[:port], as the browser's Origin header names the site. */
    public function origin(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_SCHEME)) . '://' . $this->host;
    }

    /**
     * The site of `$request` (null: no request, as in the console): the primary one unless a module serves its host.
     *
     * @throws SiteUnavailableException when the module that serves the host cannot serve it now
     */
    public static function resolve(\Pimple\Container $di, ?Request $request): self
    {
        $primary = self::primary();
        try {
            $host = $request === null ? '' : strtolower($request->getHttpHost());
        } catch (\UnexpectedValueException) {
            // A malformed Host header gets the primary site, as before.
            return $primary;
        }
        if ($host === '' || $host === $primary->host) {
            return $primary;
        }

        // A site module that cannot run here cannot say which hosts are its own: no host but the primary one gets a site
        // that may be another's.
        $providers = self::providers($di);
        foreach ($providers as $module) {
            if (($reason = $di['mod']($module)->cannotRun()) !== null) {
                $di['logger']->warning(sprintf('The %s module cannot answer for the host %s: %s', $module, $host, strtr(...$reason)));

                throw new SiteUnavailableException(strtr(...$reason));
            }
        }
        foreach ($providers as $module) {
            try {
                $service = $di['mod_service']($module);
            } catch (\Throwable $e) {
                // Only site() may say a host is unavailable: a module that cannot load is skipped, as before.
                $di['logger']->warning(sprintf('The %s module could not answer for the host %s: %s', $module, $host, $e->getMessage()));

                continue;
            }
            try {
                $site = $service->site($host);
                if ($site === null) {
                    continue;
                }
                if ($site instanceof self && self::serves($site, $host)) {
                    // The stored key names the module too, so two modules' sites never share one.
                    return new self($site->url, $site->host, $module . '/' . $site->key);
                }
                $problem = 'it answered with a site that is not on that host';
            } catch (SiteUnavailableException $e) {
                $di['logger']->warning(sprintf('The %s module cannot serve the host %s now: %s', $module, $host, $e->getMessage()));

                throw $e;
            } catch (\Throwable $e) {
                $problem = $e->getMessage();
            }
            $di['logger']->warning(sprintf('The %s module could not answer for the host %s: %s', $module, $host, $problem));
        }

        return $primary;
    }

    /** @return list<string> the switched-on modules (sorted by name) whose manifest declares "sites": true */
    private static function providers(\Pimple\Container $di): array
    {
        // Every new record and client email asks (stamp(), forRecord()), so the list is kept between requests in
        // $di['cache'] (one read per request) until an extension changes (forgetProviders()) or a core update clears it.
        return RequestCache::remember($di, 'site.providers', static fn (): array => isset($di['cache'])
            ? $di['cache']->get(self::PROVIDERS, static function (\Symfony\Contracts\Cache\ItemInterface $item, bool &$save) use ($di): array {
                $providers = self::findProviders($di);
                // A list that could not be read is not kept: the next request reads it again.
                $save = $providers !== null;

                return $providers ?? [];
            })
            : self::findProviders($di) ?? []);
    }

    /** Called when an extension is activated, deactivated, removed or upgraded (Doctrine\SiteStamp): the list is read again. */
    public static function forgetProviders(\Pimple\Container $di): void
    {
        RequestCache::forget($di, 'site.');
        if (isset($di['cache'])) {
            $di['cache']->delete(self::PROVIDERS);
        }
    }

    /** @return list<string>|null null when the module list cannot be read */
    private static function findProviders(\Pimple\Container $di): ?array
    {
        try {
            $modules = $di['mod_service']('extension')->getCoreAndActiveModules();
            if (!is_array($modules)) {
                return null;
            }
        } catch (\Throwable) {
            // Without the module list the request keeps the primary site, as before.
            return null;
        }

        return array_values(array_filter(
            $modules,
            static function (string $name) use ($di): bool {
                // A broken manifest leaves out only that module, as in the settings search (SettingsIndex).
                try {
                    $mod = $di['mod']($name);

                    return $mod->hasManifest() && ($mod->getManifest()['sites'] ?? null) === true;
                } catch (\Throwable) {
                    return false;
                }
            },
        ));
    }

    /** Whether `$site` is an http(s) site on `$host`, with a base URL and its own identifier. */
    private static function serves(self $site, string $host): bool
    {
        $parts = parse_url($site->url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !isset($parts['query']) && !isset($parts['fragment'])
            && str_ends_with($site->url, '/') && $site->host === $host && $site->key !== null && preg_match(self::KEY, $site->key) === 1
            // The exact authority, as a browser reads it: no user info, backslash or default port in front of the host.
            && str_starts_with(strtolower($site->url), strtolower($parts['scheme']) . '://' . $host . '/');
    }

    /** host[:port] of `$url` in lower case, without the scheme's default port (as Request::getHttpHost() shows it). */
    private static function hostOf(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower(is_array($parts) ? ($parts['scheme'] ?? '') : '');
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return strtolower((is_array($parts) ? ($parts['host'] ?? '') : '') . ($port === null || $default ? '' : ':' . $port));
    }
}
